<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Only the test environment may admit a transport class for `async` without the Doctrine-connection check.
 *
 * `AsyncTransportValidator` waives the check for every class listed in
 * `erpify.messenger.accepted_async_transport_classes`, so one line adding a class to that list outside test
 * disables the publish guard's transport half in production with every other gate green. The base
 * declaration is therefore pinned to an empty list, and among the environment service files
 * (`config/services*.yaml`) the test one is the only file allowed a non-empty value — a `services_dev.yaml`
 * or `services_prod.yaml` overriding it is refused.
 *
 * A green proves what these YAML files declare. It does not see a value set by a PHP-configured container,
 * a `config/packages` file, an environment-variable parameter or a compiler pass.
 *
 * @internal
 */
#[CoversNothing]
final class AcceptedAsyncTransportClassesGateTest extends TestCase
{
    private const string PARAMETER = 'erpify.messenger.accepted_async_transport_classes';

    private const string TEST_FILE = 'services_test.yaml';

    #[Test]
    public function theBaseDeclarationIsExactlyAnEmptyList(): void
    {
        $parameters = $this->parametersOf('services.yaml');

        $this->assertArrayHasKey(self::PARAMETER, $parameters, 'services.yaml no longer declares the parameter.');
        $this->assertSame([], $parameters[self::PARAMETER], 'The base value must be an empty list.');
    }

    #[Test]
    public function onlyTheTestServicesFileDeclaresANonEmptyValue(): void
    {
        $files = \glob(\dirname(__DIR__, 3) . '/config/services*.yaml');
        $this->assertIsArray($files);
        $this->assertContains(
            \dirname(__DIR__, 3) . '/config/' . self::TEST_FILE,
            $files,
            'The sweep does not reach the test services file, so it proves nothing.',
        );

        foreach ($files as $file) {
            $name = \basename($file);
            $parameters = $this->parametersOf($name);

            if (self::TEST_FILE === $name || !\array_key_exists(self::PARAMETER, $parameters)) {
                continue;
            }

            $this->assertSame(
                [],
                $parameters[self::PARAMETER],
                \sprintf('%s widens %s outside the test environment.', $name, self::PARAMETER),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parametersOf(string $file): array
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/' . $file, Yaml::PARSE_CUSTOM_TAGS);
        $this->assertIsArray($parsed);

        $parameters = $parsed['parameters'] ?? [];
        $this->assertIsArray($parameters);

        /** @var array<string, mixed> $parameters */
        return $parameters;
    }
}
