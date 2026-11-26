<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Audit\Infrastructure\Http;

use Erpify\Backoffice\Audit\Infrastructure\Http\AuditDiffDisclosure;
use Erpify\Backoffice\Audit\Infrastructure\Http\AuditEventDetailResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Field by field, the read side serves a diff entry in clear only when it is a sealed envelope or a classified clear
 * scalar of the row's resource type, and withholds everything else while keeping the entry in the diff.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") each case and its data provider are separate public methods
 */
#[CoversClass(AuditDiffDisclosure::class)]
final class AuditDiffDisclosureTest extends TestCase
{
    /** base64url of a nonce, a ciphertext and a tag: the shape the sealing writes. */
    private const string CIPHERTEXT = 'dGhlLW5vbmNlLXRoZS1jaXBoZXJ0ZXh0LWFuZC10aGUtdGFnLXgteS16LTAxMjM0NTY3ODk';

    private const array WITHHELD = [
        'old' => AuditEventDetailResourceMapper::WITHHELD,
        'new' => AuditEventDetailResourceMapper::WITHHELD,
    ];

    #[Test]
    public function aClassifiedClearScalarIsServed(): void
    {
        $changes = ['name' => ['old' => 'Acme', 'new' => 'Acme Bank'], 'logoImageId' => ['old' => null, 'new' => 'x']];

        $this->assertSame($changes, (new AuditDiffDisclosure())->disclose('Bank', $changes));
    }

    #[Test]
    public function aSealedEnvelopeIsServedWhateverTheFieldIsCalled(): void
    {
        $sealed = ['__enc__' => self::CIPHERTEXT];
        $changes = ['iban' => ['old' => $sealed, 'new' => $sealed], 'alias' => ['old' => null, 'new' => $sealed]];

        $this->assertSame($changes, (new AuditDiffDisclosure())->disclose('BankAccount', $changes));
    }

    #[Test]
    public function anUnclassifiedFieldKeepsItsPlaceWithBothSidesWithheld(): void
    {
        $disclosed = (new AuditDiffDisclosure())->disclose('Bank', [
            'contactEmail' => ['old' => 'jane.doe@example.test', 'new' => 'jane.roe@example.test'],
        ]);

        $this->assertSame(['contactEmail' => self::WITHHELD], $disclosed);
    }

    #[Test]
    public function aClearFieldOfAnotherResourceTypeIsNotServed(): void
    {
        $disclosed = (new AuditDiffDisclosure())->disclose('Bank', ['bic' => ['old' => 'A', 'new' => 'B']]);

        $this->assertSame(['bic' => self::WITHHELD], $disclosed);
    }

    #[Test]
    #[DataProvider('provideARowWithoutAKnownResourceTypeServesNoClearFieldCases')]
    public function aRowWithoutAKnownResourceTypeServesNoClearField(?string $resourceType): void
    {
        $disclosed = (new AuditDiffDisclosure())->disclose($resourceType, ['name' => ['old' => 'a', 'new' => 'b']]);

        $this->assertSame(['name' => self::WITHHELD], $disclosed);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function provideARowWithoutAKnownResourceTypeServesNoClearFieldCases(): iterable
    {
        yield 'absent' => [null];
        yield 'unknown' => ['Invoice'];
        yield 'a case variant of a known type' => ['bank'];
    }

    #[Test]
    #[DataProvider('provideAnEntryThatIsNotExactlyAnOldNewPairIsWithheldCases')]
    public function anEntryThatIsNotExactlyAnOldNewPairIsWithheld(mixed $entry): void
    {
        $disclosed = (new AuditDiffDisclosure())->disclose('Bank', ['name' => $entry]);

        $this->assertSame(['name' => self::WITHHELD], $disclosed);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideAnEntryThatIsNotExactlyAnOldNewPairIsWithheldCases(): iterable
    {
        yield 'a scalar' => ['Acme'];
        yield 'a missing side' => [['new' => 'Acme']];
        yield 'a third key' => [['old' => null, 'new' => 'Acme', 'by' => 'x']];
        yield 'a list' => [['Acme', 'Acme Bank']];
    }

    #[Test]
    #[DataProvider('provideAnEnvelopeThatDoesNotLookSealedIsWithheldCases')]
    public function anEnvelopeThatDoesNotLookSealedIsWithheld(mixed $side): void
    {
        $disclosed = (new AuditDiffDisclosure())->disclose('BankAccount', ['iban' => ['old' => $side, 'new' => null]]);

        $this->assertSame(['iban' => self::WITHHELD], $disclosed);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideAnEnvelopeThatDoesNotLookSealedIsWithheldCases(): iterable
    {
        yield 'an address' => [['__enc__' => 'jane.doe@example.test']];
        yield 'a short token' => [['__enc__' => 'abc']];
        yield 'a nested map' => [['__enc__' => ['email' => 'jane.doe']]];
        yield 'a second key beside the marker' => [['__enc__' => self::CIPHERTEXT, 'email' => 'jane.doe']];
        yield 'an empty array' => [[]];
    }

    #[Test]
    public function aClearFieldHoldingAStructureIsWithheld(): void
    {
        $disclosed = (new AuditDiffDisclosure())->disclose(
            'Bank',
            ['name' => ['old' => ['email' => 'jane.doe@example.test'], 'new' => 'Acme']],
        );

        $this->assertSame(['name' => self::WITHHELD], $disclosed);
    }
}
