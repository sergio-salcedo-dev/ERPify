<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Problem;

use Erpify\Shared\Domain\Exception\Conflict;
use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\Forbidden;
use Erpify\Shared\Domain\Exception\InvalidInput;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;
use Erpify\Shared\Domain\Exception\InvariantViolation;
use Erpify\Shared\Domain\Exception\NotFound;
use Erpify\Shared\Domain\Exception\RateLimited;
use Erpify\Shared\Domain\Exception\Unauthenticated;
use JsonSerializable;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * Single mapping site between domain throwables and the {@see ProblemDetails} wire shape.
 *
 * Marker resolution honours the implements-clause order pinned by
 * `testMarkerOrderingFollowsImplementsClause`; the constant `MARKER_STATUS_MAP` is the
 * sole source of truth for the marker→HTTP-status mapping.
 *
 * environment-aware `debug` extension:
 *   - `dev` / `test`: every body carries a `debug` extension with `exception_class`,
 *     `message`, `file`, `line`, `previous_chain` (cycle-safe walk of `getPrevious()`).
 *   - `staging`: minimal `debug` extension carrying only `exception_class` + `message`.
 *   - `prod` (and any unrecognised `$environment`): no `debug` extension AT ALL, and the
 *     terminal `unhandled-exception` branch's `title` is replaced by the safe literal
 *     `'An unexpected error occurred.'` regardless of `$throwable->getMessage()` (prod
 *     no-leak guarantee — closes the message-leak path; the absent debug closes the
 *     stack-trace-leak path; the existing extensions whitelist closes the context-leak
 *     path). `$environment` flows in via `#[Autowire('%kernel.environment%')]` (the
 *     Symfony 8 idiom — never read `$_ENV` / `getenv()`).
 *   - `'debug'` is part of `RESERVED_KEYS` so domain code cannot inject a fake debug
 *     extension via {@see DomainException::context()}.
 *
 * redaction denylist: the `redactKeys()` seam delegates to
 * {@see RedactionDenylist::filter}, stripping case-insensitive exact-match keys
 * (`password`, `token`, `secret`, `authorization`, `cookie`, `ssn`, `iban`) from the
 * `DomainException::context()` before extensions promotion. The strip runs AFTER the
 * {@see RESERVED_KEYS} `unset()` layer and BEFORE the whitelist branch so a denylisted
 * `JsonSerializable` value cannot survive via the whitelist.
 *
 * unserializable sentinel and default-deny on unknown exception types:
 * the `applyUnserializableSentinel()` seam now substitutes any non-whitelisted
 * top-level context value with the literal token `'[unserializable]'` AND emits one PSR-3
 * `notice` log line per replacement carrying `{instance, correlation_id, context_key,
 * original_type}`. `original_type` is the {@see sanitiseExceptionClass}-cleaned FQCN for
 * objects (no `\0`/path leak) or `\gettype($value)` (e.g. `'resource (stream)'`,
 * `'object'`) for non-object scalars. Single-level only — values inside a whitelisted
 * array are preserved unchanged. The body sentinel is type-uniform (no `'[resource]'` /
 * `'[closure]'` variants) so structural metadata stays in logs, never in the body.
 *
 * The default-deny terminal branch ('throwable that is not a `DomainException`, not a
 * `ValidationFailedException` in the chain, not `AccessDeniedException` /
 * `AuthenticationException` / `HttpExceptionInterface`') lands on `type='unhandled-exception'`,
 * `status=500`, `extensions=[]`, and (in prod / unrecognised env) the safe literal title.
 *
 * 16 KiB body cap with truncation marker. Every {@see ProblemDetails}
 * returned from {@see fromThrowable} flows through {@see applyBodyCap}: if the JSON-encoded
 * body exceeds {@see BODY_BYTE_CAP}, extensions are truncated in a deterministic order and
 * `'truncated' => true` is appended as the LAST extension member. Truncation order:
 *   1. Pop one entry from the END of `extensions['violations']` if present and non-empty
 *      (violations is a list — popping the tail discards the most recent diagnostic, keeping
 *      the head intact for the client).
 *   2. Once `violations` is empty, drop the LAST extension key (reverse declaration order —
 *      `debug` first, then user-declared keys, finally `violations: []` when empty).
 *   3. If only the required core fields remain (`type, title, status, instance,
 *      correlation-id`) and the body STILL exceeds the cap, throw
 *      {@see ProblemBodyTooLargeException} so the listener's outer try/catch
 *      escalates to the static last-resort body.
 * The cap operates on serialised byte length using `\json_encode` with `JSON_UNESCAPED_UNICODE
 * | JSON_THROW_ON_ERROR` (mirrors {@see \Erpify\Shared\Infrastructure\Http\ProblemDetailsResponder}).
 *
 * constant-time branching for 401/403 paths. The four error routes that
 * yield 401/403 (`Unauthenticated` / `Forbidden` markers on a `DomainException` plus the
 * Symfony `AuthenticationException` / `AccessDeniedException` bridges) all flow through the
 * same construction shape — either `withDebug(new ProblemDetails(...))` for the marker path
 * or `withDebug($this->buildBridgeResponse(...))` for the bridge path — with no resource-
 * presence-conditional logic and no conditional I/O on the listener / factory path. The
 * factory holds no database, request, or filesystem dependency and never branches on whether
 * a controller-side resource exists or not — `fromThrowable` keys solely on exception type.
 *
 * Out of scope (explicit): application-level timing — database lookup latency, controller-
 * side resource resolution, repository / API client round trips — is the controller's
 * concern, not the listener's. NFR9 only covers the listener / factory's own contribution
 * to response time, which this contract pins via two source-text reflection tests
 * ({@see \Erpify\Tests\Unit\Shared\Application\Problem\ConstantTimeAuthBranchingContractTest})
 * and one informational microbenchmark
 * ({@see \Erpify\Tests\Unit\Shared\Application\Problem\ConstantTimeAuthBranchingBenchmarkTest})
 * with a generous 2x asymmetry threshold so it stays informative on shared CI hardware
 * without false-positive flakiness.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class ProblemDetailsFactory
{
    private const array MARKER_STATUS_MAP = [
        NotFound::class => Response::HTTP_NOT_FOUND,
        Conflict::class => Response::HTTP_CONFLICT,
        Forbidden::class => Response::HTTP_FORBIDDEN,
        Unauthenticated::class => Response::HTTP_UNAUTHORIZED,
        InvariantViolation::class => Response::HTTP_UNPROCESSABLE_ENTITY,
        InvalidInput::class => Response::HTTP_BAD_REQUEST,
        RateLimited::class => Response::HTTP_TOO_MANY_REQUESTS,
        InvalidSearchCriteria::class => Response::HTTP_BAD_REQUEST,
    ];

    private const array MARKER_DEFAULT_TYPE_MAP = [
        NotFound::class => 'not-found',
        Conflict::class => 'conflict',
        Forbidden::class => 'forbidden',
        Unauthenticated::class => 'unauthenticated',
        InvariantViolation::class => 'invariant-violation',
        InvalidInput::class => 'invalid-input',
        RateLimited::class => 'rate-limited',
        InvalidSearchCriteria::class => 'invalid-search-criteria',
    ];

    /**
     * status→type alignment for Symfony framework exceptions. Values mirror
     * `MARKER_DEFAULT_TYPE_MAP` for the corresponding statuses so PWA `type`-only routing
     * is uniform whether the error originated from a marker `DomainException`, a Security Core
     * exception, or a Symfony `HttpExceptionInterface`. The alignment is pinned by
     * `testHttpStatusTypeMapValuesMirrorMarkerDefaultTypeMapValues`.
     */
    private const array HTTP_STATUS_TYPE_MAP = [
        Response::HTTP_BAD_REQUEST => 'invalid-input',
        Response::HTTP_UNAUTHORIZED => 'unauthenticated',
        Response::HTTP_FORBIDDEN => 'forbidden',
        Response::HTTP_NOT_FOUND => 'not-found',
        Response::HTTP_CONFLICT => 'conflict',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'invariant-violation',
        Response::HTTP_TOO_MANY_REQUESTS => 'rate-limited',
    ];

    private const array RESERVED_KEYS = [
        'type',
        'title',
        'status',
        'detail',
        'instance',
        'correlation-id',
        'violations',
        'debug',
        'truncated',
    ];

    private const string DEBUG_MODE_FULL = 'full';

    private const string DEBUG_MODE_MINIMAL = 'minimal';

    private const string DEBUG_MODE_OMIT = 'omit';

    private const string UNHANDLED_TITLE_FALLBACK = 'An unexpected error occurred.';

    /**
     * wire-side body sentinel substituted for any non-whitelisted top-level context
     * value (closure, resource, plain object, anonymous-class proxy, etc.). The literal is
     * type-uniform: no `'[resource]'` / `'[closure]'` variants, no class name in the token —
     * structural metadata belongs in logs, not the body.
     */
    private const string UNSERIALIZABLE_SENTINEL = '[unserializable]';

    /**
     * PSR-3 message emitted exactly once per substituted context value. Operators
     * grep by `instance` for the per-error correlation, `correlation_id` for the request trail,
     * and `original_type` for the dropped value's PHP shape.
     */
    private const string SENTINEL_LOG_MESSAGE
        = 'DomainException context value substituted with unserializable sentinel.';

    /**
     * hard upper bound on the JSON-encoded Problem Details body, in bytes.
     * 16 KiB matches the typical proxy / CDN small-buffer cutoff above which downstream
     * intermediaries start fragmenting or buffering responses; bodies above this size also
     * exceed common observability-pipeline log-line limits (Datadog 1 MiB, but most grep tools
     * truncate at 8–16 KiB). Pinned by `testBodyByteCapConstantIsExactly16384`.
     */
    private const int BODY_BYTE_CAP = 16384;

    /**
     * appended as the LAST extension member when {@see applyBodyCap} drops at
     * least one extension entry. The literal `true` boolean signals truncation occurred to
     * downstream consumers without leaking which keys were dropped.
     */
    private const string TRUNCATED_MARKER_KEY = 'truncated';

    /**
     * Empty-property-path fallback. Symfony emits an empty `propertyPath` when the validator
     * runs against a scalar root (e.g. a route id passed through `Validator::ensure`) and the
     * caller did not supply a `propertyPath:` argument to rebind it. The wire contract
     * promises a non-empty `field` for every violation so the PWA can route per-field, so any
     * still-empty path collapses onto this neutral literal here as a last-resort safeguard.
     *
     * Callers SHOULD pass `propertyPath:` to `Validator::ensure` for scalar-root validations
     * — that produces a meaningful field name (e.g. `'id'`) instead of this generic fallback.
     */
    private const string VIOLATION_FIELD_FALLBACK = 'value';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    public function fromThrowable(Throwable $throwable, string $correlationId, string $instance): ProblemDetails
    {
        $validationException = $this->findInChain($throwable, ValidationFailedException::class);
        $message = $throwable->getMessage();
        $debug = $this->buildDebugExtension($throwable);

        // Each match arm independently wraps through applyBodyCap(withDebug(...)) so the
        // AccessDeniedException and AuthenticationException bridges remain structurally
        // identical (pinned by ConstantTimeAuthBranchingContractTest). Sharing a single
        // post-match wrap would let an attacker time-distinguish the 401/403 bridges.
        return match (true) {
            $throwable instanceof DomainException => $this->applyBodyCap(
                $this->withDebug(
                    $this->buildDomainExceptionResponse($throwable, $correlationId, $instance),
                    $debug,
                ),
            ),
            $validationException instanceof ValidationFailedException => $this->applyBodyCap(
                $this->withDebug(
                    new ProblemDetails(
                        type: 'validation-failed',
                        title: 'Validation failed.',
                        status: Response::HTTP_BAD_REQUEST,
                        detail: null,
                        instance: $instance,
                        correlationId: $correlationId,
                        extensions: ['violations' => $this->buildViolations($validationException->getViolations())],
                    ),
                    $debug,
                ),
            ),
            $throwable instanceof AccessDeniedException => $this->applyBodyCap(
                $this->withDebug(
                    $this->buildBridgeResponse(
                        type: 'forbidden',
                        status: Response::HTTP_FORBIDDEN,
                        title: '' !== $message
                            ? $message
                            : 'Access denied.',
                        correlationId: $correlationId,
                        instance: $instance,
                    ),
                    $debug,
                ),
            ),
            $throwable instanceof AuthenticationException => $this->applyBodyCap(
                $this->withDebug(
                    $this->buildBridgeResponse(
                        type: 'unauthenticated',
                        status: Response::HTTP_UNAUTHORIZED,
                        title: '' !== $message
                            ? $message
                            : 'Authentication required.',
                        correlationId: $correlationId,
                        instance: $instance,
                    ),
                    $debug,
                ),
            ),
            $throwable instanceof HttpExceptionInterface => $this->applyBodyCap(
                $this->withDebug(
                    $this->buildBridgeResponse(
                        type: self::HTTP_STATUS_TYPE_MAP[$throwable->getStatusCode()] ?? 'http-error',
                        status: $throwable->getStatusCode(),
                        title: '' !== $message
                            ? $message
                            : 'An HTTP error occurred.',
                        correlationId: $correlationId,
                        instance: $instance,
                    ),
                    $debug,
                ),
            ),
            default => $this->applyBodyCap(
                $this->withDebug(
                    new ProblemDetails(
                        type: 'unhandled-exception',
                        title: $this->resolveUnhandledTitle($throwable),
                        status: Response::HTTP_INTERNAL_SERVER_ERROR,
                        detail: null,
                        instance: $instance,
                        correlationId: $correlationId,
                        extensions: [],
                    ),
                    $debug,
                ),
            ),
        };
    }

    /**
     * Builds the `ProblemDetails` for a `DomainException`. Extracted so {@see fromThrowable}
     * stays within PHPMD complexity budgets — keeps the marker-precedence + explicit-type
     * resolution out of the top-level dispatcher.
     */
    private function buildDomainExceptionResponse(
        DomainException $throwable,
        string $correlationId,
        string $instance,
    ): ProblemDetails {
        $firstMarker = $this->firstMatchingMarker($throwable);
        $status = null !== $firstMarker ? self::MARKER_STATUS_MAP[$firstMarker] : Response::HTTP_INTERNAL_SERVER_ERROR;

        return new ProblemDetails(
            type: $this->resolveDomainType($throwable->type(), $firstMarker),
            title: $throwable->title(),
            status: $status,
            detail: null,
            instance: $instance,
            correlationId: $correlationId,
            extensions: $this->buildExtensions($throwable, $correlationId, $instance),
        );
    }

    /**
     * Resolves the wire `type` for a `DomainException`: explicit `type()` wins, otherwise
     * the marker default, otherwise the generic `'domain-error'` literal. Extracted to remove
     * the `else` clause and the chained `if/elseif/else` that previously lived inside
     * {@see fromThrowable}.
     *
     * @param key-of<self::MARKER_STATUS_MAP>|null $firstMarker
     */
    private function resolveDomainType(string $explicitType, ?string $firstMarker): string
    {
        if ('' !== $explicitType) {
            return $explicitType;
        }

        if (null !== $firstMarker) {
            return self::MARKER_DEFAULT_TYPE_MAP[$firstMarker];
        }

        return 'domain-error';
    }

    /**
     * @param iterable<ConstraintViolationInterface> $violations
     *
     * @return list<array{field: string, message: string, code: string}>
     */
    private function buildViolations(iterable $violations): array
    {
        $out = [];

        foreach ($violations as $violation) {
            $field = $violation->getPropertyPath();

            $out[] = [
                'field' => '' !== $field ? $field : self::VIOLATION_FIELD_FALLBACK,
                'message' => (string) $violation->getMessage(),
                'code' => $violation->getCode() ?? '',
            ];
        }

        return $out;
    }

    private function buildBridgeResponse(
        string $type,
        int $status,
        string $title,
        string $correlationId,
        string $instance,
    ): ProblemDetails {
        return new ProblemDetails(
            type: $type,
            title: $title,
            status: $status,
            detail: null,
            instance: $instance,
            correlationId: $correlationId,
        );
    }

    /**
     * Walks `$throwable->getPrevious()` looking for an instance of `$class` so the
     * ValidationFailedException branch also unwraps Symfony's `RequestPayloadValueResolver`
     * HttpException(422) wrapper (used by `#[MapRequestPayload]` / `#[MapQueryString]`).
     *
     * Cycle-safe via the same `\spl_object_id` seen-set guard {@see walkPreviousChain} uses
     * — a malformed chain (or a buggy rewrap that reuses a previous instance) MUST NOT
     * deadlock the listener and stall the FrankenPHP worker.
     *
     * @template T of Throwable
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function findInChain(?Throwable $throwable, string $class): ?Throwable
    {
        $seen = [];

        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            $id = \spl_object_id($current);

            if (isset($seen[$id])) {
                return null;
            }

            $seen[$id] = true;

            if ($current instanceof $class) {
                return $current;
            }
        }

        return null;
    }

    /**
     * Returns the FQCN of the first marker the exception declares, in `class_implements` order
     * intersected with the canonical marker list. Mirrors the precedence pinned by
     * `testMarkerOrderingFollowsImplementsClause`.
     *
     * @return key-of<self::MARKER_STATUS_MAP>|null
     */
    private function firstMatchingMarker(Throwable $e): ?string
    {
        /** @var array<string, class-string> $implemented */
        $implemented = \class_implements($e);

        /** @var list<key-of<self::MARKER_STATUS_MAP>> $markers */
        $markers = \array_values(\array_intersect(
            $implemented,
            \array_keys(self::MARKER_STATUS_MAP),
        ));

        return $markers[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExtensions(DomainException $domainException, string $correlationId, string $instance): array
    {
        $context = $domainException->context();

        foreach (self::RESERVED_KEYS as $reserved) {
            unset($context[$reserved]);
        }

        $context = $this->redactKeys($context);

        $extensions = [];

        foreach ($context as $key => $value) {
            if ($this->isWhitelistedValue($value)) {
                $extensions[$key] = $value;

                continue;
            }

            // substitute non-whitelisted top-level value with the literal sentinel
            // and emit one PSR-3 `notice` per replacement. The seam keeps factory state
            // out of the substitution helper by passing the correlation pair through.
            $extensions[$key] = $this->applyUnserializableSentinel($value, (string) $key, $correlationId, $instance);
        }

        return $extensions;
    }

    private function isWhitelistedValue(mixed $value): bool
    {
        return null === $value
            || \is_scalar($value)
            || \is_array($value)
            || $value instanceof JsonSerializable;
    }

    /**
     * exact-key case-insensitive denylist strip via {@see RedactionDenylist::filter}.
     *
     * Order is load-bearing: this runs AFTER the {@see RESERVED_KEYS} `unset()` strip and
     * BEFORE the {@see isWhitelistedValue} loop, so a denylisted `JsonSerializable` value
     * cannot survive via the whitelist branch. Pinned by
     * `testFactoryStripsDenylistedKeyEvenWhenValueIsJsonSerializable`.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function redactKeys(array $context): array
    {
        /** @var array<string, mixed> */
        return RedactionDenylist::filter($context);
    }

    /**
     * body sentinel substitution + per-replacement structured log.
     *
     * Always returns the literal {@see UNSERIALIZABLE_SENTINEL} (no type-variant tokens —
     * structural metadata stays in logs). Emits exactly one PSR-3 `notice` per call carrying
     * the four-field context `{instance, correlation_id, context_key, original_type}`.
     * `original_type` is the {@see sanitiseExceptionClass}-cleaned FQCN for objects (anonymous
     * class FQCNs lose their `\0/path:line$N` suffix so the log never carries a path leak) or
     * `\gettype($value)` (e.g. `'resource (closed)'`, `'resource (stream)'`) for non-objects.
     *
     * Single-level only — the caller (`buildExtensions`) only invokes this on the
     * non-whitelisted branch of a top-level loop; nested objects inside a whitelisted array
     * are preserved unchanged by design.
     */
    private function applyUnserializableSentinel(
        mixed $value,
        string $contextKey,
        string $correlationId,
        string $instance,
    ): string {
        $originalType = \is_object($value)
            ? $this->sanitiseExceptionClass($value::class)
            : \gettype($value);

        $this->logger->log(LogLevel::NOTICE, self::SENTINEL_LOG_MESSAGE, [
            'instance' => $instance,
            'correlation_id' => $correlationId,
            'context_key' => $contextKey,
            'original_type' => $originalType,
        ]);

        return self::UNSERIALIZABLE_SENTINEL;
    }

    /**
     * environment-aware decision:
     * exact-string match on `'dev'` / `'test'` / `'staging'`; anything else (including
     * uppercase, the empty string, `'ci'`, `'production'`) falls through to omit-debug.
     */
    private function resolveDebugMode(): string
    {
        return match ($this->environment) {
            'dev', 'test' => self::DEBUG_MODE_FULL,
            'staging' => self::DEBUG_MODE_MINIMAL,
            default => self::DEBUG_MODE_OMIT,
        };
    }

    /**
     * builds the `debug` extension shape per environment, or `null` to omit.
     *
     * Dev/test: 5-key map with `previous_chain` (cycle-safe flat walk of `getPrevious()`).
     * Staging: 2-key map (`exception_class`, `message`) only — NO `file`, NO `line`, NO chain.
     * Prod (or unrecognised env): `null` — caller MUST NOT add a `debug` extension.
     *
     * @return array{
     *     exception_class: string,
     *     message: string,
     *     file: string,
     *     line: int,
     *     previous_chain: list<array{exception_class: string, message: string, file: string, line: int}>,
     * }|array{exception_class: string, message: string}|null
     */
    private function buildDebugExtension(Throwable $e): ?array
    {
        $mode = $this->resolveDebugMode();

        if (self::DEBUG_MODE_OMIT === $mode) {
            return null;
        }

        if (self::DEBUG_MODE_MINIMAL === $mode) {
            return [
                'exception_class' => $this->sanitiseExceptionClass($e::class),
                'message' => $e->getMessage(),
            ];
        }

        return [
            'exception_class' => $this->sanitiseExceptionClass($e::class),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'previous_chain' => $this->walkPreviousChain($e),
        ];
    }

    /**
     * Cycle-safe flat walk of `$top->getPrevious()`. Each entry mirrors the four scalar
     * fields of the top-level debug map (no recursion — the chain itself IS the unfolded tree).
     *
     * @return list<array{exception_class: string, message: string, file: string, line: int}>
     */
    private function walkPreviousChain(Throwable $top): array
    {
        $chain = [];
        $seen = [];

        for ($current = $top->getPrevious(); $current instanceof Throwable; $current = $current->getPrevious()) {
            $id = \spl_object_id($current);

            if (isset($seen[$id])) {
                break;
            }

            $seen[$id] = true;
            $chain[] = [
                'exception_class' => $this->sanitiseExceptionClass($current::class),
                'message' => $current->getMessage(),
                'file' => $current->getFile(),
                'line' => $current->getLine(),
            ];
        }

        return $chain;
    }

    /**
     * Strips the `\0/path:line$N` suffix PHP appends to anonymous-class FQCNs (e.g.
     * `RuntimeException@anonymous\0/srv/app/src/Foo.php:42$0` → `RuntimeException@anonymous`).
     * Closes the staging-mode path-leak surface: AC #4 forbids `file`/`line` in the staging
     * `debug` shape, but the embedded NUL byte in `$throwable::class` would smuggle the file
     * path through `exception_class` when `json_encode` emits `  ` followed by the path
     * verbatim. Sanitisation is uniform across all environments — the path embedded in the
     * FQCN is rarely actionable for debugging because the dev/test full shape already exposes
     * the throw-site `file`/`line` separately.
     */
    private function sanitiseExceptionClass(string $class): string
    {
        $nul = \strpos($class, "\0");

        return false === $nul ? $class : \substr($class, 0, $nul);
    }

    /**
     * post-processing wrap that appends the `debug` extension LAST when present.
     *
     * The spread `[...$base->extensions, 'debug' => $debug]` preserves declaration order so
     * `debug` always slots after `violations` and any domain-emitted extensions. When `$debug`
     * is `null` (prod / unrecognised env), the base `ProblemDetails` is returned unchanged so
     * production bodies never carry a `debug` key.
     *
     * @param array{
     *     exception_class: string,
     *     message: string,
     *     file: string,
     *     line: int,
     *     previous_chain: list<array{exception_class: string, message: string, file: string, line: int}>,
     * }|array{exception_class: string, message: string}|null $debug
     */
    private function withDebug(ProblemDetails $base, ?array $debug): ProblemDetails
    {
        if (null === $debug) {
            return $base;
        }

        return new ProblemDetails(
            type: $base->type,
            title: $base->title,
            status: $base->status,
            detail: $base->detail,
            instance: $base->instance,
            correlationId: $base->correlationId,
            extensions: [...$base->extensions, 'debug' => $debug],
        );
    }

    /**
     * prod-safe title swap on the terminal `unhandled-exception` branch.
     * In prod (or any unrecognised env that falls through to `DEBUG_MODE_OMIT`), the
     * title is the static safe literal regardless of `$throwable->getMessage()`. In dev /
     * test / staging, the behaviour is preserved (message → title with a
     * safe fallback when empty), and the message also surfaces in `debug.message`.
     */
    private function resolveUnhandledTitle(Throwable $e): string
    {
        if (self::DEBUG_MODE_OMIT === $this->resolveDebugMode()) {
            return self::UNHANDLED_TITLE_FALLBACK;
        }

        $message = $e->getMessage();

        return '' !== $message ? $message : self::UNHANDLED_TITLE_FALLBACK;
    }

    /**
     * enforces the {@see BODY_BYTE_CAP} hard upper bound on the JSON-encoded
     * Problem Details body . Returns the input unchanged when the encoded body fits;
     * otherwise truncates extensions deterministically and appends `'truncated' => true` as
     * the LAST extension member.
     *
     * Truncation algorithm — deterministic so repeated runs over the same input produce
     * byte-identical output:
     *   1. Compute the candidate body size with `'truncated' => true` PROVISIONALLY
     *      appended (so the marker's own ~18 bytes are accounted for from the first iteration
     *      onward — avoids the off-by-one where we trim, then re-add the marker, then exceed
     *      again).
     *   2. While the candidate exceeds {@see BODY_BYTE_CAP}:
     *      a. If `extensions['violations']` exists and is a non-empty list, pop one entry
     *         from its END (preserves deterministic order: the LAST violation is dropped
     *         first, the head violations are kept). When the list reaches `[]`, the empty
     *         array stays in place — only step (b) drops the key itself.
     *      b. Else, drop the LAST extension key (reverse declaration order — `debug` first
     *         since {@see withDebug} appends it last; then user-declared extensions; finally
     *         a now-empty `violations: []` left over from step 2a).
     *      c. Recompute the candidate.
     *   3. If extensions becomes empty AND the core fields PLUS `'truncated' => true` STILL
     *      exceed the cap, throw {@see ProblemBodyTooLargeException}. The listener's outer
     *      try/catch then escalates to the static last-resort body. This is the
     *      only path where the cap can fail to produce a conforming body — by design,
     *      because the alternative is leaking partial / truncated core fields.
     *
     * The encoder flags MUST mirror {@see ProblemDetailsResponder::respond} so the cap and
     * the wire emission compute the same byte length. `JSON_THROW_ON_ERROR` keeps the helper
     * type-safe (encoder failures bubble out — they are listener self-failures and
     * handles them via the same outer try/catch).
     */
    private function applyBodyCap(ProblemDetails $problemDetails): ProblemDetails
    {
        if (\strlen($this->encodeBody($problemDetails->toArray())) <= self::BODY_BYTE_CAP) {
            return $problemDetails;
        }

        $core = [
            'type' => $problemDetails->type,
            'title' => $problemDetails->title,
            'status' => $problemDetails->status,
        ];

        if (null !== $problemDetails->detail) {
            $core['detail'] = $problemDetails->detail;
        }

        $core['instance'] = $problemDetails->instance;
        $core['correlation-id'] = $problemDetails->correlationId;

        $extensions = $problemDetails->extensions;

        while (
            \strlen($this->encodeBody($core + $extensions + [self::TRUNCATED_MARKER_KEY => true])) > self::BODY_BYTE_CAP
        ) {
            if ([] === $extensions) {
                throw new ProblemBodyTooLargeException(\sprintf(
                    'Problem Details body exceeds %d-byte cap on the required core fields alone.',
                    self::BODY_BYTE_CAP,
                ));
            }

            if (
                \array_key_exists('violations', $extensions)
                && \is_array($extensions['violations'])
                && [] !== $extensions['violations']
            ) {
                \array_pop($extensions['violations']);

                continue;
            }

            $lastKey = \array_key_last($extensions);
            unset($extensions[$lastKey]);
        }

        $extensions[self::TRUNCATED_MARKER_KEY] = true;

        return new ProblemDetails(
            type: $problemDetails->type,
            title: $problemDetails->title,
            status: $problemDetails->status,
            detail: $problemDetails->detail,
            instance: $problemDetails->instance,
            correlationId: $problemDetails->correlationId,
            extensions: $extensions,
        );
    }

    /**
     * single encode site shared by {@see applyBodyCap}'s size probe and the cap
     * loop. Flags mirror {@see ProblemDetailsResponder::respond} byte-for-byte so the cap
     * and the wire emission agree on length.
     *
     * @param array<string, mixed> $body
     */
    private function encodeBody(array $body): string
    {
        return \json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
