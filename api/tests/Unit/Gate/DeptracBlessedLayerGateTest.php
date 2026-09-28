<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the scope of the one dependency deptrac BLESSES rather than grandfathers: the validation seam
 * (`Shared.ValidatorSeam`) importing the Symfony Validator runtime (`Vendor.SymfonyValidatorRuntime`).
 *
 * deptrac enforces the grant, but nothing in deptrac says what the grant may be. Each of these edits keeps
 * `make php.deptrac` green while widening it: a `classLike` regex on the seam that matches a second class, a
 * seventh type in the runtime regex, or the runtime layer listed in some `*.Application` ruleset. The
 * opposite edits — a ruleset reaching `Shared.Application` or `Vendor.Symfony` without its carved-out twin —
 * fail closed in deptrac already (spurious violations), and are asserted here only so the pairing rule is a
 * test rather than a sentence in `api/CLAUDE.md`.
 *
 * A green proves the grant is exactly one class and six types, reached inward only by the seam. It never
 * judges whether blessing them was right — that is ADR `external-dependencies-in-domain.md` D5.
 *
 * @internal
 */
#[CoversNothing]
final class DeptracBlessedLayerGateTest extends TestCase
{
    private const string SEAM = 'Shared.ValidatorSeam';

    private const string RUNTIME = 'Vendor.SymfonyValidatorRuntime';

    private const string SEAM_CLASS_PATTERN = '^Erpify\\\Shared\\\Validation\\\Application\\\Validator$';

    private const string RUNTIME_TYPES_PATTERN = '^Symfony\\\Component\\\Validator\\\(Constraint|ConstraintViolation'
        . '|ConstraintViolationList|ConstraintViolationListInterface|Exception\\\ValidationFailedException'
        . '|Validator\\\ValidatorInterface)$';

    public function testTheSeamLayerCollectsExactlyOneAnchoredClass(): void
    {
        $this->assertSame(
            [['type' => 'classLike', 'value' => self::SEAM_CLASS_PATTERN]],
            $this->layer(self::SEAM)['collectors'] ?? null,
            self::SEAM . ' must collect exactly Erpify\Shared\Validation\Application\Validator, anchored.',
        );
    }

    public function testTheRuntimeLayerCollectsExactlyTheSixBlessedTypes(): void
    {
        $this->assertSame(
            [['type' => 'classLike', 'value' => self::RUNTIME_TYPES_PATTERN]],
            $this->layer(self::RUNTIME)['collectors'] ?? null,
            self::RUNTIME . ' must collect exactly the six blessed Validator runtime types, anchored. Adding one '
            . 'is a decision for ADR external-dependencies-in-domain.md D5, not a config edit.',
        );
    }

    public function testBothCarveOutsExcludeTheirBlessedTwin(): void
    {
        $this->assertContains(
            ['type' => 'layer', 'value' => self::SEAM],
            $this->boolMustNot('Shared.Application'),
            'Shared.Application must exclude ' . self::SEAM . ', or Validator sits in both layers.',
        );
        $this->assertContains(
            ['type' => 'layer', 'value' => self::RUNTIME],
            $this->boolMustNot('Vendor.Symfony'),
            'Vendor.Symfony must exclude ' . self::RUNTIME . ', or the six types sit in both layers.',
        );
    }

    public function testOnlyTheSeamAdmitsTheRuntimeInward(): void
    {
        $inward = [];

        foreach ($this->ruleset() as $depender => $admitted) {
            if (\in_array(self::RUNTIME, $admitted, true) && !\in_array('Vendor.Symfony', $admitted, true)) {
                $inward[] = $depender;
            }
        }

        $this->assertSame(
            [self::SEAM],
            $inward,
            'Only ' . self::SEAM . ' may admit ' . self::RUNTIME . ' without also admitting Vendor.Symfony.',
        );
    }

    public function testEveryRulesetReachingACarvedLayerAlsoReachesItsTwin(): void
    {
        $unpaired = [];
        $pairs = ['Shared.Application' => self::SEAM, 'Vendor.Symfony' => self::RUNTIME];

        foreach ($this->ruleset() as $depender => $admitted) {
            foreach ($pairs as $carvedFrom => $twin) {
                // A layer never lists itself: its own classes are not a dependency it has to admit.
                $reachesCarved = \in_array($carvedFrom, $admitted, true);

                if ($depender !== $twin && $reachesCarved && !\in_array($twin, $admitted, true)) {
                    $unpaired[] = $depender . ' admits ' . $carvedFrom . ' but not ' . $twin;
                }
            }
        }

        $this->assertSame([], $unpaired);
    }

    /**
     * @return array<mixed>
     */
    private function layer(string $name): array
    {
        $layers = $this->config()['layers'] ?? [];
        $this->assertIsArray($layers);

        foreach ($layers as $layer) {
            if (\is_array($layer) && ($layer['name'] ?? null) === $name) {
                return $layer;
            }
        }

        $this->fail(\sprintf('deptrac.yaml declares no layer named %s.', $name));
    }

    /**
     * @return list<mixed>
     */
    private function boolMustNot(string $layerName): array
    {
        $collectors = $this->layer($layerName)['collectors'] ?? [];
        $this->assertIsArray($collectors);

        foreach ($collectors as $collector) {
            if (\is_array($collector) && 'bool' === ($collector['type'] ?? null)) {
                return \array_values((array) ($collector['must_not'] ?? []));
            }
        }

        $this->fail(\sprintf('%s has no bool collector to carve its twin out of.', $layerName));
    }

    /**
     * @return array<string, list<string>>
     */
    private function ruleset(): array
    {
        $ruleset = $this->config()['ruleset'] ?? [];
        $this->assertIsArray($ruleset);
        $this->assertArrayHasKey(self::SEAM, $ruleset, 'The seam layer has no ruleset of its own.');

        $normalised = [];

        foreach ($ruleset as $depender => $admitted) {
            $this->assertIsArray($admitted);
            $normalised[(string) $depender] = \array_values(\array_filter($admitted, \is_string(...)));
        }

        return $normalised;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        /** @var array{deptrac?: array<string, mixed>} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/tools/deptrac/deptrac.yaml');

        return $config['deptrac'] ?? [];
    }
}
