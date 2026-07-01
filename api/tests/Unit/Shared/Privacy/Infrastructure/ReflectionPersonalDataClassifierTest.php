<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Privacy\Infrastructure;

use Erpify\Shared\Privacy\Infrastructure\ReflectionPersonalDataClassifier;
use Erpify\Tests\Unit\Shared\Privacy\Infrastructure\Fixtures\SampleWithoutPersonalData;
use Erpify\Tests\Unit\Shared\Privacy\Infrastructure\Fixtures\SampleWithPersonalData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReflectionPersonalDataClassifier::class)]
final class ReflectionPersonalDataClassifierTest extends TestCase
{
    #[Test]
    public function itListsThePersonalDataFieldsOfAClassSortedByName(): void
    {
        $classifier = new ReflectionPersonalDataClassifier();

        $this->assertSame(['email', 'fullName'], $classifier->personalFieldsOf(SampleWithPersonalData::class));
    }

    #[Test]
    public function itAcceptsAnObjectInstance(): void
    {
        $classifier = new ReflectionPersonalDataClassifier();

        $this->assertSame(['email', 'fullName'], $classifier->personalFieldsOf(new SampleWithPersonalData()));
    }

    #[Test]
    public function itReturnsAnEmptyListWhenNoFieldIsPersonalData(): void
    {
        $classifier = new ReflectionPersonalDataClassifier();

        $this->assertSame([], $classifier->personalFieldsOf(SampleWithoutPersonalData::class));
    }
}
