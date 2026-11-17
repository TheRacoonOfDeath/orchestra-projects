<?php

declare(strict_types=1);

namespace Orchestra\Projects\Field;

/**
 * A question to the participants of a project, defined centrally by the organizers (see
 * {@see ProjectFields}) and shown in the projects that use it. The types are those of the profile
 * fields of orchestra-members.
 *
 * Only the member and the organizers see the answers.
 */
final class ProjectField
{
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_SELECT = 'select';

    public const TYPES = [self::TYPE_CHECKBOX, self::TYPE_TEXT, self::TYPE_TEXTAREA, self::TYPE_SELECT];

    public const LABEL_MAX_LENGTH = 100;
    public const HINT_MAX_LENGTH = 200;
    public const CHOICE_MAX_LENGTH = 100;
    public const TEXT_MAX_LENGTH = 200;
    public const TEXTAREA_MAX_LENGTH = 1000;
    public const OTHER_MAX_LENGTH = 100;

    /** The form value of the choice "Other" of a choice list with a free-text answer. */
    public const OTHER = '__other__';

    /** The form fields of the answers: `orchestra_projects_answer[ID]`, `orchestra_projects_answer_other[ID]`. */
    public const ANSWER_FIELD = 'orchestra_projects_answer';
    public const OTHER_FIELD = 'orchestra_projects_answer_other';

    /**
     * @param string $id Stable key; the label can change
     * @param string $hint Help text; the text next to the box for checkboxes
     * @param list<string> $choices Only for {@see TYPE_SELECT}
     * @param bool $allowsOther Only for {@see TYPE_SELECT}: offers "Other" with a free-text answer
     * @param bool $required Participants are asked to answer it (a checkbox to tick it)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $type,
        public readonly string $hint = '',
        public readonly array $choices = [],
        public readonly bool $allowsOther = false,
        public readonly bool $required = false,
    ) {
    }

    /**
     * The name of the form field of the answer.
     */
    public function inputName(): string
    {
        return self::ANSWER_FIELD . '[' . $this->id . ']';
    }

    public function maxLength(): int
    {
        return match ($this->type) {
            self::TYPE_TEXTAREA => self::TEXTAREA_MAX_LENGTH,
            self::TYPE_SELECT => self::OTHER_MAX_LENGTH,
            default => self::TEXT_MAX_LENGTH,
        };
    }

    /**
     * The answer for submitted form data: "1" or "" for checkboxes, one of the choices, the
     * free-text answer or "" for choice lists, text without tags and control characters otherwise.
     *
     * The free-text answer counts if "Other" or nothing is chosen, so that it also works without
     * choosing "Other" first.
     */
    public function normalize(mixed $submitted, mixed $other = ''): string
    {
        if (!\is_string($submitted)) {
            return '';
        }

        return match ($this->type) {
            self::TYPE_CHECKBOX => $submitted === '1' ? '1' : '',
            self::TYPE_SELECT => match (true) {
                \in_array($submitted, $this->choices, true) => $submitted,
                $this->allowsOther && ($submitted === self::OTHER || $submitted === '') && \is_string($other)
                    => Text::singleLine($other),
                default => '',
            },
            self::TYPE_TEXTAREA => Text::multiLine($submitted),
            default => Text::singleLine($submitted),
        };
    }

    /**
     * The free-text answer of a choice list, or "" if the answer is one of the choices.
     */
    public function otherText(string $value): string
    {
        return $this->allowsOther && $value !== '' && !\in_array($value, $this->choices, true) ? $value : '';
    }

    /**
     * Why the answer cannot be saved, or null if it can.
     */
    public function error(string $value): ?string
    {
        if ($this->required && $value === '') {
            return $this->type === self::TYPE_CHECKBOX
                /* translators: %s: field label */
                ? \sprintf(\__('Please tick "%s".', 'orchestra-projects'), $this->label)
                /* translators: %s: field label */
                : \sprintf(\__('Please answer "%s".', 'orchestra-projects'), $this->label);
        }
        if (\mb_strlen($value) <= $this->maxLength()) {
            return null;
        }

        /* translators: 1: field label, 2: maximum number of characters */
        $message = \__('%1$s must not be longer than %2$d characters.', 'orchestra-projects');

        return \sprintf($message, $this->label, $this->maxLength());
    }

    /**
     * The answer as plain text, e.g. for the export: "Yes" or "No" for checkboxes.
     */
    public function display(string $value): string
    {
        if ($this->type === self::TYPE_CHECKBOX) {
            return $value === '1' ? \__('Yes', 'orchestra-projects') : \__('No', 'orchestra-projects');
        }

        return $value;
    }

    /**
     * @return array<string, string> type => translated name
     */
    public static function typeNames(): array
    {
        return [
            self::TYPE_TEXT => \__('Text', 'orchestra-projects'),
            self::TYPE_TEXTAREA => \__('Long text', 'orchestra-projects'),
            self::TYPE_CHECKBOX => \__('Checkbox', 'orchestra-projects'),
            self::TYPE_SELECT => \__('Choice list', 'orchestra-projects'),
        ];
    }

    /**
     * @return array{
     *     id: string, label: string, type: string, hint: string, choices: list<string>, other: bool, required: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'type' => $this->type,
            'hint' => $this->hint,
            'choices' => $this->choices,
            'other' => $this->allowsOther,
            'required' => $this->required,
        ];
    }

    /**
     * @param array<mixed> $data As returned by {@see toArray()}
     */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $label = $data['label'] ?? null;
        $type = $data['type'] ?? null;
        if (!\is_string($id) || $id === '' || !\is_string($label) || !\in_array($type, self::TYPES, true)) {
            return null;
        }
        $hint = $data['hint'] ?? '';
        $choices = \is_array($data['choices'] ?? null) ? $data['choices'] : [];

        return new self(
            $id,
            $label,
            $type,
            \is_string($hint) ? $hint : '',
            $type === self::TYPE_SELECT ? \array_values(\array_filter($choices, 'is_string')) : [],
            $type === self::TYPE_SELECT && ($data['other'] ?? false) === true,
            ($data['required'] ?? false) === true,
        );
    }
}
