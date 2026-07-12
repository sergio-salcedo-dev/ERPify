<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the persisted per-identity lockout to `identity_user`: a `failed_attempts` counter and a nullable
 * `locked_until` expiry (a timestamp gate orthogonal to the identity status).
 */
final class Version20260711171040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add identity_user.failed_attempts and identity_user.locked_until for the login lockout.';
    }

    public function up(Schema $schema): void
    {
        // Add the counter with a default so the NOT NULL add backfills existing rows (the bootstrap
        // administrator, dev/test seeds) in one statement instead of failing mid-boot on all-or-nothing —
        // then drop the default: the aggregate always sets the counter explicitly, so a lingering DB default
        // would only mask a future write that forgot to. IF NOT EXISTS keeps re-runs idempotent.
        $this->addSql('ALTER TABLE identity_user ADD COLUMN IF NOT EXISTS failed_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE identity_user ALTER COLUMN failed_attempts DROP DEFAULT');

        // No lock is in force until the counter trips the threshold, so the expiry is nullable and absent by
        // default.
        $this->addSql(
            'ALTER TABLE identity_user ADD COLUMN IF NOT EXISTS locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_user DROP COLUMN IF EXISTS locked_until');
        $this->addSql('ALTER TABLE identity_user DROP COLUMN IF EXISTS failed_attempts');
    }
}
