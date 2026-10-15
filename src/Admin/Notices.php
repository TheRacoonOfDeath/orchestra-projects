<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

/**
 * Messages that survive the redirect after submitting a form.
 */
final class Notices
{
    private const TRANSIENT_PREFIX = 'orchestra_projects_notices_';

    public function register(): void
    {
        \add_action('admin_notices', [$this, 'render']);
    }

    public function success(string $message): void
    {
        $this->add('success', $message);
    }

    public function error(string $message): void
    {
        $this->add('error', $message);
    }

    /**
     * @param list<string> $messages
     */
    public function errors(array $messages): void
    {
        foreach ($messages as $message) {
            $this->error($message);
        }
    }

    /**
     * Returns and forgets the stored notices, e.g. to show them on the front end.
     *
     * @return list<array{string, string}> type ("success" or "error") and message
     */
    public function take(): array
    {
        $notices = $this->stored();
        if ($notices !== []) {
            \delete_transient($this->transientName());
        }

        return $notices;
    }

    /**
     * @internal Hooked to `admin_notices`.
     */
    public function render(): void
    {
        foreach ($this->take() as [$type, $message]) {
            \printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                \esc_attr($type),
                \esc_html($message)
            );
        }
    }

    private function add(string $type, string $message): void
    {
        $notices = $this->stored();
        $notices[] = [$type, $message];
        \set_transient($this->transientName(), $notices, 5 * MINUTE_IN_SECONDS);
    }

    /**
     * @return list<array{string, string}>
     */
    private function stored(): array
    {
        $stored = \get_transient($this->transientName());
        if (!\is_array($stored)) {
            return [];
        }

        $notices = [];
        foreach ($stored as $notice) {
            if (\is_array($notice) && \is_string($notice[0] ?? null) && \is_string($notice[1] ?? null)) {
                $notices[] = [$notice[0], $notice[1]];
            }
        }

        return $notices;
    }

    private function transientName(): string
    {
        return self::TRANSIENT_PREFIX . \get_current_user_id();
    }
}
