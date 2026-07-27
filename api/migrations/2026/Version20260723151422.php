<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723151422 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit_log.resource_erased: the resource-axis counterpart of actor_erased, set in the '
            . 'same UPDATE as the resource_id remint so an anonymised subject reference is distinguishable '
            . 'from one whose erasure was missed.';
    }

    public function up(Schema $schema): void
    {
        // Mirrors the actor_erased add: the column carries no persistent default (the schema listener, its
        // source of truth, expresses none — the writer supplies FALSE on insert, the resource erasure
        // UPDATE flips it to TRUE). The transient DEFAULT FALSE only lets the NOT NULL add backfill
        // existing rows; dropping it keeps the live column matching the listener so `make db.diff` stays
        // clean. Backfilling FALSE is correct: no erasure has ever touched resource_id before this ships.
        $this->addSql('ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS resource_erased BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE audit_log ALTER COLUMN resource_erased DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP COLUMN IF EXISTS resource_erased');
    }
}
