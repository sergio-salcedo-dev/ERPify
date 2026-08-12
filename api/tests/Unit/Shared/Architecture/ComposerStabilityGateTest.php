<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over `api/composer.json`: nothing in `require` may admit a non-stable version.
 *
 * `minimum-stability: stable` is a declaration, not an enforcement — a per-package stability flag
 * (`0.4.x-dev`, `dev-main`, `^4.0@alpha`) overrides it silently, and Composer records the override in
 * `composer.lock`'s `stability-flags` where nobody looks. This repo shipped exactly that for months:
 * `symfony/mercure-bundle: 0.4.x-dev` sat in `require`, so an untagged upstream commit reached
 * production, and `composer update` on that package moved it to whatever `main` HEAD was. It was a
 * deliberate, argued exception — the tagged release tripped a real deprecation gate — but nothing in
 * the build could tell that exception apart from an accident, and the knowledge lived in prose plus a
 * bespoke weekly cron that was deleted with the pin it watched.
 *
 * `require-dev` is deliberately out of scope. Two entries live there today for good reasons
 * (`behat/behat` at `@alpha`, which is what lifted the `symfony/console` cap, and
 * `roave/security-advisories` at `dev-latest`, which is only ever a dev-latest package), and a
 * development tool that never ships cannot put unreviewed code in front of a customer.
 *
 * What a green proves: no shipped requirement carries a stability flag or a branch constraint. What it
 * does not prove: that the locked versions are the ones the manifest describes (that is `composer
 * validate`'s job), that a stable tag is any safer than the branch it replaced, or anything at all
 * about transitive dependencies — a stable package may itself require a dev one, and this gate reads
 * only the root manifest.
 *
 * @internal
 */
#[CoversNothing]
final class ComposerStabilityGateTest extends TestCase
{
    /**
     * Failure preamble — a class const so make-target wrappers and CI log scrapers can grep the
     * literal string, and so the rule travels with the failure instead of just the package name.
     */
    private const string RULE = 'Nothing in composer.json `require` may admit a non-stable version: '
        . 'a branch constraint or an @-stability flag there ships unreviewed upstream code. '
        . 'Put development-only tools in `require-dev`, or argue the exception in api/CLAUDE.md '
        . 'with its retirement trigger written beside the constraint.';

    #[Test]
    public function shippedRequirementsAdmitOnlyStableVersions(): void
    {
        $manifest = $this->manifest();

        $require = $manifest['require'] ?? [];
        $this->assertIsArray($require);

        // A scan over an empty or misread `require` block would pass vacuously and look identical to a
        // clean tree, which is the failure mode this whole directory exists to prevent.
        $this->assertNotEmpty($require, 'composer.json declares no `require` block — the gate read nothing.');
        $this->assertArrayHasKey('php', $require, 'composer.json `require` has no `php` entry — wrong file?');

        $offenders = [];

        foreach ($require as $package => $constraint) {
            if (!\is_string($constraint)) {
                continue;
            }

            if ($this->admitsNonStable($constraint)) {
                $offenders[] = \sprintf('%s: %s', $package, $constraint);
            }
        }

        $this->assertSame([], $offenders, self::RULE . "\nOffending requirements:\n  " . \implode("\n  ", $offenders));
    }

    /**
     * The premise the rule rests on. Flipping `minimum-stability` would let every constraint admit
     * prereleases without any of them carrying a flag this gate can see, so the check above would keep
     * passing over a tree it no longer describes.
     */
    #[Test]
    public function minimumStabilityIsStable(): void
    {
        $manifest = $this->manifest();

        $this->assertSame(
            'stable',
            $manifest['minimum-stability'] ?? null,
            'composer.json must declare `minimum-stability: stable`; ' . self::RULE,
        );
    }

    /**
     * Falsifiability of the predicate itself. Without this, a typo in the pattern turns the gate above
     * into a test that passes because it recognises nothing.
     */
    #[Test]
    #[DataProvider('provideTheRuleRejectsANonStableConstraintCases')]
    public function theRuleRejectsANonStableConstraint(string $constraint): void
    {
        $this->assertTrue($this->admitsNonStable($constraint), \sprintf('"%s" should be rejected', $constraint));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheRuleRejectsANonStableConstraintCases(): iterable
    {
        yield 'branch alias' => ['0.4.x-dev'];
        yield 'branch name' => ['dev-main'];
        yield 'branch name with alias' => ['dev-main as 0.4.0'];
        yield 'dev stability flag' => ['^1.0@dev'];
        yield 'alpha stability flag' => ['^4.0@alpha'];
        yield 'beta stability flag' => ['^2.1@beta'];
        yield 'RC stability flag' => ['^3.0@RC'];
        yield 'flag on one alternative' => ['^1.0 || ^2.0@beta'];
    }

    #[Test]
    #[DataProvider('provideTheRuleAcceptsAStableConstraintCases')]
    public function theRuleAcceptsAStableConstraint(string $constraint): void
    {
        $this->assertFalse($this->admitsNonStable($constraint), \sprintf('"%s" should be accepted', $constraint));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheRuleAcceptsAStableConstraintCases(): iterable
    {
        yield 'caret' => ['^8.1.1'];
        yield 'wildcard minor' => ['8.1.*'];
        yield 'exact' => ['2.12.3'];
        yield 'any' => ['*'];
        yield 'range' => ['>=1.0 <2.0'];
        yield 'alternatives' => ['^0.6.1|^0.7'];
        // "development" is a package name here, not a stability flag: the rule must key on the
        // constraint's grammar, not on a substring anyone could hit by accident.
        yield 'zero-major caret' => ['^0.8'];
    }

    /**
     * A constraint admits a non-stable version when it names a branch (`dev-*`, `*.x-dev`) or carries an
     * explicit `@`-stability flag below `stable`. Both override `minimum-stability` per package.
     */
    private function admitsNonStable(string $constraint): bool
    {
        foreach (\preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            $alternative = \trim($alternative);

            if (1 === \preg_match('/@(?:dev|alpha|beta|RC)\b/i', $alternative)) {
                return true;
            }

            foreach (\preg_split('/\s+/', $alternative) ?: [] as $token) {
                if (1 === \preg_match('/^dev-\S+/', $token) || \str_ends_with($token, '-dev')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $path = \dirname(__DIR__, 4) . '/composer.json';

        $this->assertFileExists($path, 'composer.json not found — the gate cannot read what it guards.');

        $raw = \file_get_contents($path);
        $this->assertIsString($raw, 'composer.json is unreadable — failing loud rather than passing blind.');

        $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
