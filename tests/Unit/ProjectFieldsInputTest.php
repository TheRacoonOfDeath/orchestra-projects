<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Field\ProjectField;
use Orchestra\Projects\Field\ProjectFieldsInput;
use PHPUnit\Framework\TestCase;

final class ProjectFieldsInputTest extends TestCase
{
    public function testAddsReordersAndDeletesFieldsWithTheirRequiredSwitch(): void
    {
        $current = [
            new ProjectField('bus', 'Bus', ProjectField::TYPE_CHECKBOX, 'I take the bus'),
            new ProjectField('arrival', 'Arrival', ProjectField::TYPE_TEXT),
        ];

        $input = ProjectFieldsInput::fromForm([
            ProjectFieldsInput::FIELD_ORDER => ['new:0', 'arrival', 'bus'],
            ProjectFieldsInput::FIELD_DELETE => 'bus',
            ProjectFieldsInput::FIELD_FIELDS => [
                'arrival' => ['label' => ' Arrival day ', 'hint' => '', 'required' => '1', 'type' => 'select'],
            ],
            ProjectFieldsInput::FIELD_NEW_FIELDS => [
                '0' => ['label' => 'Room', 'type' => 'select', 'choices' => "Single\nDouble", 'other' => '1'],
            ],
        ], $current);

        $this->assertSame([], $input->errors);
        $this->assertEquals([
            new ProjectField('room', 'Room', ProjectField::TYPE_SELECT, '', ['Single', 'Double'], true),
            new ProjectField('arrival', 'Arrival day', ProjectField::TYPE_TEXT, '', [], false, true),
        ], $input->fields, 'the type of an existing field stays');
        $this->assertEquals([$current[0]], $input->removed);
    }

    public function testStoredFieldsKeepTheirRequiredSwitch(): void
    {
        $field = new ProjectField('room', 'Room', ProjectField::TYPE_SELECT, 'Hint', ['Single'], true, true);

        $this->assertEquals($field, ProjectField::fromArray($field->toArray()));
    }
}
