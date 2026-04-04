<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404144725 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bank table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank (id UUID NOT NULL, name VARCHAR(255) NOT NULL, short_name VARCHAR(50) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bank');
    }
}
