<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Monolog\Level;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\EventListener\ErrorListener as ConsoleErrorListener;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Command\FailedMessagesRetryCommand;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Yaml\Yaml;

/**
 * `console` and `messenger` sit INSIDE prod's `fingers_crossed` handler — it excludes only `deprecation` and
 * `observability` — and unlike the API request path, whose 4xx line is `warning` and therefore below
 * `action_level`, both channels have producers that log at levels the handler ACTIVATES on. A record at that
 * level does not merely sit in the buffer: it flushes the fifty preceding records to `php://stderr`.
 *
 * This gate pins what those producers are, so the decision recorded in `PRODUCTION_SECURITY_CHECKLIST.md` §7
 * cannot drift into being false. The enumeration behind it, measured against the installed sources rather
 * than assumed:
 *
 *  - `console` — the error listener's CRITICAL on `ConsoleErrorEvent`. Its carriers are `exception`,
 *    `message` (the throwable's own) and `command`, the full argv. `command` is the one person-data carrier
 *    either channel has, and {@see \Erpify\Shared\Monitoring\Infrastructure\Monolog\ConsoleCommandRedactionProcessor}
 *    is what closes it. The listener's other path is DEBUG, which buffers without activating.
 *  - `messenger` — one CRITICAL, in the retry listener, when an envelope is dropped after its last retry. Its
 *    carriers are the message CLASS NAME, the transport `message_id`, the retry count, and the throwable
 *    plus its message. The payload is deliberately NOT among them: the listener binds the message object at
 *    the top of the method and puts only `$message::class` into the context. `Worker` and the
 *    failure-transport listener log at INFO alone, so neither activates anything.
 *
 * **The decision this pins is "no exclusion".** Excluding either channel from the buffer was the obvious fix
 * and is the wrong one twice over: it would take every command and worker failure out of the deployed log
 * entirely, which is the only place an operator learns a consumer is dying, and it would not even close the
 * argv leak — the DEBUG path writes the same string, and the always-on `console` handler does not carry
 * DEBUG, so the record would simply be lost rather than redacted. Closing the carrier at the carrier, which
 * is what the processor does, leaves the diagnostic and removes the datum.
 *
 * **What a green does not prove.** That the record is harmless — `error` and `exception` carry a throwable's
 * own message, and a throwable composed from a person datum reaches this sink through them on any channel.
 * That residual is undefended by construction and is recorded, not closed. It also proves nothing about the
 * levels these classes log at BELOW the threshold, which buffer and flush with the rest, and nothing about a
 * producer on a third channel: the universe here is the two channels #804 asks about, and it is well founded
 * only because `config/services.yaml` binds no `Erpify\` class to either — the only channel it binds is
 * `observability`, which the handler excludes.
 *
 * @internal
 */
#[CoversNothing]
final class BufferedChannelAmplificationGateTest extends TestCase
{
    /**
     * The channels this gate reasons about, which must stay inside the buffer for the reasoning to apply.
     */
    private const array CHANNELS = ['console', 'messenger'];

    /**
     * Every installed class that logs on one of those channels, against the set of levels it logs at that are
     * at or above the deployed `action_level`.
     *
     * An empty list is a claim, not an omission: it says this class cannot flush the buffer today, and it
     * goes red the moment a bump gives it a way to.
     *
     * **This list is pinned but NOT hand-kept**, which is the correction an adversarial pass forced. It named
     * four classes and called them "every installed class that logs on one of those channels"; the framework
     * tags EIGHT services onto the `messenger` channel, and planting
     * `$this->logger?->error('No handler for message {class}', $context + ['payload' => $message])` in
     * `HandleMessageMiddleware` — which holds the message object — left the gate green. The universe is now
     * derived from the `monolog.logger` tags in the installed vendor configuration and compared against this
     * map in both directions, so a ninth producer cannot arrive unnamed.
     *
     * @var array<class-string, list<string>>
     */
    private const array ACTIVATING_LEVELS = [
        ConsoleErrorListener::class => ['critical'],
        SendFailedMessageForRetryListener::class => ['critical'],
        SendFailedMessageToFailureTransportListener::class => [],
        StopWorkerOnRestartSignalListener::class => [],
        SendMessageMiddleware::class => [],
        HandleMessageMiddleware::class => [],
        ConsumeMessagesCommand::class => [],
        FailedMessagesRetryCommand::class => [],
        Worker::class => [],
    ];

    /**
     * The producer no `monolog.logger` tag names, so the derived universe cannot find it.
     *
     * `Worker` is constructed by `ConsumeMessagesCommand` and handed that command's logger, which IS the
     * `messenger` channel one — so its records land on the channel without the class ever being tagged. Named
     * here rather than folded into the map above, so the completeness check below stays a real question.
     *
     * @var list<class-string>
     */
    private const array UNTAGGED_PRODUCERS = [Worker::class];

    /**
     * Every level a PSR-3 logger exposes as a method, so the census reads what the class CAN do rather than
     * what this file remembered to look for.
     */
    private const array PSR3_LEVELS = [
        'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency',
    ];

    /**
     * The premise of the whole issue: if either channel is later excluded, this gate's reasoning no longer
     * describes the deployment and its verdict has to be revisited rather than inherited.
     */
    #[Test]
    #[DataProvider('provideTheChannelIsStillInsideTheBufferedHandlerCases')]
    public function theChannelIsStillInsideTheBufferedHandler(string $channel): void
    {
        $declared = $this->deployedHandler()['channels'] ?? [];
        $this->assertIsArray($declared);

        $entries = [];

        foreach ($declared as $entry) {
            $this->assertIsString($entry, 'a channel entry that is not a string cannot be reasoned about');
            $entries[] = $entry;
        }

        $refused = \sprintf(
            '"%s" is no longer inside prod\'s buffered handler. That changes what this gate and the §7 record '
            . 'describe — re-read both rather than deleting this assertion.',
            $channel,
        );

        // MonologBundle accepts the key either way round, and reading only the denylist form was a measured
        // hole: rewriting the handler to `channels: ["request", "security"]` takes BOTH channels out of the
        // buffer while `assertNotContains('!console', …)` — and `MonologExclusionDeclarationGateTest` — stay
        // green. An entry without a leading `!` means the list is an allowlist, so membership inverts.
        $isAllowlist = [] !== \array_filter($entries, static fn (string $e): bool => !\str_starts_with($e, '!'));

        if ($isAllowlist) {
            $this->assertContains($channel, $entries, $refused);

            return;
        }

        $this->assertNotContains('!' . $channel, $entries, $refused);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheChannelIsStillInsideTheBufferedHandlerCases(): iterable
    {
        foreach (self::CHANNELS as $channel) {
            yield $channel => [$channel];
        }
    }

    /**
     * The map above is a pin; this is what stops it being a hand-kept list that ages. The universe comes from
     * the `monolog.logger` tags in the installed vendor configuration, so a service the framework starts
     * routing to either channel arrives here as a red rather than as silence.
     */
    #[Test]
    public function everyTaggedProducerOnThoseChannelsIsPinned(): void
    {
        $derived = $this->taggedProducers();

        $this->assertNotEmpty(
            $derived,
            'no tagged producer was found at all, so this check is reading nothing — the vendor configuration '
            . 'layout changed and the parser below no longer matches it',
        );

        $expected = \array_values(\array_unique([...$derived, ...self::UNTAGGED_PRODUCERS]));
        $pinned = \array_keys(self::ACTIVATING_LEVELS);

        \sort($expected);
        \sort($pinned);

        $this->assertSame(
            $expected,
            $pinned,
            'the classes the framework routes to the `console`/`messenger` channels are not the ones this '
            . 'gate pins. Read what the new one logs before adding its line — one of them holds the message '
            . 'object.',
        );
    }

    /**
     * @param class-string $emitter
     * @param list<string> $pinned
     */
    #[Test]
    #[DataProvider('provideTheEmitterActivatesTheBufferAtExactlyThePinnedLevelsCases')]
    public function theEmitterActivatesTheBufferAtExactlyThePinnedLevels(string $emitter, array $pinned): void
    {
        $source = $this->sourceOf($emitter);
        $threshold = $this->deployedActionLevel();

        // `->log($level, …)` names its level in an ARGUMENT, so no per-level matcher can classify it. Changing
        // one `->info(` to `->log('critical', ` in a class pinned as `[]` was measured leaving this gate
        // green, so the dynamic form is refused outright rather than parsed: a producer that reaches it has to
        // be read by a person.
        foreach (['logger->log(', 'logger?->log('] as $dynamic) {
            $this->assertStringNotContainsString(
                $dynamic,
                $source,
                \sprintf(
                    '%s logs through the PSR-3 `log()` form, whose level is an argument this census cannot '
                    . 'read. Read what level it reaches and pin it explicitly.',
                    $emitter,
                ),
            );
        }

        $found = [];

        foreach (self::PSR3_LEVELS as $level) {
            if (Level::fromName($level)->value < $threshold->value) {
                continue;
            }

            // Both spellings, because the nullable-logger shape (`$this->logger?->critical(`) is what three
            // of these four classes use and a matcher for the plain arrow alone reads them as silent.
            foreach (['logger->' . $level . '(', 'logger?->' . $level . '('] as $call) {
                if (\str_contains($source, $call)) {
                    $found[] = $level;

                    break;
                }
            }
        }

        $this->assertSame(
            $pinned,
            $found,
            \sprintf(
                "%s now activates prod's buffered handler at a different set of levels. A new activating "
                . 'record flushes the preceding fifty to php://stderr — read what it carries, then update '
                . 'this pin and the §7 record together.',
                $emitter,
            ),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideTheEmitterActivatesTheBufferAtExactlyThePinnedLevelsCases(): iterable
    {
        foreach (self::ACTIVATING_LEVELS as $emitter => $levels) {
            yield $emitter => [$emitter, $levels];
        }
    }

    /**
     * The one carrier that would reopen #804 on its own: the retry listener has the message OBJECT in hand
     * and puts only its class name into the record. A bump that added the payload back would put a queued
     * aggregate id — and, for an event about a person, the personal datum itself — into a sink with no
     * rotation, no TTL and no owner of erasure, with every other gate green.
     */
    #[Test]
    public function theMessengerRecordCarriesTheMessageClassAndNotItsPayload(): void
    {
        $source = $this->sourceOf(SendFailedMessageForRetryListener::class);

        $this->assertStringContainsString(
            "'class' => \$message::class",
            $source,
            'the retry listener no longer identifies the message by class name — read what it writes instead',
        );

        // The whole literal, not a denylist of spellings. Naming two was measured useless: adding
        // `'payload' => $message` to the same array left this green, and a denylist can only ever refuse the
        // spellings somebody thought of — the same failure this change's own processor refuses to repeat.
        $matched = \preg_match('/\$context = \[(?<body>.*?)\];/s', $source, $captured);

        $this->assertSame(1, $matched, 'the retry listener no longer builds its context as one array literal');

        $this->assertSame(
            "'class' => \$message::class, 'message_id' => \$envelope->last(TransportMessageIdStamp::class)?->getId(),",
            \preg_replace('/\s+/', ' ', \trim($captured['body'])),
            "the retry listener's log context changed. It is the one activating record on the `messenger` "
            . 'channel, so read every key it now carries: for an event about a person the aggregate id IS '
            . 'the personal datum, and the payload must not be among them.',
        );
    }

    /**
     * Every class the installed vendor configuration tags onto one of these channels.
     *
     * Read from the PHP service-configuration files rather than from a booted container, because this gate is
     * kernel-free by design and its home is the artifact-gate folder. It resolves `->set('id', Foo::class)`
     * against the file's own `use` statements, then keeps the tag that follows it — so a service whose class
     * is not installed (`AmazonSqsTransportFactory`, absent here) drops out by `class_exists` rather than by
     * anybody remembering to exclude it.
     *
     * @return list<class-string>
     */
    private function taggedProducers(): array
    {
        $found = [];

        foreach (\glob(\dirname(__DIR__, 4) . '/vendor/symfony/*/Resources/config/*.php') ?: [] as $file) {
            $source = \file_get_contents($file);

            if (\is_string($source) && \str_contains($source, "'monolog.logger'")) {
                $found = [...$found, ...$this->taggedIn($source)];
            }
        }

        /** @var list<class-string> $unique */
        $unique = \array_values(\array_unique($found));

        return $unique;
    }

    /**
     * @return list<string>
     */
    private function taggedIn(string $source): array
    {
        $shortNames = $this->importsOf($source);
        $current = null;
        $found = [];

        foreach (\explode("\n", $source) as $line) {
            if (1 === \preg_match('/->set\(\s*\'[^\']+\'\s*,\s*(\w+)::class/', $line, $set)) {
                $current = $shortNames[$set[1]] ?? null;
            }

            $channel = $this->channelTaggedOn($line);

            if (null !== $current && null !== $channel && \class_exists($current)) {
                $found[] = $current;
            }
        }

        return $found;
    }

    /**
     * Captured as `\S+` rather than as an explicit backslash class: a namespace separator inside a character
     * class needs an escape depth the code-style fixer rewrites into a broken pattern, and an import has no
     * whitespace in it anyway.
     *
     * @return array<string, string>
     */
    private function importsOf(string $source): array
    {
        \preg_match_all('/^use\s+(\S+);/m', $source, $imports);

        $shortNames = [];

        foreach ($imports[1] as $import) {
            $parts = \explode('\\', $import);
            $shortNames[\end($parts)] = $import;
        }

        return $shortNames;
    }

    private function channelTaggedOn(string $line): ?string
    {
        if (1 !== \preg_match('/\'monolog\.logger\'.*\'channel\'\s*=>\s*\'([^\']+)\'/', $line, $tag)) {
            return null;
        }

        return \in_array($tag[1], self::CHANNELS, true) ? $tag[1] : null;
    }

    /**
     * @param class-string $class
     */
    private function sourceOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $this->assertIsString($file, \sprintf('%s has no file to read', $class));

        $source = \file_get_contents($file);
        $this->assertIsString($source, \sprintf('%s could not be read', $class));

        return $source;
    }

    private function deployedActionLevel(): Level
    {
        $declared = $this->deployedHandler()['action_level'] ?? null;

        $this->assertIsString($declared, "prod's buffered handler declares no action_level to read");
        $this->assertContains(
            $declared,
            self::PSR3_LEVELS,
            "prod's buffered handler declares an action_level that is not a PSR-3 level name",
        );

        return Level::fromName($declared);
    }

    /**
     * @return array<string, mixed>
     */
    private function deployedHandler(): array
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 4) . '/config/packages/monolog.yaml');

        // Guarded rather than chained off `mixed`: four offsets deep, the sibling gate does the same, and
        // PHPStan at `level: max` refuses the unguarded form.
        $this->assertIsArray($parsed, 'monolog.yaml does not parse to a map');

        $prod = $parsed['when@prod'] ?? null;
        $this->assertIsArray($prod, 'monolog.yaml declares no `when@prod` block');

        $handlers = $prod['monolog']['handlers'] ?? null;
        $this->assertIsArray($handlers, 'the `when@prod` block declares no handlers');

        $handler = $handlers['main'] ?? null;

        if (!\is_array($handler)) {
            $this->fail('prod declares no `main` handler, so this gate cannot read what buffers.');
        }

        /** @var array<string, mixed> $handler */
        return $handler;
    }
}
