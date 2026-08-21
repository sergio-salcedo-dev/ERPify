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
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
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
     * @var array<class-string, list<string>>
     */
    private const array ACTIVATING_LEVELS = [
        ConsoleErrorListener::class => ['critical'],
        SendFailedMessageForRetryListener::class => ['critical'],
        Worker::class => [],
        SendFailedMessageToFailureTransportListener::class => [],
    ];

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
    #[DataProvider('channels')]
    public function theChannelIsStillInsideTheBufferedHandler(string $channel): void
    {
        $excluded = $this->deployedHandler()['channels'] ?? [];
        $this->assertIsArray($excluded);

        $this->assertNotContains(
            '!' . $channel,
            $excluded,
            \sprintf(
                '"%s" is now excluded from prod\'s buffered handler. That changes what this gate and the §7 '
                . 'record describe — re-read both rather than deleting this assertion.',
                $channel,
            ),
        );
    }

    /**
     * @param class-string $emitter
     * @param list<string> $pinned
     */
    #[Test]
    #[DataProvider('emitters')]
    public function theEmitterActivatesTheBufferAtExactlyThePinnedLevels(string $emitter, array $pinned): void
    {
        $source = $this->sourceOf($emitter);
        $threshold = $this->deployedActionLevel();

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
                '%s now activates prod\'s buffered handler at a different set of levels. A new activating '
                . 'record flushes the preceding fifty to php://stderr — read what it carries, then update '
                . 'this pin and the §7 record together.',
                $emitter,
            ),
        );
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

        foreach (["'message' => \$message", "'message' => \$envelope->getMessage()"] as $payload) {
            $this->assertStringNotContainsString(
                $payload,
                $source,
                'the retry listener now writes the message PAYLOAD into a record that flushes the buffer to '
                . 'an unowned sink. For an event about a person the aggregate id IS the personal datum.',
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function channels(): iterable
    {
        foreach (self::CHANNELS as $channel) {
            yield $channel => [$channel];
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function emitters(): iterable
    {
        foreach (self::ACTIVATING_LEVELS as $emitter => $levels) {
            yield $emitter => [$emitter, $levels];
        }
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

        $this->assertIsString($declared, 'prod\'s buffered handler declares no action_level to read');

        return Level::fromName($declared);
    }

    /**
     * @return array<string, mixed>
     */
    private function deployedHandler(): array
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 4) . '/config/packages/monolog.yaml');

        $handler = $parsed['when@prod']['monolog']['handlers']['main'] ?? null;

        if (!\is_array($handler)) {
            $this->fail('prod declares no `main` handler, so this gate cannot read what buffers.');
        }

        /** @var array<string, mixed> $handler */
        return $handler;
    }
}
