<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Members\Member;
use Orchestra\Members\Profile\Profile;
use Orchestra\Members\Profile\ProfileField;
use Orchestra\Projects\Export\ParticipantsExport;
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

    public function testUsesTheDisplayNameWithoutFirstAndLastName(): void
    {
        $rows = ParticipantsExport::table([], [[self::member(4, '', '', 'Conny'), null, '', '']]);

        $this->assertSame(['Conny', '', 'user4@example.com', '', ''], $rows[1]);
    }

    private static function member(int $id, string $firstName, string $lastName, string $displayName = ''): Member
    {
        return new Member($id, $displayName, "user{$id}@example.com", [], true, $firstName, $lastName);
    }
}
