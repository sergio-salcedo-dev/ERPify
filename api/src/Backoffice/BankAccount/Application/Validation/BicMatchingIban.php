<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Validation;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraints\Bic;
use Symfony\Component\Validator\Constraints\BicValidator;

/**
 * `Assert\Bic` as this module's command DTOs need it, declared once.
 *
 * Four options travel together and each is load-bearing:
 *
 * - `ibanPropertyPath` reads the CANONICAL IBAN. The stock constraint takes the country from
 *   `substr($iban, 0, 2)` of the value as submitted, so an IBAN that leads with a separator — the
 *   ordinary shape of a copy-paste out of a statement — makes `ctype_alpha()` false and the country
 *   comparison is skipped in silence rather than failed. The edge then accepts a pair it exists to
 *   reject.
 * - `ibanMessage` names the country instead of the account. The stock default interpolates the IBAN,
 *   which is `#[PersonalData]` on the aggregate, and a violation message is rendered into the
 *   per-error log line — a sink no erasure path reaches.
 * - `mode` is case-insensitive because a pasted BIC is as likely to be lower-cased as an IBAN.
 * - `message` keeps the wire contract's own wording for a malformed BIC, matching its `Assert\Iban`
 *   sibling.
 *
 * It exists as a type rather than four repeated arguments because the two commands must not drift:
 * dropping `ibanMessage` from one of them reopens the leak on that endpoint alone, and nothing else
 * in the build would notice.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class BicMatchingIban extends Bic
{
    /**
     * @param string[]|null $groups
     */
    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct(
            message: 'This is not a valid BIC.',
            ibanPropertyPath: 'canonicalIban',
            ibanMessage: 'The BIC does not match the IBAN country.',
            groups: $groups,
            payload: $payload,
            mode: self::VALIDATION_MODE_CASE_INSENSITIVE,
        );
    }

    /**
     * A constraint and its validator are bound by NAME — `Constraint::validatedBy()` derives
     * `static::class . 'Validator'`, which here would be a class that does not exist and would fatal
     * at validation time while every static gate stayed green. The behaviour is stock, so the stock
     * validator is named explicitly.
     */
    #[Override]
    public function validatedBy(): string
    {
        return BicValidator::class;
    }
}
