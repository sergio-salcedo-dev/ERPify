<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives `audit_log` the two `CHECK` constraints `AuditLogSchemaListener::checkConstraints()` declares:
 * `actor_type` holds an `ActorType` token, and `actor_id` is present exactly when that type names someone.
 *
 * Written by hand, because DBAL models no `CHECK` and `make db.diff` can neither generate nor see one. The
 * expressions are frozen here as literals rather than read from the listener, so this migration keeps
 * meaning what it meant when it ran; the listener's current declaration is held against the live database
 * by `AuditLogActorCheckConstraintFunctionalTest`, which compares the two as Postgres normalises them.
 *
 * Both constraints validate every existing row as they are added, and that is the intent: a row that
 * breaks them was written around `ActorContext` by raw SQL, and the migration failing on it names the row's
 * existence instead of grandfathering it with `NOT VALID`. Every writer that predates this — the current
 * DBAL writer and any earlier image a rollback redeploys — builds its actor through `ActorContext`, so no
 * in-service `INSERT` starts failing. The actor erasure re-mints `actor_id` rather than nulling it, so it
 * satisfies the pairing too.
 *
 * Each `ADD CONSTRAINT` holds `ACCESS EXCLUSIVE` on `audit_log` for one sequential scan, so audit writes wait
 * behind it for that long. Splitting it into `NOT VALID` plus `VALIDATE CONSTRAINT` would not shorten the
 * wait here: migrations run in one transaction, which keeps the first lock until commit, and running this
 * one outside it is the out-of-band path reserved for a table large enough to need it.
 *
 * `down()` drops both and is fully reversible: no row is rewritten in either direction.
 */
final class Version20261003101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Constrain audit_log.actor_type to the ActorType tokens and pair actor_id presence with it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE audit_log ADD CONSTRAINT audit_log_actor_type_check '
            . "CHECK (actor_type IN ('anonymous', 'api_key', 'system', 'user'))",
        );
        $this->addSql(
            'ALTER TABLE audit_log ADD CONSTRAINT audit_log_actor_id_presence_check '
            . "CHECK ((actor_type IN ('anonymous', 'system')) = (actor_id IS NULL))",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_actor_id_presence_check');
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_actor_type_check');
    }
}
