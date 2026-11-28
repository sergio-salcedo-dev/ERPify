<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bank-account evidence: a link from a bank account to a document of the Documents context, by id on both sides.
 * Both foreign keys have no cascade and are not deferrable, so removing either end while a link names it is refused at
 * the statement that tries. The one into `document` crosses a bounded context on purpose (a justified Level 2 of the
 * bounded-context gate): a document is never deleted, only erased in place, so the key costs nothing and keeps a link
 * from naming a row that never existed.
 *
 * A document is the evidence of one account (`UNIQUE (document_id)`), which also serves the foreign key's lookups
 * from `document`.
 *
 * No backfill: the table is new and no production deployment holds bank accounts with evidence.
 */
final class Version20261128110011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bank-account evidence: the link table from a bank account to a Documents document.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_account_evidence (id UUID NOT NULL, bank_account_id UUID NOT NULL, document_id UUID NOT NULL, kind TEXT NOT NULL, attached_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_bank_account_evidence_document ON bank_account_evidence (document_id)');
        $this->addSql('CREATE INDEX IDX_7DE661BF12CB990C ON bank_account_evidence (bank_account_id)');
        $this->addSql('ALTER TABLE bank_account_evidence ADD CONSTRAINT FK_BANK_ACCOUNT_EVIDENCE_ACCOUNT FOREIGN KEY (bank_account_id) REFERENCES bank_account (id)');
        $this->addSql('ALTER TABLE bank_account_evidence ADD CONSTRAINT FK_BANK_ACCOUNT_EVIDENCE_DOCUMENT FOREIGN KEY (document_id) REFERENCES document (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS bank_account_evidence');
    }
}
