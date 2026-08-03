<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\PersonReferenceFinding;
use Erpify\Iam\Identity\Application\UnreconciledPersonReferences;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two properties carry this type's whole reason to exist: an axis that reports nothing is still counted as
 * checked, and two axes are never merged into one bucket.
 *
 * @internal
 */
#[CoversClass(UnreconciledPersonReferences::class)]
#[CoversClass(PersonReferenceFinding::class)]
#[CoversClass(PersonReferenceAxis::class)]
final class UnreconciledPersonReferencesTest extends TestCase
{
    private const string FIRST_ID = '0190f600-0000-7000-8000-0000000000e1';

    private const string SECOND_ID = '0190f600-0000-7000-8000-0000000000e2';

    #[Test]
    public function anEmptyVerdictReportsNothingAndHasCheckedNothing(): void
    {
        $verdict = UnreconciledPersonReferences::none();

        $this->assertTrue($verdict->isEmpty());
        $this->assertSame(0, $verdict->axesChecked());
        $this->assertSame(0, $verdict->total());
        $this->assertSame([], $verdict->findings());
    }

    #[Test]
    public function anAxisThatReconcilesIsCountedButNotReported(): void
    {
        // "Checked and clean" must not be spelled the same way as "never wired", which is what dropping the
        // axis entirely would do.
        $verdict = UnreconciledPersonReferences::none()
            ->withAxis(PersonReferenceAxis::of('a.clean'), [])
        ;

        $this->assertTrue($verdict->isEmpty());
        $this->assertSame(1, $verdict->axesChecked());
        $this->assertSame([], $verdict->findings());
    }

    #[Test]
    public function twoAxesKeepTheirOwnIdsAndTheirOwnPlace(): void
    {
        $verdict = UnreconciledPersonReferences::none()
            ->withAxis(PersonReferenceAxis::of('Some\Namespace\First::$userId'), [self::FIRST_ID])
            ->withAxis(PersonReferenceAxis::of('b.clean'), [])
            ->withAxis(PersonReferenceAxis::of('Some\Namespace\Second::$userId'), [self::SECOND_ID])
        ;

        $findings = $verdict->findings();

        $this->assertCount(2, $findings);
        $this->assertSame(3, $verdict->axesChecked());
        $this->assertSame(2, $verdict->total());
        $this->assertSame('Some\Namespace\First::$userId', $findings[0]->axis->key());
        $this->assertSame([self::FIRST_ID], $findings[0]->subjectIds);
        $this->assertSame('Some\Namespace\Second::$userId', $findings[1]->axis->key());
        $this->assertSame([self::SECOND_ID], $findings[1]->subjectIds);
    }

    #[Test]
    public function itNamesEveryPlaceItWasAskedAboutInAStableOrder(): void
    {
        // The evidence behind the count: a clean run has to be able to show WHICH places were checked, or
        // a control that quietly lost a source prints what a full sweep prints.
        $verdict = UnreconciledPersonReferences::none()
            ->withAxis(PersonReferenceAxis::of('z.last'), [])
            ->withAxis(PersonReferenceAxis::of('a.first'), [self::FIRST_ID])
        ;

        $this->assertSame(['a.first', 'z.last'], $verdict->axesCheckedKeys());
    }

    #[Test]
    public function itRefusesTwoSourcesReportingTheSamePlace(): void
    {
        // Assignment would keep the later findings and drop the earlier ones — one whole place unreported
        // while both sources look wired — and would decrement the count an operator reads to notice it.
        $verdict = UnreconciledPersonReferences::none()
            ->withAxis(PersonReferenceAxis::of('a.first'), [self::FIRST_ID])
        ;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Two sources reported the person-reference axis "a\.first"/');

        $verdict->withAxis(PersonReferenceAxis::of('a.first'), [self::SECOND_ID]);
    }

    #[Test]
    public function theVerdictIsImmutable(): void
    {
        // Each source folds into the verdict as it is asked, so a `withAxis` that mutated in place would let
        // an earlier snapshot of the report change under whoever already held it.
        $empty = UnreconciledPersonReferences::none();
        $withOne = $empty->withAxis(PersonReferenceAxis::of('Some\Namespace\First::$userId'), [self::FIRST_ID]);

        $this->assertSame(0, $empty->axesChecked());
        $this->assertSame(1, $withOne->axesChecked());
    }
}
