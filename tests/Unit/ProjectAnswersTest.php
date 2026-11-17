<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Field\ProjectAnswers;
use Orchestra\Projects\Field\ProjectField;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;
use PHPUnit\Framework\TestCase;

final class ProjectAnswersTest extends TestCase
{
    public function testReadsOneAnswerPerField(): void
    {
        [$answers, $errors] = ProjectAnswers::fromForm(self::fields(), [
            ProjectField::ANSWER_FIELD => ['bus' => '1', 'arrival' => "  Friday\nevening <b>late</b> ", 'room' => 'x'],
            ProjectField::OTHER_FIELD => ['room' => 'With Anna'],
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(['bus' => '1', 'arrival' => 'Friday evening late', 'room' => ''], $answers);
    }

    public function testTakesTheFreeTextAnswerOfAChoiceListWithOther(): void
    {
        [$answers] = ProjectAnswers::fromForm(self::fields(), [
            ProjectField::ANSWER_FIELD => ['arrival' => 'Friday', 'room' => ProjectField::OTHER],
            ProjectField::OTHER_FIELD => ['room' => 'With Anna'],
        ]);

        $this->assertSame('With Anna', $answers['room']);
        $this->assertSame('', $answers['bus'], 'an unticked checkbox is not submitted');
    }

    public function testRequiredFieldsMustBeAnswered(): void
    {
        [, $errors] = ProjectAnswers::fromForm(self::fields(), [ProjectField::ANSWER_FIELD => ['arrival' => ' ']]);

        $this->assertSame(['Please answer "Arrival".'], $errors);

        $checkbox = new ProjectField('rules', 'Rules', ProjectField::TYPE_CHECKBOX, '', [], false, true);
        [, $errors] = ProjectAnswers::fromForm([$checkbox], []);

        $this->assertSame(['Please tick "Rules".'], $errors);
    }

    public function testOnlyAcceptedParticipantsAnswerWhileTheProjectIsOpen(): void
    {
        $open = new Project(1, 'Spring Concert', '', ProjectStatus::Open, null, null);
        $preparation = new Project(1, 'Spring Concert', '', ProjectStatus::Preparation, null, null);
        $accepted = new Participation(1, 1, 5, ParticipationStatus::Accepted, null, null, null, null, null);
        $pending = new Participation(1, 1, 5, ParticipationStatus::Open, null, null, null, null, null);

        $this->assertTrue(ProjectAnswers::open($open, $accepted));
        $this->assertFalse(ProjectAnswers::open($open, $pending));
        $this->assertFalse(ProjectAnswers::open($preparation, $accepted));
    }

    /**
     * @return list<ProjectField>
     */
    private static function fields(): array
    {
        return [
            new ProjectField('bus', 'Bus', ProjectField::TYPE_CHECKBOX, 'I take the bus'),
            new ProjectField('arrival', 'Arrival', ProjectField::TYPE_TEXT, '', [], false, true),
            new ProjectField('room', 'Room', ProjectField::TYPE_SELECT, '', ['Single', 'Double'], true),
        ];
    }
}
