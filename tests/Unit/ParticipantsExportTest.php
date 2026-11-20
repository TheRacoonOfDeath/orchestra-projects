<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Members\Member;
use Orchestra\Members\Profile\Profile;
use Orchestra\Members\Profile\ProfileField;
use Orchestra\Projects\Export\ParticipantsExport;
use Orchestra\Projects\Field\ProjectField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParticipantsExportTest extends TestCase
{
    public function testHasAColumnPerPropertyAndProfileField(): void
    {
        $fields = [
            new ProfileField('financial_support', 'Financial support', ProfileField::TYPE_CHECKBOX),
            new ProfileField('diet', 'Dietary restrictions', ProfileField::TYPE_TEXTAREA),
        ];
        $people = [
            [
                self::member(1, 'Mia', 'Muster'),
                new Profile(1, true, ['financial_support' => '1', 'diet' => 'vegan']),
                'Only from the 27th',
                'Room booked from the 27th',
            ],
            [
                self::member(2, 'Anna', 'Muster'),
                new Profile(2, true, ['financial_support' => '', 'diet' => '']),
                '',
                '',
            ],
            [self::member(3, 'Ben', 'Albers'), null, '', ''],
        ];

        $this->assertSame([
            [
                'Last name', 'First name', 'Email address', 'Financial support', 'Dietary restrictions', 'Comment',
                'Organizer note',
            ],
            ['Albers', 'Ben', 'user3@example.com', 'No', '', '', ''],
            ['Muster', 'Anna', 'user2@example.com', 'No', '', '', ''],
            ['Muster', 'Mia', 'user1@example.com', 'Yes', 'vegan', 'Only from the 27th', 'Room booked from the 27th'],
        ], ParticipantsExport::table($fields, $people));
    }

    public function testAddsTheBirthDateAndTheAgeAtTheStartOfTheProject(): void
    {
        $people = [
            [self::member(1, 'Mia', 'Muster'), new Profile(1, true, [], '2008-05-08'), '', ''],
            [self::member(2, 'Anna', 'Muster'), new Profile(2, true, []), '', ''],
            [self::member(3, 'Ben', 'Albers'), null, '', ''],
        ];

        $this->assertSame([
            [
                'Last name', 'First name', 'Email address', 'Birth date', 'Age at project start', 'Comment',
                'Organizer note',
            ],
            ['Albers', 'Ben', 'user3@example.com', '', '', '', ''],
            ['Muster', 'Anna', 'user2@example.com', '', '', '', ''],
            ['Muster', 'Mia', 'user1@example.com', '2008-05-08', '18', '', ''],
        ], ParticipantsExport::table([], $people, [], [], true, '2026-07-01'));
        $withoutStart = ParticipantsExport::table([], [$people[0]], [], [], true, null);
        $this->assertSame(['Muster', 'Mia', 'user1@example.com', '2008-05-08', '', '', ''], $withoutStart[1]);
    }

    public function testLeavesTheBirthDateOutWithAnOlderOrchestraMembers(): void
    {
        $people = [[self::member(1, 'Mia', 'Muster'), new Profile(1, true, [], '2008-05-08'), '', '']];

        $this->assertSame(
            ['Last name', 'First name', 'Email address', 'Comment', 'Organizer note'],
            ParticipantsExport::table([], $people, [], [], false, '2026-07-01')[0]
        );
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function ages(): array
    {
        return [
            'the day before the birthday' => ['2008-05-08', '2026-05-07', '17'],
            'on the birthday' => ['2008-05-08', '2026-05-08', '18'],
            'after the birthday' => ['2008-05-08', '2026-12-31', '18'],
            'born on 29 February, before 1 March' => ['2008-02-29', '2026-02-28', '17'],
            'born on 29 February, on 1 March' => ['2008-02-29', '2026-03-01', '18'],
            'no birth date' => ['', '2026-05-08', ''],
            'no start date' => ['2008-05-08', null, ''],
            'empty start date' => ['2008-05-08', '', ''],
            'born after the start' => ['2027-01-01', '2026-05-08', ''],
        ];
    }

    #[DataProvider('ages')]
    public function testCalculatesTheAgeInFullYears(string $birthDate, ?string $startDate, string $age): void
    {
        $this->assertSame($age, ParticipantsExport::age($birthDate, $startDate));
    }

    public function testCountsTheAnswersOfChoiceLists(): void
    {
        $fields = [
            new ProfileField(
                'diet',
                'Dietary restrictions',
                ProfileField::TYPE_SELECT,
                '',
                ['vegetarian', 'vegan', 'halal'],
                false,
                true
            ),
            new ProfileField('note', 'Note', ProfileField::TYPE_TEXT),
        ];
        $people = [];
        foreach (['vegan', 'vegetarian', 'vegan', 'no nuts', '', 'Lactose-free', 'vegan'] as $index => $diet) {
            $profile = new Profile($index + 1, true, ['diet' => $diet]);
            $people[] = [self::member($index + 1, 'P', 'P' . $index), $profile, '', ''];
        }
        $people[] = [self::member(9, 'Q', 'Q'), null, '', ''];

        $rows = ParticipantsExport::table($fields, $people);

        $this->assertSame([
            [],
            ['Dietary restrictions', 'Number'],
            ['vegetarian', '1'],
            ['vegan', '3'],
            ['halal', '0'],
            ['Lactose-free', '1'],
            ['no nuts', '1'],
            ['No answer', '2'],
        ], \array_slice($rows, 9), 'only choice lists are counted; free-text answers and missing answers too');
    }

    public function testUsesTheDisplayNameWithoutFirstAndLastName(): void
    {
        $rows = ParticipantsExport::table([], [[self::member(4, '', '', 'Conny'), null, '', '']]);

        $this->assertSame(['Conny', '', 'user4@example.com', '', ''], $rows[1]);
    }

    public function testHasAColumnPerProjectFieldAndCountsItsChoices(): void
    {
        $projectFields = [
            new ProjectField('bus', 'Bus', ProjectField::TYPE_CHECKBOX),
            new ProjectField('room', 'Room', ProjectField::TYPE_SELECT, '', ['Single', 'Double']),
        ];
        $people = [[self::member(1, 'Mia', 'Muster'), null, '', ''], [self::member(2, 'Ben', 'Albers'), null, '', '']];

        $rows = ParticipantsExport::table([], $people, $projectFields, [1 => ['bus' => '1', 'room' => 'Double']]);

        $this->assertSame([
            ['Last name', 'First name', 'Email address', 'Bus', 'Room', 'Comment', 'Organizer note'],
            ['Albers', 'Ben', 'user2@example.com', 'No', '', '', ''],
            ['Muster', 'Mia', 'user1@example.com', 'Yes', 'Double', '', ''],
            [],
            ['Room', 'Number'],
            ['Single', '0'],
            ['Double', '1'],
            ['No answer', '1'],
        ], $rows);
    }

    private static function member(int $id, string $firstName, string $lastName, string $displayName = ''): Member
    {
        return new Member($id, $displayName, "user{$id}@example.com", [], true, $firstName, $lastName);
    }
}
