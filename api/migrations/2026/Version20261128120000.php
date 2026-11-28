<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Introduces `identity_totp_factor`: an identity's optional time-based second factor — the user id, the random id of
 * the encryption scope its secret is sealed under, the sealed secret (ciphertext only, never the secret), when the
 * owner confirmed it, and the last time step a code was accepted at.
 *
 * No FK to `identity_user`, like every other reference to a person in this schema: the identity erasure deletes the
 * row and destroys its scope's key, and that removal is owed by `EraseIdentitySubject` rather than by a constraint.
 *
 * `user_id` is UNIQUE: one factor per identity, and the index serves the locked lookup by owner and the
 * person-reference source's keyset pages. `scope_id` is UNIQUE too: two factors sharing a key would be shredded
 * together. `enabled_at` NULL is a pending enrolment that gates nothing; `enrolment_started_at` (when it was started
 * or last restarted) and `failed_confirm_attempts` (wrong codes since then) bound how long and how often it can be
 * confirmed. The count has no DEFAULT on purpose: the aggregate writes it on every insert.
 * `last_accepted_step` is a 30 second step count since the epoch, which fits an INT for some two thousand years.
 */
final class Version20261128120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add identity_totp_factor (one optional, sealed TOTP second factor per identity).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS identity_totp_factor ('
            . 'id UUID NOT NULL, '
            . 'user_id UUID NOT NULL, '
            . 'scope_id UUID NOT NULL, '
            . 'sealed_secret TEXT NOT NULL, '
            . 'enrolment_started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'failed_confirm_attempts INT NOT NULL, '
            . 'enabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, '
            . 'last_accepted_step INT DEFAULT NULL, '
            . 'created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'PRIMARY KEY (id))',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_identity_totp_factor_user_id ON identity_totp_factor (user_id)',
        );
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_identity_totp_factor_scope_id ON identity_totp_factor (scope_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS identity_totp_factor');
    }
}
