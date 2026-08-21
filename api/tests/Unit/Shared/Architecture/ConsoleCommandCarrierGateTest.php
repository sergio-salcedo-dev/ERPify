<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

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
                . 'before trusting this processor\'s docblock.',
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
        $services = $this->serviceDefinitions();

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
     * @return array<string, mixed>
     */
    private function serviceDefinitions(): array
    {
        // `PARSE_CUSTOM_TAGS`, because the file carries `!tagged_iterator` and the parser refuses it
        // otherwise — the same reason `MonologExclusionDeclarationGateTest` passes the flag.
        $parsed = Yaml::parseFile(\dirname(__DIR__, 4) . '/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);

        if (!\is_array($parsed) || !\is_array($parsed['services'] ?? null)) {
            $this->fail('config/services.yaml declares no services to read.');
        }

        /** @var array<string, mixed> $services */
        $services = $parsed['services'];

        return $services;
    }
}
