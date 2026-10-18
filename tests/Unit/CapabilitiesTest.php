<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Core\Authorization\CapabilityRegistry;
use Orchestra\Core\Authorization\Roles;
use Orchestra\Projects\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    private CapabilityRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CapabilityRegistry();
        // Participation 1 belongs to user 7, participation 2 to user 8, participation 3 is anonymous.
        Capabilities::register($this->registry, static fn (int $id): ?int => [1 => 7, 2 => 8][$id] ?? null);
    }

    public function testRegistersTheDocumentedGrants(): void
    {
        $byRole = $this->registry->capabilitiesByRole();

        $this->assertSame([
            Capabilities::ASSIGNMENT_READ,
            Capabilities::PARTICIPATION_READ_OWN,
            Capabilities::PARTICIPATION_RESPOND_OWN,
            Capabilities::PROJECT_READ,
        ], $byRole[Roles::MEMBER]);
        $this->assertSame([
            Capabilities::ASSIGNMENT_HISTORY_READ,
            Capabilities::ASSIGNMENT_MANAGE,
            Capabilities::ASSIGNMENT_READ,
            Capabilities::EMAIL_SEND,
            Capabilities::PARTICIPATION_READ_OTHERS,
            Capabilities::PROGRAM_MANAGE,
            Capabilities::PROJECT_READ,
        ], $byRole[Roles::CONDUCTOR]);
        $this->assertSame([
            Capabilities::ASSIGNMENT_HISTORY_READ,
            Capabilities::ASSIGNMENT_MANAGE,
            Capabilities::ASSIGNMENT_READ,
            Capabilities::EMAIL_SEND,
            Capabilities::PARTICIPATION_EXPORT,
            Capabilities::PARTICIPATION_MANAGE,
            Capabilities::PARTICIPATION_READ_OTHERS,
            Capabilities::PARTICIPATION_READ_OWN,
            Capabilities::PARTICIPATION_RESPOND_OWN,
            Capabilities::PROGRAM_MANAGE,
            Capabilities::PROJECT_MANAGE,
            Capabilities::PROJECT_READ,
        ], $byRole[Roles::ORGANIZER]);
        $this->assertContains(Capabilities::PROJECT_DELETE, $byRole[Roles::ADMINISTRATOR]);
    }

    public function testRespondingForOthersRequiresManagingParticipation(): void
    {
        $respond = $this->registry->metaCapabilityMapper(Capabilities::PARTICIPATION_RESPOND);
        $read = $this->registry->metaCapabilityMapper(Capabilities::PARTICIPATION_READ);
        $this->assertNotNull($respond);
        $this->assertNotNull($read);

        $this->assertSame([Capabilities::PARTICIPATION_RESPOND_OWN], $respond(7, [1]));
        $this->assertSame([Capabilities::PARTICIPATION_MANAGE], $respond(7, [2]));
        $this->assertSame([Capabilities::PARTICIPATION_READ_OTHERS], $read(7, [2]));
        $this->assertSame(['do_not_allow'], $respond(7, [3]));
    }
}
