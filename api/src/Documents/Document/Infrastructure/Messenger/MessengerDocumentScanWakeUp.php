<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger;

use Erpify\Documents\Document\Application\Upload\DocumentScanWakeUp;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches {@see ScanDocumentMessage} on the default bus, which routes it to `async`. Called only after the
 * row is promoted and the promotion committed, so a delivery never precedes the state it announces.
 */
#[AsAlias(DocumentScanWakeUp::class)]
final readonly class MessengerDocumentScanWakeUp implements DocumentScanWakeUp
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    #[Override]
    public function wake(string $documentId): void
    {
        $this->messageBus->dispatch(new ScanDocumentMessage($documentId));
    }
}
