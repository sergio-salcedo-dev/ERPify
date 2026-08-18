<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use LogicException;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Symfony\Bridge\Monolog\Handler\FingersCrossed\HttpCodeActivationStrategy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Drives the `main` handler the container actually holds, so a gate can ask what reaches the sink without also
 * owning the reflection, the request stack and the throwaway rig that gets it there.
 *
 * The reading is the half that is easy to get quietly wrong. The strategy must be the HTTP-code one — deleting
 * `excluded_http_codes` makes MonologBundle build an `ErrorLevelActivationStrategy` with no `requestStack` at
 * all, and reflecting a missing property names the property rather than the invariant. And the stack pushed
 * onto must be the instance the strategy itself holds: an exclusion is only evaluated when `getMainRequest()`
 * answers, so pushing onto another instance under the same id makes an assertion pass by never reaching the
 * exclusion at all.
 *
 * @internal test support
 */
final class DeployedFingersCrossedGate
{
    /** @var list<RequestStack> */
    private array $pushed = [];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function activationStrategy(): HttpCodeActivationStrategy
    {
        $handler = $this->deployedHandler();
        $strategy = (new ReflectionProperty($handler, 'activationStrategy'))->getValue($handler);

        if (!$strategy instanceof HttpCodeActivationStrategy) {
            throw new LogicException('the deployed handler excludes no status code, so every 404 flushes the buffer');
        }

        return $strategy;
    }

    /**
     * The codes the deployed strategy excludes unconditionally. A URL-scoped exclusion holds only for the
     * paths it names, so it is not the unconditional property a caller would read it as, and it is refused
     * here rather than silently counted.
     *
     * @return list<int>
     */
    public function unconditionallyExcludedCodes(): array
    {
        $strategy = $this->activationStrategy();
        $exclusions = (new ReflectionProperty($strategy, 'exclusions'))->getValue($strategy);

        if (!\is_array($exclusions)) {
            throw new LogicException('the deployed strategy holds no exclusion list');
        }

        $codes = [];

        foreach ($exclusions as $exclusion) {
            if (!\is_array($exclusion) || !\is_int($exclusion['code'] ?? null)) {
                throw new LogicException('an exclusion declares no integer code');
            }

            if ([] !== ($exclusion['urls'] ?? [])) {
                throw new LogicException(\sprintf(
                    'the exclusion for %d is scoped to URLs rather than unconditional',
                    $exclusion['code'],
                ));
            }

            $codes[] = $exclusion['code'];
        }

        return $codes;
    }

    /**
     * Logs one failure carrying `$status` through a throwaway gate handler wired to the deployed strategy, and
     * answers with the sink it flushed into. The buffer size is left at the library default because it cannot
     * change the outcome: on non-activation nothing reaches the sink at any size, and on activation the whole
     * buffer does.
     *
     * @param list<callable(LogRecord): LogRecord> $processors
     */
    public function flushRecordsFor(LogRecord $record, array $processors): TestHandler
    {
        $strategy = $this->activationStrategy();

        $requestStack = (new ReflectionProperty($strategy, 'requestStack'))->getValue($strategy);

        if (!$requestStack instanceof RequestStack) {
            throw new LogicException('the deployed strategy holds no request stack, so no exclusion is evaluated');
        }

        $requestStack->push(Request::create('/api/v1/banks/does-not-exist'));
        // The stack is the container's, shared with whatever runs next in this kernel.
        $this->pushed[] = $requestStack;

        $sink = new TestHandler();
        $logger = new Logger($record->channel, [new FingersCrossedHandler($sink, $strategy)], $processors);
        $logger->error($record->message, $record->context);

        return $sink;
    }

    public function releaseRequests(): void
    {
        foreach ($this->pushed as $requestStack) {
            $requestStack->pop();
        }

        $this->pushed = [];
    }

    private function deployedHandler(): FingersCrossedHandler
    {
        $handler = $this->container->get('monolog.handler.main');

        if (!$handler instanceof FingersCrossedHandler) {
            throw new LogicException('the deployed `main` handler does not buffer, so nothing gates the sink');
        }

        return $handler;
    }
}
