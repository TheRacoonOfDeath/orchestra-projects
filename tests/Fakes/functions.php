<?php

/**
 * Minimal fakes of the WordPress functions used by the unit-tested classes.
 */

declare(strict_types=1);

function __(string $text, string $domain = 'default'): string
{
    return $text;
}
