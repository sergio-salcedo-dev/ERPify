<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Introduces the `identity_recovery_secret` table: the at-rest record of an identity's administrative
 * recovery credential — a user id, the single-use secret digest (never the raw secret) and its expiry.
 *
 * No FK to `identity_user`, like every other reference to a person in this schema: the id-only reference is
 * what keeps the subject hard-deletable for GDPR erasure, and the removal is owed by
 * `EraseIdentitySubject` rather than by a constraint.
 *
 * `user_id` is UNIQUE rather than merely indexed, and that is the aggregate's invariant reaching the schema:
 * an identity holds at most one recovery secret at a time, so a second mint is refused rather than silently
 * superseding a secret whose owner may already have written it down and stored it away from the machine.
 * The uniqueness also serves the two lookups by owner (the profile read and the revoke) with one index.
 *
 * No lifecycle column, deliberately. A consumed or revoked secret is deleted outright: retaining the row
 * would keep a live reference to a person for the sake of a status nothing reads, and single use is carried
 * by a `SELECT … FOR UPDATE` re-read at the consuming site rather than by a flag. `expires_at` is ten years
 * out by policy, so nothing here is swept on a retention schedule either. No credentials or PII in the
 * schema: the digest is a SHA-256 of a 256-bit CSPRNG secret and the user id is an opaque UUID.
 */
final class Version20260828102209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add identity_recovery_secret (one recovery-credential digest per identity).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS identity_recovery_secret ('
            . 'id UUID NOT NULL, '
            . 'user_id UUID NOT NULL, '
            . 'secret_hash VARCHAR(255) NOT NULL, '
            . 'expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'PRIMARY KEY (id))',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_identity_recovery_secret_user_id '
            . 'ON identity_recovery_secret (user_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS identity_recovery_secret');
    }
}
