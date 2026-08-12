<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Mercure;

use Override;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Recording {@see HubInterface} test double: captures published {@see Update}s in process-static
 * state instead of doing network I/O. Bound as the `HubInterface` in `services_test.yaml`, so the
 * real {@see \Erpify\Backoffice\Bank\Infrastructure\Messenger\RefreshRealtimeOnBankChanged} runs
 * unchanged when a bank event is consumed — without it the publisher would reach the live hub and
 * hang ~5s in Behat, gating every consume-based assertion.
 *
 * State is static so {@see \Erpify\Tests\Behat\Context\MercureContext} reads the captured updates
 * directly, regardless of which container instance the publisher resolves the hub from.
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
     * `config/packages/mercure.yaml` sets no `protocol_version`, so the real hub is the bundle's
     * default. Pinning the double to the same case keeps a scenario that asserts on the cookie
     * honest: under protocol 1.0 the name changes and the browser contract changes with it.
     */
    #[Override]
    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::Legacy;
    }

    /**
     * Derived from the version rather than spelled out, exactly as {@see \Symfony\Component\Mercure\Hub}
     * does — a literal here would keep returning the old name after a protocol switch.
     */
    #[Override]
    public function getCookieName(): string
    {
        return $this->getProtocolVersion()->getDefaultCookieName();
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
