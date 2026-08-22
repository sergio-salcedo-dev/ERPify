<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Validator\Constraint;

/**
 * Every first-party validation constraint in `src` must resolve to a validator that exists.
 *
 * Symfony binds a constraint to its validator by NAME, not by wiring: `Constraint::validatedBy()` returns
 * `static::class . 'Validator'` unless a subclass says otherwise. So the binding is a convention held
 * together by the two classes sharing a namespace — and moving one of them breaks it silently. Nothing in
 * the build could see that: PHPStan reads no such relationship, deptrac reads dependencies rather than
 * absences, and the constraint's own unit test instantiates the validator DIRECTLY (that is what
 * `ConstraintValidatorTestCase::createValidator()` does), so it never travels through `validatedBy()` and
 * stays green over a binding that throws at runtime.
 *
 * That is not hypothetical. `EnumType` was moved one directory to satisfy a layering rule while
 * `EnumTypeValidator` stayed behind; `validatedBy()` then pointed at a class that did not exist, every
 * `#[EnumType]`-annotated property on `BankAccount` would have fatalled on validation, and PHPStan,
 * deptrac and the whole unit suite were green.
 *
 * What a green here proves: for every concrete `Constraint` subclass under `src`, the name
 * `validatedBy()` returns is a loadable class. What it does NOT prove: that the validator is registered
 * as a service (Symfony's default factory instantiates by class name, so it need not be), that it
 * validates correctly, or that a constraint returning a SERVICE ID rather than a class name is wired —
 * that indirection would fail here and needs its own proof, which is the intended outcome rather than a
 * gap to widen.
 *
 * @internal
 */
#[CoversNothing]
final class ConstraintValidatorResolutionGateTest extends TestCase
{
    private const string RULE = 'A validation constraint and its validator are bound by NAME: '
        . 'Constraint::validatedBy() returns `static::class . "Validator"`. Moving a constraint away from '
        . "its validator breaks that binding at runtime while every static gate and the constraint's own "
        . 'unit test stay green. Keep the pair in the same namespace, or override validatedBy() and prove '
        . 'the target resolves.';

    /**
     * PSR-4 root: `Erpify\` maps to `api/src/`.
     */
    private const string NAMESPACE_PREFIX = 'Erpify\\';

    #[Test]
    public function everyFirstPartyConstraintResolvesToAnExistingValidator(): void
    {
        $constraints = $this->firstPartyConstraints();

        // A walk that finds no constraint passes vacuously and looks identical to a healthy tree — the
        // exact shape of failure this directory exists to deny. `assertNotEmpty` alone is too weak to
        // say it: the population is two, so a walk regression that drops one of them still satisfies it.
        // Pinning the set is what makes the walk itself falsifiable, and it costs a deliberate edit when
        // a constraint is legitimately added.
        $this->assertSame(
            [
                \Erpify\Backoffice\BankAccount\Application\Validation\BicMatchingIban::class,
                \Erpify\Shared\Validation\Infrastructure\EnumType::class,
                \Erpify\Shared\Validation\Infrastructure\PasswordPolicy::class,
            ],
            $constraints,
            self::RULE . PHP_EOL . 'The set of first-party constraints under src/ changed. If that is '
                . 'deliberate, update this list; if the walk stopped reaching one, fix the walk.',
        );

        $stranded = [];

        foreach ($constraints as $constraint) {
            $validatorClass = $this->resolveValidator($constraint);

            if (!\class_exists($validatorClass)) {
                $stranded[] = \sprintf('%s -> %s (missing)', $constraint, $validatorClass);
            }
        }

        $this->assertSame(
            [],
            $stranded,
            self::RULE . PHP_EOL . 'Constraints whose validator does not exist:' . PHP_EOL
                . \implode(PHP_EOL, $stranded),
        );
    }

    /**
     * The gate's own falsifiability: the predicate has to be able to say NO, or a green above proves
     * nothing. Both fixtures are concrete constraints; only one has a validator at the derived name.
     *
     * @param class-string<Constraint> $constraint
     */
    #[Test]
    #[DataProvider('provideResolutionPredicateDistinguishesBoundFromStrandedCases')]
    public function resolutionPredicateDistinguishesBoundFromStranded(string $constraint, bool $expected): void
    {
        $this->assertSame($expected, \class_exists($this->resolveValidator($constraint)));
    }

    /**
     * @return iterable<string, array{class-string<Constraint>, bool}>
     */
    public static function provideResolutionPredicateDistinguishesBoundFromStrandedCases(): iterable
    {
        yield 'validator beside the constraint' => [
            Fixture\ConstraintResolution\ResolvableFixtureConstraint::class,
            true,
        ];

        yield 'constraint separated from its validator' => [
            Fixture\ConstraintResolution\StrandedFixtureConstraint::class,
            false,
        ];
    }

    /**
     * Calls the real method rather than re-deriving the convention, so a constraint that DOES override
     * `validatedBy()` is judged on what it actually returns. The constructor is bypassed because a
     * constraint's required arguments are irrelevant to the binding.
     *
     * @param class-string<Constraint> $constraintClass
     */
    private function resolveValidator(string $constraintClass): string
    {
        $reflection = new ReflectionClass($constraintClass);

        /** @var Constraint $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        return $instance->validatedBy();
    }

    /**
     * @return list<class-string<Constraint>>
     */
    private function firstPartyConstraints(): array
    {
        $sourceRoot = $this->apiRoot() . '/src';
        $prefix = \strlen($sourceRoot) + 1;
        $found = [];

        // Depth-unbounded: a scan capped at a nesting level carries this gate's own blind spot one
        // directory further down.
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($tree as $file) {
            // Unreachable at runtime — the iterator only ever yields SplFileInfo — but not removable:
            // the iterator is untyped, so PHPStan sees `mixed` and rejects the calls below without it.
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            if ('php' !== $file->getExtension()) {
                continue;
            }

            $relative = \substr($file->getPathname(), $prefix);
            $candidate = self::NAMESPACE_PREFIX . \str_replace('/', '\\', \substr($relative, 0, -4));

            if (!\class_exists($candidate)) {
                continue;
            }

            $reflection = new ReflectionClass($candidate);

            if ($reflection->isAbstract()) {
                continue;
            }

            if (!$reflection->isSubclassOf(Constraint::class)) {
                continue;
            }

            // isSubclassOf() is a runtime proof PHPStan cannot carry into the generic.
            /** @var class-string<Constraint> $candidate */
            $found[] = $candidate;
        }

        \sort($found);

        return $found;
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
