<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\FulfilIdentityErasureResult;
use Erpify\Iam\Identity\Infrastructure\Cli\EraseIdentitySubjectCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * The erasure chain is enumerated twice — once as the constructor's properties, once as the categories a
 * surface shows a human — and the two may not diverge. They did: `recoverySecretsDeleted` joined the
 * properties and the CLI's success line while the confirmation prompt, the command's help and two docblocks
 * kept describing eight links, so the operator consented to less than the erasure destroys.
 *
 * The universe is taken by reflection from the constructor, never from a list written here, so a tenth
 * property fails this test on the day it is declared rather than on the day someone reads the prompt.
 *
 * A green proves the two sets are equal and that the labels are non-empty and distinct. It does not prove a
 * label DESCRIBES its property — swapping two labels passes — and it reaches no docblock, so prose
 * elsewhere in the chain stays a review matter.
 *
 * @internal
 */
#[CoversClass(FulfilIdentityErasureResult::class)]
final class FulfilIdentityErasureResultTest extends TestCase
{
    public function testEveryErasedCountHasAConsentCategoryAndViceVersa(): void
    {
        $properties = $this->constructorPropertyNames();
        $labelled = \array_keys(FulfilIdentityErasureResult::ERASED_CATEGORIES);

        $this->assertNotEmpty($properties, 'the reflection universe must not be empty');
        $this->assertSame(
            $properties,
            $labelled,
            'ERASED_CATEGORIES must name every constructor property, in the same order',
        );
    }

    public function testEveryCategoryReadsAsSomethingAnOperatorCanConsentTo(): void
    {
        $labels = \array_values(FulfilIdentityErasureResult::ERASED_CATEGORIES);

        foreach ($labels as $label) {
            $this->assertNotSame('', \trim($label), 'a blank label would silently shorten the prompt');
        }

        $this->assertSame(
            $labels,
            \array_unique($labels),
            'two properties sharing a label hide one of them inside the other',
        );

        foreach ($labels as $label) {
            $this->assertStringNotContainsString(
                EraseIdentitySubjectCommand::CATEGORY_SEPARATOR,
                $label,
                'a label carrying the separator splits into two entries in the rendered prompt',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function constructorPropertyNames(): array
    {
        $constructor = (new ReflectionClass(FulfilIdentityErasureResult::class))->getConstructor();
        $this->assertInstanceOf(ReflectionMethod::class, $constructor, 'the result is a constructor-promoted DTO');

        return \array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            \array_values(\array_filter(
                $constructor->getParameters(),
                static fn (ReflectionParameter $parameter): bool => $parameter->isPromoted(),
            )),
        );
    }
}
