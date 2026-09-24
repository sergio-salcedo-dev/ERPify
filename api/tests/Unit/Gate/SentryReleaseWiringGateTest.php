<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every prod surface that reports to Sentry receives the one release `make` resolves for the deploy.
 *
 * The release is the commit SHA `make/config.mk` exports for `ENV=prod|staging`. It travels by hand-written
 * wiring: an environment entry beside each `SENTRY_DSN` in `compose.prod.yaml`, a build arg on the `pwa`
 * service, and an `ARG`/`ENV` pair in the `pwa` builder stage that `next build` reads. Drop any one link and
 * the API and the PWA report different releases, or one reports none. Nothing else notices: the PWA's unit
 * tests call the release logic with hand-built environments and never read the plumbing.
 *
 * A green proves the DECLARATION is complete. It does not prove a deploy ran through `make`: a bare
 * `docker compose` run or a `--no-build` start can still split the release, and
 * `docs/deployment-guide.md` → Observability records those paths rather than gating them.
 *
 * @internal
 */
#[CoversNothing]
final class SentryReleaseWiringGateTest extends TestCase
{
    private const string PROD_COMPOSE = 'compose.prod.yaml';

    private const string PWA_DOCKERFILE = 'pwa/Dockerfile';

    private const string MAKE_CONFIG = 'make/config.mk';

    private const string RELEASE_INTERPOLATION = '${SENTRY_RELEASE:-}';

    /**
     * The API services that report to Sentry, pinned so the sweep below cannot pass by finding none.
     *
     * @var list<string>
     */
    private const array EXPECTED_REPORTING_SERVICES = ['messenger_worker', 'php', 'scheduler_worker'];

    #[Test]
    public function everyServiceReportingToSentryReceivesTheRelease(): void
    {
        $reporting = [];

        foreach ($this->prodServices() as $name => $definition) {
            $environment = \is_array($definition) && \is_array($definition['environment'] ?? null)
                ? $definition['environment']
                : [];

            if (!\array_key_exists('SENTRY_DSN', $environment)) {
                continue;
            }

            $reporting[] = $name;
            $this->assertSame(
                self::RELEASE_INTERPOLATION,
                $environment['SENTRY_RELEASE'] ?? null,
                \sprintf(
                    'Service "%s" reports to Sentry but does not receive SENTRY_RELEASE as %s, so its events '
                    . 'would carry a different release from the rest of the deploy, or none.',
                    $name,
                    self::RELEASE_INTERPOLATION,
                ),
            );
        }

        \sort($reporting);
        $this->assertSame(
            self::EXPECTED_REPORTING_SERVICES,
            $reporting,
            'The services declaring SENTRY_DSN are not the ones this gate was written against. Add the new one '
            . 'to EXPECTED_REPORTING_SERVICES once it receives SENTRY_RELEASE.',
        );
    }

    #[Test]
    public function thePwaBuildReceivesTheReleaseAndTheRepository(): void
    {
        $pwa = $this->prodServices()['pwa'] ?? null;
        $this->assertIsArray($pwa, \sprintf('"%s" declares no pwa service.', self::PROD_COMPOSE));

        $args = \is_array($pwa['build'] ?? null) && \is_array($pwa['build']['args'] ?? null)
            ? $pwa['build']['args']
            : [];

        $this->assertSame(self::RELEASE_INTERPOLATION, $args['SENTRY_RELEASE'] ?? null);
        $this->assertSame('${SENTRY_REPOSITORY:-}', $args['SENTRY_REPOSITORY'] ?? null);
    }

    /**
     * Read per stage rather than per file: an `ARG` declared in another stage, or after the build step, is
     * not visible to `next build`, and a whole-file search would accept both.
     */
    #[Test]
    public function theBuilderStageExposesBothBeforeTheBuild(): void
    {
        $stage = $this->builderStage();
        $build = $this->lineIndex($stage, '/npm run build/');

        foreach (['SENTRY_RELEASE', 'SENTRY_REPOSITORY'] as $variable) {
            $arg = $this->lineIndex($stage, \sprintf('/^ARG %s=?$/', $variable));
            $env = $this->lineIndex($stage, \sprintf('/^ENV %1$s=\$\{%1$s\}$/', $variable));

            $this->assertLessThan($build, $arg, \sprintf('ARG %s must precede the build step.', $variable));
            $this->assertLessThan($build, $env, \sprintf('ENV %s must precede the build step.', $variable));
        }
    }

    #[Test]
    public function makeExportsTheReleaseForProdAndStaging(): void
    {
        $config = $this->read(self::MAKE_CONFIG);

        $this->assertMatchesRegularExpression(
            '/^ifneq \(\$\(filter \$\(ENV\),prod staging\),\)\n'
            . '(?:[^\n]*\n)*?\s*SENTRY_RELEASE := \$\(shell git [^\n]*rev-parse HEAD[^\n]*\)\n'
            . '(?:[^\n]*\n)*?\s*export SENTRY_RELEASE\n/m',
            $config,
            'make/config.mk no longer resolves SENTRY_RELEASE from git HEAD and exports it for ENV=prod|staging.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function prodServices(): array
    {
        $parsed = Yaml::parse($this->read(self::PROD_COMPOSE));

        if (!\is_array($parsed) || !\is_array($parsed['services'] ?? null)) {
            $this->fail(\sprintf('"%s" declares no services to read.', self::PROD_COMPOSE));
        }

        /** @var array<string, mixed> $services */
        $services = $parsed['services'];

        return $services;
    }

    /**
     * @return list<string>
     */
    private function builderStage(): array
    {
        $lines = \array_map(trim(...), \explode("\n", $this->read(self::PWA_DOCKERFILE)));
        $start = $this->lineIndex($lines, '/^FROM \S+ AS builder$/i');
        $stage = [];

        foreach (\array_slice($lines, $start + 1) as $line) {
            if (1 === \preg_match('/^FROM /i', $line)) {
                break;
            }

            $stage[] = $line;
        }

        return $stage;
    }

    /**
     * @param list<string> $lines
     */
    private function lineIndex(array $lines, string $pattern): int
    {
        foreach ($lines as $index => $line) {
            if (1 === \preg_match($pattern, $line)) {
                return $index;
            }
        }

        $this->fail(\sprintf('No line matches %s.', $pattern));
    }

    private function read(string $relativePath): string
    {
        $apiRoot = \dirname(__DIR__, 3);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            if (\is_file($candidate . '/' . $relativePath)) {
                $contents = \file_get_contents($candidate . '/' . $relativePath);
                $this->assertIsString($contents);

                return $contents;
            }
        }

        $this->fail(\sprintf(
            'Cannot find "%s" from %s: the gate reads the repository root, which the php container sees only '
            . 'through the read-only `./` mount at /app/repo in compose.dev.yaml.',
            $relativePath,
            $apiRoot,
        ));
    }
}
