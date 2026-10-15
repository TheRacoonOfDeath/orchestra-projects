<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Participation\ParticipationRules;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\ProjectStatus;
use PHPUnit\Framework\TestCase;

final class ParticipationRulesTest extends TestCase
{
    public function testMembersRespondOnceWhileTheProjectIsOpen(): void
    {
        foreach ([ParticipationStatus::Accepted, ParticipationStatus::Declined] as $response) {
            $this->assertNull(
                ParticipationRules::responseRefusal(ProjectStatus::Open, ParticipationStatus::Open, $response)
            );
        }
    }

    public function testTheDecisionIsFinal(): void
    {
        $this->assertNotNull(ParticipationRules::responseRefusal(
            ProjectStatus::Open,
            ParticipationStatus::Accepted,
            ParticipationStatus::Declined
        ));
    }

    public function testNoResponsesOutsideOpenProjects(): void
    {
        foreach ([ProjectStatus::Draft, ProjectStatus::Preparation, ProjectStatus::Completed] as $status) {
            $this->assertNotNull(ParticipationRules::responseRefusal(
                $status,
                ParticipationStatus::Open,
                ParticipationStatus::Accepted
            ));
        }
    }

    public function testMembersCannotResetTheirResponseToPending(): void
    {
        $this->assertNotNull(ParticipationRules::responseRefusal(
            ProjectStatus::Open,
            ParticipationStatus::Open,
            ParticipationStatus::Open
        ));
    }
}
