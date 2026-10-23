<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ProdEnvScript;
use Erpify\Tests\Support\ProdEnvScriptTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * How a value, a production host and the required keys are read — as Compose reads the files.
 *
 * `scripts/deploy/lib/prod-env.sh` is the one reader of a prod env file: make/config.mk takes the compose project
 * and the overlay list from it, `make prod.env.check` refuses an env file through it, and the deploy, backup and
 * restore scripts source it — restore's production guard included. The suite cannot run host `make`, so this
 * family executes the script itself over fixture files ({@see ProdEnvScriptTestCase}).
 *
 * Blind spots shared by the family: internal TLS spelled other than `tls internal` reads as public, and nothing
 * here runs Compose or Docker. What the scripts themselves leave open (the project a stack was started under is
 * not recorded) is recorded in `PRODUCTION_SECURITY_CHECKLIST.md` §7.
 *
 * @internal
 */
#[CoversNothing]
final class ProdEnvReadingGateTest extends ProdEnvScriptTestCase
{
    #[Test]
    #[DataProvider('provideItReadsAValueAsComposeDoesCases')]
    public function itReadsAValueAsComposeDoes(string $contents, ?string $expected): void
    {
        $result = ProdEnvScript::run($this->root(), ['value', 'K', $this->envFile($contents)]);

        if (null === $expected) {
            $this->assertSame(1, $result['exit'], 'An absent key must exit 1, distinct from an empty value.');

            return;
        }

        $this->assertSame(0, $result['exit']);
        $this->assertSame($expected, \trim($result['stdout'], "\n"));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideItReadsAValueAsComposeDoesCases(): iterable
    {
        yield 'unquoted loses a trailing comment' => ["K=value  # note\n", 'value'];
        yield 'double quotes keep a hash' => ["K=\"a # b\" # note\n", 'a # b'];
        yield 'quotes stop at the first closing one' => ["K=\"a\" # \"b\"\n", 'a'];
        yield 'single quotes' => ["K='s'\n", 's'];
        yield 'export prefix and blanks' => ["  export K = v\n", 'v'];
        yield 'last occurrence wins' => ["K=first\nK=last\n", 'last'];
        yield 'a value starting with a hash is kept' => ["K=#abc\n", '#abc'];
        yield 'colon separator' => ["K: v\n", 'v'];
        yield 'comment only is empty' => ["K= # fill in\n", ''];
        yield 'absent' => ["OTHER=1\n", null];
    }

    #[Test]
    #[DataProvider('provideOnlyAnExplicitlyLocalServerIsNonProductionCases')]
    public function onlyAnExplicitlyLocalServerIsNonProduction(string $contents, bool $local): void
    {
        $result = ProdEnvScript::run($this->root(), ['server-is-local', $this->envFile($contents)]);

        $this->assertSame($local ? 0 : 1, $result['exit']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideOnlyAnExplicitlyLocalServerIsNonProductionCases(): iterable
    {
        yield 'local name' => ["SERVER_NAME=erpify.local\n", true];
        yield 'several local names' => ["SERVER_NAME=erpify.local localhost\n", true];
        yield 'real domain' => ["SERVER_NAME=erp.example.com\n", false];
        yield 'one real among local' => ["SERVER_NAME=erpify.local erp.example.com\n", false];
        yield 'duplicated, last real' => ["SERVER_NAME=erpify.local\nSERVER_NAME=erp.example.com\n", false];
        yield 'duplicated, both local' => ["SERVER_NAME=erpify.local\nSERVER_NAME=localhost\n", false];
        yield 'comma list, all local' => ["SERVER_NAME=erpify.local, localhost\n", true];
        yield 'comma-joined, one real' => ["SERVER_NAME=prod.example.com,erpify.local\n", false];
        yield 'scheme, port and case' => ["SERVER_NAME=https://ERPIFY.local:8443\n", true];
        yield 'bracketed IPv6 with a port' => ["SERVER_NAME=[::1]:443\n", true];
        yield 'a glob is a host, not a filename' => ["SERVER_NAME=erpify.local *\n", false];
        yield 'empty' => ["SERVER_NAME=\n", false];
        yield 'absent' => ["OTHER=1\n", false];
    }

    #[Test]
    public function itRequiresBothFormsInBothFilesAndSkipsCommentLines(): void
    {
        \file_put_contents($this->scratch . '/compose.yaml', "x: \${BASE_ONLY:?set}\n");
        \file_put_contents(
            $this->scratch . '/compose.prod.yaml',
            "# header quoting \${COMMENTED:?msg}\na: \${COLON:?m}\nb: \${BARE?m}\nc: \${OPTIONAL:-x}\n",
        );

        $this->assertSame("BARE\nBASE_ONLY\nCOLON", $this->answer(['required-keys', $this->scratch]));
    }

    #[Test]
    public function aMissingComposeFileFailsRatherThanShrinkingTheSet(): void
    {
        \file_put_contents($this->scratch . '/compose.prod.yaml', "a: \${COLON:?m}\n");

        $this->assertNotSame(0, ProdEnvScript::run($this->root(), ['required-keys', $this->scratch])['exit']);
    }
}
