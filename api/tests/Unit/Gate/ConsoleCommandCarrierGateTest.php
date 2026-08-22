<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Monitoring\Infrastructure\Monolog\ConsoleCommandRedactionProcessor;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Console\EventListener\ErrorListener;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\Component\Yaml\Yaml;

/**
 * The two halves {@see ConsoleCommandRedactionProcessor} cannot prove about itself.
 *
 * Every assertion in `ConsoleCommandRedactionProcessorTest` runs over a record the test fabricates, so it
 * proves what the rule DOES with a `command` key and nothing about whether anybody still writes one, or
 * whether the rule is reachable from the loggers that carry it. Both directions fail silently: a
 * `symfony/console` bump that renames the key leaves the processor guarding a spelling nothing produces while
 * the argv flows to `php://stderr` unredacted, and a `_defaults` edit that drops the tag leaves it enrolled
 * nowhere with the same green suite. This is the sibling of `PersonDataCarrierEmitterGateTest`, for the
 * carrier that one deliberately excludes.
 *
 * **What a green does not prove.** That the listener still LOGS the key — a literal surviving anywhere in the
 * file satisfies the first half, comments and unrelated arrays included. That the enrolment WORKS, which is a
 * property of the compiled container: this reads the declaration in `config/services.yaml`, so it is blind to
 * a container that fails to build the definition for any other reason. And nothing here sees the direction
 * with no instrument at all — a dependency that starts writing the full argv under a key nobody declared.
 *
 * @internal
 */
#[CoversNothing]
final class ConsoleCommandCarrierGateTest extends TestCase
{
    /**
     * The context key the console error listener writes a failing process's whole argv under, on both of its
     * paths — `ConsoleErrorEvent` at CRITICAL and any non-zero exit at DEBUG.
     */
    private const string CARRIER = 'command';

    /**
     * The tag that puts a processor on every channel LOGGER, which is what keeps the redaction ahead of the
     * `fingers_crossed` buffer and ahead of the handler-scoped `PsrLogMessageProcessor` that interpolates
     * `{command}` into the message.
     */
    private const string PROCESSOR_TAG = 'monolog.processor';

    #[Test]
    public function theInstalledListenerStillWritesTheCarrierThisProcessorGuards(): void
    {
        $file = (new ReflectionClass(ErrorListener::class))->getFileName();
        $this->assertIsString($file);

        $source = \file_get_contents($file);
        $this->assertIsString($source);

        $this->assertStringContainsString(
            \sprintf("'%s' => ", self::CARRIER),
            $source,
            \sprintf(
                '%s no longer writes a "%s" context key, so the processor guards a spelling nobody produces '
                . 'while whatever replaced it reaches php://stderr unredacted.',
                ErrorListener::class,
                self::CARRIER,
            ),
        );
    }

    /**
     * The listener composes that value out of the INPUT, which is what makes it argv rather than a name. A
     * bump that reduced it to the command name would make this processor inert — harmless, but it should be
     * a decision rather than a discovery, and the docblock's claim about what is being redacted would be
     * false until someone noticed.
     */
    #[Test]
    public function theCarrierIsStillComposedFromTheWholeInput(): void
    {
        $file = (new ReflectionClass(ErrorListener::class))->getFileName();
        $this->assertIsString($file);

        $source = \file_get_contents($file);
        $this->assertIsString($source);

        $this->assertStringContainsString(
            '(string) $event->getInput()',
            $source,
            \sprintf(
                '%s no longer stringifies the whole input into its log context. Re-read what it writes now '
                . "before trusting this processor's docblock.",
                ErrorListener::class,
            ),
        );
    }

    /**
     * Enrolment IS the control. A processor nothing pushes onto a logger redacts nothing, and every
     * behavioural assertion over it stays green — which is why the sibling rule's own checklist entry records
     * enrolment as the property that had to be measured on four separate arms.
     */
    #[Test]
    public function theProcessorIsEnrolledOnEveryChannelLogger(): void
    {
        $services = $this->rootDefinitions();

        $this->assertArrayHasKey(
            ConsoleCommandRedactionProcessor::class,
            $services,
            'the processor has no service definition, so nothing but autoconfiguration enrols it',
        );

        $definition = $services[ConsoleCommandRedactionProcessor::class];
        $this->assertIsArray($definition);

        $tags = $definition['tags'] ?? [];
        $this->assertIsArray($tags);

        $this->assertContains(
            self::PROCESSOR_TAG,
            $tags,
            \sprintf('the processor is not tagged "%s", so no channel logger carries it', self::PROCESSOR_TAG),
        );
    }

    /**
     * The root block is not the whole file, and it is not the whole configuration either — which is a measured
     * hole rather than a hypothetical one. `MicroKernelTrait` imports `config/services_{env}.yaml` after
     * `config/services.yaml` (`services_dev.yaml` and `services_test.yaml` already exist here), and a
     * `when@prod:` block inside the base file is applied later than its own root. Either can redeclare this
     * service with `autoconfigure: false` and `tags: []`, which unenrols it in production ALONE. Appending
     * exactly that to `config/services.yaml` was measured leaving the assertion above green.
     *
     * The sibling gate parses per-environment overrides for the same reason and says so at its own method:
     * the override is applied after the base file and wins.
     */
    #[Test]
    public function noEnvironmentOverrideUnenrolsIt(): void
    {
        $overrides = $this->overrideDefinitions();

        // Not merely a guard against emptiness reading as compliance: it is what makes this test assert
        // something on a tree that happens to declare no override for THIS service, which is today's tree.
        // Without it the loop body never runs and PHPUnit reports the test risky, which `failOnRisky` turns
        // into a red for the wrong reason.
        $this->assertNotEmpty(
            $overrides,
            'no per-environment services block was found at all, so this check is reading nothing — '
            . '`config/services_dev.yaml` and `config/services_test.yaml` exist, so the reader is broken',
        );

        foreach ($overrides as $origin => $services) {
            if (!\array_key_exists(ConsoleCommandRedactionProcessor::class, $services)) {
                continue;
            }

            $definition = $services[ConsoleCommandRedactionProcessor::class];
            $tags = \is_array($definition) ? ($definition['tags'] ?? []) : [];

            $this->assertIsArray($tags);
            $this->assertContains(
                self::PROCESSOR_TAG,
                $tags,
                \sprintf(
                    '"%s" redeclares the processor without the "%s" tag, which unenrols it in that '
                    . 'environment while the root declaration keeps every other assertion green.',
                    $origin,
                    self::PROCESSOR_TAG,
                ),
            );
        }
    }

    /**
     * The check above looks the processor up BY FQCN, so every unenrolling shape that never spells the name
     * passes it. A namespace prefix is the one that reaches this service: `Erpify\: { resource: '../src/' }`
     * inside a `when@prod:` block re-registers the class from the filesystem, and a re-registration REPLACES
     * the root definition rather than merging with it — the explicit `tags:` there is gone. What decides
     * enrolment from that point is the scope's own `_defaults.autoconfigure`, because the class implements
     * `Monolog\Processor\ProcessorInterface`, which MonologBundle autoconfigures with the tag.
     *
     * And the safe-looking half is the trap: `autoconfigure` defaults to **false** when `_defaults` omits it.
     * A scope that merely forgets the line unenrols the processor exactly as thoroughly as one that writes
     * `autoconfigure: false` on purpose, with the per-class lookup above green throughout. So the assertion
     * is on the positive declaration, not on the absence of a negative one.
     *
     * Today's overrides register `Erpify\Tests\DataFixtures\` and `Erpify\Tests\Behat\Context\`, neither of
     * which covers this class — so this refuses a shape the tree does not have, which is the point: the
     * per-class lookup could not report it if it arrived.
     */
    #[Test]
    public function noOverrideReRegistersItThroughANamespacePrefix(): void
    {
        $overrides = $this->overrideDefinitions();

        // Two guards before the sweep, and neither is ceremony: today's tree registers no covering
        // prefix, so the loop below asserts NOTHING on it — and `failOnRisky` turns a test with no
        // assertions into a red for the wrong reason. They are also the anti-vacuity pin. The first
        // says the reader still finds the override scopes; the second says it still finds PREFIXES
        // in them, which is the shape being judged. A parser change that yielded neither would
        // otherwise leave this green over exactly the blind spot it was written to close.
        $this->assertNotEmpty(
            $overrides,
            'no per-environment services block was found at all, so this check is reading nothing — '
            . '`config/services_dev.yaml` and `config/services_test.yaml` exist, so the reader is broken',
        );

        $prefixes = [];

        foreach ($overrides as $origin => $services) {
            foreach (\array_keys($services) as $id) {
                if (\str_ends_with($id, '\\')) {
                    $prefixes[] = $origin . ' → ' . $id;
                }
            }
        }

        $this->assertNotEmpty(
            $prefixes,
            'no override registers a namespace prefix at all, so the check below judges nothing — '
            . 'the per-environment files register `Erpify\Tests\DataFixtures\` and '
            . '`Erpify\Tests\Behat\Context\`, so the reader is broken',
        );

        foreach ($overrides as $origin => $services) {
            $autoconfigures = $this->autoconfiguresByDefault($services);

            foreach (\array_keys($services) as $id) {
                if (!\str_ends_with($id, '\\') || !\str_starts_with(ConsoleCommandRedactionProcessor::class, $id)) {
                    continue;
                }

                $this->assertTrue(
                    $autoconfigures,
                    \sprintf(
                        '"%s" re-registers "%s", which covers the processor and drops the explicit "%s" tag '
                        . 'the root file gives it — and the scope does not declare `_defaults.autoconfigure: '
                        . 'true`, so nothing puts the tag back. The per-class check cannot see this: the '
                        . 'service is never named.',
                        $origin,
                        $id,
                        self::PROCESSOR_TAG,
                    ),
                );
            }
        }
    }

    /**
     * Whether a scope autoconfigures what it registers. Absent is FALSE — Symfony's own default — which is
     * why this reads the value rather than asking whether it was set to false.
     *
     * @param array<string, mixed> $services
     */
    private function autoconfiguresByDefault(array $services): bool
    {
        $defaults = $services['_defaults'] ?? null;

        return \is_array($defaults) && true === ($defaults['autoconfigure'] ?? false);
    }

    /**
     * The tag makes the rule reachable; this makes it reachable IN PRODUCTION. An attribute conditioning the
     * class into or out of a named environment leaves every assertion here and in the processor's own test
     * green while removing the redaction from the only environment whose sink has no owner — the failure mode
     * `CLAUDE.md` records under "Declaring a class out of production", and the property
     * `PersonDataRedactionArrivalTest` pins for the sibling rule. Both attributes, because `WhenNot` does not
     * extend `When`.
     */
    #[Test]
    public function theProcessorIsNotConditionedIntoOrOutOfAnyEnvironment(): void
    {
        $reflection = new ReflectionClass(ConsoleCommandRedactionProcessor::class);

        $this->assertSame(
            [],
            $reflection->getAttributes(When::class, ReflectionAttribute::IS_INSTANCEOF),
            'the rule is conditioned into named environments, so production may not have it',
        );
        $this->assertSame(
            [],
            $reflection->getAttributes(WhenNot::class, ReflectionAttribute::IS_INSTANCEOF),
            'the rule is conditioned out of a named environment, so production may not have it',
        );
    }

    /**
     * Every `services:` map that is NOT the base file's root: the `when@<env>:` blocks inside it, and the
     * per-environment `config/services_<env>.yaml` files the kernel imports after it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function overrideDefinitions(): array
    {
        $configDir = \dirname(__DIR__, 3) . '/config';
        $overrides = $this->whenBlocksOf($configDir . '/services.yaml');

        foreach (\glob($configDir . '/services_*.yaml') ?: [] as $file) {
            $services = $this->servicesOf(Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS));

            if (null !== $services) {
                $overrides['config/' . \basename($file)] = $services;
            }
        }

        return $overrides;
    }

    /**
     * The `when@<env>:` blocks inside the base file, which the kernel applies after its root.
     *
     * @return array<string, array<string, mixed>>
     */
    private function whenBlocksOf(string $file): array
    {
        $parsed = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);
        $blocks = [];

        foreach (\is_array($parsed) ? $parsed : [] as $key => $block) {
            if (!\is_string($key) || !\str_starts_with($key, 'when@')) {
                continue;
            }

            $services = $this->servicesOf($block);

            if (null !== $services) {
                $blocks['config/services.yaml → ' . $key] = $services;
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function servicesOf(mixed $block): ?array
    {
        $services = \is_array($block) ? ($block['services'] ?? null) : null;

        if (!\is_array($services)) {
            return null;
        }

        // Re-keyed as strings rather than annotated as such: a YAML map can carry a numeric-looking key, and
        // asserting the shape in a docblock the parser cannot honour is the `@var`-instead-of-declare smell.
        $byServiceId = [];

        foreach ($services as $id => $definition) {
            $byServiceId[(string) $id] = $definition;
        }

        return $byServiceId;
    }

    /**
     * @return array<string, mixed>
     */
    private function rootDefinitions(): array
    {
        // `PARSE_CUSTOM_TAGS`, because the file carries `!tagged_iterator` and the parser refuses it
        // otherwise — the same reason `MonologExclusionDeclarationGateTest` passes the flag.
        $parsed = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);

        if (!\is_array($parsed) || !\is_array($parsed['services'] ?? null)) {
            $this->fail('config/services.yaml declares no services to read.');
        }

        /** @var array<string, mixed> $services */
        $services = $parsed['services'];

        return $services;
    }
}
