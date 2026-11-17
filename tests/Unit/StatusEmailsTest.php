<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Email\Automatic\AutomaticEmail;
use Orchestra\Email\Automatic\AutomaticEmailRegistry;
use Orchestra\Projects\Email\StatusEmails;
use Orchestra\Projects\Project\ProjectStatus;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class StatusEmailsTest extends TestCase
{
    public function testOnlyTheForwardMovesToOpenAndPartsAssignedInformTheMembers(): void
    {
        $informing = [];
        foreach (ProjectStatus::cases() as $from) {
            foreach (ProjectStatus::cases() as $to) {
                if ($from->canMoveTo($to) && StatusEmails::informs($from, $to)) {
                    $informing[] = $from->value . ' → ' . $to->value;
                }
            }
        }

        $this->assertSame(['draft → open', 'preparation → parts_assigned'], $informing);
    }

    public function testTheDefaultTextsOnlyUseTheirPlaceholders(): void
    {
        $registry = new AutomaticEmailRegistry();
        // Registering needs neither the email service nor the database.
        (new ReflectionClass(StatusEmails::class))->newInstanceWithoutConstructor()->registerEmails($registry);

        $expected = [
            StatusEmails::OPEN_ID => ['dates', 'description', 'link', 'name', 'project'],
            StatusEmails::PARTS_ASSIGNED_ID => ['dates', 'link', 'name', 'project'],
        ];
        foreach ($expected as $id => $placeholders) {
            $email = $registry->find($id);
            $this->assertInstanceOf(AutomaticEmail::class, $email);
            \preg_match_all('/\{([a-z0-9_]+)\}/', $email->defaultSubject . $email->defaultBody, $matches);
            $this->assertSame($placeholders, $this->sorted($matches[1]));
            $this->assertSame($placeholders, $this->sorted(\array_keys($email->placeholders)));
        }
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        $names = \array_values(\array_unique($names));
        \sort($names);

        return $names;
    }
}
