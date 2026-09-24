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
 * @internal test support
 */
final class CredentialProofRules
{
    public const string CREDENTIAL_AFFECTING = 'credential-affecting';

    public const string ORDINARY = 'ordinary';

    public const string ANONYMOUS = 'anonymous';

    public const string THROTTLE = \Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle::class;

    public const string PROOF = \Erpify\Iam\Identity\Application\ProveCurrentPassword::class;

    private const string SEPARATOR = ' :: ';

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
                self::ANONYMOUS => 3 === \count($fields) && '' !== $reason,
                default => false,
            };

            if ('' === $route || !$shapeIsValid) {
                throw new RuntimeException(\sprintf(
                    'Malformed .credential-proof-policy line "%s": expected "<route> :: %s", "<route> :: %s"'
                    . ' or "<route> :: %s :: <reason>".',
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
     * authenticated route: its path has to match a pattern the firewall exempts.
     *
     * @param array<string, array{class: string, reason: ?string}> $registry
     * @param array<string, string>                                $paths          route name => path
     * @param list<string>                                         $publicPatterns `access_control` path regexes
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
                static fn (string $pattern): bool => 1 === \preg_match(
                    '#' . \str_replace('#', '\#', $pattern) . '#',
                    $path,
                ),
            );

            if (!$admitted) {
                $violations[] = \sprintf(
                    'Route "%s" (%s) is classified anonymous but no api/.public-access-exemptions pattern admits it'
                    . ' — an authenticated route cannot opt out of the proof.',
                    $route,
                    $paths[$route],
                );
            }
        }

        return $violations;
    }

    /**
     * The proof itself, over the controller of one `credential-affecting` route.
     *
     * `$controllerCollaborators` maps each constructor parameter name to the types it names; `$useCases` maps a
     * parameter name to that collaborator's source and its own collaborators.
     *
     * @param array<string, list<string>>                                                      $controllerCollaborators
     * @param array<string, array{source: string, collaborators: array<string, list<string>>}> $useCases
     *
     * @return list<string>
     */
    public static function proofViolations(
        string $route,
        string $controllerSource,
        array $controllerCollaborators,
        array $useCases,
    ): array {
        $code = PhpSource::withoutComments($controllerSource);
        $throttle = self::parameterTyped($controllerCollaborators, self::THROTTLE);

        if (null === $throttle) {
            return [\sprintf('Route "%s": its controller does not inject CurrentPasswordProofThrottle.', $route)];
        }

        $spend = \strpos($code, '$this->' . $throttle . '->ensureWithinBudget(');

        if (false === $spend) {
            return [\sprintf(
                'Route "%s": its controller injects CurrentPasswordProofThrottle but never calls ensureWithinBudget().',
                $route,
            )];
        }

        foreach ($useCases as $parameter => $useCase) {
            $proof = self::parameterTyped($useCase['collaborators'], self::PROOF);

            $useCaseCode = PhpSource::withoutComments($useCase['source']);

            if (null === $proof || !\str_contains($useCaseCode, '$this->' . $proof . '->ensure(')) {
                continue;
            }

            $invocation = \strpos($code, '$this->' . $parameter . '->');

            if (false === $invocation) {
                continue;
            }

            if ($invocation < $spend) {
                return [\sprintf(
                    'Route "%s": its controller reaches the proving use case before it spends the'
                    . ' CurrentPasswordProofThrottle budget.',
                    $route,
                )];
            }

            return [];
        }

        return [\sprintf(
            'Route "%s": no use case its controller invokes calls ProveCurrentPassword::ensure().',
            $route,
        )];
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
