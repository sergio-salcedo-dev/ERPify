<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Upload\RollForwardPromotions;
use Erpify\Documents\Document\Domain\UploadLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One roll-forward tick; idempotent, each row rechecked under its own lock.
 */
#[AsMessageHandler]
final readonly class RollForwardPromotionsHandler
{
    private const int BATCH = 500;

    public function __construct(
        private RollForwardPromotions $rollForwardPromotions,
    ) {
    }

    public function __invoke(RollForwardPromotionsMessage $message): void
    {
        unset($message);

        $this->rollForwardPromotions->run(UploadLimits::ROLL_FORWARD_AGE_SECONDS, self::BATCH);
    }
}
