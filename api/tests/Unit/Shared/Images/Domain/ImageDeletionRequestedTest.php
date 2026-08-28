<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Domain;

use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Shared\Images\Domain\ImageId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * What the signal is allowed to carry, and what the module is not allowed to contain.
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

    /**
     * No Doctrine lifecycle listener anywhere in this module. The contrary shape is the one the governing
     * ADR names as its counter-example: a `postRemove` listener deleting bytes as a side effect of the
     * owner's flush, inside the owner's transaction.
     *
     * The check is deliberately scoped to this module, and that scope IS its limit: the listener the ADR
     * names lived outside `Shared/Images/`, and a listener registered by service tag or by
     * `#[AsDoctrineListener]` would not be seen either. It covers the letter of the rule, not its purpose.
     */
    public function testTheModuleContainsNoDoctrineLifecycleListener(): void
    {
        $sources = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(\dirname(__DIR__, 5) . '/src/Shared/Images'),
        );

        foreach ($files as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $sources[$file->getFilename()] = self::codeWithoutComments((string) \file_get_contents($file->getPathname()));
            }
        }

        $this->assertNotSame([], $sources, 'the sweep must see the module, or it asserts nothing');

        foreach ($sources as $name => $source) {
            foreach (['AsEntityListener', 'AsDoctrineListener', 'postRemove', 'preRemove'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $name . ' must declare no lifecycle hook');
            }
        }
    }

    /**
     * The subject is CODE, so comments are dropped before matching. A text sweep cannot tell a hook from
     * the prose explaining why there is no hook — measured here, the handler's own docblock naming
     * `postRemove` as the shape it exists to avoid was enough to fail this check.
     */
    private static function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
