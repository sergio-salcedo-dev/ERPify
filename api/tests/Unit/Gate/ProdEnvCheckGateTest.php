<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use Erpify\Tests\Support\ProdEnvScript;
use Erpify\Tests\Support\ProdEnvScriptTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClassConstant;

/**
 * What `make prod.env.check` refuses: a file Compose would run wrongly, or the app would refuse at first use.
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
final class ProdEnvCheckGateTest extends ProdEnvScriptTestCase
{
    private const string VALID_KEK = 'abcdefghijklmnopqrstuvwxyz012345';

    #[Test]
    #[DataProvider('provideTheCheckRefusesWhatComposeWouldRunWronglyCases')]
    public function theCheckRefusesWhatComposeWouldRunWrongly(string $extra, bool $passes, string $reason): void
    {
        $this->writeComposeFixtures();
        $base = "S=real\nAUDIT_KEK=" . self::VALID_KEK
            . "\nMAILER_SECURITY_FROM=security@erpify.dev\nPOSTGRES_PASSWORD=abc123\nSENTRY_ENVIRONMENT=rehearsal\n";

        $result = ProdEnvScript::run($this->root(), ['check', $this->envFile($extra . $base), $this->scratch]);

        $this->assertSame($passes ? 0 : 1, $result['exit'], $result['stdout']);

        if ('' !== $reason) {
            $this->assertStringContainsString($reason, $result['stdout']);
        }
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
        yield 'memory knob below the Docker minimum' => ["PHP_MEM_LIMIT=5m\n", false, 'below Docker'];
        yield 'timeout below one second' => ["MAILER_SMTP_TIMEOUT=0.5\n", false, 'between 1 and 300'];
        yield 'timeout above five minutes' => ["MAILER_SMTP_TIMEOUT=301\n", false, 'between 1 and 300'];
        yield 'any key declared twice' => ["HTTP_PORT=80\nHTTP_PORT=8080\n", false, 'HTTP_PORT'];
        yield 'a $ Compose would interpolate' => ["MAILER_SMTP_TIMEOUT=\$TIMEOUT\n", false, 'interpolate'];
        yield 'a single-quoted $ is literal' => ["X='a\$b'\n", true, ''];
        yield 'public host tagged as the rehearsal' => [
            "CADDY_SERVER_EXTRA_DIRECTIVES=\n",
            false,
            'tag real production events as the rehearsal',
        ];
    }

    #[Test]
    #[DataProvider('provideTheCheckRefusesABadValueOfARequiredKeyCases')]
    public function theCheckRefusesABadValueOfARequiredKey(string $key, string $value, string $reason): void
    {
        $this->writeComposeFixtures();
        $values = [
            'S' => 'real',
            'AUDIT_KEK' => self::VALID_KEK,
            'MAILER_SECURITY_FROM' => 'security@erpify.dev',
            'POSTGRES_PASSWORD' => 'abc123',
            'SENTRY_ENVIRONMENT' => 'rehearsal',
            $key => $value,
        ];
        $contents = \implode('', \array_map(
            static fn (string $k, string $v): string => $k . '=' . $v . "\n",
            \array_keys($values),
            $values,
        ));

        $result = ProdEnvScript::run($this->root(), ['check', $this->envFile($contents), $this->scratch]);

        $this->assertSame('' === $reason ? 0 : 1, $result['exit'], $result['stdout']);

        if ('' !== $reason) {
            $this->assertStringContainsString($reason, $result['stdout']);
        }
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
        yield 'URL-unsafe password' => ['POSTGRES_PASSWORD', 'a/b+c', 'URL-unreserved'];
        yield 'a hash in the password' => ['POSTGRES_PASSWORD', '"ab#cd"', 'URL-unreserved'];
        yield "a sender with no domain is the app's call, not this check's" => [
            'MAILER_SECURITY_FROM',
            'security.local',
            '',
        ];
        yield 'a $ in a required value' => ['S', 'ab$cd', 'interpolate'];
        yield 'rehearsal tagged prod' => ['SENTRY_ENVIRONMENT', '', 'must set'];
        yield 'rehearsal tagged as a real host' => ['SENTRY_ENVIRONMENT', 'production', 'must set'];
    }

    /**
     * A tls-internal file loads compose.rehearsal.yaml too, so a key that overlay requires must be set.
     */
    #[Test]
    public function aRehearsalFileAlsoOwesTheOverlaysRequiredKeys(): void
    {
        $this->writeComposeFixtures("r: \${REHEARSAL_ONLY:?r}\n");
        $file = "S=real\nAUDIT_KEK=" . self::VALID_KEK
            . "\nMAILER_SECURITY_FROM=security@erpify.dev\nPOSTGRES_PASSWORD=abc123\nSENTRY_ENVIRONMENT=rehearsal\n";

        $internal = ProdEnvScript::run($this->root(), ['check', $this->envFile($file), $this->scratch]);
        $public = ProdEnvScript::run(
            $this->root(),
            ['check', $this->envFile(\str_replace(
                "SENTRY_ENVIRONMENT=rehearsal\n",
                "CADDY_SERVER_EXTRA_DIRECTIVES=\n",
                $file,
            )), $this->scratch],
        );

        $this->assertSame(1, $internal['exit']);
        $this->assertStringContainsString('- REHEARSAL_ONLY', $internal['stdout']);
        $this->assertSame(0, $public['exit'], $public['stdout']);
    }

    /**
     * The sender refusal mirrors `SecuritySenderAddress`, which otherwise fires only at send time; a domain
     * the app learns to refuse must reach the script too.
     */
    #[Test]
    public function theSenderDomainsMirrorTheApplication(): void
    {
        $constant = new ReflectionClassConstant(SecuritySenderAddress::class, 'UNDELIVERABLE_DOMAINS');

        $this->assertSame($constant->getValue(), $this->bashArray('PROD_UNDELIVERABLE_DOMAINS'));
    }

    /**
     * Every `*_MEM_LIMIT` the prod stack or the rehearsal overlay interpolates is one the check validates.
     */
    #[Test]
    public function theMemoryKnobsAreTheOnesComposeReads(): void
    {
        $knobs = [];

        foreach (['compose.yaml', 'compose.prod.yaml', 'compose.rehearsal.yaml'] as $file) {
            $compose = (string) \file_get_contents($this->root() . '/' . $file);
            \preg_match_all('/\$\{([A-Z0-9_]+_MEM_LIMIT)\b/', $compose, $m);
            $knobs = [...$knobs, ...$m[1]];
        }

        $knobs = \array_values(\array_unique($knobs));
        $declared = $this->bashArray('PROD_MEM_KNOBS');
        \sort($knobs);
        \sort($declared);
        $this->assertSame($knobs, $declared);
    }

    private function writeComposeFixtures(string $rehearsal = "m: mailpit\n"): void
    {
        \file_put_contents($this->scratch . '/compose.yaml', "x: \${AUDIT_KEK:?k}\nz: \${MAILER_SECURITY_FROM:?f}\n");
        \file_put_contents($this->scratch . '/compose.prod.yaml', "y: \${S:?s}\nw: \${POSTGRES_PASSWORD:?p}\n");
        \file_put_contents($this->scratch . '/compose.rehearsal.yaml', $rehearsal);
    }

    /**
     * @return list<string>
     */
    private function bashArray(string $name): array
    {
        $script = (string) \file_get_contents($this->root() . '/scripts/deploy/lib/prod-env.sh');

        if (1 !== \preg_match('/^' . $name . '=\(([^)]*)\)$/m', $script, $m) || !isset($m[1])) {
            $this->fail($name . ' is not declared as a one-line array in prod-env.sh.');
        }

        return \array_values(\array_filter(\explode(' ', $m[1])));
    }
}
