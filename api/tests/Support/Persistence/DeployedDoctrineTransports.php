<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Persistence\ConnectionRegistry;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface as ServiceLocator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Yaml\Yaml;

/**
 * The Doctrine Messenger transports the DEPLOYED configuration builds, rebuilt inside a test kernel whose own
 * `when@test` block swaps every transport for `in-memory://`.
 *
 * They come from the base `transports:` block of `config/packages/messenger.yaml`, which is what every
 * environment but `test` runs — asserted, not assumed: a `when@<env>` other than `when@test` that overrides
 * `framework.messenger` fails here. Each DSN goes through Symfony's own {@see DoctrineTransportFactory}, and a
 * transport that factory does not support is left out, exactly as the deployed container would never hand it
 * to Doctrine's schema listener.
 *
 * A `%env(NAME)%` DSN is resolved by the booted container's own env var processor — the service
 * `Container::getEnv()` delegates to — so the value is the one the deployed container would read, `env(NAME)`
 * parameter defaults included. Any other placeholder fails rather than being guessed at.
 *
 * Every guard is a PHPUnit assertion, so a broken premise reads as a test failure with its reason.
 *
 * @internal test support
 */
final readonly class DeployedDoctrineTransports
{
    private const string MESSENGER_CONFIG = '/config/packages/messenger.yaml';

    private const string ENV_PLACEHOLDER = '/^%env\((\w+)\)%$/';

    private const string ENV_PROCESSORS = 'container.env_var_processors_locator';

    public function __construct(
        private string $messengerConfigFile,
        private ConnectionRegistry $registry,
        private EnvVarProcessorInterface $envVarProcessor,
    ) {
    }

    public static function fromContainer(ContainerInterface $container): self
    {
        $registry = $container->get('doctrine');
        Assert::assertInstanceOf(ConnectionRegistry::class, $registry);

        $projectDir = $container->getParameter('kernel.project_dir');
        Assert::assertIsString($projectDir);

        $processors = $container->get(self::ENV_PROCESSORS);
        Assert::assertInstanceOf(ServiceLocator::class, $processors);
        $processor = $processors->get('string');
        Assert::assertInstanceOf(EnvVarProcessorInterface::class, $processor);

        return new self($projectDir . self::MESSENGER_CONFIG, $registry, $processor);
    }

    /**
     * @return list<DoctrineTransport>
     */
    public function transports(): array
    {
        $config = Yaml::parseFile($this->messengerConfigFile);
        Assert::assertIsArray($config);
        $this->refuseEnvironmentOverrides($config);

        $factory = new DoctrineTransportFactory($this->registry);
        $transports = [];

        foreach ($this->baseTransports($config) as $name => $definition) {
            $dsn = $this->resolveDsn((string) $name, \is_array($definition) ? $definition['dsn'] ?? null : $definition);
            $options = $this->optionsOf($definition);

            if ($factory->supports($dsn, $options)) {
                $transports[] = $factory->createTransport($dsn, $options, new PhpSerializer());
            }
        }

        Assert::assertNotEmpty(
            $transports,
            'The deployed Messenger config holds no Doctrine transport, so nothing needs supplementing: remove '
            . 'the supplement from this test.',
        );

        return $transports;
    }

    /**
     * The tables the transports add to a schema that does not yet declare them.
     *
     * @param list<DoctrineTransport> $transports
     *
     * @return list<string>
     */
    public static function tablesAddedTo(Schema $schema, Connection $connection, array $transports): array
    {
        $supplemented = $schema;

        foreach ($transports as $transport) {
            // Consulted only for a transport on another connection, which the deployed config has none of;
            // "different database" keeps such a table out, as Symfony's own listener would.
            $supplemented = $transport->configureSchema($supplemented, $connection, static fn (): bool => false);
        }

        return \array_values(\array_diff(self::tableNames($supplemented), self::tableNames($schema)));
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private function baseTransports(array $config): array
    {
        $framework = $config['framework'] ?? null;
        Assert::assertIsArray($framework, 'The Messenger config declares no base `framework:` block.');
        $messenger = $framework['messenger'] ?? null;
        Assert::assertIsArray($messenger, 'The Messenger config declares no base `messenger:` block.');
        $transports = $messenger['transports'] ?? null;
        Assert::assertIsArray($transports, 'The Messenger config declares no base `transports:` block.');

        return $transports;
    }

    /**
     * @param array<mixed> $config
     */
    private function refuseEnvironmentOverrides(array $config): void
    {
        foreach ($config as $key => $block) {
            if (!\str_starts_with((string) $key, 'when@') || 'when@test' === $key || !\is_array($block)) {
                continue;
            }

            Assert::assertArrayNotHasKey(
                'messenger',
                \is_array($block['framework'] ?? null) ? $block['framework'] : [],
                \sprintf('"%s" overrides Messenger, so the base block is not what that environment runs.', $key),
            );
        }
    }

    /**
     * @return array<mixed>
     */
    private function optionsOf(mixed $definition): array
    {
        return \is_array($definition) && \is_array($definition['options'] ?? null) ? $definition['options'] : [];
    }

    /**
     * Only a literal or a bare `%env(NAME)%` is understood.
     */
    private function resolveDsn(string $transport, mixed $dsn): string
    {
        Assert::assertIsString($dsn, \sprintf('Transport "%s" declares no string DSN.', $transport));

        if (1 === \preg_match(self::ENV_PLACEHOLDER, $dsn, $match)) {
            return $this->resolveEnv($transport, $match[1]);
        }

        Assert::assertStringNotContainsString(
            '%',
            $dsn,
            \sprintf('Transport "%s" uses a DSN placeholder this test cannot resolve: "%s".', $transport, $dsn),
        );

        return $dsn;
    }

    /**
     * Called as `Container::getEnv()` calls it for an unprefixed name: the `string` processor, an empty prefix.
     * An undefined variable surfaces as that processor's own `EnvNotFoundException`, which names it.
     */
    private function resolveEnv(string $transport, string $name): string
    {
        $resolved = $this->envVarProcessor->getEnv('', $name, static fn (): never => Assert::fail(
            \sprintf('Resolving %s asked for a nested env var, which a bare `%%env()%%` never does.', $name),
        ));

        Assert::assertIsString(
            $resolved,
            \sprintf('Transport "%s" reads %s, which does not resolve to a string.', $transport, $name),
        );

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private static function tableNames(Schema $schema): array
    {
        return \array_map(
            static fn (Table $table): string => $table->getObjectName()->toString(),
            $schema->getTables(),
        );
    }
}
