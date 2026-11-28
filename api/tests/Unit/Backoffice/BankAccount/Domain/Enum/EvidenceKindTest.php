<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Enum;

use Erpify\Backoffice\BankAccount\Domain\Enum\EvidenceKind;
use Erpify\Documents\Document\Domain\Upload\LegalBasis;
use Erpify\Documents\Document\Domain\Upload\RetentionReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A kind spells its retention terms as the wire strings of the Documents vocabulary, because BankAccount may not import
 * that context's enums. This test may, and it pins the spelling: a Documents value renamed or removed would otherwise
 * make every attach of that kind answer 422 with every gate green.
 *
 * @internal
 */
#[CoversClass(EvidenceKind::class)]
final class EvidenceKindTest extends TestCase
{
    public function testEveryKindNamesRetentionTermsTheDocumentsVocabularyHolds(): void
    {
        foreach (EvidenceKind::cases() as $kind) {
            $this->assertInstanceOf(
                RetentionReason::class,
                RetentionReason::tryFrom($kind->retentionReason()),
                \sprintf('%s names a retention reason Documents does not publish.', $kind->value),
            );
            $this->assertInstanceOf(
                LegalBasis::class,
                LegalBasis::tryFrom($kind->legalBasis()),
                \sprintf('%s names a legal basis Documents does not publish.', $kind->value),
            );
        }
    }
}
