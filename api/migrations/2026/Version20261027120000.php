<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Holds `event_store.payload` and `event_store.metadata` to a JSON object. The writer already emits one for both
 * (`DbalEventStore::append()`), and the reader refuses any other root as a corrupt row; the constraint closes the
 * remaining door, a write that bypasses the writer. The empty list `[]` stays admitted: rows written before the
 * writer's object coercion hold it, and ADR `event-store-and-projections.md` D4 accepts them without a backfill.
 * Both columns are `NOT NULL`, so a SQL `NULL` never reaches the check, and `jsonb_typeof` is `'null'` for a
 * stored JSON `null`, which it rejects.
 *
 * A row that already breaks the rule fails this migration loudly: nothing here rewrites a historical event. A
 * plain `ADD CONSTRAINT` takes an exclusive lock and scans the table once, which is acceptable while
 * `event_store` is small, and `NOT VALID` plus `VALIDATE` would buy nothing inside the migration's transaction.
 *
 * Idempotent (`DROP … IF EXISTS` before `ADD`), because `ADD CONSTRAINT` has no `IF NOT EXISTS`. DBAL does not
 * introspect CHECK constraints, so `doctrine:schema:validate` stays silent about these and this migration owns
 * them; `EventStoreJsonObjectConstraintFunctionalTest` proves they exist and refuse a non-object.
 */
final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Constrain event_store payload and metadata to JSON objects';
    }

    public function up(Schema $schema): void
    {
        foreach (['payload', 'metadata'] as $column) {
            $name = 'event_store_' . $column . '_is_object';

            $this->addSql('ALTER TABLE event_store DROP CONSTRAINT IF EXISTS ' . $name);
            $this->addSql(\sprintf(
                "ALTER TABLE event_store ADD CONSTRAINT %s CHECK (jsonb_typeof(%s) = 'object' OR %s = '[]'::jsonb)",
                $name,
                $column,
                $column,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_store DROP CONSTRAINT IF EXISTS event_store_metadata_is_object');
        $this->addSql('ALTER TABLE event_store DROP CONSTRAINT IF EXISTS event_store_payload_is_object');
    }
}
