<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\StaticAuthorizationPolicy;
use Erpify\Tests\Support\ConstantValueTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tripwire for "policy = data, not mechanism": each policy map must be a literal, so authorization decisions
 * stay a data lookup and never grow a hand-written branch. Declaring the maps as `const` already lets the
 * compiler forbid closures and calls; this test adds a cheap second line — it tokenises the value of each
 * const and fails if any control-flow, closure, call, ternary, `new` or nullsafe token appears there.
 * Enum-case and class-constant fetches (`Role::VIEWER->value`, `self::WILDCARD`) are pure data and pass; a
 * `match`, `fn`, function call, `?:`, `new Foo` or `?->` slipped into the map would not. The heavier CI gate
 * (core-set invariance, `subject:` left unread) belongs to a later story; this one is the local, fast tripwire.
 *
 * The token machinery lives in {@see ConstantValueTokens}, shared with the catalog's twin tripwire
 * ({@see PermissionCatalogIsDataOnlyTest}) so both gates agree on what "literal" means.
 *
 * @internal
 */
#[CoversClass(StaticAuthorizationPolicy::class)]
final class StaticAuthorizationPolicyIsDataOnlyTest extends TestCase
{
    #[DataProvider('provideThePolicyDataConstantIsPureDataCases')]
    public function testThePolicyDataConstantIsPureData(string $constantName): void
    {
        $valueTokens = ConstantValueTokens::of(StaticAuthorizationPolicy::class, $constantName);

        $this->assertNotEmpty($valueTokens, \sprintf('Constant %s was not found in the policy source.', $constantName));

        foreach ($valueTokens as $valueToken) {
            $this->assertFalse(ConstantValueTokens::isExecutable($valueToken), \sprintf(
                'Policy data constant %s must be literal data, but found an executable token: %s',
                $constantName,
                ConstantValueTokens::describe($valueToken),
            ));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideThePolicyDataConstantIsPureDataCases(): iterable
    {
        yield 'TIER_VERBS' => ['TIER_VERBS'];
        yield 'EXPLICIT_GRANTS' => ['EXPLICIT_GRANTS'];
        yield 'TIER_OPT_OUT' => ['TIER_OPT_OUT'];
    }
}
