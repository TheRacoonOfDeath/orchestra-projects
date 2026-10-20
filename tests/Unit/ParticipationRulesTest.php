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

    public function testCommentsCanBeChangedWhileTheProjectIsOpenOrInPreparation(): void
    {
        foreach (ProjectStatus::cases() as $status) {
            $this->assertSame(
                \in_array($status, [ProjectStatus::Open, ProjectStatus::Preparation], true),
                ParticipationRules::commentsOpen($status)
            );
        }
    }

    public function testCommentsArePlainTextWithLineBreaksAndALimit(): void
    {
        $this->assertSame("Only from the 27th\n\nby train", ParticipationRules::text(
            "  <b>Only</b> from the 27th\r\n\r\n\r\n\r\nby train ",
            100
        ));
        $this->assertNull(ParticipationRules::text("  \n ", 100));
        $this->assertSame('Grüß', ParticipationRules::text('Grüße', 4));
    }
}
