<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405190808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domain event audit table table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE domain_event (id UUID NOT NULL, name VARCHAR(190) NOT NULL, aggregate_id VARCHAR(36) NOT NULL, event_id VARCHAR(36) NOT NULL, occurred_on TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, body JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX domain_event_aggregate_id_idx ON domain_event (aggregate_id)');
        $this->addSql('CREATE INDEX domain_event_name_idx ON domain_event (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE domain_event');
    }
}
