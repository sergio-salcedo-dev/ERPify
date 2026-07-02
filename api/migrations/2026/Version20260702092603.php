<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702092603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create identity_user table (User aggregate: email, password_hash, roles).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_user (email VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, roles JSON NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_39E1FCDE7927C74 ON identity_user (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS identity_user');
    }
}
