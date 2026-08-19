<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional;

use Monolog\Logger;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Throwable;

/**
 * Every Monolog channel logger the container builds, keyed by service id.
 *
 * The trap is the enumeration, not the loop. `TestContainer::getServiceIds()` answers with the PUBLIC
 * container alone, and MonologBundle makes public only the channels named under `monolog.channels` — which
 * excludes `security` and `request`, the two channels Symfony's security stack and router listener write a
 * person's identifier on. Enumerating through that API alone therefore reads as exhaustive while sweeping a
 * fraction of the graph, so the ids come from the public container UNION the test private-services locator.
 *
 * A `monolog.logger*` service that cannot be read, or that is not a `Logger`, fails the test rather than
 * being skipped. A channel silently dropped from the sweep is the one shape that makes an emptiness
 * assertion prove nothing, and "not a `Logger`" is exactly what a decorator or a channel degraded to a
 * `NullLogger` would look like — the case where the sweep needs to shout, not shrug.
 */
trait EnumeratesChannelLoggers
{
    /**
     * @return array<string, Logger>
     */
    private function channelLoggers(): array
    {
        $container = self::getContainer();

        $privateLocator = $container->get('test.private_services_locator');
        self::assertInstanceOf(ServiceProviderInterface::class, $privateLocator);

        $ids = \array_unique([...$container->getServiceIds(), ...\array_keys($privateLocator->getProvidedServices())]);
        \sort($ids);

        $loggers = [];

        foreach ($ids as $id) {
            if (!\str_starts_with($id, 'monolog.logger')) {
                continue;
            }

            try {
                $service = $container->get($id);
            } catch (Throwable $throwable) {
                self::fail(\sprintf('channel logger "%s" could not be read: %s', $id, $throwable->getMessage()));
            }

            self::assertInstanceOf(
                Logger::class,
                $service,
                \sprintf('"%s" is not a Monolog logger, so this sweep would pass over it in silence', $id),
            );

            $loggers[$id] = $service;
        }

        return $loggers;
    }
}
