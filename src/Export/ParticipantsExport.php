<?php

declare(strict_types=1);

namespace Orchestra\Projects\Export;

use Orchestra\Members\Member;
use Orchestra\Members\MembersService;
use Orchestra\Members\Profile\Profile;
use Orchestra\Members\Profile\ProfileField;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;

/**
 * The accepted participants of a project as a table: last name, first name, email address and one
 * column per profile field. Profile values are sensitive; the caller checks the capabilities.
 */
final class ParticipantsExport
{
    public function __construct(
        private readonly ParticipationRepository $participations,
        private readonly MembersService $members,
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
    public function rows(array $userIds): array
    {
        $people = [];
        foreach ($userIds as $userId) {
            $member = $this->members->member($userId);
            if ($member !== null) {
                $people[] = [$member, $this->members->profile($userId)];
            }
        }

        return self::table($this->members->profileFields(), $people);
    }

    /**
     * Rows ordered by last and first name. Users without first and last name, e.g. accounts
     * created in wp-admin, get their display name as last name.
     *
     * @param list<ProfileField> $fields
     * @param list<array{Member, ?Profile}> $people
     * @return list<list<string>> the headings first
     */
    public static function table(array $fields, array $people): array
    {
        $rows = [];
        foreach ($people as [$member, $profile]) {
            $lastName = $member->lastName;
            if ($lastName === '' && $member->firstName === '') {
                $lastName = $member->displayName;
            }
            $row = [$lastName, $member->firstName, $member->email];
            foreach ($fields as $field) {
                $value = $profile?->value($field->id) ?? '';
                // An unchecked checkbox is stored as "" and exported as "No".
                $row[] = $value === '' && $field->type !== ProfileField::TYPE_CHECKBOX ? '' : $field->display($value);
            }
            $rows[] = $row;
        }
        \usort($rows, static fn (array $a, array $b): int => \strcasecmp($a[0], $b[0]) ?: \strcasecmp($a[1], $b[1]));

        $headings = [
            \__('Last name', 'orchestra-projects'),
            \__('First name', 'orchestra-projects'),
            \__('Email address', 'orchestra-projects'),
            ...\array_map(static fn (ProfileField $field): string => $field->label, $fields),
        ];

        return [$headings, ...$rows];
    }
}
