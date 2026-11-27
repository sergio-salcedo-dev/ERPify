<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every PHP service of the production stack pins `APP_DEBUG` to the literal `0`.
 *
 * `kernel.debug` is what turns on Doctrine's DBAL logging middleware — which writes every statement's bound
 * parameters, so the login address, onto the container log nothing erases — and Monolog's debug-level records.
 * `compose.prod.yaml` used to set no `APP_DEBUG` at all and rely on Dotenv's `'prod' !== $env` default, so the
 * property held until one `APP_DEBUG` reached the stack from an env file or a command line. Pinning it makes the
 * claim structural; this gate keeps it pinned.
 *
 * A literal and not an interpolation, for the reason `deploy.replicas` and the log bound are: a value the
 * environment can supply looks pinned while the real one sits in a file no gate opens.
 *
 * What a green proves: each service running the production PHP image declares `APP_DEBUG` as the string `0` in
 * `compose.prod.yaml`. What it does not: that a deploy injects nothing over it on a command line (`-e`), or that a
 * service running that image under another file is covered.
 *
 * @internal
 */
#[CoversNothing]
final class ProdDebugPinGateTest extends TestCase
{
    private const string FILE = 'compose.prod.yaml';

    private const string PHP_IMAGE = 'app-php-prod';

    /**
     * Pinned so the sweep cannot go vacuous: a restructured file that stopped naming its PHP services would
     * satisfy every assertion below by having nothing to check.
     *
     * @var list<string>
     */
    private const array EXPECTED_PHP_SERVICES = ['messenger_worker', 'php', 'scheduler_worker'];

    #[Test]
    public function everyPhpServiceOfTheProductionStackPinsDebugOff(): void
    {
        $phpServices = [];

        foreach ($this->services() as $name => $definition) {
            $image = \is_array($definition) ? ($definition['image'] ?? null) : null;

            if (!\is_array($definition) || !\is_string($image) || !\str_contains($image, self::PHP_IMAGE)) {
                continue;
            }

            $phpServices[] = $name;
            $environment = $definition['environment'] ?? null;

            $this->assertIsArray($environment, \sprintf('service "%s" declares no environment map', $name));
            $this->assertArrayHasKey(
                'APP_DEBUG',
                $environment,
                \sprintf('service "%s" leaves APP_DEBUG to Dotenv\'s default instead of pinning it', $name),
            );
            $this->assertSame(
                '0',
                $environment['APP_DEBUG'],
                \sprintf(
                    'service "%s" does not pin APP_DEBUG to the literal string "0": an interpolation or a '
                    . 'different value lets one env entry turn on DBAL parameter logging in production',
                    $name,
                ),
            );
        }

        \sort($phpServices);
        $this->assertSame(self::EXPECTED_PHP_SERVICES, $phpServices, 'the sweep found a different set of PHP services');
    }

    /**
     * @return array<string, mixed>
     */
    private function services(): array
    {
        $apiRoot = \dirname(__DIR__, 3);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            if (\is_file($candidate . '/' . self::FILE)) {
                $parsed = Yaml::parseFile($candidate . '/' . self::FILE);

                if (\is_array($parsed) && \is_array($parsed['services'] ?? null)) {
                    /** @var array<string, mixed> $services */
                    $services = $parsed['services'];

                    return $services;
                }

                $this->fail(\sprintf('"%s" declares no services to read.', self::FILE));
            }
        }

        $this->fail(
            'The root compose files are not reachable, so this gate cannot check anything. Inside the container they '
            . 'come from the read-only `./` bind mount at /app/repo declared in compose.dev.yaml — restore it rather '
            . 'than relaxing this failure into a skip.',
        );
    }
}
