<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;

/**
 * Closes evidence ingest for the rest of a scenario. The test environment reads `documents.ingest_enabled`
 * from `DOCUMENTS_INGEST_ENABLED`, and the kernel reboots between requests, so the next request's container
 * reads the closed switch — the same parameter the production gate reads as a tracked literal. Reopened after
 * every scenario, so a scenario that closes it cannot leak into the next.
 */
final class DocumentsIngestContext extends AbstractContext implements Context
{
    private const string VARIABLE = 'DOCUMENTS_INGEST_ENABLED';

    #[Given('evidence ingest is switched off')]
    public function evidenceIngestIsSwitchedOff(): void
    {
        $this->set('false');
    }

    #[AfterScenario]
    public function reopenEvidenceIngest(): void
    {
        $this->set('true');
    }

    /**
     * @SuppressWarnings("PHPMD.Superglobals") Symfony's env processor reads `$_ENV`/`$_SERVER` before `getenv()`,
     * so the switch has to land in all three for the next kernel to see it.
     */
    private function set(string $value): void
    {
        $_ENV[self::VARIABLE] = $value;
        $_SERVER[self::VARIABLE] = $value;
        \putenv(self::VARIABLE . '=' . $value);
    }
}
