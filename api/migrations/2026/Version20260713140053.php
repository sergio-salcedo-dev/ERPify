<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Introduces the `identity_password_reset_token` table: the at-rest record of a pending password reset —
 * a user id, the single-use token digest (never the raw token), and its expiry. No FK to `identity_user`
 * (id-only reference, so a user stays hard-deletable for GDPR erasure); indexed on `user_id` for the
 * supersede-on-reissue delete. No credentials or PII in the schema.
 */
final class Version20260713140053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add identity_password_reset_token (pending password-reset digests).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS identity_password_reset_token ('
            . 'id UUID NOT NULL, '
            . 'user_id UUID NOT NULL, '
            . 'token_hash VARCHAR(255) NOT NULL, '
            . 'expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'PRIMARY KEY (id))',
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_identity_password_reset_token_user_id '
            . 'ON identity_password_reset_token (user_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS identity_password_reset_token');
    }
}
