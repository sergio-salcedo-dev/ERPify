<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626215406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit_log.actor_erased: a materialised, queryable GDPR-erasure flag set in the same '
            . 'UPDATE as the actor_id remint and ip/user_agent redaction.';
    }

    public function up(Schema $schema): void
    {
        // The column carries no persistent default (the schema listener, its source of truth, expresses
        // none — the writer supplies FALSE on insert, the erasure UPDATE flips it to TRUE). The transient
        // DEFAULT FALSE only lets the NOT NULL add backfill any existing rows; dropping it keeps the live
        // column matching the listener so `make db.diff` stays clean.
        $this->addSql('ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS actor_erased BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE audit_log ALTER COLUMN actor_erased DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP COLUMN IF EXISTS actor_erased');
    }
}
