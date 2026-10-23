<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ProdEnvScript;
use Erpify\Tests\Support\ProdEnvScriptTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What `make prod.env.check` refuses: a file Compose would run wrongly, or the app would refuse at first use.
 *
 * `scripts/deploy/lib/prod-env.sh` is the one reader of a prod env file: make/config.mk takes the compose project
 * and the overlay list from it, `make prod.env.check` refuses an env file through it, and the deploy, backup and
 * restore scripts source it — restore's production guard included. The suite cannot run host `make`, so this
 * family executes the script itself over fixture files ({@see ProdEnvScriptTestCase}).
 *
 * Blind spots shared by the family: internal TLS spelled other than `tls internal` reads as public; the project
 * an existing stack was started under is not recorded, so editing the TLS profile between a stand-up and a
 * backup moves the backup to the other project; and nothing here runs Compose or Docker.
 *
 * @internal
 */
#[CoversNothing]
final class ProdEnvCheckGateTest extends ProdEnvScriptTestCase
{
    private const string VALID_KEK = 'abcdefghijklmnopqrstuvwxyz012345';

    #[Test]
    #[DataProvider('provideTheCheckRefusesWhatComposeWouldRunWronglyCases')]
    public function theCheckRefusesWhatComposeWouldRunWrongly(string $extra, bool $passes, string $reason): void
    {
        \file_put_contents($this->scratch . '/compose.yaml', "x: \${AUDIT_KEK:?k}\nz: \${MAILER_SECURITY_FROM:?f}\n");
        \file_put_contents($this->scratch . '/compose.prod.yaml', "y: \${S:?s}\nw: \${POSTGRES_PASSWORD:?p}\n");
        $base = "S=real\nAUDIT_KEK=" . self::VALID_KEK
            . "\nMAILER_SECURITY_FROM=security@erpify.dev\nPOSTGRES_PASSWORD=abc123\n";

        $result = ProdEnvScript::run($this->root(), ['check', $this->envFile($extra . $base), $this->scratch]);

        $this->assertSame($passes ? 0 : 1, $result['exit'], $result['stdout']);
        $this->assertStringContainsString($reason, $result['stdout']);
    }

    /**
     * Each case is prepended to a complete file, so the first occurrence is the case and the last the base.
     *
     * @return iterable<string, array{string, bool, string}>
     */
    public static function provideTheCheckRefusesWhatComposeWouldRunWronglyCases(): iterable
    {
        yield 'complete' => ['', true, ''];
        yield 'optional knobs well formed' => ["MAILER_SMTP_TIMEOUT=10\nPHP_MEM_LIMIT=\"512m\"\n", true, ''];
        yield 'password with a trailing comment' => ["X=1 # POSTGRES_PASSWORD is fine\n", true, ''];
        yield 'duplicated required key' => ["S=CHANGE_ME\n", false, 'more than once'];
        yield 'duplicated optional knob' => [
            "MAILER_SMTP_TIMEOUT=3\nMAILER_SMTP_TIMEOUT=10s\n",
            false,
            'more than once',
        ];
        yield 'duplicated TLS directive' => [
            "CADDY_SERVER_EXTRA_DIRECTIVES=\nCADDY_SERVER_EXTRA_DIRECTIVES=tls internal\n",
            false,
            'more than once',
        ];
        yield 'timeout as a duration' => ["MAILER_SMTP_TIMEOUT=10s\n", false, 'plain number of seconds'];
        yield 'bare memory limit' => ["PWA_MEM_LIMIT=512\n", false, 'PWA_MEM_LIMIT'];
        yield 'rehearsal mailpit limit' => ["REHEARSAL_MAILPIT_MEM_LIMIT=1k\n", false, 'REHEARSAL_MAILPIT_MEM_LIMIT'];
    }

    #[Test]
    #[DataProvider('provideTheCheckRefusesABadValueOfARequiredKeyCases')]
    public function theCheckRefusesABadValueOfARequiredKey(string $key, string $value, string $reason): void
    {
        \file_put_contents($this->scratch . '/compose.yaml', "x: \${AUDIT_KEK:?k}\nz: \${MAILER_SECURITY_FROM:?f}\n");
        \file_put_contents($this->scratch . '/compose.prod.yaml', "y: \${S:?s}\nw: \${POSTGRES_PASSWORD:?p}\n");
        $values = [
            'S' => 'real',
            'AUDIT_KEK' => self::VALID_KEK,
            'MAILER_SECURITY_FROM' => 'security@erpify.dev',
            'POSTGRES_PASSWORD' => 'abc123',
            $key => $value,
        ];
        $contents = \implode('', \array_map(
            static fn (string $k, string $v): string => $k . '=' . $v . "\n",
            \array_keys($values),
            $values,
        ));

        $result = ProdEnvScript::run($this->root(), ['check', $this->envFile($contents), $this->scratch]);

        $this->assertSame(1, $result['exit'], $result['stdout']);
        $this->assertStringContainsString($reason, $result['stdout']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideTheCheckRefusesABadValueOfARequiredKeyCases(): iterable
    {
        yield 'placeholder' => ['S', 'CHANGE_ME_x', '- S'];
        yield 'quoted placeholder' => ['S', '"CHANGE_ME_x"', '- S'];
        yield 'old example DSN' => ['S', 'https://examplePublicKey@o0.ingest.de.sentry.io/0', '- S'];
        yield 'comment only' => ['S', ' # fill in', '- S'];
        yield 'hex 32 KEK' => ['AUDIT_KEK', \str_repeat('ab', 32), 'exactly 32 bytes'];
        yield 'base64 32 KEK' => ['AUDIT_KEK', \str_repeat('a', 43) . '=', 'exactly 32 bytes'];
        yield 'quoted KEK is read without its quotes' => [
            'AUDIT_KEK',
            '"' . \str_repeat('a', 31) . '"',
            'exactly 32 bytes',
        ];
        yield '.local sender' => ['MAILER_SECURITY_FROM', 'security@erpify.local', 'refused at send time'];
        yield 'no-reply sender' => ['MAILER_SECURITY_FROM', 'no-reply@erpify.dev', 'refused at send time'];
        yield 'URL-unsafe password' => ['POSTGRES_PASSWORD', 'a/b+c', 'URL-unsafe'];
    }
}
