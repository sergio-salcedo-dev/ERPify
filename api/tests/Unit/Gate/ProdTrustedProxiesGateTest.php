<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Production trusts no proxy unless a deployment names one. FrankenPHP is the edge: Caddy terminates the
 * client's connection and runs PHP in-process, so no hop in front of Symfony can vouch for an
 * `X-Forwarded-For`, and the header arrives exactly as the client wrote it. `compose.yaml` trusts every RFC1918
 * range for development; a prod stack inheriting that hands the header to any connection whose peer is a
 * private address, and with it the key of every IP-scoped limiter and the `ip` the audit trail and the
 * session registry record.
 *
 * Four directions, each a way the value comes back wider:
 *   - `compose.prod.yaml` declares it on `php`, resolving EMPTY when the environment does not set it — an
 *     absent declaration inherits the development ranges from `compose.yaml`;
 *   - no other root compose overlay (`compose*.y[a]ml` beside the base, dev and prod files — the rehearsal
 *     overlay today) declares a value that resolves non-empty, found by glob so a new overlay is read by default;
 *   - the env template ships no active line for it, in either spelling Compose and `prod-env.sh` accept;
 *   - nothing sets the proxies itself: no `trusted_proxies` key in any YAML or PHP file under `config/`, and no
 *     `Request::setTrustedProxies()` under `src/`, `public/` or `config/`, either of which bypasses the variable.
 *
 * **What a green does NOT prove.** It reads the files, never the deploy: a value set in `.env.prod.local`, on a
 * command line or in a service started outside these files is invisible here, and so is a load balancer that
 * a deployment names correctly or wrongly. It never judges a non-empty value an operator chooses on purpose —
 * that is the opt-in, and review is the only control on it. Its config and source scans are text matches, so a
 * key or call reached through a constant, a string built at runtime or an alias is invisible to them.
 *
 * @internal
 */
#[CoversNothing]
final class ProdTrustedProxiesGateTest extends TestCase
{
    private const string VARIABLE = 'SYMFONY_TRUSTED_PROXIES';

    private const string PROD_OVERLAY = 'compose.prod.yaml';

    private const string TEMPLATE = '.env.prod.example';

    #[Test]
    public function theProdOverlayDeclaresAnEmptyDefaultOnTheEdgeService(): void
    {
        $environment = $this->environmentsIn(self::PROD_OVERLAY)['php'] ?? [];

        $this->assertArrayHasKey(self::VARIABLE, $environment, \sprintf(
            '%s declares no %s on `php`, so prod inherits the RFC1918 ranges compose.yaml trusts for development '
                . 'and a client behind any private peer chooses its own IP.',
            self::PROD_OVERLAY,
            self::VARIABLE,
        ));
        $this->assertSame('', $this->resolvedWhenUnset($environment[self::VARIABLE]), \sprintf(
            '%s on `php` in %s resolves to a non-empty value when the deployment sets none. FrankenPHP is the '
                . 'edge, so the default must trust no hop; a real load balancer is named by the deployment.',
            self::VARIABLE,
            self::PROD_OVERLAY,
        ));
    }

    #[Test]
    public function noOtherOverlayWidensIt(): void
    {
        $overlays = $this->otherOverlays();

        // The rehearsal overlay exists today, so an empty sweep means the glob or the root broke, not that the
        // tree holds nothing to read.
        $this->assertContains('compose.rehearsal.yaml', $overlays, 'The overlay sweep found no overlay at all.');

        foreach ($overlays as $overlay) {
            foreach ($this->environmentsIn($overlay) as $service => $environment) {
                if (!\array_key_exists(self::VARIABLE, $environment)) {
                    continue;
                }

                $this->assertSame('', $this->resolvedWhenUnset($environment[self::VARIABLE]), \sprintf(
                    '%s sets %s on `%s` to a value that resolves non-empty, overriding the empty prod default.',
                    $overlay,
                    self::VARIABLE,
                    $service,
                ));
            }
        }
    }

    #[Test]
    public function theTemplateShipsNoActiveValue(): void
    {
        $active = \preg_grep(
            '/^\s*(?:export\s+)?' . self::VARIABLE . '\s*[=:]/',
            \explode("\n", $this->read(self::TEMPLATE)),
        );

        $this->assertSame([], false === $active ? [] : \array_values($active), \sprintf(
            '%s sets %s on an active line, so every deployment copying it trusts that range. Keep it commented: '
                . 'only a deployment with a real load balancer names one.',
            self::TEMPLATE,
            self::VARIABLE,
        ));
    }

    #[Test]
    public function nothingSetsTheTrustedProxiesItself(): void
    {
        $offenders = [];

        // `reference.php` documents every option, this one included, and configures nothing.
        $configuration = Finder::create()->files()->in($this->apiRoot() . '/config')
            ->name(['*.yaml', '*.yml', '*.php'])->notName('reference.php')
        ;

        foreach ($configuration as $file) {
            if (1 === \preg_match('/trusted_?proxies[\'"]?\s*(?::|=>|\()/i', $file->getContents())) {
                $offenders[] = 'config/' . $file->getRelativePathname();
            }
        }

        $code = Finder::create()->files()->in([$this->apiRoot() . '/src', $this->apiRoot() . '/public'])
            ->name('*.php')
        ;

        foreach ($code as $file) {
            if (\str_contains($file->getContents(), 'setTrustedProxies(')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, \sprintf(
            'These files set the trusted proxies themselves, which bypasses %s and the empty prod default this '
                . 'gate pins: %s',
            self::VARIABLE,
            \implode(', ', $offenders),
        ));
    }

    /**
     * What Compose interpolates when the deployment's environment does not set the variable: a literal is
     * itself, `${VAR:-default}` and `${VAR-default}` are their default. Any other spelling is refused rather
     * than guessed, because a reading this gate cannot make is a value it cannot vouch for — and so is a bare
     * key, which Compose leaves UNSET in the container rather than empty, handing the value to whatever the
     * image's dumped `.env` holds.
     */
    private function resolvedWhenUnset(mixed $value): string
    {
        $this->assertIsScalar($value, \sprintf(
            "%s carries no value, so the container receives it unset and falls back to the image's dumped `.env`. "
                . 'Spell the empty default explicitly: ${%s:-}.',
            self::VARIABLE,
            self::VARIABLE,
        ));

        $value = (string) $value;

        if (!\str_contains($value, '$')) {
            return $value;
        }

        if (1 !== \preg_match('/^\$\{' . self::VARIABLE . ':?-([^}]*)\}$/', $value, $match)) {
            $this->fail(\sprintf(
                '%s is spelled "%s", which this gate cannot resolve. Use a literal or ${%s:-<default>}.',
                self::VARIABLE,
                $value,
                self::VARIABLE,
            ));
        }

        return $match[1];
    }

    /**
     * Each service's environment as a map, whichever of Compose's two spellings it uses.
     *
     * @return array<string, array<string, mixed>>
     */
    private function environmentsIn(string $composeFile): array
    {
        $parsed = Yaml::parse($this->read($composeFile));
        $this->assertIsArray($parsed, \sprintf('%s does not parse to a map.', $composeFile));

        $services = $parsed['services'] ?? [];
        $this->assertIsArray(
            $services,
            \sprintf('%s declares `services` as something other than a map.', $composeFile),
        );

        $environments = [];

        foreach ($services as $name => $service) {
            $environment = \is_array($service) ? ($service['environment'] ?? []) : [];
            $environments[(string) $name] = \is_array($environment) ? $this->asMap($environment) : [];
        }

        return $environments;
    }

    /**
     * @param array<array-key, mixed> $environment
     *
     * @return array<string, mixed>
     */
    private function asMap(array $environment): array
    {
        if (!\array_is_list($environment)) {
            $map = [];

            foreach ($environment as $key => $value) {
                $map[(string) $key] = $value;
            }

            return $map;
        }

        $map = [];

        foreach ($environment as $entry) {
            $parts = \explode('=', \is_scalar($entry) ? (string) $entry : '', 2);
            $map[$parts[0]] = $parts[1] ?? null;
        }

        return $map;
    }

    /**
     * Every root compose file except the three whose roles are fixed: the base trusts the development ranges
     * on purpose, dev never ships, and the prod overlay is pinned by its own test.
     *
     * @return list<string>
     */
    private function otherOverlays(): array
    {
        $overlays = [];

        $files = Finder::create()->files()->in($this->root())->depth(0)->name(['compose*.yaml', 'compose*.yml']);

        foreach ($files as $file) {
            $overlays[] = $file->getFilename();
        }

        \sort($overlays);

        return \array_values(\array_diff($overlays, ['compose.yaml', 'compose.dev.yaml', self::PROD_OVERLAY]));
    }

    private function root(): string
    {
        $root = RepositoryRoot::path();
        $this->assertNotNull(
            $root,
            'Cannot find the repository root: the gate reads it through the read-only `./` mount at /app/repo '
                . 'in compose.dev.yaml.',
        );

        return $root;
    }

    private function read(string $relativePath): string
    {
        $contents = \file_get_contents($this->root() . '/' . $relativePath);
        $this->assertIsString($contents, \sprintf('Cannot read "%s" at the repository root.', $relativePath));

        return $contents;
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
