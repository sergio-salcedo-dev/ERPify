<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain;

/**
 * Stable Mercure topic IRIs for back-office bank-account real-time updates (not tied to hostname).
 * The global collection topic carries list-level changes (created/updated/deleted) across every bank
 * for the standalone accounts hub; the per-bank collection topic addresses one bank's nested list by
 * `bankId`; the per-account topic carries a single account's detail changes.
 */
final class MercureBankAccountTopic
{
    /** Global list-level changes across all banks' accounts: created, updated, deleted. */
    public const string COLLECTION = 'urn:erpify:backoffice:bankaccounts';

    /** URI-template (RFC 6570) covering every per-bank accounts topic — used for subscriber authorization. */
    public const string COLLECTION_TEMPLATE = 'urn:erpify:backoffice:bank:{bankId}:accounts';

    /** URI-template (RFC 6570) covering every per-account topic — used for subscriber authorization. */
    public const string DETAIL_TEMPLATE = 'urn:erpify:backoffice:bankaccount:{id}';

    /** Concrete per-bank accounts collection topic. */
    public static function forBank(string $bankId): string
    {
        return \sprintf('urn:erpify:backoffice:bank:%s:accounts', $bankId);
    }

    /** Concrete per-account topic for a single aggregate. */
    public static function forAccount(string $accountId): string
    {
        return \sprintf('urn:erpify:backoffice:bankaccount:%s', $accountId);
    }
}
