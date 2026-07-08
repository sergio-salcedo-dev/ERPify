<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260707141602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create organization and membership tables (Organization + Membership aggregates); '
            . 'membership.organization_id → organization.id FK. No credentials or PII.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization (name VARCHAR(255) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE membership (user_id UUID NOT NULL, organization_id UUID NOT NULL, roles JSON NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_86FFD285A76ED395 ON membership (user_id)');
        $this->addSql('CREATE INDEX idx_membership_organization_id ON membership (organization_id)');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT fk_membership_organization FOREIGN KEY (organization_id) REFERENCES organization (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS membership');
        $this->addSql('DROP TABLE IF EXISTS organization');
    }
}
