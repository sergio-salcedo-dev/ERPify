<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\ExceptionResponder;
use Erpify\Shared\ErrorContract\Infrastructure\Http\ProblemDetailsResponder;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Support\DeclaredFingersCrossedHandlers;
use Erpify\Tests\Support\DeployedBufferActionLevel;
use Erpify\Tests\Support\DeployedFingersCrossedGate;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * The `/api/*` surface stays out of the buffered sink because of a GAP between two numbers, and nothing else.
 *
 * `ExceptionResponder` answers every `/api/*` throwable, and `RequestEvent::setResponse()` calls
 * `stopPropagation()` — so HttpKernel's `ErrorListener::logKernelException`, the only thing that puts
 * `['exception' => $throwable]` into a record on this path, never runs there, and the exclusion it feeds is
 * INERT on the API surface. The one line emitted is the responder's own, and for a client error it is
 * `warning`: below the `error` the `main` handler activates at, so an API 404 flushes nothing.
 * `excluded_http_codes` decides nothing here — it is consulted only once the LEVEL has already activated the
 * handler, and its real population is the routing 404/405s from OUTSIDE `/api/`, where `RouterListener`
 * throws, the responder does not match the prefix, and `ErrorListener` does log at `error`.
 *
 * Close that gap from either side — raise the 4xx branch of `resolveLogLevel()`, or lower `action_level` —
 * and every API client error dumps the buffer into `php://stderr`, among it the `debug` record Symfony's
 * `ContextListener` writes on every authenticated request, whose `username` is this application's user
 * identifier and therefore the person's email address. That sink is bounded by size alone, still with no
 * TTL and no owner of erasure. `FingersCrossedActivationIntegrityTest` and `MonologExclusionDeclarationGateTest`
 * both stay green through either change, which is the reason this file exists.
 *
 * **The level compared is the one the listener LOGS, not the one a helper returns.** The responder is built
 * from the container's own collaborators with a capturing handler in place of its logger and driven through
 * `__invoke()`, so a call site rewritten to log a literal — leaving `resolveLogLevel()` untouched, byte for
 * byte — reds here. Exactly one record per client error is required, which is also what stops the sweep
 * going vacuous over a listener that emits none. The threshold it is compared against is read off the
 * handler the container COMPILED, so neither number is a literal written here and both land on one scale
 * through `Monolog\Level` itself.
 *
 * **The throwables are the ones this application actually answers 4xx with.** In this codebase the dominant
 * client error is a `DomainException`, which the SPL hierarchy makes a `LogicException` — the class
 * `resolveLogLevel()` sends to `critical`, and which reaches `warning` only through the carve-out that
 * excludes it. Feeding a `RuntimeException` alone leaves that carve-out with no coverage at all, so deleting
 * it turns every domain 404, 409 and 422 into a buffer flush with every gate green. The corpus therefore
 * covers both families, and its statuses are required to cover every client-error status the contract
 * declares (`MARKER_STATUS_MAP`, `HTTP_STATUS_TYPE_MAP`) plus every code the deployed exclusion names, plus
 * one outside all of them for the factory's `http-error` fallback — a marker added later without a corpus
 * entry reds rather than being skipped.
 *
 * The environment half reads the FILES, for the reason `MonologExclusionDeclarationGateTest` states: only
 * the `test` declaration is reachable from a booted kernel, so a `prod` block lowered on its own is
 * invisible to any container this suite can boot. It is the same reading through
 * `Tests\Support\DeclaredFingersCrossedHandlers`, and the compiled reading is asserted to be THIS
 * environment's own `main` — not merely one of the declared levels, which two environments spelling `error`
 * satisfy by coincidence.
 *
 * **Where the `main` handler does not buffer, this REDS rather than skips** — `DeployedBufferActionLevel`
 * throws and the run errors. In `dev` that handler is a plain stream and the coupling is genuinely
 * unobservable, but `tools/phpunit/phpunit.dist.xml` pins `APP_ENV=test` with `force="true"`, so arriving
 * there means the invariant stopped being measurable at all, and a gate that answers "skipped" to that is a
 * gate an environment variable switches off. The file half keeps asserting wherever it runs.
 *
 * What a green does NOT prove: nothing about a 5xx or a non-domain `LogicException`, which are `error` and
 * `critical` BY DESIGN and are meant to flush; nothing about the responder's OTHER emission — `emitLastResort`
 * logs `critical` on the same channel when the factory, the responder or the logger throws, and a client
 * error reaches it (a title over the body cap), so that path activates the handler by construction; nothing
 * about a `DomainException` declaring an explicit `type()`, an open set no gate can enumerate, one of whose
 * values (`unhandled-exception`) `resolveLogLevel()` sends to `critical`; nothing about `buffer_size`, which
 * sizes the leak without opening it; nothing about what the buffer HOLDS, which is a property of the enrolled
 * processors and of `ContextListener`; nothing about the sink the flush reaches, its rotation or its
 * retention; nothing about any handler other than `main`, nor about the channel routing that decides whether
 * the responder's record reaches it — the best-effort reporters are bound to `observability` for that reason
 * and that binding is a co-equal control this file says nothing about; nothing about `excluded_http_codes`,
 * which is the other two files; nothing about a Monolog configuration written in PHP, which the file half
 * refuses rather than reads; and nothing about the off-request path, where the strategy never consults its
 * exclusion at all.
 *
 * The coupling is the subject, not an accident: a gate that drives one arm of the error contract per
 * throwable has to name each of them, and naming them is what keeps the corpus a sweep of the deployed
 * mapping rather than a list of statuses somebody typed.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversNothing]
final class ApiClientErrorBufferCouplingGateTest extends KernelTestCase
{
    use ResolvesContainerServices;

    /** The listener whose record would carry a 4xx into the buffer if it were ever reached on `/api/*`. */
    private const string PREEMPTED_LISTENER = 'logKernelException';

    private const string PROBE_PATH = '/api/v1/probe';

    /** @var array<string, array{int, Level}>|null */
    private ?array $emissions = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    /**
     * The premise the whole coupling rests on: the responder answers BEFORE the listener that would log a
     * client error at the level the handler flushes at, and it stops the chain when it does. Invert either
     * and every API 4xx carries an `error` line from HttpKernel — with the level gap untouched and every
     * other assertion here green.
     */
    #[Test]
    public function theResponderPreemptsTheListenerThatWouldLogAClientErrorAtTheFlushLevel(): void
    {
        $this->assertGreaterThan(
            $this->preemptedListenerPriority(),
            ExceptionResponder::PRIORITY,
            'HttpKernel logs a client error at `error` before the responder answers, so every API 4xx flushes',
        );
    }

    #[Test]
    public function everyClientErrorTheApiAnswersIsLoggedBelowTheLevelTheDeployedHandlerFlushesAt(): void
    {
        $compiled = (new DeployedBufferActionLevel(self::getContainer()))->level();
        $environment = self::getContainer()->getParameter('kernel.environment');
        $this->assertIsString($environment);

        $this->assertSame(
            $this->declaredActionLevels()[$environment . '/main'] ?? null,
            $compiled,
            'the compiled reading is not the `main` handler this environment declares, so the halves disagree',
        );

        $this->assertEveryClientErrorStaysBelow($compiled, 'the handler this environment compiled');
    }

    /**
     * The compiled reading above is this environment's, and `prod` compiles nowhere a booted test kernel can
     * see. Comparing the declarations is what makes a `prod` block lowered on its own — every other gate
     * green, the deployment one 404 away from dumping its buffer — fail here.
     */
    #[Test]
    public function everyEnvironmentDeclaringTheBufferDeclaresALevelNoClientErrorReaches(): void
    {
        $declared = $this->declaredActionLevels();

        $this->assertArrayHasKey(
            'prod/main',
            $declared,
            'the deployed environment declares no buffered `main` handler, so nothing here answers for it',
        );

        foreach ($declared as $handler => $level) {
            $this->assertEveryClientErrorStaysBelow($level, \sprintf('the "%s" handler', $handler));
        }
    }

    private function assertEveryClientErrorStaysBelow(Level $threshold, string $origin): void
    {
        foreach ($this->clientErrorEmissions() as $description => [$status, $emitted]) {
            $this->assertLessThan(
                $threshold->value,
                $emitted->value,
                \sprintf(
                    '%s answers %d and is logged at %s, while %s activates at %s — so every one of them '
                    . "flushes the buffered records, a person's identifier among them, into the unrotated sink",
                    $description,
                    $status,
                    $emitted->getName(),
                    $origin,
                    $threshold->getName(),
                ),
            );
        }
    }

    /**
     * Every client error the corpus can provoke, as the status the responder ANSWERED with and the level it
     * LOGGED at — both read off the listener's own output rather than restated here.
     *
     * @return array<string, array{int, Level}>
     */
    private function clientErrorEmissions(): array
    {
        if (null !== $this->emissions) {
            return $this->emissions;
        }

        $emissions = [];

        foreach ($this->clientErrorCorpus() as $description => $throwable) {
            $emissions[$description] = $this->emissionFor($throwable, $description);
        }

        $this->assertStatusesCoverTheDeclaredContract($emissions);

        return $this->emissions = $emissions;
    }

    /**
     * Drives the real listener, with the container's own collaborators and a capturing handler where its
     * logger goes. The response is read back for the status because that is what the client is answered
     * with, and `isPropagationStopped()` is asserted because it is the mechanism that keeps HttpKernel's
     * `ErrorListener` off this path at all.
     *
     * @return array{int, Level}
     */
    private function emissionFor(Throwable $throwable, string $description): array
    {
        $sink = new TestHandler();
        $kernel = self::$kernel;
        $this->assertInstanceOf(HttpKernelInterface::class, $kernel);

        $responder = new ExceptionResponder(
            $this->service(ProblemDetailsFactory::class),
            $this->service(ProblemDetailsResponder::class),
            new Logger('app', [$sink]),
            $this->service(ApiRequestMatcher::class),
        );

        $event = new ExceptionEvent(
            $kernel,
            Request::create(self::PROBE_PATH),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
        $responder($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(
            Response::class,
            $response,
            \sprintf('%s was not answered by the responder', $description),
        );
        $this->assertTrue(
            $event->isPropagationStopped(),
            \sprintf('%s leaves the exception chain running, so HttpKernel logs it at `error` too', $description),
        );

        $records = $sink->getRecords();
        $this->assertCount(
            1,
            $records,
            \sprintf('%s emits %d records, and the coupling holds for exactly one', $description, \count($records)),
        );

        $record = \reset($records);
        $status = $response->getStatusCode();
        $this->assertGreaterThanOrEqual(Response::HTTP_BAD_REQUEST, $status);
        $this->assertLessThan(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $status,
            \sprintf('%s is no client error', $description),
        );

        return [$status, $record->level];
    }

    /**
     * The throwables the `/api/*` surface answers a client error with, one per arm of
     * `ProblemDetailsFactory::fromThrowable()` plus one per status the contract declares. The
     * `DomainException` is what makes the `LogicException` carve-out observable; the rest are
     * `RuntimeException` descendants and cover the other family.
     *
     * @return array<string, Throwable>
     */
    private function clientErrorCorpus(): array
    {
        $corpus = [
            'a domain client error' => new class ('', 'Not found') extends DomainException implements NotFound {
            },
            'a validation failure' => new ValidationFailedException(new stdClass(), new ConstraintViolationList()),
            'a denied access' => new AccessDeniedException(),
            'a failed authentication' => new AuthenticationException(),
            'a status outside the contract' => new HttpException($this->statusOutsideTheContract()),
        ];

        foreach ($this->clientErrorStatusesTheContractDeclares() as $status) {
            $corpus[\sprintf('an HTTP %d', $status)] = new HttpException($status);
        }

        return $corpus;
    }

    /**
     * Without this the corpus above is a list somebody has to remember to extend: a marker mapped to a
     * status nothing exercises would ship with every assertion here green over the statuses that remain.
     *
     * @param array<string, array{int, Level}> $emissions
     */
    private function assertStatusesCoverTheDeclaredContract(array $emissions): void
    {
        $answered = [];

        foreach ($emissions as [$status]) {
            $answered[$status] = true;
        }

        foreach ($this->clientErrorStatusesTheContractDeclares() as $status) {
            $this->assertArrayHasKey(
                $status,
                $answered,
                \sprintf('the contract declares %d and nothing here puts it on the level scale', $status),
            );
        }
    }

    /**
     * Every client-error status the error contract declares, plus every one the deployed exclusion names —
     * that second set is where the codes this coupling was reasoned about (404, 405) come from, and 405 is
     * in no map the factory holds.
     *
     * @return list<int>
     */
    private function clientErrorStatusesTheContractDeclares(): array
    {
        $reflection = new ReflectionClass(ProblemDetailsFactory::class);
        $markers = $reflection->getConstant('MARKER_STATUS_MAP');
        $types = $reflection->getConstant('HTTP_STATUS_TYPE_MAP');
        $this->assertIsArray($markers);
        $this->assertIsArray($types);

        $declared = [
            ...\array_values($markers),
            ...\array_keys($types),
            ...(new DeployedFingersCrossedGate(self::getContainer()))->unconditionallyExcludedCodes(),
        ];

        $statuses = [];

        foreach ($declared as $status) {
            $isClientError = \is_int($status)
                && $status >= Response::HTTP_BAD_REQUEST
                && $status < Response::HTTP_INTERNAL_SERVER_ERROR;

            if ($isClientError) {
                $statuses[$status] = $status;
            }
        }

        $this->assertNotEmpty($statuses, 'the contract declares no client-error status, so nothing is exercised');
        \ksort($statuses);

        return \array_values($statuses);
    }

    /**
     * A client-error status no map holds, so the factory's `http-error` fallback is exercised without this
     * file naming a status the contract may adopt later.
     */
    private function statusOutsideTheContract(): int
    {
        $declared = $this->clientErrorStatusesTheContractDeclares();

        for ($status = Response::HTTP_BAD_REQUEST; $status < Response::HTTP_INTERNAL_SERVER_ERROR; ++$status) {
            if (!\in_array($status, $declared, true)) {
                return $status;
            }
        }

        $this->fail('every client-error status is declared, so the fallback branch cannot be reached');
    }

    /**
     * @return array<string, Level>
     */
    private function declaredActionLevels(): array
    {
        return (new DeclaredFingersCrossedHandlers(\dirname(__DIR__, 4) . '/config/packages'))->actionLevels();
    }

    /**
     * Read off HttpKernel's own subscription rather than named here: the listener this coupling pre-empts is
     * the one that logs, not the one that renders, and their priorities differ.
     */
    private function preemptedListenerPriority(): int
    {
        $subscriptions = ErrorListener::getSubscribedEvents()[KernelEvents::EXCEPTION] ?? null;
        $this->assertIsArray($subscriptions);

        foreach ($subscriptions as $subscription) {
            if (!\is_array($subscription) || self::PREEMPTED_LISTENER !== \reset($subscription)) {
                continue;
            }

            // A subscription naming no priority runs at zero, which is the dispatcher's own default.
            return $subscription[1] ?? 0;
        }

        $this->fail(\sprintf(
            'HttpKernel no longer subscribes %s, so this coupling reads a listener that is gone',
            self::PREEMPTED_LISTENER,
        ));
    }
}
