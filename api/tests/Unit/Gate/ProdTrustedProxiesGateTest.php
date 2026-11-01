<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
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
 *   - no other overlay of the prod stack declares a value that resolves non-empty;
 *   - the env template ships no active line for it, so a copied template cannot widen it;
 *   - no file under `config/packages` sets `trusted_proxies` itself, which would bypass the variable.
 *
 * **What a green does NOT prove.** It reads the files, never the deploy: a value set in `.env.prod.local`, on a
 * command line or in a service started outside these files is invisible here, and so is a load balancer that
 * a deployment names correctly or wrongly. It never judges a non-empty value an operator chooses on purpose —
 * that is the opt-in, and review is the only control on it.
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
    #[DataProvider('provideProdStackOverlayCases')]
    public function noOtherProdOverlayWidensIt(string $overlay): void
    {
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideProdStackOverlayCases(): iterable
    {
        yield 'the local rehearsal overlay' => ['compose.rehearsal.yaml'];
    }

    #[Test]
    public function theTemplateShipsNoActiveValue(): void
    {
        $active = \preg_grep(
            '/^\s*' . self::VARIABLE . '\s*=/',
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
    public function noPackageConfigurationSetsTheTrustedProxiesItself(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in($this->apiRoot() . '/config/packages')->name('*.yaml') as $file) {
            if (1 === \preg_match('/^\s*trusted_proxies\s*:/m', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, \sprintf(
            'These files set `framework.trusted_proxies` directly, which bypasses %s and the empty prod default '
                . 'this gate pins: %s',
            self::VARIABLE,
            \implode(', ', $offenders),
        ));
    }

    /**
     * What Compose interpolates when the deployment's environment does not set the variable: a literal is
     * itself, `${VAR:-default}` and `${VAR-default}` are their default. Any other spelling is refused rather
     * than guessed, because a reading this gate cannot make is a value it cannot vouch for.
     */
    private function resolvedWhenUnset(mixed $value): string
    {
        $this->assertTrue(
            null === $value || \is_scalar($value),
            \sprintf('%s is not a scalar, so this gate cannot read what it resolves to.', self::VARIABLE),
        );

        $value = null === $value ? '' : (string) $value;

        if (!\str_contains($value, '$')) {
            return $value;
        }

        if (1 !== \preg_match('/^\$\{' . self::VARIABLE . ':?-([^}]*)\}$/', $value, $match)) {
            self::fail(\sprintf(
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
            $entry = \is_scalar($entry) ? (string) $entry : '';
            [$key, $value] = \array_pad(\explode('=', $entry, 2), 2, null);
            $map[$key] = $value;
        }

        return $map;
    }

    private function read(string $relativePath): string
    {
        $root = RepositoryRoot::path();
        $this->assertNotNull(
            $root,
            'Cannot find the repository root: the gate reads it through the read-only `./` mount at /app/repo '
                . 'in compose.dev.yaml.',
        );

        $contents = \file_get_contents($root . '/' . $relativePath);
        $this->assertIsString($contents, \sprintf('Cannot read "%s" at the repository root.', $relativePath));

        return $contents;
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
