<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Messenger;

use Erpify\Documents\Document\Infrastructure\Messenger\MessengerDocumentScanWakeUp;
use Erpify\Documents\Document\Infrastructure\Messenger\ScanDocumentMessage;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
#[CoversClass(MessengerDocumentScanWakeUp::class)]
#[CoversClass(ScanDocumentMessage::class)]
final class MessengerDocumentScanWakeUpTest extends TestCase
{
    public function testItDispatchesTheScanMessageOfTheDocument(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            #[Override]
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message, $stamps);
            }
        };

        (new MessengerDocumentScanWakeUp($bus))->wake('0190e9c2-7b5a-7d40-9c8f-aabbccddeeff');

        $this->assertCount(1, $bus->dispatched);
        $this->assertInstanceOf(ScanDocumentMessage::class, $bus->dispatched[0]);
        $this->assertSame('0190e9c2-7b5a-7d40-9c8f-aabbccddeeff', $bus->dispatched[0]->documentId);
    }
}
