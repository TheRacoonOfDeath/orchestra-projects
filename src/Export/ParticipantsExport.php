<?php

declare(strict_types=1);

namespace Orchestra\Projects\Export;

use Orchestra\Members\Member;
use Orchestra\Members\MembersService;
use Orchestra\Members\Profile\Profile;
use Orchestra\Members\Profile\ProfileField;
use Orchestra\Projects\Field\ProjectAnswers;
use Orchestra\Projects\Field\ProjectField;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;

/**
 * The accepted participants of a project as a table: last name, first name, email address, one
 * column per profile field, one per project field the project shows, the member's comment and the
 * organizers' note. Below them, the number of participants per answer of every choice list (e.g. 5
 * vegetarian, 3 vegan). Profile values, answers, comments and notes are for organizers; the caller
 * checks the capabilities.
 */
final class ParticipantsExport
{
    public function __construct(
        private readonly ParticipationRepository $participations,
        private readonly MembersService $members,
        private readonly ?ProjectAnswers $answers = null,
    ) {
    }

    /**
     * @return list<int> the accepted participants; deleted users are left out
     */
    public function userIds(int $projectId): array
    {
        return \array_values(\array_filter(\array_map(
            static fn (Participation $participation): ?int => $participation->userId,
            $this->participations->forProject($projectId, ParticipationStatus::Accepted)
        )));
    }

    /**
     * @param list<int> $userIds
     * @return list<list<string>> the headings first
     */
    public function rows(Project $project, array $userIds): array
    {
        $projectId = $project->id;
        $participations = [];
        foreach ($this->participations->forProject($projectId, ParticipationStatus::Accepted) as $participation) {
            $participations[$participation->userId ?? 0] = $participation;
        }
        $people = [];
        foreach ($userIds as $userId) {
            $member = $this->members->member($userId);
            if ($member !== null) {
                $participation = $participations[$userId] ?? null;
                $people[] = [
                    $member,
                    $this->members->profile($userId),
                    $participation->memberComment ?? '',
                    $participation->organizerNote ?? '',
                ];
            }
        }

        return self::table(
            $this->members->profileFields(),
            $people,
            $this->answers?->fields($project) ?? [],
            $this->answers?->forProject($project) ?? []
        );
    }

    /**
     * Rows ordered by last and first name. Users without first and last name, e.g. accounts
     * created in wp-admin, get their display name as last name.
     *
     * @param list<ProfileField> $fields
     * @param list<array{Member, ?Profile, string, string}> $people member, profile, comment and note
     * @param list<ProjectField> $projectFields
     * @param array<int, array<string, string>> $projectAnswers user ID => field ID => answer
     * @return list<list<string>> the headings first, the counts of the choice lists last
     */
    public static function table(
        array $fields,
        array $people,
        array $projectFields = [],
        array $projectAnswers = [],
    ): array {
        $rows = [];
        $answers = [];
        foreach ($people as [$member, $profile, $comment, $note]) {
            $lastName = $member->lastName;
            if ($lastName === '' && $member->firstName === '') {
                $lastName = $member->displayName;
            }
            $row = [$lastName, $member->firstName, $member->email];
            foreach ($fields as $field) {
                $value = $profile?->value($field->id) ?? '';
                $answers[$field->id][] = $value;
                // An unchecked checkbox is stored as "" and exported as "No".
                $row[] = $value === '' && $field->type !== ProfileField::TYPE_CHECKBOX ? '' : $field->display($value);
            }
            foreach ($projectFields as $field) {
                $value = $projectAnswers[$member->id][$field->id] ?? '';
                $answers['project:' . $field->id][] = $value;
                $row[] = $value === '' && $field->type !== ProjectField::TYPE_CHECKBOX ? '' : $field->display($value);
            }
            $row[] = $comment;
            $row[] = $note;
            $rows[] = $row;
        }
        \usort($rows, static fn (array $a, array $b): int => \strcasecmp($a[0], $b[0]) ?: \strcasecmp($a[1], $b[1]));

        $headings = [
            \__('Last name', 'orchestra-projects'),
            \__('First name', 'orchestra-projects'),
            \__('Email address', 'orchestra-projects'),
            ...\array_map(static fn (ProfileField $field): string => $field->label, $fields),
            ...\array_map(static fn (ProjectField $field): string => $field->label, $projectFields),
            \__('Comment', 'orchestra-projects'),
            \__('Organizer note', 'orchestra-projects'),
        ];

        $choiceLists = [];
        foreach ($fields as $field) {
            if ($field->type === ProfileField::TYPE_SELECT) {
                $choiceLists[] = [$field->label, $field->choices, $answers[$field->id] ?? []];
            }
        }
        foreach ($projectFields as $field) {
            if ($field->type === ProjectField::TYPE_SELECT) {
                $choiceLists[] = [$field->label, $field->choices, $answers['project:' . $field->id] ?? []];
            }
        }

        return [$headings, ...$rows, ...self::counts($choiceLists)];
    }

    /**
     * After an empty line, every choice list with the number of participants per answer: the
     * choices in their order (also those nobody chose), then free-text answers alphabetically, then
     * those without an answer.
     *
     * @param list<array{string, list<string>, list<string>}> $choiceLists label, choices and the
     *     answer of every participant
     * @return list<list<string>>
     */
    private static function counts(array $choiceLists): array
    {
        $rows = [];
        foreach ($choiceLists as [$label, $choices, $values]) {
            if ($values === []) {
                continue;
            }
            $counts = \array_fill_keys($choices, 0);
            $others = [];
            $unanswered = 0;
            foreach ($values as $value) {
                if ($value === '') {
                    $unanswered++;
                } elseif (\array_key_exists($value, $counts)) {
                    $counts[$value]++;
                } else {
                    $others[$value] = ($others[$value] ?? 0) + 1;
                }
            }
            \uksort($others, static fn (string|int $a, string|int $b): int => \strcasecmp((string) $a, (string) $b));

            $rows[] = [];
            $rows[] = [$label, \__('Number', 'orchestra-projects')];
            foreach ($counts + $others as $answer => $count) {
                $rows[] = [(string) $answer, (string) $count];
            }
            if ($unanswered > 0) {
                $rows[] = [\__('No answer', 'orchestra-projects'), (string) $unanswered];
            }
        }

        return $rows;
    }
}
