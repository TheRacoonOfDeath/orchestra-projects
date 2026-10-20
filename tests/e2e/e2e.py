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

    def request(self, path, data=None, cookie=None, follow=True, files=None):
        """Returns (status, headers, body). Values in `data` may be lists. `files` is a list of
        (field, path, file name) and sends the form as multipart/form-data."""
        header_file = os.path.join(WORK, "headers.txt")
        cmd = ["curl", "-s", "-c", self.jar, "-b", self.jar, "-D", header_file, "-o", "-"]
        if follow:
            cmd.append("-L")
        if cookie:
            cmd += ["--cookie", cookie]
        if files is not None:
            for key, value in (data or {}).items():
                for v in (value if isinstance(value, list) else [value]):
                    cmd += ["--form-string", f"{key}={v}"]
            for key, file_path, file_name in files:
                cmd += ["-F", f"{key}=@{file_path};filename={file_name}"]
        elif data is not None:
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


TAB_OF = {"status": "overview", "complete": "overview", "participation": "participation",
          "participation_note": "participation", "assign": "assignments", "unassign": "assignments",
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


def create_project(session, title, start, end, concerts=(), conductors=(), organizers=()):
    """Creates a project with the form on the projects page."""
    _, page = session.get(f"{DASHBOARD}&orchestra_project=new")
    data = {
        "action": "orchestra_projects", "op": "save", "project": "0",
        "_wpnonce": field(page, "_wpnonce") or "missing", "redirect_to": field(page, "redirect_to") or DASHBOARD,
        "orchestra_projects_title": title, "orchestra_projects_description": f"Description of {title}",
        "orchestra_projects_start_date": start, "orchestra_projects_end_date": end,
        "orchestra_projects_concert_date[]": list(concerts),
        "orchestra_projects_staff[conductor][]": [str(i) for i in conductors],
        "orchestra_projects_staff[organizer][]": [str(i) for i in organizers],
    }
    _, page = session.post("/wp-admin/admin-post.php", data)
    project_id = wp_eval(f'echo (new WP_Query(["post_type" => "orchestra_project", "title" => "{title}",'
                         ' "fields" => "ids", "orderby" => "ID"]))->posts[0] ?? 0;')
    return int(project_id or 0), page


def projects_api(code):
    return wp_json("$p = Orchestra\\Projects\\Plugin::instance()->projects(); " + code)


def assignments_pdf_html(project):
    """The HTML the assignments PDF is made of; the PDF itself stores its text in glyph codes."""
    return wp_json(
        'global $wpdb; $s = new Orchestra\\Projects\\Database\\Schema($wpdb);'
        ' $a = new Orchestra\\Projects\\Assignment\\AssignmentRepository($wpdb, $s);'
        ' $pr = new Orchestra\\Projects\\Program\\ProgramRepository($wpdb, $s);'
        ' $l = Orchestra\\Library\\Plugin::instance()->library();'
        ' $svc = new Orchestra\\Projects\\Assignment\\AssignmentService($a,'
        ' new Orchestra\\Projects\\Participation\\ParticipationRepository($wpdb, $s), $pr,'
        ' new Orchestra\\Projects\\Project\\ProjectRepository(), $l);'
        ' $pdf = new Orchestra\\Projects\\Export\\AssignmentsPdf($pr, $a, $svc, $l,'
        ' new Orchestra\\Projects\\Admin\\Names(Orchestra\\Members\\Plugin::instance()->members()),'
        ' new Orchestra\\Projects\\Participation\\ParticipationRepository($wpdb, $s));'
        f' echo json_encode($pdf->html((new Orchestra\\Projects\\Project\\ProjectRepository())->find({project})));'
    )


NEW_BADGE = 'class="orchestra-projects-new">new</span>'


def participation_of(project, login):
    return wp_json(
        f'$u = get_user_by("login", "{login}")->ID; global $wpdb;'
        f'$r = $wpdb->get_row($wpdb->prepare("SELECT id, status, member_comment, organizer_note, fee_paid FROM {{$wpdb->prefix}}orchestra_projects_participation'
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


def respond(session, participation_id, response, nonce_page, **extra):
    return session.post("/wp-admin/admin-post.php", {
        "action": "orchestra_projects_respond", "participation": str(participation_id), "response": response,
        "_wpnonce": response_nonce(nonce_page, participation_id) or "missing", "redirect_to": DASHBOARD, **extra,
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

_, page = organizer.get(f"{DASHBOARD}&orchestra_project=new")
check("+ Add concert" in page and page.count('name="orchestra_projects_concert_date[]"') == 2,
      "new projects have no concert, only the template and the field without JavaScript")
spring, page = create_project(organizer, "Spring Concert 2027", "2027-03-01", "2027-05-10", ["2027-05-09", "2027-05-08"],
                              conductors=[ids["conductor"], ids["mia"]], organizers=[ids["organizer"]])
project = projects_api(f'$x = $p->project({spring}); echo json_encode($x ? [$x->title, $x->status->value,'
                       f' $x->startDate, $x->endDate, $x->concertDates] : null);')
check(project == ["Spring Concert 2027", "draft", "2027-03-01", "2027-05-10", ["2027-05-08", "2027-05-09"]],
      "organizer creates a project as draft with its dates and several concerts")
staff = wp_json(f'global $wpdb; echo json_encode($wpdb->get_results("SELECT user_id, role FROM'
                f' {{$wpdb->prefix}}orchestra_projects_staff WHERE project_id = {spring} ORDER BY role", ARRAY_A));')
check(staff == [{"user_id": str(ids["conductor"]), "role": "conductor"},
                {"user_id": str(ids["organizer"]), "role": "organizer"}],
      "staff is stored, members cannot be selected as conductors")

bad, page = create_project(organizer, "Broken dates", "2027-05-10", "2027-03-01", ["2027-02-30"])
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
    "orchestra_projects_fee": "120 €, reduced 80 €",
    "orchestra_projects_end_date": "2027-05-10", "orchestra_projects_concert_date[]": ["2027-05-09"],
    "orchestra_projects_staff[conductor][]": [str(ids["conductor"])],
    "orchestra_projects_staff[organizer][]": [str(ids["organizer"])],
})
check(projects_api(f'echo json_encode($p->project({spring})->description);') == "Description of Spring Concert 2027",
      "organizers edit projects on the projects page")
check(projects_api(f'echo json_encode($p->project({spring})->concertDates);') == ["2027-05-09"],
      "removed concerts are deleted")
_, page = organizer.get(DASHBOARD)
check("May 9, 2027" not in text(page), "the project list leaves out the concerts")
check(re.search(r'orchestra-badge[^"]*">[^<]*</span></div><h3 class="orchestra-card__title">', page) is not None,
      "the project list shows the status on its own line above the title")
_, page = organizer.get(f"{DASHBOARD}&orchestra_project={spring}")
check("May 9, 2027" in text(page), "the project page shows the concert")
check("Fee" in text(page) and "120 €, reduced 80 €" in text(page), "organizers set a fee, shown on the project page")

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
_, page = mia.get(f"{DASHBOARD}&orchestra_project={spring}")
check('name="comment"' in page and 'value="accepted"' in page, "the project page asks for an optional comment")
_, page = respond(mia, mia_participation, "accepted", mia_open_page, comment="Only from the <b>27th</b>")
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
_, page = mo.get(f"{DASHBOARD}&orchestra_project={spring}")
check("orchestra_tab=accepted" in page and "Who accepted" in page and "2 people have accepted" not in page,
      "members have a tab with everyone who accepted, not on the overview")
_, page = mo.get(f"{DASHBOARD}&orchestra_project={spring}&orchestra_tab=accepted")
accepted = page.split('<ul class="orchestra-list">', 1)[1].split("</ul>", 1)[0] if "orchestra-list" in page else ""
check("2 people have accepted." in text(page) and "<li>Mia</li>" in accepted and "<li>Olga</li>" in accepted
      and "Max" not in accepted, "members see who accepted, even before responding themselves")
_, page = conductor.get(manage_url(spring))
check("orchestra_tab=accepted" not in page, "conductors see who accepted on the participation tab instead")

_, page = mia.get(f"{DASHBOARD}&orchestra_project={spring}")
plain = text(page)
check("Description of Spring Concert 2027" in plain and "Carl" in plain and "Olga" in plain
      and 'class="orchestra-program"' not in page and "orchestra_tab=program" in page,
      "the project page shows description and staff, the program on its own tab")
_, page = mia.get(f"{DASHBOARD}&orchestra_project={spring}&orchestra_tab=program")
program = page.split('<ol class="orchestra-program">', 1)[1].split("</ol>", 1)[0] if "orchestra-program" in page else ""
check(program.index("Pirates of the Caribbean") < program.index("Lord of the Dance") and "program_up" not in page,
      "members see the ordered program as a list, without changing it")

_, page = mia.get(f"{DASHBOARD}&orchestra_project={spring}")
check("Your comment: Only from the 27th" in text(page) and "<b>27th" not in page and "Change your comment" in page,
      "members see their comment as plain text and can change it")
respond(mia, mia_participation, "comment", page, comment="Only from the 28th")
check(participation_of(spring, "mia")["member_comment"] == "Only from the 28th", "members change their comment")
status, _ = respond(mia, max_participation, "comment", page, comment="Max is ill")
check(status == 403 and participation_of(spring, "max")["member_comment"] is None,
      "members cannot comment for others")
act(organizer, spring, "participation_note", participation=mia_participation, note="Room booked from the 28th")
check(participation_of(spring, "mia")["organizer_note"] == "Room booked from the 28th", "organizers write a note")
_, page = organizer.get(manage_url(spring, "participation"))
check("Only from the 28th" in text(page) and "Room booked from the 28th" in text(page),
      "organizers see comments and notes on the participation tab")
_, page = conductor.get(manage_url(spring, "participation"))
check("Only from the 28th" not in page and "Room booked" not in page, "conductors see neither comments nor notes")
status, _ = act(conductor, spring, "participation_note", participation=mia_participation, note="x")
check(status == 403, "conductors cannot write notes")
_, page = mia.get(f"{DASHBOARD}&orchestra_project={spring}")
check("Room booked" not in page and "120 €, reduced 80 €" in text(page),
      "members do not see the organizers' note, but the fee")
act(organizer, spring, "participation_paid", participation=mia_participation, paid="1")
check(participation_of(spring, "mia")["fee_paid"] == "1", "organizers mark the fee as paid")
_, page = organizer.get(manage_url(spring, "participation"))
check("Mark as not paid" in page and "Mark as paid" in page, "the participation tab shows who paid the fee")
status, _ = act(conductor, spring, "participation_paid", participation=mia_participation, paid="0")
check(status == 403 and participation_of(spring, "mia")["fee_paid"] == "1", "conductors cannot change payments")
_, page = conductor.get(manage_url(spring, "participation"))
check("Mark as" not in page, "conductors do not see payments")

_, page = conductor.get(manage_url(spring, "participation"))
check("Mia" in page and "Max" in page and 'name="status"' not in page,
      "conductors see the participation without changing it")
status, _ = act(conductor, spring, "participation", participation=max_participation, status="accepted")
check(status == 403, "conductors cannot change participation")

# --- Participants export --------------------------------------------------------------------
wp("user", "update", str(ids["mia"]), "--first_name=Mia", "--last_name=Müller")
wp("user", "meta", "update", str(ids["mia"]), "orchestra_members_financial_support", "1")
wp("user", "meta", "update", str(ids["mia"]), "orchestra_members_dietary_restrictions", '=1+1, "vegan"\nno nuts')
EXPORT = f"/wp-admin/admin-post.php?action=orchestra_projects_export_participants&project={spring}"
_, page = organizer.get(manage_url(spring, "participation"))
check("Export accepted participants (CSV)" in page, "organizers see the export on the participation tab")
check("Financial support" in text(page).split("Mia", 1)[1].split("Olga", 1)[0],
      "organizers see who asked for financial support next to the fee")
_, page = conductor.get(manage_url(spring, "participation"))
check("Export accepted participants" not in page, "conductors do not see the export")
status, _, _ = conductor.request(EXPORT)
check(status == 403, "conductors cannot export participants")
status, headers, body = organizer.request(EXPORT)
csv = body.decode("utf-8")
check(status == 200 and headers.get("content-type") == "text/csv; charset=UTF-8"
      and "Spring%20Concert%202027%20-%20participants.csv" in headers.get("content-disposition", ""),
      "organizers download the participants as a CSV file")
check(body.startswith(b"\xef\xbb\xbf") and csv.endswith("\r\n"), "the export is UTF-8 with BOM and Windows line endings")
check(csv.lstrip("﻿").split("\r\n")[:3] == [
    '"Last name","First name","Email address","Financial support","Dietary restrictions","Fee paid","Comment","Organizer note"',
    '"Müller","Mia","mia@example.com","Yes","\'=1+1, ""vegan""\nno nuts","Yes","Only from the 28th","Room booked from the 28th"',
    '"Olga","","organizer@example.com","No","","No","",""',
] and "max@example.com" not in csv, "one column per property and profile field, accepted participants only, no formulas")
wp("option", "update", "orchestra_core_language", "de_DE")
_, _, body = organizer.request(EXPORT)
check(body.decode("utf-8").lstrip("﻿").startswith('"Nachname";"Vorname";"E-Mail-Adresse";'),
      "the German export uses semicolons, as German Excel expects")
wp("option", "delete", "orchestra_core_language")

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
pirates = page.split("<h3>Pirates of the Caribbean", 1)[1].split("</section>", 1)[0]
check(f'name="users[]" value="{ids["mia"]}"' not in pirates
      and f'name="users[]" value="{ids["organizer"]}"' not in pirates and 'name="users[]"' in page,
      "members who play a part of a piece are not offered for its other parts")
dance = page.split("<h3>Lord of the Dance", 1)[1].split("</h3>", 1)[0]
check("At least one member has not been assigned a part yet" in dance,
      "pieces in which an accepted member has no part yet show a warning")
check(NEW_BADGE in pirates, "the assignments mark members who play a part for the first time")
pdf_html = assignments_pdf_html(spring)
check("Mia*" in pdf_html and "* plays this part for the first time" in pdf_html,
      "the PDF marks new parts with an asterisk and explains it")
check("Parts by member" in pdf_html and "Horn 1*" in pdf_html, "the PDF also lists the parts by member")
_, page = conductor.get(manage_url(spring, "assignments") + "&orchestra_assignments_view=members")
mia_row = next((row for row in re.findall(r"<tr>.*?</tr>", page, flags=re.S) if ">Mia<" in row), "")
check("By member" in page and "Horn 1" in mia_row and "Violin 1" in mia_row and 'name="users[]"' not in page,
      "the assignments tab shows the parts by member, read-only")

# --- Sheet music download -------------------------------------------------------------------
_, page = mia.get(DASHBOARD)
plain = text(page)
check("Your parts" in plain and "Pirates of the Caribbean (Klaus Badelt) Horn 1" in plain
      and "Lord of the Dance (Ronan Hardiman) Violin 1" in plain, "members see their parts on the dashboard")
check('<strong>Pirates of the Caribbean</strong>' in page and '</div><div class="orchestra-actions"><span>Horn 1</span>' in page,
      "the part, its label and its download are on a line below the piece and its composer")
check(page.count(NEW_BADGE) == 2, "members see which of their parts they play for the first time")
check("assets/orchestra-projects.css" in page, "the page loads the stylesheet of the label")
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
_, page = organizer.get(manage_url(spring, "participation"))
check("entered by Olga" in text(page) and "Last change" in text(page),
      "the participation tab shows who entered a decision for a member")
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
    # Since orchestra-email 0.3, the reply address is a field, prefilled with the sender's address.
    data = {"_wpnonce": field(page, "_wpnonce") or "missing", "op": "review", "subject": subject, "body": "Hello",
            "groups[]": groups, "reply_to": field(page, "reply_to") or ""}
    status, page = session.post(EMAIL, data)
    if op == "review":
        return status, page
    send = next((f for f in re.findall(r"<form.*?</form>", page, flags=re.S) if "orchestra_email_send" in f), "")
    return session.post("/wp-admin/admin-post.php", {
        "action": "orchestra_email_send", "_wpnonce": field(send, "_wpnonce") or "missing",
        "subject": subject, "body": "Hello", "groups[]": groups, "reply_to": field(send, "reply_to") or "",
        "orchestra_log_url": field(send, "orchestra_log_url") or EMAIL, "redirect_to": EMAIL,
    })


_, page = conductor.get(COMPOSE)
plain = text(page)
check("Spring Concert 2027" in plain and "Accepted participants" in plain, "conductors can email the project groups")
check("Everyone playing" not in plain and "Pirates of the Caribbean: Horn 1" not in plain,
      "there are no groups for pieces or parts")
check("Declined participants" not in plain and "Accepted and pending participants" in plain,
      "there is no group for members who declined")
_, page = send_email(conductor, [f"orchestra_projects_{spring}_all"], "Reminder", "review")
listed = text(page).split("Recipients", 1)[1].split("Subject", 1)[0]
check("Mia" in listed and "Olga" in listed and "Mo" in listed and "Max" not in listed,
      "the accepted and pending participants leave out those who declined")
check("Broken dates" not in plain, "draft projects have no groups")
_, page = mo.get(COMPOSE)
check("not allowed to send emails" in page, "members cannot email project groups")

accepted_group = f"orchestra_projects_{spring}_accepted"
_, page = organizer.get(manage_url(spring))
check(f"groups%5B%5D={accepted_group}" in page, "the project overview links to an email to the accepted participants")
_, page = conductor.get(manage_url(spring, "assignments"))
check("_piece_" not in page and "Email the players" not in page, "the assignments do not link to emails per piece")

before = len(mails())
send_email(organizer, [accepted_group], "Rehearsal plan")
recipients = sorted(m["to"] for m in mails()[before:])
check(recipients == ["mia@example.com", "organizer@example.com"], "email to the accepted participants")
before = len(mails())
send_email(conductor, [accepted_group, f"orchestra_projects_{spring}_all"], "Reminder")
recipients = sorted(m["to"] for m in mails()[before:])
check(len(recipients) == len(set(recipients)) and {"mia@example.com", "organizer@example.com"} <= set(recipients)
      and "max@example.com" not in recipients, "overlapping groups send one email per person")

# --- Formatted emails with attachments --------------------------------------------------------
def tiny_png():
    import struct
    import zlib

    def chunk(kind, payload):
        return struct.pack(">I", len(payload)) + kind + payload + struct.pack(">I", zlib.crc32(kind + payload))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", 1, 1, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(b"\x00\xff\x00\x00")) + chunk(b"IEND", b""))


def work_file(name, content):
    path = os.path.join(WORK, name)
    with open(path, "wb") as handle:
        handle.write(content)
    return path


def hidden_fields(form):
    """The hidden fields of a form as a dict of lists."""
    fields = {}
    for tag in re.findall(r'<(?:input|textarea)[^>]*type="hidden"[^>]*>|<textarea[^>]*hidden[^>]*>.*?</textarea>', form,
                          flags=re.S):
        name = re.search(r'name="([^"]+)"', tag).group(1)
        value = re.search(r'value="([^"]*)"', tag) if tag.startswith("<input") else re.search(r">(.*)</textarea>", tag,
                                                                                               flags=re.S)
        fields.setdefault(name, []).append(html.unescape(value.group(1) if value else ""))
    return fields


pdf = work_file("plan.pdf", b"%PDF-1.4\n% Rehearsal plan\n%%EOF\n")
png = work_file("seating.png", tiny_png())
script = work_file("evil.php", b"<?php echo 'x';")
_, page = organizer.get(EMAIL)
check('id="orchestra_email_body"' in page and "tinymce" in page.lower() and 'name="attachments[]"' in page
      and 'enctype="multipart/form-data"' in page, "the email page has an editor and a file field")
body = ('<p><strong>Bold</strong> and <em>italic</em> <span style="color: #c0392b; font-size: 20px; position: fixed">'
        'red</span></p><script>alert(1)</script><ul><li>One</li></ul>')
review = {"_wpnonce": field(page, "_wpnonce") or "missing", "op": "review", "subject": "Plan & seating",
          "body": body, "groups[]": [accepted_group], "reply_to": field(page, "reply_to") or ""}
_, _, raw = organizer.request(EMAIL, review, files=[("attachments[]", script, "evil.php")])
check("cannot be attached" in text(raw.decode()) and "orchestra_email_send" not in raw.decode(),
      "files of other types are rejected")
_, page = organizer.get(EMAIL)
review["_wpnonce"] = field(page, "_wpnonce") or "missing"
_, _, raw = organizer.request(EMAIL, review, files=[("attachments[]", pdf, "Plan.pdf"), ("attachments[]", png, "Plan.png"),
                                                    ("attachments[]", pdf, "Plan.pdf")])
page = raw.decode()
send_form = next((f for f in re.findall(r"<form.*?</form>", page, flags=re.S) if "orchestra_email_send" in f), "")
fields = hidden_fields(send_form)
preview = html.unescape(re.search(r'srcdoc="([^"]*)"', page).group(1)) if 'srcdoc="' in page else ""
check("<strong>Bold</strong>" in preview and "<script" not in preview and "<script>alert" not in page
      and "Plan.pdf" in text(page) and len(fields.get("attachment_ids[]", [])) == 3,
      "the review shows a preview of the formatted text, and the attachments")
STORED = 'glob(WP_CONTENT_DIR . "/uploads/orchestra-email-*/*-*-*-*-*")'
uploads = wp_json(f'echo json_encode(array_map(fn ($f) => basename($f), {STORED}));')
check(len(uploads) == 3 and all(re.fullmatch(r"[0-9a-f-]{36}", f) for f in uploads),
      "attachments wait in the private directory under random names")
_, page = conductor.get(EMAIL)
_, page = conductor.post(EMAIL, {"_wpnonce": field(page, "_wpnonce") or "missing", "op": "review", "subject": "Mine",
                                 "body": "Hi", "groups[]": [accepted_group], "reply_to": field(page, "reply_to") or "",
                                 "attachment_ids[]": fields.get("attachment_ids[]", [])})
check("no longer available" in text(page) and "orchestra_email_send" not in page,
      "nobody can send the attachments of someone else")
before = len(mails())
organizer.post("/wp-admin/admin-post.php", {**fields, "action": "orchestra_email_send", "redirect_to": EMAIL})
sent = mails()[before:]
first = sent[0] if sent else {}
message = first.get("message", "")
names = sorted(first.get("attachments", {}).keys()) if isinstance(first.get("attachments"), dict) else []
check(len(sent) == 2 and "<strong>Bold</strong>" in message and "<em>italic</em>" in message
      and 'style="color: #c0392b; font-size: 20px;"' in message and "<script" not in message
      and "position" not in message and "<li>One</li>" in message, "the email keeps bold, italic, size, color and lists")
own = next((m.get("message", "") for m in sent if m.get("to") == "organizer@example.com"), "")
others = [m.get("message", "") for m in sent if m.get("to") != "organizer@example.com"]
check(any("text/html" in h for h in first.get("headers", [])) and "Sent by Olga" in own
      and others != [] and all("Sent by" not in m for m in others),
      "emails are sent as HTML; only the sender's copy has the footer naming sender and groups")
check(names == ["Plan (2).pdf", "Plan.pdf", "Plan.png"], "attachments keep their names, made unique")
leftover = wp_json(f'global $wpdb; echo json_encode([count({STORED}),'
                   ' (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orchestra_email_attachments")]);')
check(leftover == [0, 0], "attachments are deleted once the email has been sent")

wp_eval(f'update_user_meta({ids["mo"]}, "orchestra_members_inactive", "1");')
_, page = send_email(organizer, [f"orchestra_projects_{spring}_open"], "Please respond", "review")
check("Mo" not in text(page).split("Recipients", 1)[1].split("Subject", 1)[0] and "Nia" in page,
      "inactive members are left out of project groups")
wp_eval(f'delete_user_meta({ids["mo"]}, "orchestra_members_inactive");')

# --- Tabs of other plugins ------------------------------------------------------------------
extra_tab = os.path.join(os.environ["E2E_WP_PATH"], "wp-content", "mu-plugins", "e2e-extra-tab.php")
with open(extra_tab, "w") as handle:
    handle.write("""<?php
add_filter('orchestra_projects_tabs', static fn (array $tabs, $project) => $tabs + ['extra' => 'Extra tab'], 10, 2);
add_filter('orchestra_projects_tab_content', static fn (string $html, string $tab, $project, string $url)
    => $tab === 'extra' ? '<p>Extra content of ' . esc_html($project->title) . ' at ' . esc_url($url) . '</p>' : $html, 10, 4);
""")
_, page = mia.get(DASHBOARD + f"&orchestra_project={spring}")
check("Extra tab" in page, "other plugins add tabs, also for members")
_, page = mia.get(DASHBOARD + f"&orchestra_project={spring}&orchestra_tab=extra")
check("Extra content of Spring Concert 2027" in page and "orchestra_tab=extra" in page,
      "other plugins render their tabs with the tab's URL")
status, page = admin.get(admin_url(spring, "extra"))
check(status == 200 and "Extra content of Spring Concert 2027" in page, "plugin tabs work in wp-admin")
os.remove(extra_tab)

# --- Assignments as PDF ---------------------------------------------------------------------
_, page = conductor.get(manage_url(spring, "assignments"))
check("Download PDF" in page and "orchestra_projects_assignments_pdf" in page, "the assignments tab offers a PDF")
status, headers, body = conductor.request(f"/wp-admin/admin-post.php?action=orchestra_projects_assignments_pdf&project={spring}")
check(status == 200 and headers.get("content-type") == "application/pdf" and body.startswith(b"%PDF-")
      and "Spring%20Concert%202027%20-%20Assignments.pdf" in headers.get("content-disposition", ""),
      "conductors download the assignments as a PDF")
with open(os.path.join(WORK, "assignments.pdf"), "wb") as handle:
    handle.write(body)
status, _, body = mia.request(f"/wp-admin/admin-post.php?action=orchestra_projects_assignments_pdf&project={spring}")
check(status == 403 and not body.startswith(b"%PDF"), "members cannot download the assignments")

# --- Assignment history ---------------------------------------------------------------------
summer, _ = create_project(organizer, "Summer 2028", "2028-06-01", "2028-07-01")
check(projects_api(f'echo json_encode($p->project({summer})->concertDates);') == [],
      "projects can have no concert")
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
check(NEW_BADGE not in page and "plays this part for the first time" not in assignments_pdf_html(summer),
      "parts played in another project before are not new")
check("At least one member has not been assigned a part yet" in page.split("<h3>Pirates of the Caribbean", 1)[1]
      .split("</h3>", 1)[0], "members who never played the piece leave the warning")

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
respond(mia, mia_participation, "comment", mia_open_page, comment="Later")
check(participation_of(spring, "mia")["member_comment"] == "Only from the 28th",
      "comments cannot be changed once the registration is closed")
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

past, _ = create_project(organizer, "Autumn 2020", "2020-10-01", "2020-10-03", ["2020-10-02"])
act(organizer, past, "status", target="open")
_, page = organizer.get(f"{DASHBOARD}&orchestra_project={past}")
check("The project ended on" in text(page) and "Complete the project" in page,
      "organizers are told to complete projects that are over")
_, page = organizer.get(DASHBOARD)
check("Ended, please complete" in text(page), "the project list marks projects that are over for organizers")
_, page = conductor.get(f"{DASHBOARD}&orchestra_project={past}")
check("The project ended on" not in text(page), "only organizers are told to complete projects")
_, page = act(organizer, past, "complete")
check(projects_api(f"echo json_encode($p->project({past})->status->value);") == "completed"
      and "The project ended on" not in text(page), "organizers complete a project that is over in one step")
summer_status = projects_api(f"echo json_encode($p->project({summer})->status->value);")
_, page = act(organizer, summer, "complete")
check("not over yet" in text(page)
      and projects_api(f"echo json_encode($p->project({summer})->status->value);") == summer_status,
      "projects that are not over cannot be completed in one step")
wp("post", "delete", str(past), "--force")

bulk, _ = create_project(organizer, "Bulk 2029", "2029-01-01", "2029-01-02")
act(organizer, bulk, "status", target="open")
selected = [str(participation_of(bulk, login)["id"]) for login in ("mia", "max")]
_, page = organizer.get(manage_url(bulk, "participation"))
check('name="participations[]"' in page and "Change selected" in page, "organizers select several participants")
_, page = conductor.get(manage_url(bulk, "participation"))
check('name="participations[]"' not in page, "conductors cannot select participants")
status, _ = act(conductor, bulk, "participation_bulk", status="accepted", **{"participations[]": selected})
check(status == 403 and participation_of(bulk, "mia")["status"] == "open", "conductors cannot change several at once")
act(organizer, bulk, "participation_bulk", status="accepted", **{"participations[]": selected})
check([participation_of(bulk, login)["status"] for login in ("mia", "max", "mo")] == ["accepted", "accepted", "open"],
      "organizers change the status of the selected participants at once")
_, page = act(organizer, bulk, "participation_bulk", status="declined")
check("Select at least one member" in text(page), "changing nobody shows an error")
wp("post", "delete", str(bulk), "--force")

# --- Privacy and deleted users --------------------------------------------------------------
export = wp_json(
    '$e = apply_filters("wp_privacy_personal_data_exporters", [])["orchestra-projects"];'
    'echo json_encode(call_user_func($e["callback"], "mia@example.com", 1));'
)
values = [item["value"] for group in export["data"] for item in group["data"]]
check("Spring Concert 2027" in values and "Horn 1" in values and "Accepted" in values,
      "personal data export contains participation and parts")
check("Only from the 28th" in values and "Room booked from the 28th" in values,
      "the personal data export contains the member's comment and the organizers' note")
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
