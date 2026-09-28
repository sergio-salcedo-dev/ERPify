<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * The rules of the credential-proof gate, as pure functions over already-resolved input so each one can be
 * driven over a violation without a dirty entry ever existing in the tree.
 *
 * The invariant: every authenticated act that creates, replaces or destroys a credential re-proves the
 * current password AND spends the one per-identity budget every such act shares. The proof is only half of
 * it — no wrong `currentPassword` feeds the persisted lockout, so the shared bucket is the only ceiling on
 * guessing that password from a stolen session, and a route with a bucket of its own (or none) hands the
 * holder of that session more guesses.
 *
 * Calls are read ({@see PhpCallSites}) inside ONE method body at each level — the action the route is bound to,
 * and the use-case method that action invokes. A call reached through a helper method, a sibling action or a
 * second-level collaborator lies outside the body read, so it reds rather than passing.
 *
 * @internal test support
 */
final class CredentialProofRules
{
    public const string CREDENTIAL_AFFECTING = 'credential-affecting';

    public const string ORDINARY = 'ordinary';

    public const string ANONYMOUS = 'anonymous';

    public const string THROTTLE = \Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle::class;

    public const string PROOF = \Erpify\Iam\Identity\Application\ProveCurrentPassword::class;

    public const string HASHER = \Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher::class;

    private const string SEPARATOR = ' :: ';

    private const string ROUTE_KEY_PREFIX = 'route:';

    /**
     * @param list<string> $lines
     *
     * @return array<string, array{class: string, reason: ?string}>
     */
    public static function parse(array $lines): array
    {
        $registry = [];

        foreach ($lines as $line) {
            $fields = \explode(self::SEPARATOR, $line);
            $route = \trim($fields[0]);
            $class = \trim($fields[1] ?? '');
            $reason = isset($fields[2]) ? \trim($fields[2]) : null;

            $shapeIsValid = match ($class) {
                self::CREDENTIAL_AFFECTING, self::ORDINARY => 2 === \count($fields),
                self::ANONYMOUS => 3 === \count($fields) && '' !== $reason && !\str_contains((string) $reason, '::'),
                default => false,
            };

            if ('' === $route || !$shapeIsValid) {
                throw new RuntimeException(\sprintf(
                    'Malformed .credential-proof-policy line "%s": expected "<route> :: %s", "<route> :: %s"'
                    . ' or "<route> :: %s :: <reason>" (no field may contain "::").',
                    $line,
                    self::CREDENTIAL_AFFECTING,
                    self::ORDINARY,
                    self::ANONYMOUS,
                ));
            }

            if (isset($registry[$route])) {
                throw new RuntimeException(
                    \sprintf('Route "%s" is classified twice in .credential-proof-policy.', $route),
                );
            }

            $registry[$route] = ['class' => $class, 'reason' => $reason];
        }

        return $registry;
    }

    /**
     * Completeness and staleness: every route the router declares has a line, and every line names a route
     * the router still declares.
     *
     * @param list<string>                                         $routes
     * @param array<string, array{class: string, reason: ?string}> $registry
     *
     * @return list<string>
     */
    public static function bijectionViolations(array $routes, array $registry): array
    {
        $violations = [];

        foreach ($routes as $route) {
            if (!isset($registry[$route])) {
                $violations[] = \sprintf(
                    'Route "%s" has no line in api/.credential-proof-policy — classify it.',
                    $route,
                );
            }
        }

        foreach (\array_keys($registry) as $route) {
            if (!\in_array($route, $routes, true)) {
                $violations[] = \sprintf(
                    'api/.credential-proof-policy classifies "%s", which the route manifest no longer declares.',
                    $route,
                );
            }
        }

        return $violations;
    }

    /**
     * `anonymous` is the one class that exempts a route from the question, so it must not be available to an
     * authenticated route: its path has to match a pattern the firewall exempts, or its name a `route:` key.
     *
     * @param array<string, array{class: string, reason: ?string}> $registry
     * @param array<string, string>                                $paths          route name => path
     * @param list<string>                                         $publicPatterns `access_control` keys
     *
     * @return list<string>
     */
    public static function anonymousViolations(array $registry, array $paths, array $publicPatterns): array
    {
        $violations = [];

        foreach ($registry as $route => $entry) {
            if (self::ANONYMOUS !== $entry['class'] || !isset($paths[$route])) {
                continue;
            }

            $path = $paths[$route];
            $admitted = \array_any(
                $publicPatterns,
                static fn (string $pattern): bool => \str_starts_with($pattern, self::ROUTE_KEY_PREFIX)
                    ? self::ROUTE_KEY_PREFIX . $route === $pattern
                    : 1 === \preg_match('#' . \str_replace('#', '\#', $pattern) . '#', $path),
            );

            if (!$admitted) {
                $violations[] = \sprintf(
                    'Route "%s" (%s) is classified anonymous but no api/.public-access-exemptions pattern admits it'
                    . ' — an authenticated route cannot opt out of the proof.',
                    $route,
                    $path,
                );
            }
        }

        return $violations;
    }

    /**
     * A `credential-affecting` route must resolve to exactly one controller action to be judged at all.
     *
     * @param list<array{class: string, method: string}> $actions
     *
     * @return list<string>
     */
    public static function actionViolations(string $route, array $actions): array
    {
        return match (\count($actions)) {
            1 => [],
            0 => [\sprintf(
                'Route "%s" resolves to no #[Route] under src/ — declare its name explicitly (`name:`), since an'
                . ' auto-generated name cannot be resolved back to its controller.',
                $route,
            )],
            default => [\sprintf(
                'Route "%s" is declared by %d #[Route] attributes under src/, expected exactly one.',
                $route,
                \count($actions),
            )],
        };
    }

    /**
     * The proof itself, over the ACTION one `credential-affecting` route is bound to.
     *
     * `$controllerCollaborators` maps each constructor parameter name to the types it names; `$useCases` maps a
     * parameter name to that collaborator's class source and its own collaborators.
     *
     * @param array<string, list<string>>                                                      $controllerCollaborators
     * @param array<string, array{source: string, collaborators: array<string, list<string>>}> $useCases
     *
     * @return list<string>
     */
    public static function proofViolations(
        string $route,
        string $controllerSource,
        string $action,
        array $controllerCollaborators,
        array $useCases,
    ): array {
        $bodies = PhpCallSites::methodBodies($controllerSource);

        if (!isset($bodies[$action])) {
            return [\sprintf('Route "%s": its controller declares no method %s().', $route, $action)];
        }

        $throttle = self::parameterTyped($controllerCollaborators, self::THROTTLE);

        if (null === $throttle) {
            return [\sprintf('Route "%s": its controller does not inject CurrentPasswordProofThrottle.', $route)];
        }

        $calls = PhpCallSites::calls($bodies[$action]);
        $violations = [];
        $spend = self::firstCall($calls, $throttle, 'ensureWithinBudget');

        if (null === $spend) {
            $violations[] = \sprintf(
                'Route "%s": its %s() never calls ensureWithinBudget() on CurrentPasswordProofThrottle.',
                $route,
                $action,
            );
        }

        if (null === self::firstCall($calls, self::parameterTyped($controllerCollaborators, self::HASHER), 'verify')) {
            $violations[] = \sprintf(
                'Route "%s": its %s() never calls PasswordHasher::verify(), so nothing checks the submitted'
                . ' password against the stored one before the proof accepts it.',
                $route,
                $action,
            );
        }

        $provingCalls = self::provingCalls($calls, $useCases);

        if ([] === $provingCalls) {
            $violations[] = \sprintf(
                'Route "%s": no use-case method its %s() invokes calls ProveCurrentPassword::ensure().',
                $route,
                $action,
            );
        }

        return [...$violations, ...self::orderViolations($route, $spend, $provingCalls)];
    }

    /**
     * Positions, in the action's call list, of every call into a use-case method whose own body proves.
     *
     * @param list<array{string, string}>                                                      $calls
     * @param array<string, array{source: string, collaborators: array<string, list<string>>}> $useCases
     *
     * @return list<int>
     */
    private static function provingCalls(array $calls, array $useCases): array
    {
        $proving = [];

        foreach ($calls as $position => [$property, $method]) {
            $useCase = $useCases[$property] ?? null;
            $proof = null === $useCase ? null : self::parameterTyped($useCase['collaborators'], self::PROOF);

            if (null === $useCase || null === $proof) {
                continue;
            }

            $body = PhpCallSites::methodBodies($useCase['source'])[$method] ?? null;

            if (null !== $body && null !== self::firstCall(PhpCallSites::calls($body), $proof, 'ensure')) {
                $proving[] = $position;
            }
        }

        return $proving;
    }

    /**
     * @param list<int> $provingCalls
     *
     * @return list<string>
     */
    private static function orderViolations(string $route, ?int $spend, array $provingCalls): array
    {
        if (null === $spend || [] === $provingCalls || \min($provingCalls) > $spend) {
            return [];
        }

        return [\sprintf(
            'Route "%s": its controller reaches the proving use case before it spends the'
            . ' CurrentPasswordProofThrottle budget.',
            $route,
        )];
    }

    /**
     * @param list<array{string, string}> $calls
     */
    private static function firstCall(array $calls, ?string $property, string $method): ?int
    {
        if (null === $property) {
            return null;
        }

        foreach ($calls as $position => $call) {
            if ([$property, $method] === $call) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $collaborators
     */
    private static function parameterTyped(array $collaborators, string $type): ?string
    {
        foreach ($collaborators as $parameter => $types) {
            if (\in_array($type, $types, true)) {
                return $parameter;
            }
        }

        return null;
    }
}
