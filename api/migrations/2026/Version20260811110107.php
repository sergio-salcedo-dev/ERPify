<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the lockout-notification stamp to `identity_user`.
 *
 * It lives on the subject's own row rather than in a cache bucket or a table of its own so the suppression
 * window is a property of the system — surviving a redeploy, shared by every worker, and erased by the same
 * DELETE that erases the person — instead of a property of whichever container happened to send the mail.
 */
final class Version20260811110107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add identity_user.lockout_notified_at, the per-subject suppression window for lockout notices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE identity_user ADD COLUMN IF NOT EXISTS lockout_notified_at '
            . 'TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_user DROP COLUMN IF EXISTS lockout_notified_at');
    }
}
