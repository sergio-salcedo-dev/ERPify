<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use LogicException;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Drives a REPLICA of the `main` handler, wired to the activation strategy the container actually holds, so a
 * gate can ask what reaches the sink without also owning the request stack and the rig that gets it there.
 * What the deployed handler consults is read by `DeployedActivationChain`; this measures what that answer does.
 *
 * A replica and not the deployed object, because the deployed one writes to `php://stderr`. Of the four
 * options the replica does not take from the deployment, three cannot change the outcome — `bufferSize` (on
 * non-activation nothing reaches the sink at any size, on activation the whole buffer does), `bubble` and
 * `stopBuffering` (one record). The fourth, `passthruLevel`, can: it ships buffered records on close whatever
 * the strategy decided, so `DeployedActivationChain` refuses a deployment that declares one rather than let
 * this answer for a handler it is not modelling.
 *
 * What the replica also does not carry is the deployed handler's OWN processors, which
 * `FingersCrossedHandler::handle()` runs before consulting the strategy — the blind spot
 * `MonologExclusionDeclarationGateTest::noProcessorIsScopedToAHandler` exists to cover.
 *
 * Behaviour is measured through the OUTERMOST strategy, never the unwrapped one: a decorator that suppressed
 * the exclusion would be invisible to a rig driven by the strategy it wraps, and the gap between the list the
 * configuration declares and the answer the deployed chain gives is the whole assertion.
 *
 * Off-request every status at or above `action_level` reaches the sink, which makes an unnamed request path a
 * green that means nothing — so there is no way to measure without naming it. `flushOnRequest()` and
 * `flushOffRequest()` are the only measurements, each asserting the container's stacks rather than assuming
 * them, and each leaving them as it found them.
 *
 * @internal test support
 */
final readonly class DeployedFingersCrossedGate
{
    /**
     * The path a pushed request carries. Only a URL-scoped exclusion reads it and the chain refuses one, so the
     * value decides nothing — it names the population the deployed exclusion actually holds: a routing 404 or
     * 405 raised outside `/api/`, which is where HttpKernel's `ErrorListener` is the thing that logs.
     */
    private const string UNROUTED_REQUEST_PATH = '/does-not-exist';

    private DeployedActivationChain $chain;

    public function __construct(ContainerInterface $container)
    {
        $this->chain = new DeployedActivationChain($container);
    }

    /**
     * @return list<int>
     */
    public function unconditionallyExcludedCodes(): array
    {
        return $this->chain->unconditionallyExcludedCodes();
    }

    /**
     * Measures on the path the deployed exclusion actually holds: a main request in front of every stack the
     * chain consults, released again before answering. The buffer size is left at the library default because
     * it cannot change the outcome — on non-activation nothing reaches the sink at any size, and on activation
     * the whole buffer does.
     *
     * @param list<callable(LogRecord): LogRecord> $processors
     */
    public function flushOnRequest(LogRecord $record, array $processors): TestHandler
    {
        $entered = $this->enterRequest();

        try {
            return $this->flush($record, $processors);
        } finally {
            $this->releaseRequests($entered);
        }
    }

    /**
     * Measures with no request in the stack, where the deployed strategy consults no exclusion at all.
     * Emptiness is asserted rather than trusted: the stacks are the container's, so another party may lead
     * them.
     *
     * @param list<callable(LogRecord): LogRecord> $processors
     */
    public function flushOffRequest(LogRecord $record, array $processors): TestHandler
    {
        foreach ($this->chain->requestStacks() as $stack) {
            $this->refuseALedStack($stack, 'a request leads the deployed stack, so this measures the on-request '
                . 'path rather than the off-request one');
        }

        return $this->flush($record, $processors);
    }

    /**
     * Logs one failure at `$record`'s own level through a throwaway gate handler wired to the deployed
     * strategy, and answers with the sink it flushed into.
     *
     * @param list<callable(LogRecord): LogRecord> $processors
     */
    private function flush(LogRecord $record, array $processors): TestHandler
    {
        $sink = new TestHandler();
        $handler = new FingersCrossedHandler($sink, $this->chain->outermostStrategy());
        $logger = new Logger($record->channel, [$handler], $processors);
        $logger->log($record->level, $record->message, $record->context);

        return $sink;
    }

    /**
     * Puts a main request in front of every stack the chain consults, and answers with what it entered so the
     * release can refuse to pop anything else.
     *
     * @return list<array{RequestStack, Request}>
     */
    private function enterRequest(): array
    {
        $stacks = $this->chain->requestStacks();

        // Every stack is read before any is written, so a refusal leaves none of them half-entered.
        foreach ($stacks as $stack) {
            $this->refuseALedStack($stack, 'a request already leads the deployed stack, so one pushed here is '
                . 'a sub-request the exclusion never reads');
        }

        $entered = [];

        // Nothing below can throw, so no stack is ever left entered by a refusal: the loop above has already
        // established every stack is empty, and pushing onto an empty one makes the pushed request the main
        // one `getMainRequest()` answers with.
        foreach ($stacks as $stack) {
            $request = Request::create(self::UNROUTED_REQUEST_PATH);
            $stack->push($request);
            $entered[] = [$stack, $request];
        }

        return $entered;
    }

    /**
     * @param list<array{RequestStack, Request}> $entered
     */
    private function releaseRequests(array $entered): void
    {
        $contaminated = false;

        // Every stack is released before any refusal is raised: throwing on the first mismatch would leave the
        // rest entered, and the next measurement would then refuse a stack this one abandoned.
        foreach ($entered as [$stack, $request]) {
            if ($stack->getCurrentRequest() === $request) {
                $stack->pop();

                continue;
            }

            $contaminated = true;
        }

        if ($contaminated) {
            throw new LogicException(
                'a deployed stack no longer leads with the request entered here, so another party pushed onto '
                . 'it while this was measuring',
            );
        }
    }

    private function refuseALedStack(RequestStack $stack, string $refusal): void
    {
        if ($stack->getMainRequest() instanceof Request) {
            throw new LogicException($refusal);
        }
    }
}
