<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Persistence;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\ViolatedUniqueConstraint;
use Erpify\Tests\Unit\Shared\Persistence\Double\StubDriverException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The classification must not depend on the language the server answers in (`lc_messages`): a lost sequence race
 * that read as a 500 because the sentence was translated is the defect this pins. Every message is synthetic and
 * carries the name in a different position, so what is asserted is the delimiting rule and not one wording.
 *
 * @internal
 */
#[CoversClass(ViolatedUniqueConstraint::class)]
final class ViolatedUniqueConstraintTest extends TestCase
{
    private const string SEQUENCE = 'uniq_document_custody_document_seq';

    private const string IDEMPOTENCY = 'uniq_document_idempotency_key_hash';

    #[Test]
    #[DataProvider('provideAUniqueViolationIsRecognisedByTheExactNameWhateverTheWordingCases')]
    public function aUniqueViolationIsRecognisedByTheExactNameWhateverTheWording(
        string $message,
        string $name,
        bool $expected,
    ): void {
        $failure = new UniqueConstraintViolationException(new StubDriverException($message), null);

        $this->assertSame($expected, ViolatedUniqueConstraint::is($failure, $name));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideAUniqueViolationIsRecognisedByTheExactNameWhateverTheWordingCases(): iterable
    {
        yield 'the English sentence' => [
            'ERROR:  duplicate key value violates unique constraint "uniq_document_custody_document_seq"',
            self::SEQUENCE,
            true,
        ];

        yield 'a Spanish sentence' => [
            'ERROR:  llave duplicada viola restricción de unicidad «uniq_document_custody_document_seq»',
            self::SEQUENCE,
            true,
        ];

        yield 'a German sentence' => [
            'FEHLER:  doppelter Schlüsselwert verletzt Unique-Constraint »uniq_document_custody_document_seq«',
            self::SEQUENCE,
            true,
        ];

        yield 'another constraint than the one asked for' => [
            'ERROR:  duplicate key value violates unique constraint "uniq_document_scope_id"',
            self::SEQUENCE,
            false,
        ];

        yield 'only the column named in the detail resembles the constraint' => [
            'ERROR:  duplicate key value violates unique constraint "uniq_other"'
            . "\nDETAIL:  Key (idempotency_key_hash)=(abc) already exists.",
            self::IDEMPOTENCY,
            false,
        ];

        yield 'a longer constraint name that starts like the one asked for' => [
            'ERROR:  duplicate key value violates unique constraint "uniq_document_custody_document_seq_v2"',
            self::SEQUENCE,
            false,
        ];

        yield 'a prefixed constraint name that ends like the one asked for' => [
            'ERROR:  duplicate key value violates unique constraint "old_uniq_document_custody_document_seq"',
            self::SEQUENCE,
            false,
        ];

        yield 'the key values resemble the name but no constraint is named' => [
            'ERROR:  duplicate key value' . "\nDETAIL:  Key (document_id, seq)=(1, 2) already exists.",
            self::SEQUENCE,
            false,
        ];
    }

    #[Test]
    public function aFailureThatIsNotAUniqueViolationIsNeverRecognisedEvenIfItsTextNamesTheConstraint(): void
    {
        $this->assertFalse(ViolatedUniqueConstraint::is(new RuntimeException(self::SEQUENCE), self::SEQUENCE));
    }

    #[Test]
    public function anEmptyNameMatchesNothing(): void
    {
        $failure = new UniqueConstraintViolationException(new StubDriverException('anything'), null);

        $this->assertFalse(ViolatedUniqueConstraint::is($failure, ''));
    }
}
