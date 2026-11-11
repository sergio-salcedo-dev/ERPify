<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger;

use Erpify\Documents\Document\Application\Upload\DocumentScanWakeUp;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches {@see ScanDocumentMessage} on the default bus, which routes it to `async`. Called inside the
 * transaction that marks the row promoted, so the message commits with that mark and a delivery never precedes the
 * state it announces; the bus refuses it outside a transaction.
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
