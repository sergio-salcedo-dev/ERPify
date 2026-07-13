<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the iam_invitation table: the state-oriented delivery record of a pending onboarding. Stores only the
 * token digest (never the raw token), the invitee/org ids (no physical FK — hard-delete-friendly, mirroring
 * iam_session) and the lifecycle status. No PII: the invited email lives on identity_user, not here.
 */
final class Version20260713141511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create iam_invitation (invitation aggregate)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE iam_invitation (organization_id UUID NOT NULL, invited_user_id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(255) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_iam_invitation_invited_user_id ON iam_invitation (invited_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE iam_invitation');
    }
}
