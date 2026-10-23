<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ProdEnvScriptTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;

/**
 * Which compose project and overlays a prod env file selects, and the host ports that stack publishes — and that
 * make asks the script for them rather than deciding alone.
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
final class ProdEnvProjectGateTest extends ProdEnvScriptTestCase
{
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
        $notACheckout = $this->scratch;

        $this->assertSame($profile, $this->answer(['profile', $file]));
        $this->assertSame($project, $this->answer(['project', $file, $notACheckout]));
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
        yield 'export prefix, indented' => [
            "  export CADDY_SERVER_EXTRA_DIRECTIVES=tls internal\n",
            'internal',
            'erpify-prodlocal',
        ];
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

    /**
     * A public env file runs as the checkout's own project, the rule make/config.mk applies to dev — so a
     * worktree never reaches the primary checkout's `erpify`, and a host deploying from its primary keeps it.
     */
    #[Test]
    public function aPublicFileRunsAsTheCheckoutsProject(): void
    {
        $primary = $this->scratch . '/primary';
        $this->git(['init', '-q', $primary]);
        $this->git([
            '-C', $primary, '-c', 'user.email=g@x', '-c', 'user.name=g', 'commit', '-q', '--allow-empty', '-m', 'x',
        ]);
        $this->git(['-C', $primary, 'worktree', 'add', '-q', $this->scratch . '/Fix+Thing-1', '-b', 'fix']);
        $public = $this->envFile("CADDY_SERVER_EXTRA_DIRECTIVES=\n");
        $internal = $this->envFile("CADDY_SERVER_EXTRA_DIRECTIVES=tls internal\n");

        $this->assertSame('erpify', $this->answer(['project', $public, $primary]));
        $this->assertSame('erpify-fix-thing-1', $this->answer(['project', $public, $this->scratch . '/Fix+Thing-1']));
        $this->assertSame('erpify-prodlocal', $this->answer(['project', $internal, $this->scratch . '/Fix+Thing-1']));
    }

    #[Test]
    public function aShellExportBeatsTheFileAsItDoesForCompose(): void
    {
        $file = $this->envFile("CADDY_SERVER_EXTRA_DIRECTIVES=tls internal\n");

        $this->assertSame(
            'erpify',
            $this->answer(['project', $file, $this->scratch], [], ['CADDY_SERVER_EXTRA_DIRECTIVES']),
        );
        $this->assertSame(
            '-f compose.yaml -f compose.prod.yaml',
            $this->answer(['compose-files', $file], [], ['CADDY_SERVER_EXTRA_DIRECTIVES']),
        );
    }

    #[Test]
    #[DataProvider('provideItListsThePortsTheStackPublishesCases')]
    public function itListsThePortsTheStackPublishes(string $contents, string $expected): void
    {
        $this->assertSame($expected, $this->answer(['published-ports', $this->envFile($contents)]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItListsThePortsTheStackPublishesCases(): iterable
    {
        yield 'public defaults' => ["CADDY_SERVER_EXTRA_DIRECTIVES=\n", "80/tcp\n443/tcp\n443/udp"];
        yield 'rehearsal adds mailpit' => [
            "CADDY_SERVER_EXTRA_DIRECTIVES=tls internal\n",
            "80/tcp\n443/tcp\n443/udp\n8025/tcp",
        ];
        yield 'file overrides' => [
            "CADDY_SERVER_EXTRA_DIRECTIVES=tls internal\nHTTPS_PORT=8443\nMAILPIT_UI_PORT=18025\n",
            "80/tcp\n8443/tcp\n443/udp\n18025/tcp",
        ];
    }

    /**
     * make cannot run in this suite, so its half of the agreement is pinned in its source: the project and the
     * TLS profile come from the script, and the rehearsal overlay is appended under that profile only.
     */
    #[Test]
    public function makeAsksTheScriptForTheProjectAndTheOverlay(): void
    {
        $config = (string) \file_get_contents($this->root() . '/make/config.mk');

        $this->assertMatchesRegularExpression(
            '/^\s*COMPOSE_PROJECT_NAME := \$\(shell bash "\$\(PROJECT_ROOT\)\/scripts\/deploy\/lib\/prod-env\.sh" '
            . 'project "\$\(PROD_ENV_PATH\)" "\$\(PROJECT_ROOT\)"\)$/m',
            $config,
        );
        $this->assertMatchesRegularExpression(
            '/^\s*PROD_TLS_PROFILE := \$\(shell bash "\$\(PROJECT_ROOT\)\/scripts\/deploy\/lib\/prod-env\.sh" '
            . 'profile "\$\(PROD_ENV_PATH\)"\)$/m',
            $config,
        );
        $this->assertMatchesRegularExpression(
            '/^ifeq \(\$\(PROD_TLS_PROFILE\),internal\)\n\s*COMPOSE_FILES \+= -f compose\.rehearsal\.yaml\nendif$/m',
            $config,
        );
    }

    /**
     * `compose.rehearsal.yaml` joins the prod stack for a tls-internal env file (scripts/deploy/lib/prod-env.sh)
     * and is outside every stack ScheduleConsumptionGateTest reads, so it must stay outside the scheduling surface
     * too: no
     * service of its own may run a `messenger:consume`, declare a replica count, or reuse a worker's name and
     * so merge over it. Its one service today is a mailpit.
     */
    #[Test]
    public function theRehearsalOverlayCarriesNoWorker(): void
    {
        $parsed = Yaml::parseFile($this->root() . '/compose.rehearsal.yaml');
        $this->assertIsArray($parsed);
        $services = \is_array($parsed['services'] ?? null) ? $parsed['services'] : [];
        $this->assertNotSame([], $services, 'compose.rehearsal.yaml declares no services to read.');

        foreach ($services as $name => $definition) {
            $this->assertIsArray($definition);
            $command = \json_encode($definition['command'] ?? null);
            $this->assertNotContains($name, ['php', 'messenger_worker', 'scheduler_worker'], \sprintf(
                'compose.rehearsal.yaml redefines `%s`, merging over a worker this gate never sees.',
                $name,
            ));
            $this->assertStringNotContainsString('messenger:consume', (string) $command, \sprintf(
                'compose.rehearsal.yaml service `%s` consumes a transport outside every stack this gate reads.',
                $name,
            ));
            $deploy = \is_array($definition['deploy'] ?? null) ? $definition['deploy'] : [];
            $this->assertArrayNotHasKey('replicas', $deploy);
        }
    }
}
