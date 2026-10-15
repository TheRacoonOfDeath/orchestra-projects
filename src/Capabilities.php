<?php

declare(strict_types=1);

namespace Orchestra\Projects;

use Closure;
use Orchestra\Core\Authorization\CapabilityRegistry;
use Orchestra\Core\Authorization\OwnershipMapper;
use Orchestra\Core\Authorization\Roles;

/**
 * The capabilities owned by orchestra-projects. See orchestra-core's docs/capabilities.md.
 *
 * Which projects members can see and when they can respond also depends on the project status;
 * those rules are checked in addition, see {@see Project\ProjectStatus}.
 */
final class Capabilities
{
    /** See projects that are visible to members. */
    public const PROJECT_READ = 'orchestra_projects_project_read';

    /** Create and edit projects, change their status and staff. */
    public const PROJECT_MANAGE = 'orchestra_projects_project_manage';

    /** Delete projects. Projects are normally archived instead. */
    public const PROJECT_DELETE = 'orchestra_projects_project_delete';

    /** Add, remove and reorder the pieces of a program. Also opens the project screens in wp-admin. */
    public const PROGRAM_MANAGE = 'orchestra_projects_program_manage';

    /** Meta capability, checked with a participation ID. */
    public const PARTICIPATION_READ = 'orchestra_projects_participation_read';
    public const PARTICIPATION_READ_OWN = 'orchestra_projects_participation_read_own';
    public const PARTICIPATION_READ_OTHERS = 'orchestra_projects_participation_read_others';

    /** Meta capability, checked with a participation ID: respond for oneself, or change any decision. */
    public const PARTICIPATION_RESPOND = 'orchestra_projects_participation_respond';
    public const PARTICIPATION_RESPOND_OWN = 'orchestra_projects_participation_respond_own';

    /** Change any participation decision. */
    public const PARTICIPATION_MANAGE = 'orchestra_projects_participation_manage';

    /** See the assignments of a project. */
    public const ASSIGNMENT_READ = 'orchestra_projects_assignment_read';

    /** Create and delete assignments. */
    public const ASSIGNMENT_MANAGE = 'orchestra_projects_assignment_manage';

    /** See who played a part before, when assigning. */
    public const ASSIGNMENT_HISTORY_READ = 'orchestra_projects_assignment_history_read';

    /** Email the project and assignment groups. Checked with the project ID. */
    public const EMAIL_SEND = 'orchestra_projects_email_send';

    /**
     * @internal Hooked to `orchestra_core_register_capabilities`.
     * @param Closure(int $participationId): ?int $participationOwner Returns the user ID of a participation
     */
    public static function register(CapabilityRegistry $registry, Closure $participationOwner): void
    {
        $everyone = [Roles::MEMBER, Roles::CONDUCTOR, Roles::ORGANIZER];
        $players = [Roles::MEMBER, Roles::ORGANIZER];

        $registry
            ->register(self::PROJECT_READ, $everyone)
            ->register(self::PROJECT_MANAGE, [Roles::ORGANIZER])
            ->register(self::PROJECT_DELETE)
            ->register(self::PROGRAM_MANAGE, [Roles::CONDUCTOR, Roles::ORGANIZER])
            ->register(self::PARTICIPATION_READ_OWN, $players)
            ->register(self::PARTICIPATION_READ_OTHERS, [Roles::CONDUCTOR, Roles::ORGANIZER])
            ->register(self::PARTICIPATION_RESPOND_OWN, $players)
            ->register(self::PARTICIPATION_MANAGE, [Roles::ORGANIZER])
            ->register(self::ASSIGNMENT_READ, $everyone)
            ->register(self::ASSIGNMENT_MANAGE, [Roles::CONDUCTOR])
            ->register(self::ASSIGNMENT_HISTORY_READ, [Roles::CONDUCTOR])
            ->register(self::EMAIL_SEND, [Roles::CONDUCTOR, Roles::ORGANIZER])
            ->registerMetaCapability(
                self::PARTICIPATION_READ,
                new OwnershipMapper(self::PARTICIPATION_READ_OWN, self::PARTICIPATION_READ_OTHERS, $participationOwner)
            )
            ->registerMetaCapability(
                self::PARTICIPATION_RESPOND,
                new OwnershipMapper(self::PARTICIPATION_RESPOND_OWN, self::PARTICIPATION_MANAGE, $participationOwner)
            );
    }
}
