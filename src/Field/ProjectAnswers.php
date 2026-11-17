<?php

declare(strict_types=1);

namespace Orchestra\Projects\Field;

use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;

/**
 * The answers of a project's participants to its fields, and when they can be given: by members who
 * accepted, while the project is open for participation.
 */
final class ProjectAnswers
{
    public function __construct(
        private readonly ProjectFields $fields,
        private readonly AnswerRepository $answers,
    ) {
    }

    /**
     * @return list<ProjectField> the fields the project shows
     */
    public function fields(Project $project): array
    {
        return $this->fields->forProject($project->id);
    }

    /**
     * @return array<string, string> field ID => answer of the member
     */
    public function of(Project $project, int $userId): array
    {
        return $this->answers->of($project->id, $userId);
    }

    /**
     * @return array<int, array<string, string>> user ID => field ID => answer
     */
    public function forProject(Project $project): array
    {
        return $this->answers->forProject($project->id);
    }

    /**
     * Whether the participant may change their answers now. The caller checks that it is their own
     * participation.
     */
    public static function open(Project $project, Participation $participation): bool
    {
        return $project->status === ProjectStatus::Open && $participation->status === ParticipationStatus::Accepted;
    }

    /**
     * @return list<ProjectField> the required fields the member has not answered yet
     */
    public function missing(Project $project, int $userId): array
    {
        $answers = $this->of($project, $userId);

        return \array_values(\array_filter(
            $this->fields($project),
            static fn (ProjectField $field): bool => $field->required && ($answers[$field->id] ?? '') === ''
        ));
    }

    /**
     * Saves the submitted answers of the participant, unless one of them is invalid.
     *
     * @param array<mixed> $post Unslashed form data
     * @return list<string> error messages; nothing was saved if there are any
     */
    public function save(Project $project, Participation $participation, array $post): array
    {
        if (!self::open($project, $participation) || $participation->userId === null) {
            return [\__(
                'The questions can only be answered while the project is open for participation.',
                'orchestra-projects'
            )];
        }

        [$answers, $errors] = self::fromForm($this->fields($project), $post);
        if ($errors === []) {
            $this->answers->save($project->id, $participation->userId, $answers);
        }

        return $errors;
    }

    /**
     * Reads the answers of a form, one per field.
     *
     * @param list<ProjectField> $fields
     * @param array<mixed> $post Unslashed form data
     * @return array{array<string, string>, list<string>} answers by field ID, and error messages
     */
    public static function fromForm(array $fields, array $post): array
    {
        $submitted = \is_array($post[ProjectField::ANSWER_FIELD] ?? null) ? $post[ProjectField::ANSWER_FIELD] : [];
        $others = \is_array($post[ProjectField::OTHER_FIELD] ?? null) ? $post[ProjectField::OTHER_FIELD] : [];
        $answers = [];
        $errors = [];
        foreach ($fields as $field) {
            // An unticked checkbox is not submitted at all.
            $answer = $field->normalize($submitted[$field->id] ?? '', $others[$field->id] ?? '');
            $error = $field->error($answer);
            if ($error !== null) {
                $errors[] = $error;
            }
            $answers[$field->id] = $answer;
        }

        return [$answers, $errors];
    }
}
