<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Domain;

use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Shared\Images\Domain\ImageId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the signal is allowed to carry.
 *
 * The module's structural rule — that no Doctrine lifecycle hook removes bytes — used to live here too,
 * and moved to {@see \Erpify\Tests\Unit\Shared\Images\ImageLifecycleListenerGateTest}: it reads the
 * tree as data rather than exercising anything, and inside a behavioural test it was invisible to the
 * placement registry, whose heuristic skips any file that also credits production coverage.
 *
 * @internal
 */
#[CoversClass(ImageDeletionRequested::class)]
final class ImageDeletionRequestedTest extends TestCase
{
    /**
     * The assertion is over what is actually SERIALISED, not only over the payload: the event name, the
     * aggregate type, the aggregate id, the event id and the timestamp all become columns of `event_store`
     * and of the queue row, so each of them is retention surface in its own right.
     */
    public function testTheWholeEnvelopeCarriesNothingButTheIdentity(): void
    {
        $imageId = ImageId::generate();
        $event = new ImageDeletionRequested($imageId->toString());

        $this->assertSame([], $event->toPrimitives(), 'the payload is empty');

        $serialised = \implode(' ', [
            ImageDeletionRequested::eventName(),
            ImageDeletionRequested::aggregateType(),
            $event->aggregateId(),
            $event->eventId(),
            $event->occurredOn()->format(DATE_ATOM),
            \json_encode($event->toPrimitives(), JSON_THROW_ON_ERROR),
        ]);

        foreach (['storageKey', 'path', 'filename', 'digest', 'absolutePath', '/app/storage'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialised);
        }

        // The identity itself is the one thing that legitimately travels, and it travels exactly once.
        $this->assertSame(1, \substr_count($serialised, $imageId->toString()));
    }

    public function testItReconstitutesFromItsPersistedRowWithoutMintingNewIdentity(): void
    {
        $original = new ImageDeletionRequested(ImageId::generate()->toString());

        $restored = ImageDeletionRequested::fromPrimitives(
            $original->aggregateId(),
            $original->toPrimitives(),
            $original->eventId(),
            $original->occurredOn()->format(DATE_ATOM),
        );

        $this->assertSame($original->aggregateId(), $restored->aggregateId());
        $this->assertSame($original->eventId(), $restored->eventId());
        $this->assertSame(
            $original->occurredOn()->format(DATE_ATOM),
            $restored->occurredOn()->format(DATE_ATOM),
        );
    }
}
