<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623164321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, level VARCHAR(16) NOT NULL, action VARCHAR(100) NOT NULL, actor_type VARCHAR(16) NOT NULL, actor_id UUID DEFAULT NULL, correlation_id UUID NOT NULL, resource_type VARCHAR(100) DEFAULT NULL, resource_id UUID DEFAULT NULL, metadata JSONB NOT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(512) DEFAULT NULL, occurred_on TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX audit_log_actor_idx ON audit_log (actor_id, occurred_on)');
        $this->addSql('CREATE INDEX audit_log_correlation_idx ON audit_log (correlation_id)');
        $this->addSql('CREATE INDEX audit_log_level_idx ON audit_log (level, occurred_on)');
        $this->addSql('CREATE INDEX audit_log_resource_idx ON audit_log (resource_type, resource_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_log');
    }
}
