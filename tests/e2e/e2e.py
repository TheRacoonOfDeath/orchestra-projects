#!/usr/bin/env python3
"""End-to-end test of orchestra-projects with the other Orchestra plugins. Started by run.sh.

Environment: E2E_BASE_URL, E2E_WP_CLI (wrapper script), E2E_WP_PATH, E2E_SEED (IDs from seed.php).
"""
import html
import io
import json
import os
import re
import subprocess
import sys
import tempfile
import zipfile

BASE = os.environ["E2E_BASE_URL"]
WPC = os.environ["E2E_WP_CLI"]
SEED = json.loads(os.environ["E2E_SEED"])
WORK = tempfile.mkdtemp(prefix="orchestra-e2e-")
failures = []


def check(condition, label):
    print(("PASS " if condition else "FAIL ") + label)
    if not condition:
        failures.append(label)


def wp(*args):
    return subprocess.run([WPC, *args], capture_output=True, text=True).stdout.strip()


def wp_eval(code):
    return wp("eval", code)


def wp_json(code):
    output = wp_eval(code)
    try:
        return json.loads(output)
    except json.JSONDecodeError:
        print("Unexpected output:", output[:500])
        return None


def user_id(login):
    return int(wp("user", "get", login, "--field=ID"))


class Session:
    def __init__(self, user=None, password="pass"):
        self.jar = os.path.join(WORK, f"cookies-{user or 'anon'}-{os.urandom(4).hex()}.txt")
        open(self.jar, "w").close()
        if user:
            self.request("/wp-login.php", data={
                "log": user, "pwd": password, "wp-submit": "Log In", "testcookie": "1",
            }, cookie="wordpress_test_cookie=WP%20Cookie%20check")

    def request(self, path, data=None, cookie=None, follow=True):
        """Returns (status, headers, body). Values in `data` may be lists."""
        header_file = os.path.join(WORK, "headers.txt")
        cmd = ["curl", "-s", "-c", self.jar, "-b", self.jar, "-D", header_file, "-o", "-"]
        if follow:
            cmd.append("-L")
        if cookie:
            cmd += ["--cookie", cookie]
        if data is not None:
            for key, value in data.items():
                for v in (value if isinstance(value, list) else [value]):
                    cmd += ["--data-urlencode", f"{key}={v}"]
        cmd.append(path if path.startswith("http") else BASE + path)
        result = subprocess.run(cmd, capture_output=True)
        raw = open(header_file, encoding="latin-1", newline="").read()
        blocks = [b for b in raw.split("\r\n\r\n") if b.strip()]
        last = blocks[-1] if blocks else ""
        status = int(re.search(r"HTTP/[\d.]+ (\d+)", last).group(1)) if last else 0
        headers = {}
        for line in last.split("\r\n")[1:]:
            if ":" in line:
                k, v = line.split(":", 1)
                headers[k.strip().lower()] = v.strip()
        return status, headers, result.stdout

    def get(self, path):
        status, _, body = self.request(path)
        return status, body.decode()

    def post(self, path, data):
        status, _, body = self.request(path, data=data)
        return status, body.decode()


def field(page, name):
    match = re.search(r'name=["\']' + re.escape(name) + r'["\'][^>]*value=["\']([^"\']*)', page) \
        or re.search(r'value=["\']([^"\']*)["\'][^>]*name=["\']' + re.escape(name) + r'["\']', page)
    return html.unescape(match.group(1)) if match else None


def text(page):
    """The visible text of a page, for checks independent of markup."""
    page = re.sub(r"<(script|style)[^>]*>.*?</\1>", " ", page, flags=re.S)
    return html.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", page)))


def manage_url(project, tab="overview"):
    """A project on the projects page."""
    return f"{DASHBOARD}&orchestra_project={project}&orchestra_tab={tab}"


def admin_url(project, tab="overview"):
    """A project in wp-admin, for administrators."""
    return f"/wp-admin/admin.php?page=orchestra-projects&project={project}&tab={tab}"


TAB_OF = {"status": "overview", "participation": "participation", "assign": "assignments", "unassign": "assignments",
          "assign_last": "assignments"}
nonces = {}


def project_nonce(session, project, op):
    """The user's nonce for the forms of a project. Kept, so it also works once the forms are gone."""
    key = (session.jar, project)
    if key not in nonces:
        _, page = session.get(manage_url(project, TAB_OF.get(op, "program")))
        nonce = field(page, "_wpnonce")
        if nonce is None:
            return "missing"
        nonces[key] = nonce
    return nonces[key]


def act(session, project, op, **fields):
    """Sends a form of the project screens. Returns the page shown after the redirect."""
    data = {"action": "orchestra_projects", "project": str(project), "op": op,
            "_wpnonce": project_nonce(session, project, op),
            "redirect_to": manage_url(project, TAB_OF.get(op, "program"))}
    data.update({k: (v if isinstance(v, list) else str(v)) for k, v in fields.items()})
    return session.post("/wp-admin/admin-post.php", data)


def create_project(session, title, start, end, concert, conductors=(), organizers=()):
    """Creates a project with the form on the projects page."""
    _, page = session.get(f"{DASHBOARD}&orchestra_project=new")
    data = {
        "action": "orchestra_projects", "op": "save", "project": "0",
        "_wpnonce": field(page, "_wpnonce") or "missing", "redirect_to": field(page, "redirect_to") or DASHBOARD,
        "orchestra_projects_title": title, "orchestra_projects_description": f"Description of {title}",
        "orchestra_projects_start_date": start, "orchestra_projects_end_date": end,
        "orchestra_projects_concert_date": concert,
        "orchestra_projects_staff[conductor][]": [str(i) for i in conductors],
        "orchestra_projects_staff[organizer][]": [str(i) for i in organizers],
    }
    _, page = session.post("/wp-admin/admin-post.php", data)
    project_id = wp_eval(f'echo (new WP_Query(["post_type" => "orchestra_project", "title" => "{title}",'
                         ' "fields" => "ids", "orderby" => "ID"]))->posts[0] ?? 0;')
    return int(project_id or 0), page


def projects_api(code):
    return wp_json("$p = Orchestra\\Projects\\Plugin::instance()->projects(); " + code)


def participation_of(project, login):
    return wp_json(
        f'$u = get_user_by("login", "{login}")->ID; global $wpdb;'
        f'$r = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {{$wpdb->prefix}}orchestra_projects_participation'
        f' WHERE project_id = %d AND user_id = %d", {project}, $u), ARRAY_A); echo json_encode($r);'
    )


def assignments_of(project):
    """{login: [part ID, …]}"""
    rows = projects_api(
        f'echo json_encode(array_map(fn($a) => [$a->userId ? get_userdata($a->userId)->user_login : null,'
        f' $a->partId], $p->assignments({project})));'
    )
    result = {}
    for login, part in rows:
        result.setdefault(login, []).append(part)
    return result


def response_nonce(page, participation_id):
    for form in re.findall(r"<form.*?</form>", page, flags=re.S):
        if f'name="participation" value="{participation_id}"' in form:
            return field(form, "_wpnonce")
    return None


def respond(session, participation_id, response, nonce_page):
    return session.post("/wp-admin/admin-post.php", {
        "action": "orchestra_projects_respond", "participation": str(participation_id), "response": response,
        "_wpnonce": response_nonce(nonce_page, participation_id) or "missing", "redirect_to": DASHBOARD,
    })


ids = {login: user_id(login) for login in ["admin", "organizer", "conductor", "mia", "max", "mo", "ina", "sam"]}
PIRATES, DANCE, MARCH = SEED["pirates"], SEED["dance"], SEED["march"]
HORN1, HORN2, VIOLIN1 = SEED["pirates:Horn 1"], SEED["pirates:Horn 2"], SEED["dance:Violin 1"]
PAGES = wp_json(
    'echo json_encode(array_combine($t = ["orchestra_projects", "orchestra_email", "orchestra_members", "orchestra_portal"],'
    ' array_map(fn ($s) => Orchestra\\Core\\Ui\\Pages::url($s), $t)));'
)
DASHBOARD, EMAIL = PAGES["orchestra_projects"], PAGES["orchestra_email"]

admin = Session("admin", "admin")
organizer = Session("organizer")
conductor = Session("conductor")
mia, max_, mo, sam = Session("mia"), Session("max"), Session("mo"), Session("sam")

# --- Capabilities ---------------------------------------------------------------------------
caps = wp_json(
    'echo json_encode(array_map(fn($r) => array_keys(array_filter(get_role($r)->capabilities)), '
    '["orchestra_member", "orchestra_conductor", "orchestra_organizer"]));'
)
check("orchestra_projects_participation_respond_own" in caps[0]
      and "orchestra_projects_program_manage" not in caps[0], "members can respond but not plan")
check("orchestra_projects_assignment_manage" in caps[1]
      and "orchestra_projects_participation_respond_own" not in caps[1], "conductors assign but do not play")
check({"orchestra_projects_project_manage", "orchestra_projects_participation_manage",
       "orchestra_projects_participation_respond_own", "orchestra_projects_assignment_manage"} <= set(caps[2]),
      "organizers manage projects, assign and play")

# --- Creating projects ----------------------------------------------------------------------
_, page = conductor.get(f"{DASHBOARD}&orchestra_project=new")
check("orchestra_projects_title" not in page and "New project" not in page, "conductors cannot create projects")
status, _ = conductor.post("/wp-admin/admin-post.php", {"action": "orchestra_projects", "op": "save", "project": "0",
                                                       "orchestra_projects_title": "X", "_wpnonce": "x"})
check(status == 403, "conductors cannot save new projects")
_, page = mia.get("/wp-admin/admin.php?page=orchestra-projects")
check("orchestra--admin" not in page and "Hello Mia" in page, "members are sent from wp-admin to the Orchestra pages")
_, page = conductor.get("/wp-admin/admin.php?page=orchestra-projects")
check("orchestra--admin" not in page, "conductors use the projects page instead of wp-admin")

spring, page = create_project(organizer, "Spring Concert 2027", "2027-03-01", "2027-05-10", "2027-05-09",
                              conductors=[ids["conductor"], ids["mia"]], organizers=[ids["organizer"]])
project = projects_api(f'$x = $p->project({spring}); echo json_encode($x ? [$x->title, $x->status->value,'
                       f' $x->startDate, $x->endDate, $x->concertDate] : null);')
check(project == ["Spring Concert 2027", "draft", "2027-03-01", "2027-05-10", "2027-05-09"],
      "organizer creates a project as draft with its dates")
staff = wp_json(f'global $wpdb; echo json_encode($wpdb->get_results("SELECT user_id, role FROM'
                f' {{$wpdb->prefix}}orchestra_projects_staff WHERE project_id = {spring} ORDER BY role", ARRAY_A));')
check(staff == [{"user_id": str(ids["conductor"]), "role": "conductor"},
                {"user_id": str(ids["organizer"]), "role": "organizer"}],
      "staff is stored, members cannot be selected as conductors")

bad, page = create_project(organizer, "Broken dates", "2027-05-10", "2027-03-01", "2027-02-30")
check("The end date must not be before the start date." in text(page) and "Concert date is not a valid date." in text(page),
      "invalid dates are rejected with messages")

_, page = organizer.get(DASHBOARD)
check("Spring Concert 2027" in page and "New project" in page, "organizers see all projects and can create them")
_, page = conductor.get(DASHBOARD)
check("Spring Concert 2027" in page and "New project" not in page, "conductors see all projects")
_, page = conductor.get(manage_url(999999))
check("does not exist" in page, "unknown projects are not found")
_, page = admin.get("/wp-admin/admin.php?page=orchestra-projects")
check("Spring Concert 2027" in page and "orchestra--admin" in page, "administrators see the projects in wp-admin")
_, page = organizer.get(f"{DASHBOARD}&orchestra_project={spring}&orchestra_edit=1")
form_nonce, form_redirect = field(page, "_wpnonce"), field(page, "redirect_to")
organizer.post("/wp-admin/admin-post.php", {
    "action": "orchestra_projects", "op": "save", "project": str(spring), "_wpnonce": form_nonce or "x",
    "redirect_to": form_redirect or DASHBOARD, "orchestra_projects_title": "Spring Concert 2027",
    "orchestra_projects_description": "Description of Spring Concert 2027", "orchestra_projects_start_date": "2027-03-01",
    "orchestra_projects_end_date": "2027-05-10", "orchestra_projects_concert_date": "2027-05-09",
    "orchestra_projects_staff[conductor][]": [str(ids["conductor"])],
    "orchestra_projects_staff[organizer][]": [str(ids["organizer"])],
})
check(projects_api(f'echo json_encode($p->project({spring})->description);') == "Description of Spring Concert 2027",
      "organizers edit projects on the projects page")

_, page = mia.get(DASHBOARD)
check("Spring Concert 2027" not in page, "draft projects are hidden from members")
_, page = mia.get(f"{DASHBOARD}&orchestra_project={spring}")
check("does not exist" in page and "Description of" not in page, "draft project pages are hidden from members")
_, page = Session().get(DASHBOARD)
check("Please log in" in page, "the dashboard asks visitors to log in")
_, page = sam.get(DASHBOARD)
check("not allowed" in page, "users without an orchestra role cannot see projects")

# --- Program --------------------------------------------------------------------------------
act(conductor, spring, "program_add", piece=PIRATES)
act(conductor, spring, "program_add", piece=DANCE)
_, page = act(conductor, spring, "program_add", piece=MARCH)
check("Archived pieces cannot be added" in text(page), "archived pieces cannot be added")
_, page = act(conductor, spring, "program_add", piece=PIRATES)
check("already in the program" in text(page), "a piece appears at most once in a program")
act(organizer, spring, "program_down", piece=PIRATES)
check(projects_api(f"echo json_encode($p->programPieceIds({spring}));") == [DANCE, PIRATES],
      "conductors and organizers order the program")
act(conductor, spring, "program_up", piece=PIRATES)
_, page = conductor.get(manage_url(spring, "program"))
check(projects_api(f"echo json_encode($p->programPieceIds({spring}));") == [PIRATES, DANCE]
      and '<option value="' + str(MARCH) not in page, "the add list offers no archived pieces")
status, _ = act(mia, spring, "program_add", piece=MARCH)
check(status == 403, "members cannot change programs")
blockers = wp_json(f'echo json_encode(apply_filters("orchestra_library_piece_delete_blockers", [], {PIRATES}));')
check(len(blockers) == 1 and "Spring Concert 2027" in blockers[0], "pieces in a program cannot be deleted in the library")

# --- Opening for participation ----------------------------------------------------------------
status, _ = act(conductor, spring, "status", target="open")
check(status == 403, "conductors cannot change the project status")
_, page = act(organizer, spring, "status", target="preparation")
check("cannot be moved to this status" in text(page), "projects move one step at a time")
act(organizer, spring, "status", target="open")
rows = projects_api(f"echo json_encode($p->participantIds({spring}));")
check(sorted(rows) == sorted([ids["organizer"], ids["mia"], ids["max"], ids["mo"]]),
      "opening asks all active members and organizers, not conductors, inactive members or others")

# --- Responses ------------------------------------------------------------------------------
_, page = mia.get(DASHBOARD)
check("Spring Concert 2027" in page and "Accept" in page and "Pending" in page,
      "members see the open project with their pending response")
mia_participation = participation_of(spring, "mia")["id"]
max_participation = participation_of(spring, "max")["id"]
mia_open_page = page
_, page = respond(mia, mia_participation, "accepted", mia_open_page)
check("You have accepted" in text(page) and participation_of(spring, "mia")["status"] == "accepted", "member accepts")
_, page = respond(mia, mia_participation, "declined", mia_open_page)
check("already responded" in text(page) and participation_of(spring, "mia")["status"] == "accepted",
      "the decision is final")
status, _ = respond(mia, max_participation, "declined", page)
check(status == 403 and participation_of(spring, "max")["status"] == "open", "members cannot respond for others")
_, page = max_.get(DASHBOARD)
respond(max_, max_participation, "declined", page)
check(participation_of(spring, "max")["status"] == "declined", "member declines")
_, page = organizer.get(DASHBOARD)
respond(organizer, participation_of(spring, "organizer")["id"], "accepted", page)
check(participation_of(spring, "organizer")["status"] == "accepted", "organizers respond for themselves")
_, page = mia.get(DASHBOARD)
check("Accepted" in page and response_nonce(page, mia_participation) is None, "no response buttons after responding")

_, page = mia.get(f"{DASHBOARD}&orchestra_project={spring}")
plain = text(page)
check("Description of Spring Concert 2027" in plain and "Carl" in plain and "Olga" in plain
      and plain.index("Pirates of the Caribbean") < plain.index("Lord of the Dance"),
      "the project page shows description, staff and the ordered program")

_, page = conductor.get(manage_url(spring, "participation"))
check("Mia" in page and "Max" in page and 'name="status"' not in page,
      "conductors see the participation without changing it")
status, _ = act(conductor, spring, "participation", participation=max_participation, status="accepted")
check(status == 403, "conductors cannot change participation")

# --- Assignments ----------------------------------------------------------------------------
_, page = act(conductor, spring, "assign", part=HORN1, **{"users[]": [str(ids["mia"])]})
act(conductor, spring, "assign", part=HORN2, **{"users[]": [str(ids["organizer"])]})
check(assignments_of(spring) == {"mia": [HORN1], "organizer": [HORN2]}, "conductor assigns members to parts")
_, page = act(conductor, spring, "assign", part=HORN1, **{"users[]": [str(ids["max"])]})
check("has not accepted" in text(page) and "max" not in assignments_of(spring), "only accepted members can be assigned")
_, page = act(conductor, spring, "assign", part=HORN2, **{"users[]": [str(ids["mia"])]})
check("already assigned to Horn 1" in text(page) and assignments_of(spring)["mia"] == [HORN1],
      "a member plays one part per piece")
act(conductor, spring, "assign", part=HORN2, **{"users[]": [str(ids["mia"])]}, move="1")
check(assignments_of(spring)["mia"] == [HORN2], "moving a member replaces the assignment")
act(conductor, spring, "assign", part=HORN1, **{"users[]": [str(ids["mia"])]}, move="1")
act(conductor, spring, "assign", part=VIOLIN1, **{"users[]": [str(ids["mia"])]})
check(sorted(assignments_of(spring)["mia"]) == sorted([HORN1, VIOLIN1]), "members play one part in each piece")
_, page = act(organizer, spring, "assign", part=HORN1, **{"users[]": [str(ids["organizer"])]})
check("already assigned to Horn 2" in text(page) and assignments_of(spring)["organizer"] == [HORN2],
      "organizers assign too, with the same rules")
_, page = act(conductor, spring, "assign", part=SEED["march:Drum"], **{"users[]": [str(ids["mia"])]})
check("does not belong to a piece of the program" in text(page), "only parts of the program can be assigned")
blockers = wp_json(f'echo json_encode(apply_filters("orchestra_library_part_delete_blockers", [], {HORN1}));')
check(len(blockers) == 1, "assigned parts cannot be deleted in the library")
_, page = act(conductor, spring, "assign", part=HORN2)
check("Select at least one member" in text(page), "assigning nobody shows an error")

_, page = conductor.get(manage_url(spring, "assignments"))
check("Horn 1" in page and "Mia" in page and "data-current" not in page.split("Horn 1", 1)[0],
      "the assignments tab lists parts and members")

# --- Sheet music download -------------------------------------------------------------------
_, page = mia.get(DASHBOARD)
plain = text(page)
check("Your parts" in plain and "Pirates of the Caribbean Horn 1" in plain and "Lord of the Dance Violin 1" in plain,
      "members see their parts on the dashboard")
match = re.search(r'href="([^"]*orchestra_library_download_bundle[^"]*)"', page)
check(match is not None, "the dashboard offers the sheet music download")
if match:
    status, headers, body = mia.request(html.unescape(match.group(1)))
    names = zipfile.ZipFile(io.BytesIO(body)).namelist() if status == 200 else []
    check(status == 200 and len(names) == 2 and "Spring-Concert-2027.zip" in headers.get("content-disposition", ""),
          "members download the sheet music of their parts as one zip")
files = re.findall(r'href="([^"]*orchestra_library_download(?:&|&#038;|&amp;)file=[^"]*)"', page)
check(len(files) == 2, "the dashboard offers each part's sheet music on its own")
if files:
    status, headers, body = mia.request(html.unescape(files[0]))
    check(status == 200 and "attachment" in headers.get("content-disposition", "") and len(body) > 0,
          "members download the sheet music of a single part")
_, page = mo.get(DASHBOARD)
check("orchestra_library_download" not in page, "members without assignments have no download")

# --- Organizers change participation --------------------------------------------------------
_, page = act(organizer, spring, "participation", participation=mia_participation, status="declined")
check("Confirm that they are removed" in text(page) and participation_of(spring, "mia")["status"] == "accepted",
      "leaving Accepted with assignments needs a confirmation")
act(organizer, spring, "participation", participation=mia_participation, status="declined", confirm="1")
check(participation_of(spring, "mia")["status"] == "declined" and "mia" not in assignments_of(spring),
      "organizer changes a decision, the assignments are removed")
act(organizer, spring, "participation", participation=mia_participation, status="accepted")
act(conductor, spring, "assign", part=HORN1, **{"users[]": [str(ids["mia"])]})
check(assignments_of(spring).get("mia") == [HORN1], "organizer accepts on behalf of a member")
act(organizer, spring, "participation", participation=participation_of(spring, "mo")["id"], status="open")

# --- New, reactivated and promoted players --------------------------------------------------
wp("user", "create", "nia", "nia@example.com", "--role=orchestra_member", "--user_pass=pass", "--display_name=Nia")
check(participation_of(spring, "nia") is not None, "new members are asked to respond to open projects")
wp_eval(f'update_user_meta({ids["ina"]}, "orchestra_members_inactive", "1");')
_, members_page = organizer.get(f"{PAGES['orchestra_members']}&orchestra_member={ids['ina']}")
organizer.post("/wp-admin/admin-post.php", {
    "action": "orchestra_members_set_status", "member": str(ids["ina"]), "status": "active",
    "_wpnonce": field(members_page, "_wpnonce") or "missing",
})
check(participation_of(spring, "ina") is not None, "reactivated members are asked to respond to open projects")
wp("user", "set-role", "sam", "orchestra_member")
check(participation_of(spring, "sam") is not None, "users promoted to member are asked to respond")
wp("user", "create", "cleo", "cleo@example.com", "--role=orchestra_conductor", "--user_pass=pass")
check(participation_of(spring, "cleo") is None, "new conductors are not asked")

# --- Email to project and assignment groups ------------------------------------------------
COMPOSE = EMAIL


def mails():
    return json.loads(wp_eval('echo json_encode(get_option("e2e_mails", []));'))


def send_email(session, groups, subject, op="send"):
    """Reviews an email on the email page and, for op "send", sends it."""
    _, page = session.get(EMAIL)
    data = {"_wpnonce": field(page, "_wpnonce") or "missing", "op": "review", "subject": subject, "body": "Hello",
            "groups[]": groups}
    status, page = session.post(EMAIL, data)
    if op == "review":
        return status, page
    send = next((f for f in re.findall(r"<form.*?</form>", page, flags=re.S) if "orchestra_email_send" in f), "")
    return session.post("/wp-admin/admin-post.php", {
        "action": "orchestra_email_send", "_wpnonce": field(send, "_wpnonce") or "missing",
        "subject": subject, "body": "Hello", "groups[]": groups,
        "orchestra_log_url": field(send, "orchestra_log_url") or EMAIL, "redirect_to": EMAIL,
    })


_, page = conductor.get(COMPOSE)
plain = text(page)
check("Spring Concert 2027" in plain and "Accepted participants" in plain
      and "Pirates of the Caribbean: Horn 1" in plain and "Everyone playing Pirates of the Caribbean" in plain,
      "conductors can email the project, piece and part groups")
check("Lord of the Dance: Violin 1" not in plain, "parts without assignments have no group")
check("Broken dates" not in plain, "draft projects have no groups")
_, page = mo.get(COMPOSE)
check("not allowed to send emails" in page, "members cannot email project groups")

accepted_group = f"orchestra_projects_{spring}_accepted"
_, page = organizer.get(manage_url(spring))
check(f"groups%5B%5D={accepted_group}" in page, "the project overview links to an email to the accepted participants")
_, page = conductor.get(manage_url(spring, "assignments"))
check(f"orchestra_projects_{spring}_piece_{PIRATES}" in page, "the assignments link to an email to the players of a piece")

before = len(mails())
send_email(organizer, [accepted_group], "Rehearsal plan")
recipients = sorted(m["to"] for m in mails()[before:])
check(recipients == ["mia@example.com", "organizer@example.com"], "email to the accepted participants")
before = len(mails())
send_email(conductor, [f"orchestra_projects_{spring}_part_{HORN1}", f"orchestra_projects_{spring}_piece_{PIRATES}"], "Horns")
recipients = sorted(m["to"] for m in mails()[before:])
check(recipients == ["mia@example.com", "organizer@example.com"], "overlapping groups send one email per person")
wp_eval(f'update_user_meta({ids["mo"]}, "orchestra_members_inactive", "1");')
_, page = send_email(organizer, [f"orchestra_projects_{spring}_open"], "Please respond", "review")
check("Mo" not in text(page).split("Recipients", 1)[1].split("Subject", 1)[0] and "Nia" in page,
      "inactive members are left out of project groups")
wp_eval(f'delete_user_meta({ids["mo"]}, "orchestra_members_inactive");')

# --- Assignment history ---------------------------------------------------------------------
summer, _ = create_project(organizer, "Summer 2028", "2028-06-01", "2028-07-01", "2028-07-01")
act(conductor, summer, "program_add", piece=PIRATES)
act(organizer, summer, "status", target="open")
for session, login in [(mia, "mia"), (organizer, "organizer"), (mo, "mo")]:
    _, page = session.get(DASHBOARD)
    respond(session, participation_of(summer, login)["id"], "accepted", page)
_, page = conductor.get(manage_url(summer, "assignments"))
horn1_row = text(page.split("orchestra-projects-part-" + str(HORN1), 1)[1].split("</details>", 1)[0])
check("Mia — played last time" in horn1_row and "Olga — never played" in horn1_row and "Mo — never played" in horn1_row,
      "conductors see who played the part last time")
horn2_row = text(page.split("orchestra-projects-part-" + str(HORN2), 1)[1].split("</details>", 1)[0])
check("Olga — played last time" in horn2_row, "history is per part")
check('type="checkbox" name="users[]"' in page and "<select multiple" not in page,
      "members are assigned with checkboxes, not a multiple selection")
wp("option", "update", "orchestra_core_language", "de_DE")
_, page = conductor.get(manage_url(summer, "overview"))
check(re.search(r"1\. \w+ 2028", text(page)) is not None,
      "dates follow the order of the language when the site keeps the English default format")
check("<dt>Status</dt>" not in page, "the overview shows the status only next to the title")
_, page = conductor.get(manage_url(summer, "participation"))
check("Niemand" in text(page) and "orchestra-aligned-tables" in page, "empty participation groups say so")
wp("option", "delete", "orchestra_core_language")
_, page = admin.get(admin_url(summer, "assignments"))
check("played last time" in page and "orchestra--admin" in page, "administrators see the history in wp-admin")

_, page = conductor.get(manage_url(summer, "assignments"))
check("Assign the parts played last time" in page, "the assignments tab offers to assign the parts played last time")
spring_parts = assignments_of(spring)
accepted = [login for login in spring_parts if login and participation_of(summer, login)["status"] == "accepted"]
_, page = organizer.get(manage_url(summer, "assignments"))
check("played last time" in page and "Assign the parts played last time" in page,
      "organizers see the assignment history and can assign the parts played last time")
_, page = act(conductor, summer, "assign_last")
summer_parts = assignments_of(summer)
check(summer_parts == {login: spring_parts[login] for login in accepted} and "mo" not in summer_parts
      and "assigned to the member" in text(page),
      f"members get the part they played last time, others stay unassigned: {summer_parts}")
_, page = conductor.get(manage_url(summer, "assignments"))
check("Assign the parts played last time" not in page, "the button is gone once there is nothing left to assign")

# --- Removing pieces and status rules -------------------------------------------------------
act(conductor, summer, "assign", part=HORN1, **{"users[]": [str(ids["mia"])]})
_, page = act(conductor, summer, "program_remove", piece=PIRATES)
check("Confirm that they are removed" in text(page) and assignments_of(summer) != {},
      "removing a piece with assignments needs a confirmation")
act(conductor, summer, "program_remove", piece=PIRATES, confirm="1")
check(projects_api(f"echo json_encode($p->programPieceIds({summer}));") == [] and assignments_of(summer) == {},
      "removing a piece removes its assignments")

_, mo_open_page = mo.get(DASHBOARD)
act(organizer, spring, "status", target="preparation")
_, page = mo.get(DASHBOARD)
check("Accept" not in text(page).split("Spring Concert 2027", 1)[1].split("Summer 2028")[0],
      "no responses during preparation")
mo_participation = participation_of(spring, "mo")["id"]
check(response_nonce(mo_open_page, mo_participation) is not None, "members see the response form while open")
respond(mo, mo_participation, "accepted", mo_open_page)
check(participation_of(spring, "mo")["status"] == "open", "responses are rejected during preparation")
act(conductor, spring, "assign", part=HORN2, **{"users[]": [str(ids["organizer"])]})
check(HORN2 in assignments_of(spring).get("organizer", []), "assignments can be changed during preparation")
act(organizer, spring, "status", target="completed")
_, page = act(conductor, spring, "assign", part=VIOLIN1, **{"users[]": [str(ids["organizer"])]})
check("can only be changed while" in text(page), "assignments are locked in completed projects")
_, page = act(conductor, spring, "program_add", piece=DANCE)
check("cannot be changed" in text(page), "programs are locked in completed projects")
act(conductor, summer, "program_add", piece=PIRATES)
_, page = conductor.get(manage_url(summer, "assignments"))
horn1_row = text(page.split("orchestra-projects-part-" + str(HORN1), 1)[1].split("</details>", 1)[0])
check("Mia — played last time, played in a completed project" in horn1_row,
      "conductors see who played the part in a completed project")
act(conductor, summer, "program_remove", piece=PIRATES)
_, page = mia.get(DASHBOARD)
check("Spring Concert 2027" not in page and "Completed" in text(page), "completed projects leave the active projects")
_, page = mia.get(f"{DASHBOARD}&orchestra_completed=1")
check("Spring Concert 2027" in page and "Summer 2028" not in page, "completed projects are listed under Completed")
_, page = conductor.get(DASHBOARD)
check("Spring Concert 2027" not in page and "Summer 2028" in page, "conductors see only active projects by default")
_, page = mia.get(f"{DASHBOARD}&orchestra_project={spring}")
check("Spring Concert 2027" in page and "Horn 1" in page and "orchestra_completed=1" in page,
      "completed projects can still be opened and lead back to the completed projects")
_, page = organizer.get(COMPOSE)
check("Spring Concert 2027" not in page and "Summer 2028" in page, "completed projects have no email groups")
status, page = act(organizer, spring, "status", target="archived")
check("Unknown status" in text(page) and projects_api(f"echo json_encode($p->project({spring})->status->value);") == "completed",
      "there is no archived status any more")
wp_eval(f'update_post_meta({spring}, "orchestra_projects_status", "archived");')
check(projects_api(f"echo json_encode($p->project({spring})->status->value);") == "completed",
      "projects archived by earlier versions count as completed")
wp_eval(f'update_post_meta({spring}, "orchestra_projects_status", "completed");')

# --- Privacy and deleted users --------------------------------------------------------------
export = wp_json(
    '$e = apply_filters("wp_privacy_personal_data_exporters", [])["orchestra-projects"];'
    'echo json_encode(call_user_func($e["callback"], "mia@example.com", 1));'
)
values = [item["value"] for group in export["data"] for item in group["data"]]
check("Spring Concert 2027" in values and "Horn 1" in values and "Accepted" in values,
      "personal data export contains participation and parts")
erased = wp_json(
    '$e = apply_filters("wp_privacy_personal_data_erasers", [])["orchestra-projects"];'
    'echo json_encode(call_user_func($e["callback"], "mia@example.com", 1));'
)
check(erased["items_retained"] and not erased["items_removed"], "the eraser keeps the history")
_, page = admin.get("/wp-admin/options-privacy.php?tab=policyguide")
check("Orchestra Projects" in page, "suggests a privacy policy text")

wp("user", "delete", "max", "--yes")
check(projects_api(f"echo json_encode(count($p->participantIds({spring})));") == 6
      and wp_eval('global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orchestra_projects_participation'
                  f' WHERE project_id = {spring} AND user_id IS NULL");') == "1",
      "deleting a user anonymizes their participation")
_, page = organizer.get(manage_url(spring, "participation"))
check("Former member" in page, "anonymized participation is shown as former member")

# --- Deleting projects and uninstalling -----------------------------------------------------
wp("post", "delete", str(summer), "--force")
left = wp_eval('global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orchestra_projects_participation'
               f' WHERE project_id = {summer}");')
check(left == "0", "deleting a project deletes its participation")
check(projects_api(f"echo json_encode($p->project({spring}) !== null);") is True, "other projects are kept")

wp("plugin", "deactivate", "orchestra-projects")
check(wp_eval(f'echo get_post_status({spring});') == "publish", "deactivating keeps the projects")
wp("plugin", "uninstall", "orchestra-projects")
tables = wp_eval('global $wpdb; echo json_encode($wpdb->get_col("SHOW TABLES LIKE \'%orchestra_projects%\'"));')
check(tables == "[]" and wp_eval(f'echo (int) (get_post({spring}) !== null);') == "0",
      "uninstall removes the tables and projects")

wp("plugin", "deactivate", "orchestra-members", "orchestra-library", "orchestra-email", "orchestra-core")
wp("plugin", "uninstall", "orchestra-core")
tables = wp_eval('global $wpdb; echo json_encode($wpdb->get_col("SHOW TABLES LIKE \'%orchestra_core_email%\'"));')
check(len(json.loads(tables)) == 2, "uninstalling orchestra-core keeps the email log of orchestra-email")
wp("plugin", "uninstall", "orchestra-email")
tables = wp_eval('global $wpdb; echo json_encode($wpdb->get_col("SHOW TABLES LIKE \'%orchestra_core%\'"));')
check(tables == "[]" and wp("cron", "event", "list", "--hook=orchestra_core_email_purge", "--format=count") == "0",
      "uninstalling orchestra-email removes the email log and its cron events")

print()
if failures:
    print(f"{len(failures)} check(s) failed")
    sys.exit(1)
print("All checks passed")
