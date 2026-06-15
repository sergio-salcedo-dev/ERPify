<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Mercure;

use Override;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * Recording {@see HubInterface} test double: captures published {@see Update}s in process-static
 * state instead of doing network I/O. Bound as the `HubInterface` in `services_test.yaml`, so the
 * real {@see \Erpify\Backoffice\Bank\Infrastructure\Messenger\BankRealtimePublisherHandler} runs
 * unchanged when a bank event is consumed — without it the publisher would reach the live hub and
 * hang ~5s in Behat, gating every consume-based assertion.
 *
 * State is static so the captured updates survive whichever container the publisher resolves from
 * (the consume worker boots its own command application) and stay readable from
 * {@see \Erpify\Tests\Behat\Context\MercureContext}.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    private static array $updates = [];

    #[Override]
    public function publish(Update $update): string
    {
        self::$updates[] = $update;

        return '';
    }

    #[Override]
    public function getPublicUrl(): string
    {
        return '';
    }

    #[Override]
    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    /**
     * @return list<Update>
     */
    public static function updates(): array
    {
        return self::$updates;
    }

    public static function reset(): void
    {
        self::$updates = [];
    }
}
