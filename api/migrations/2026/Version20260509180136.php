<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509180136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce uniqueness on bank.name and bank.short_name to align with #[UniqueEntity].';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_name ON bank (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_short_name ON bank (short_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_bank_name');
        $this->addSql('DROP INDEX UNIQ_bank_short_name');
    }
}
