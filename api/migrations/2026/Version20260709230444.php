<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Introduces the identity lifecycle: an `IdentityStatus` column on `identity_user` and a nullable
 * `password_hash` (an INVITED identity has no credential until it activates).
 */
final class Version20260709230444 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add identity_user.status (IdentityStatus) and make password_hash nullable for INVITED identities.';
    }

    public function up(Schema $schema): void
    {
        // Add the column with a default so the NOT NULL add backfills existing rows (the bootstrap
        // administrator, dev/test seeds) in one statement instead of failing mid-boot on all-or-nothing —
        // then drop the default: the aggregate always sets status explicitly, so a lingering DB default
        // would only mask a future write that forgot to. IF NOT EXISTS keeps re-runs idempotent.
        $this->addSql("ALTER TABLE identity_user ADD COLUMN IF NOT EXISTS status VARCHAR(255) DEFAULT 'ACTIVE' NOT NULL");
        $this->addSql('ALTER TABLE identity_user ALTER COLUMN status DROP DEFAULT');

        // A credential is fixed only at activation, so an INVITED identity carries none yet.
        $this->addSql('ALTER TABLE identity_user ALTER password_hash DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Reverting assumes no credential-less (INVITED) rows remain — SET NOT NULL would reject them.
        $this->addSql('ALTER TABLE identity_user ALTER password_hash SET NOT NULL');
        $this->addSql('ALTER TABLE identity_user DROP COLUMN IF EXISTS status');
    }
}
