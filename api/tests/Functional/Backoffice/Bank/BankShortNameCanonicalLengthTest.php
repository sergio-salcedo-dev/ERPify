<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Validation\Application\Validator;
use Erpify\Shared\Domain\Uuid\Uuid as DomainUuid;
use Erpify\Shared\Domain\ValueObject\NormalizedText;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * gh-203 follow-up: a raw `shortName` within the 50-char DTO limit can still grow past 50 once
 * `NormalizedText::toAsciiUpper` folds it (ß→SS, Æ→AE), because the canonical form is what the
 * `VARCHAR(50)` column persists. This pins that the Bank entity invariant rejects the over-long
 * canonical form as a clean validation violation on `shortName` (→ 422) instead of letting the
 * INSERT raise a database "value too long" 500 — the short-name analogue of the guard that
 * {@see Bank::validateNormalizedNameLength()} provides for `name`.
 *
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankShortNameCanonicalLengthTest extends KernelTestCase
{
    public function testCanonicalShortNameOverflowIsAValidationViolationNotADatabaseError(): void
    {
        self::bootKernel();

        $validator = self::getContainer()->get(Validator::class);
        $this->assertInstanceOf(Validator::class, $validator);

        // 40 × "ß" is 40 chars raw (inside the 50-char DTO limit) but folds to "SS"×40 under
        // `Any-Latin; Latin-ASCII; Upper()`, overflowing the VARCHAR(50) column. Pin the
        // expansion explicitly so a mis-chosen input fails loudly instead of passing by accident.
        $rawShortName = \str_repeat('ß', 40);
        $canonical = NormalizedText::toAsciiUpper($rawShortName);
        $this->assertLessThanOrEqual(50, \mb_strlen($rawShortName));
        $this->assertGreaterThan(50, \mb_strlen($canonical));

        $id = DomainUuid::generate();
        $suffix = \strtoupper(\substr(\str_replace('-', '', $id), 0, 8));
        $bank = Bank::create($id, 'Canonical Overflow Bank ' . $suffix, $rawShortName);

        try {
            $validator->ensure($bank);
            $this->fail('Expected a validation violation for the over-long canonical shortName, none thrown.');
        } catch (ValidationFailedException $validationFailedException) {
            $shortNameMessages = [];

            foreach ($validationFailedException->getViolations() as $violation) {
                if ('shortName' === $violation->getPropertyPath()) {
                    $shortNameMessages[] = (string) $violation->getMessage();
                }
            }

            $this->assertNotEmpty($shortNameMessages, 'Expected a shortName violation, none was produced.');
            $this->assertStringContainsString('50', \implode(' | ', $shortNameMessages), \sprintf(
                'Expected the shortName violation to be the 50-char length guard, got: [%s].',
                \implode(' | ', $shortNameMessages),
            ));
        }
    }
}
