<?php

declare(strict_types=1);

namespace Orchestra\Projects\Field;

/**
 * Cleans text entered in forms.
 */
final class Text
{
    /**
     * Strips tags and control characters, keeps line breaks and trims surrounding whitespace.
     */
    public static function multiLine(string $text): string
    {
        $text = \strip_tags(\str_replace("\r\n", "\n", $text));
        $text = (string) \preg_replace('/[^\P{C}\n\t]/u', '', $text);

        return \trim($text);
    }

    /**
     * The same, with all line breaks and tabs replaced by spaces.
     */
    public static function singleLine(string $text): string
    {
        return self::multiLine((string) \preg_replace('/[\r\n\t]+/', ' ', $text));
    }
}
