<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ProdEnvScript;
use Erpify\Tests\Support\RepositoryRoot;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `scripts/deploy/lib/prod-env.sh` answers as Compose reads the files, for every caller at once.
 *
 * make/config.mk takes the compose project and the overlay list from it, `make prod.env.check` refuses an env
 * file through it, and the deploy, backup and restore scripts source it. A wrong answer here is therefore a
 * restore landing on dev's database or a prod stack started on a placeholder secret, with no other test in
 * the way: the suite cannot run host `make`, so this gate executes the script itself, over fixture files.
 *
 * Its blind spots: a Caddy directive meaning internal TLS spelled other than `tls internal` reads as public;
 * the project an existing stack was started under is not recorded, so editing the TLS profile between a
 * stand-up and a backup moves the backup to the other project; and nothing here runs Compose.
 *
 * @internal
 */
#[CoversNothing]
final class ProdEnvSupportScriptGateTest extends TestCase
{
    private const string VALID_KEK = 'abcdefghijklmnopqrstuvwxyz012345';

    private string $scratch = '';

    #[Override]
    protected function setUp(): void
    {
        $this->scratch = \sys_get_temp_dir() . '/prod-env-gate-' . \bin2hex(\random_bytes(6));
        \mkdir($this->scratch);
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ((array) \glob($this->scratch . '/*') as $file) {
            \unlink((string) $file);
        }

        \rmdir($this->scratch);
    }

    #[Test]
    #[DataProvider('provideItResolvesTheProfileProjectAndOverlaysAsComposeReadsTheFileCases')]
    public function itResolvesTheProfileProjectAndOverlaysAsComposeReadsTheFile(
        ?string $contents,
        string $profile,
        string $project,
    ): void {
        $file = null === $contents ? $this->scratch . '/absent.env' : $this->envFile($contents);
        $overlays = '-f compose.yaml -f compose.prod.yaml'
            . ('internal' === $profile ? ' -f compose.rehearsal.yaml' : '');

        $this->assertSame($profile, $this->answer(['profile', $file]));
        $this->assertSame($project, $this->answer(['project', $file]));
        $this->assertSame($overlays, $this->answer(['compose-files', $file]));
    }

    /**
     * @return iterable<string, array{string|null, string, string}>
     */
    public static function provideItResolvesTheProfileProjectAndOverlaysAsComposeReadsTheFileCases(): iterable
    {
        yield 'key absent: compose defaults to tls internal' => ["X=1\n", 'internal', 'erpify-prodlocal'];
        yield 'tls internal' => ["CADDY_SERVER_EXTRA_DIRECTIVES=tls internal\n", 'internal', 'erpify-prodlocal'];
        yield 'quoted, CRLF' => ["CADDY_SERVER_EXTRA_DIRECTIVES=\"tls internal\"\r\n", 'internal', 'erpify-prodlocal'];
        yield 'empty: public ACME' => ["CADDY_SERVER_EXTRA_DIRECTIVES=\n", 'public', 'erpify'];
        yield 'empty with a comment' => ["CADDY_SERVER_EXTRA_DIRECTIVES= # acme\n", 'public', 'erpify'];
        yield 'other directives' => ["CADDY_SERVER_EXTRA_DIRECTIVES=encode gzip\n", 'public', 'erpify'];
        yield 'last occurrence wins' => [
            "CADDY_SERVER_EXTRA_DIRECTIVES=tls internal\nCADDY_SERVER_EXTRA_DIRECTIVES=\n",
            'public',
            'erpify',
        ];
        yield 'no file' => [null, 'public', 'erpify'];
    }

    #[Test]
    public function aShellExportBeatsTheFileAsItDoesForCompose(): void
    {
        $file = $this->envFile("CADDY_SERVER_EXTRA_DIRECTIVES=tls internal\n");

        // Non-empty on purpose: proc_open drops an environment entry whose value is the empty string.
        $this->assertSame(
            'erpify',
            $this->answer(['project', $file], ['CADDY_SERVER_EXTRA_DIRECTIVES' => 'encode gzip']),
        );
    }

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
        yield 'single quotes' => ["K='s'\n", 's'];
        yield 'last occurrence wins' => ["K=first\nK=last\n", 'last'];
        yield 'absent' => ["OTHER=1\n", null];
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

    #[Test]
    #[DataProvider('provideTheCheckRefusesWhatComposeWouldRunWronglyCases')]
    public function theCheckRefusesWhatComposeWouldRunWrongly(string $contents, bool $passes, string $reason): void
    {
        \file_put_contents($this->scratch . '/compose.yaml', "x: \${AUDIT_KEK:?k}\n");
        \file_put_contents($this->scratch . '/compose.prod.yaml', "y: \${S:?s}\n");

        $result = ProdEnvScript::run($this->root(), ['check', $this->envFile($contents), $this->scratch]);

        $this->assertSame($passes ? 0 : 1, $result['exit'], $result['stdout']);
        $this->assertStringContainsString($reason, $result['stdout']);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function provideTheCheckRefusesWhatComposeWouldRunWronglyCases(): iterable
    {
        $kek = self::VALID_KEK;

        yield 'complete' => ["S=real\nAUDIT_KEK={$kek}\n", true, ''];
        yield 'quoted 32-byte KEK' => ["S=real\nAUDIT_KEK=\"{$kek}\"\n", true, ''];
        yield 'KEK with a trailing comment' => ["S=real\nAUDIT_KEK={$kek} # generated once\n", true, ''];
        yield 'placeholder' => ["S=CHANGE_ME_x\nAUDIT_KEK={$kek}\n", false, '- S'];
        yield 'quoted placeholder' => ["S=\"CHANGE_ME_x\"\nAUDIT_KEK={$kek}\n", false, '- S'];
        yield 'old example DSN' => [
            "S=https://examplePublicKey@o0.ingest.de.sentry.io/0\nAUDIT_KEK={$kek}\n",
            false,
            '- S',
        ];
        yield 'comment only' => ["S= # fill in\nAUDIT_KEK={$kek}\n", false, '- S'];
        yield 'duplicated key' => ["S=real\nS=CHANGE_ME\nAUDIT_KEK={$kek}\n", false, 'more than once'];
        yield 'hex 32 KEK' => ["S=real\nAUDIT_KEK=" . \str_repeat('ab', 32) . "\n", false, 'exactly 32 bytes'];
        yield 'base64 32 KEK' => ["S=real\nAUDIT_KEK=" . \str_repeat('a', 43) . "=\n", false, 'exactly 32 bytes'];
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function answer(array $arguments, array $environment = []): string
    {
        $result = ProdEnvScript::run($this->root(), $arguments, $environment);
        $this->assertSame(0, $result['exit'], $result['stderr']);

        return \trim($result['stdout'], "\n");
    }

    private function envFile(string $contents): string
    {
        $file = \tempnam($this->scratch, 'env');
        $this->assertIsString($file);
        \file_put_contents($file, $contents);

        return $file;
    }

    private function root(): string
    {
        $root = RepositoryRoot::path();
        $this->assertNotNull($root, 'Cannot find the repository root (the /app/repo mount in compose.dev.yaml).');

        return $root;
    }
}
