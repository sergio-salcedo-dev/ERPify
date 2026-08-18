<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * The rules the public-access gate applies, as pure functions over parsed input.
 *
 * Kept separate from the readers so each rule can be driven over synthetic input — every shape the config
 * accepts, without a dirty entry ever existing in the real files — the way the persistent-transport gate
 * already separates its resolution from its assertions.
 *
 * @internal test support
 *
 * @phpstan-import-type Classification from PublicAccessExemptionRegistry
 */
final class PublicAccessExemptionRules
{
    public const string PUBLIC_ROLE = 'PUBLIC_ACCESS';

    public const string CATCH_ALL_PATH = '^/api';

    public const string AUTHENTICATED_ROLE = 'IS_AUTHENTICATED_FULLY';

    /**
     * A rule keyed on the route NAME rather than on a path. Symfony accepts both; the registry records the
     * spelling the config uses, so the two can be compared textually.
     */
    private const string ROUTE_KEY_PREFIX = 'route:';

    /**
     * Keys whose effect on who is admitted this gate cannot evaluate. Refused rather than ignored: a rule
     * it cannot read is a rule it cannot classify, and silence there fails toward an unreviewed exemption.
     */
    private const array UNREADABLE_KEYS = ['allow_if', 'request_matcher'];

    /**
     * Every `access_control` rule that lets an unauthenticated caller through, as the matcher it declares.
     *
     * Two shapes qualify, and the second is the one that reads as safe and is not. A rule whose roles hold
     * PUBLIC_ACCESS is an explicit exemption. A rule whose roles are ABSENT or EMPTY is a stronger one:
     * `AccessListener::authenticate` returns before deciding anything at all, so the route is open and the
     * word PUBLIC_ACCESS never appears. A sweep keyed on that token sees neither, and neither does one
     * keyed on `path`, because Symfony also accepts `route:`.
     *
     * Parsed, never matched as text: the token also appears in prose, so a textual sweep invents exemptions
     * no registry can satisfy and keeps reading a commented-out entry as live.
     *
     * @param array<array-key, mixed> $security
     *
     * @return list<string>
     */
    public static function exemptionsIn(array $security): array
    {
        $exemptions = [];

        foreach (self::accessControlRules($security) as $rule) {
            foreach (self::UNREADABLE_KEYS as $key) {
                if (isset($rule[$key])) {
                    throw new RuntimeException(\sprintf(
                        'An access_control rule declares `%s`, which this gate cannot evaluate. Whether it '
                        . 'admits an unauthenticated caller has to be decided by a person, so the rule is '
                        . 'refused rather than assumed safe.',
                        $key,
                    ));
                }
            }

            $matcher = self::matcherOf($rule);

            if (null !== $matcher && self::admitsAnonymous($rule)) {
                $exemptions[] = $matcher;
            }
        }

        return $exemptions;
    }

    /**
     * The catch-all is what turns everything else into an exemption. Without a terminal `^/api` rule
     * demanding authentication, the allowlist is not an allowlist — it is the whole policy, and every route
     * absent from it is open while this gate reports a perfect bijection.
     *
     * @param array<array-key, mixed> $security
     *
     * @return list<string>
     */
    public static function defaultDenyViolations(array $security): array
    {
        $rules = self::accessControlRules($security);
        $last = \end($rules);

        if (false === $last) {
            return ['access_control declares no rule at all, so nothing under `^/api` requires a session.'];
        }

        $roles = $last['roles'] ?? null;
        $roleList = \is_array($roles) ? $roles : [$roles];

        if (self::CATCH_ALL_PATH !== ($last['path'] ?? null) || !\in_array(self::AUTHENTICATED_ROLE, $roleList, true)) {
            return [\sprintf(
                'The last access_control rule must be `%s` requiring %s. First match wins, so a catch-all '
                . 'that is absent — or no longer last — leaves every unlisted route open with this gate green.',
                self::CATCH_ALL_PATH,
                self::AUTHENTICATED_ROLE,
            )];
        }

        return [];
    }

    /**
     * The bijection, in both directions. An exemption with no line is an unreviewed hole in the firewall;
     * a line with no exemption is a registry drifting into documentation.
     *
     * @param list<string>                  $exemptions
     * @param array<string, Classification> $registry
     *
     * @return list<string>
     */
    public static function bijectionViolations(array $exemptions, array $registry): array
    {
        $violations = [];

        foreach ($exemptions as $matcher) {
            if (!isset($registry[$matcher])) {
                $violations[] = \sprintf(
                    'Rule "%s" admits an unauthenticated caller but carries no line in '
                    . '.public-access-exemptions. Classify it: an unreviewed exemption is a hole nobody chose.',
                    $matcher,
                );
            }
        }

        foreach (\array_keys($registry) as $matcher) {
            if (!\in_array($matcher, $exemptions, true)) {
                $violations[] = \sprintf(
                    'Rule "%s" is classified in .public-access-exemptions but no longer admits an '
                    . 'unauthenticated caller. Delete the line: a registry that outlives what it describes is prose.',
                    $matcher,
                );
            }
        }

        return $violations;
    }

    /**
     * What the anchor buys is not "being anchored" — it is that the exemption matches ONE route rather than
     * a subtree. Checking the trailing `$` alone does not establish that: `^/api/v1/(a|b)$` is anchored and
     * exempts two routes, and `^/api/v1/health.*$` is anchored and exempts a whole subtree. So an `exact`
     * pattern must also be a LITERAL path — no regex metacharacter between the anchors.
     *
     * @param array<string, Classification> $registry
     *
     * @return list<string>
     */
    public static function formViolations(array $registry): array
    {
        $violations = [];

        foreach ($registry as $matcher => $entry) {
            $isRouteName = \str_starts_with($matcher, self::ROUTE_KEY_PREFIX);
            $anchored = \str_ends_with($matcher, '$');

            if (PublicAccessExemptionRegistry::PREFIX === $entry['form']) {
                if ($anchored || $isRouteName) {
                    $violations[] = \sprintf(
                        'Rule "%s" is classified `prefix` but matches a single target. Classify it `exact`.',
                        $matcher,
                    );
                }

                continue;
            }

            if ($isRouteName) {
                continue;
            }

            if (!$anchored) {
                $violations[] = \sprintf(
                    'Pattern "%s" is classified `exact` but is not anchored with `$`, so it exempts every route '
                    . 'nested under it. That is how the database probe became anonymous.',
                    $matcher,
                );

                continue;
            }

            if (1 !== \preg_match('#^\^[A-Za-z0-9/_-]+\$$#', $matcher)) {
                $violations[] = \sprintf(
                    'Pattern "%s" is classified `exact` but is not a literal path — a regex metacharacter '
                    . 'between the anchors can match more than one route while still ending in `$`. An '
                    . 'exemption that spans a set is a `prefix`, and a prefix must name what bounds it.',
                    $matcher,
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $security
     *
     * @return list<array<array-key, mixed>>
     */
    private static function accessControlRules(array $security): array
    {
        $section = $security['security'] ?? null;
        $rules = \is_array($section) ? ($section['access_control'] ?? null) : null;

        if (!\is_array($rules)) {
            return [];
        }

        return \array_values(\array_filter($rules, \is_array(...)));
    }

    /**
     * @param array<array-key, mixed> $rule
     */
    private static function matcherOf(array $rule): ?string
    {
        if (\is_string($rule['path'] ?? null)) {
            return $rule['path'];
        }

        if (\is_string($rule['route'] ?? null)) {
            return self::ROUTE_KEY_PREFIX . $rule['route'];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $rule
     */
    private static function admitsAnonymous(array $rule): bool
    {
        $roles = $rule['roles'] ?? null;

        if (null === $roles || [] === $roles) {
            return true;
        }

        return \in_array(self::PUBLIC_ROLE, \is_array($roles) ? $roles : [$roles], true);
    }
}
