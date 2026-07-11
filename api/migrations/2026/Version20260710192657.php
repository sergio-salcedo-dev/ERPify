<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `iam_session` server-side session registry. `user_id` / `organization_id` are by-id references
 * with no physical foreign key (isolation by id, and erasure-first: a FK would block or couple the GDPR
 * hard-delete of a user). `expires_at` is NOT NULL; `revoked_at` / `ip` are nullable. `ip` / `device` are
 * short-lived operational data (the table is not audited). Indexed on `(user_id, status)` for the "my
 * sessions" read.
 */
final class Version20260710192657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create iam_session server-side session registry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS iam_session (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                organization_id UUID NOT NULL,
                status VARCHAR(255) NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                device VARCHAR(255) NOT NULL,
                ip VARCHAR(45) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_iam_session_user_id_status ON iam_session (user_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS iam_session');
    }
}
