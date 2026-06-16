<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Swap bank_account.status from smallint to text to match the string-backed '
            . 'BankAccountStatus identity enum (wire contract = SCREAMING_SNAKE ->value)';
    }

    public function up(Schema $schema): void
    {
        // Map the legacy smallint codes to the enum ->value identity. The column is NOT NULL, so an
        // unmapped code falls to NULL and the constraint rejects it loudly rather than persisting junk.
        $this->addSql(
            'ALTER TABLE bank_account ALTER COLUMN status TYPE text USING '
            . "CASE status WHEN 1 THEN 'ACTIVE' WHEN 2 THEN 'INACTIVE' WHEN 3 THEN 'CLOSED' ELSE NULL END",
        );
    }

    public function down(Schema $schema): void
    {
        // Inverse map. The ELSE casts a descriptive string to smallint, which raises
        // "invalid input syntax for type smallint" naming the offending value — an unexpected status
        // must fail loud on rollback, never silently degrade to NULL.
        $this->addSql(
            'ALTER TABLE bank_account ALTER COLUMN status TYPE smallint USING '
            . "CASE status WHEN 'ACTIVE' THEN 1 WHEN 'INACTIVE' THEN 2 WHEN 'CLOSED' THEN 3 "
            . "ELSE CAST('unexpected bank_account.status value: ' || status AS smallint) END",
        );
    }
}
