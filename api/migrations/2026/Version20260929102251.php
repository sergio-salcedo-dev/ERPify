<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes Postgres enforce the actor discriminant of `audit_log`, which until now only PHP enforced.
 *
 * `ActorContext` already makes an illegal actor unrepresentable in the application, but raw SQL — DBAL,
 * fixtures, acceptance steps — writes this table without it. A `user` row with a NULL `actor_id` is the
 * dangerous one: both erasure passes match by id, so neither ever reaches it and a person's request
 * metadata survives the erasure. Two named constraints close it:
 *
 * - `audit_log_actor_type_check` — `actor_type` is one of the four lowercase tokens of `ActorType`.
 * - `audit_log_actor_id_presence_check` — `anonymous`/`system` carry no id, `api_key`/`user` always carry
 *   one. Written as a boolean equality rather than an implication: with `actor_type NOT NULL` both sides
 *   are always TRUE or FALSE, so the expression never evaluates to NULL, which a CHECK would accept.
 *
 * The token lists are literals on purpose. A migration is immutable and the code is not, so it imports
 * nothing from `src`; `AuditLogActorCheckConstraintFunctionalTest` is what fails when `ActorType` gains a
 * case this constraint does not admit. DBAL neither models nor introspects a table-level CHECK, so
 * `make db.diff` never sees these two in either direction — that test is their only guardian.
 *
 * Cost: one `ALTER TABLE` validates both constraints in a single scan of `audit_log` while holding an
 * `ACCESS EXCLUSIVE` lock, blocking every audit write — and the `change` tier writes inside the business
 * transaction. Negligible on the pre-production table this ships against; whoever applies it over a large
 * `audit_log` should split it into `ADD CONSTRAINT … NOT VALID` and a separate `VALIDATE CONSTRAINT`.
 */
final class Version20260929102251 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce the audit_log actor discriminant: closed actor_type set and actor_id presence by type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE audit_log '
            . "ADD CONSTRAINT audit_log_actor_type_check CHECK (actor_type IN ('anonymous', 'system', 'api_key', 'user')), "
            . "ADD CONSTRAINT audit_log_actor_id_presence_check CHECK ((actor_type IN ('anonymous', 'system')) = (actor_id IS NULL))",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_actor_id_presence_check');
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT IF EXISTS audit_log_actor_type_check');
    }
}
