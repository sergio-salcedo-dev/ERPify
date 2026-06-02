<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bank_account table referencing bank with a unique IBAN';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_account (iban VARCHAR(34) NOT NULL, holder_name VARCHAR(255) NOT NULL, alias VARCHAR(100) DEFAULT NULL, currency VARCHAR(3) NOT NULL, bic VARCHAR(11) DEFAULT NULL, status INT NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, bank_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_53A23E0AFAD56E62 ON bank_account (iban)');
        $this->addSql('CREATE INDEX IDX_53A23E0A11C8FB41 ON bank_account (bank_id)');
        $this->addSql('ALTER TABLE bank_account ADD CONSTRAINT FK_53A23E0A11C8FB41 FOREIGN KEY (bank_id) REFERENCES bank (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_account DROP CONSTRAINT FK_53A23E0A11C8FB41');
        $this->addSql('DROP TABLE bank_account');
    }
}
