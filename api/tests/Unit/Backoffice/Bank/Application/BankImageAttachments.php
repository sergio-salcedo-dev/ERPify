<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Images\Application\ImageAttachment;
use Erpify\Shared\Images\Application\UploadImage;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\RefusingImageProcessor;
use Erpify\Tests\Unit\Shared\Images\Application\InMemoryImageRepository;
use Erpify\Tests\Unit\Shared\Images\Application\InMemoryImageStorage;
use Psr\Log\NullLogger;

/**
 * An {@see ImageAttachment} over in-memory collaborators for the bank use cases that only RELEASE an image or drive
 * the holder through it: the processor refuses, because no case here stores bytes.
 *
 * @internal
 */
final class BankImageAttachments
{
    private function __construct()
    {
    }

    public static function over(EventBus $eventBus, TransactionManager $transactions): ImageAttachment
    {
        return new ImageAttachment(
            new UploadImage(
                new RefusingImageProcessor(),
                new InMemoryImageStorage(),
                new InMemoryImageRepository(),
                $transactions,
            ),
            $transactions,
            $eventBus,
            new NullLogger(),
        );
    }
}
