<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads what the tree actually declares for the public-access gate: the firewall's `access_control`, how
 * `.public-access-exemptions` classifies it, and every routing source a route can be registered from.
 *
 * The rules applied to all of that live in {@see PublicAccessExemptionRules}, and the registry's grammar in
 * {@see PublicAccessExemptionRegistry} — so the rules stay drivable over synthetic input while this class
 * stays a reader with nothing to decide.
 *
 * @internal test support
 *
 * @phpstan-import-type Classification from PublicAccessExemptionRegistry
 */
final readonly class PublicAccessExemptions
{
    /**
     * What Symfony's MicroKernelTrait imports: `{routes}/{env}/*` and `{routes}/*`, in both formats. A
     * sweep narrower than this is how a live production route sat under an anonymous prefix, green.
     */
    private const array ROUTE_GLOBS = ['/routes/*.%s', '/routes/*/*.%s'];

    public function __construct(private string $apiRoot)
    {
    }

    public static function fromGateLocation(string $gateDirectory): self
    {
        return new self(\dirname($gateDirectory, 3));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function securityConfig(): array
    {
        $parsed = Yaml::parseFile($this->apiRoot . '/config/packages/security.yaml');

        return \is_array($parsed) ? $parsed : [];
    }

    /**
     * The rules that admit an unauthenticated caller, as the matcher each one declares.
     *
     * @return list<string>
     */
    public function declaredExemptions(): array
    {
        return PublicAccessExemptionRules::exemptionsIn($this->securityConfig());
    }

    /**
     * @return array<string, Classification>
     */
    public function registry(): array
    {
        return PublicAccessExemptionRegistry::parse(
            AllowlistFile::entries($this->apiRoot . '/.public-access-exemptions'),
        );
    }

    /**
     * Every YAML routing source: the `config/routes/` tree — its per-environment subdirectories included —
     * and `config/routes.yaml` itself, which is where the attribute-routing resources live and so the one
     * place a route is registered without naming its own path.
     *
     * @return array<string, mixed>
     */
    public function routeConfigs(): array
    {
        $configs = [];

        foreach ($this->routeFiles('yaml') as $path) {
            $configs[$this->relative($path)] = Yaml::parseFile($path);
        }

        $root = $this->apiRoot . '/config/routes.yaml';

        if (\is_file($root)) {
            $configs[$this->relative($root)] = Yaml::parseFile($root);
        }

        return $configs;
    }

    /**
     * PHP routing sources, as text. Symfony imports them alongside the YAML ones and they cannot be read as
     * data, so the gate matches their source rather than pretending the format does not exist.
     *
     * @return array<string, string>
     */
    public function phpRouteSources(): array
    {
        $sources = [];

        foreach ($this->routeFiles('php') as $path) {
            $contents = \file_get_contents($path);
            $sources[$this->relative($path)] = false === $contents ? '' : $contents;
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private function routeFiles(string $extension): array
    {
        $files = [];

        foreach (self::ROUTE_GLOBS as $glob) {
            $files = [...$files, ...(\glob($this->apiRoot . '/config' . \sprintf($glob, $extension)) ?: [])];
        }

        return $files;
    }

    private function relative(string $path): string
    {
        return \substr($path, \strlen($this->apiRoot) + 1);
    }
}
