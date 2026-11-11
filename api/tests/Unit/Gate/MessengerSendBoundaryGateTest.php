<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A message leaves `api/src` through the default bus, and the files allowed to hold that bus are a pinned set.
 *
 * **The guard is only as wide as the doors it stands in.** `RequireTransactionalPublishMiddleware` sits first on
 * `messenger.bus.default`, so everything dispatched there owes a transaction and a transactional `async`. A
 * class that holds another bus, or a sender or transport of its own and calls `send()`, writes to the outbox
 * without passing it — and its unit test, which exercises that class alone, is green. Nothing else notices a
 * second way out opening, so it is a source fact gated as one.
 *
 * Two sets, because they answer different questions:
 *
 *  - **who may hold a bus** — the callers. Two today, and each is a deliberate port adapter:
 *    `SymfonyMessengerEventBus` for domain events and `MessengerDocumentScanWakeUp` for a non-event wake-up
 *    hint. A third caller is a decision, and the edit here is where it is made;
 *  - **who may name a sender, a transport or a transport locator** — the plumbing. The guard and the validator
 *    inspect transports and the Sentry filter names the PostgreSQL bridge to recognise its exception. None
 *    sends. A fifth file naming one is where a direct `send()` would appear. `MessengerDeadLetterReader` is in
 *    the set for a weaker reason, stated plainly: it takes a read-only `ReceiverInterface` and names no
 *    transport in code — the set holds it because its docblock spells the container id the failure transport
 *    is wired by (`config/services.yaml`, outside this sweep). Editing that docblock is an edit here.
 *
 * The `Receiver` and `Serialization` namespaces are deliberately not part of the transport match: a receiver
 * can only read and a serializer only encodes. Every other type under `Messenger\Transport\` is, so a
 * `SyncTransport`, a transport factory or a setup-capable transport joins the set without naming a listed type.
 *
 * A third assertion pins the writer of the outbox itself: no file under `api/src` inserts into
 * `messenger_messages`, so the only INSERT is the transport's own, inside the caller's transaction.
 *
 * What a green proves: no file under `api/src` other than the pinned ones names a bus, a sender, a transport
 * or one of their container ids, and none inserts into the outbox table.
 *
 * What it does not prove:
 * - **Nothing about what a pinned file does.** `MessengerDocumentScanWakeUp` dispatching on the default bus is
 *   what puts its message behind the guard; a pinned file switching to another bus keeps its place in the set.
 *   `RequireTransactionalPublishFunctionalTest` and the routing tests hold the behaviour, this holds only the
 *   membership.
 * - **Nothing outside `api/src`.** A command in `tools/`, a script, a test fixture, or `config/*.yaml` — where
 *   a bus or transport can be injected by container id with no token in `src` — is not swept.
 * - It is a text match over source (backslashes doubled inside a quoted string are normalised first). A type
 *   reached through a computed class-string, a class alias, a grouped
 *   `use Symfony\Component\Messenger\{…}` or a namespace alias, or a service fetched by a container id
 *   assembled at runtime is invisible to it, and a docblock spelling one of these names reds it — the accepted
 *   cost of a match with no configuration of its own.
 * - The outbox assertion matches the two spellings of an INSERT (`INSERT INTO messenger_messages` and
 *   DBAL's `->insert('messenger_messages'`); a statement assembled from parts, or issued by a database-side
 *   writer, is invisible to it.
 * - It says nothing about which transport `async` resolves to; that is `AsyncTransportValidator`'s question,
 *   asked of the running process.
 *
 * @internal
 */
#[CoversNothing]
final class MessengerSendBoundaryGateTest extends TestCase
{
    /**
     * Namespace prefixes and container ids by which a class holds a bus. `MessageBus` as a prefix covers the
     * interface and the concrete bus; the routable bus and `HandleTrait` (which dispatches through a
     * `$messageBus` property) share no token with it.
     *
     * @var list<string>
     */
    private const array BUS_TOKENS = [
        \Symfony\Component\Messenger\MessageBus::class,
        \Symfony\Component\Messenger\RoutableMessageBus::class,
        \Symfony\Component\Messenger\HandleTrait::class,
        'messenger.bus.',
        'messenger.default_bus',
        'messenger.routable_message_bus',
    ];

    /**
     * @var list<string>
     */
    private const array BUS_SITES = [
        'Documents/Document/Infrastructure/Messenger/MessengerDocumentScanWakeUp.php',
        'Shared/Event/Infrastructure/Bus/SymfonyMessengerEventBus.php',
    ];

    /**
     * Whatever can put an envelope on a transport, or resolve the object that can: the whole Bridge root (the
     * Doctrine transport and its connection live there), the container ids that hand any of them out, and the
     * stamp that picks a transport at dispatch. The
     * `Transport` namespace is matched by {@see self::TRANSPORT_NAMESPACE} instead, since it is a prefix with
     * two exclusions.
     *
     * @var list<string>
     */
    private const array TRANSPORT_TOKENS = [
        'Symfony\Component\Messenger\Bridge',
        'messenger.transport.',
        'messenger.transport_factory',
        'messenger.failure_transports',
        'messenger.senders_locator',
        'messenger.receiver_locator',
        // Addresses an envelope to transports at dispatch, past every routing key the build-time checks read.
        \Symfony\Component\Messenger\Stamp\TransportNamesStamp::class,
    ];

    /**
     * Every type under `Transport\` except the read-only and the encoding namespaces: the senders and their
     * locator, the transport interface and its setup variants, the sync and in-memory transports, the factories.
     */
    private const string TRANSPORT_NAMESPACE = '#Symfony\\\Component\\\Messenger\\\Transport\\\\'
        . '(?!Receiver\\\|Serialization\\\)#';

    /**
     * The two spellings of an INSERT into the outbox table.
     */
    private const string OUTBOX_INSERT = '#(?:INSERT\s+INTO|->insert\()\s*["\'`]?messenger_messages#i';

    /**
     * @var list<string>
     */
    private const array TRANSPORT_SITES = [
        'Shared/Event/Infrastructure/Messenger/AsyncTransportValidator.php',
        'Shared/Event/Infrastructure/Messenger/MessengerDeadLetterReader.php',
        'Shared/Event/Infrastructure/Messenger/RequireTransactionalPublishMiddleware.php',
        'Shared/Monitoring/Infrastructure/Sentry/SentryEventFilter.php',
    ];

    #[Test]
    public function onlyThePortAdaptersHoldTheMessageBus(): void
    {
        $this->assertSame(
            self::BUS_SITES,
            $this->filesNamingAny(self::BUS_TOKENS),
            'a class outside the pinned adapters holds a message bus, so what it dispatches may bypass the '
            . 'transactional publish guard on messenger.bus.default',
        );
    }

    #[Test]
    public function onlyThePlumbingNamesASenderOrATransport(): void
    {
        $this->assertSame(
            self::TRANSPORT_SITES,
            $this->filesNamingAny(self::TRANSPORT_TOKENS, self::TRANSPORT_NAMESPACE),
            'a class outside the pinned plumbing names a sender or a transport, so it may write to the outbox '
            . 'without passing the transactional publish guard',
        );
    }

    #[Test]
    public function nothingInsertsIntoTheOutboxButTheTransport(): void
    {
        $this->assertSame(
            [],
            $this->filesNamingAny([], self::OUTBOX_INSERT),
            'a class writes to messenger_messages itself, outside the transaction-bound transport and the guard',
        );
    }

    #[Test]
    public function theSweepReachesTheSourceItIsPinnedOver(): void
    {
        $swept = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $swept[] = $file->getPathname();
        }

        $this->assertGreaterThan(500, \count($swept), 'the sweep walked a fraction of api/src');

        foreach ([...self::BUS_SITES, ...self::TRANSPORT_SITES] as $site) {
            $this->assertContains(ApiSourceFiles::root() . '/' . $site, $swept, $site . ' is pinned but never swept');
        }
    }

    /**
     * @param list<string> $tokens  literal substrings
     * @param string       $pattern an additional regular expression, or '' for none
     *
     * @return list<string>
     */
    private function filesNamingAny(array $tokens, string $pattern = ''): array
    {
        $root = ApiSourceFiles::root();
        $naming = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $source = \file_get_contents($file->getPathname());
            $this->assertIsString($source, $file->getPathname() . ' could not be read, so it was never swept');
            // A class name written inside a single-quoted string doubles its backslashes.
            $source = \str_replace('\\\\', '\\', $source);

            if (
                \array_any($tokens, static fn (string $token): bool => \str_contains($source, $token))
                || ('' !== $pattern && 1 === \preg_match($pattern, $source))
            ) {
                $naming[] = \substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        \sort($naming);

        return $naming;
    }
}
