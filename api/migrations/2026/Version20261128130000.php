<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The documents a bank account holds as evidence: a link table in the BankAccount module (`BankAccountEvidence`).
 *
 * Both foreign keys are without cascade and not deferrable (PostgreSQL's default for a key that names neither), and
 * both are re-injected into the ORM schema by a listener, so `make db.diff` stays empty. The one to `document` crosses a
 * bounded context by decision; its listener records why. The two single-column indexes carry the names DBAL derives for
 * a foreign key's backing index; the unique pair is named so its violation is classified by name.
 */
final class Version20261128130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bank_account_evidence (a bank account\'s link to a document it holds as evidence).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_account_evidence (attached_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, id UUID NOT NULL, bank_account_id UUID NOT NULL, document_id UUID NOT NULL, kind VARCHAR(32) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_bank_account_evidence_account_document ON bank_account_evidence (bank_account_id, document_id)');
        $this->addSql('CREATE INDEX IDX_7DE661BF12CB990C ON bank_account_evidence (bank_account_id)');
        $this->addSql('CREATE INDEX IDX_7DE661BFC33F7837 ON bank_account_evidence (document_id)');
        $this->addSql('ALTER TABLE bank_account_evidence ADD CONSTRAINT fk_bank_account_evidence_bank_account FOREIGN KEY (bank_account_id) REFERENCES bank_account (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE bank_account_evidence ADD CONSTRAINT fk_bank_account_evidence_document FOREIGN KEY (document_id) REFERENCES document (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("ALTER TABLE bank_account_evidence ADD CONSTRAINT bank_account_evidence_kind_check CHECK (kind IN ('ownership-certificate'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_account_evidence DROP CONSTRAINT fk_bank_account_evidence_document');
        $this->addSql('ALTER TABLE bank_account_evidence DROP CONSTRAINT fk_bank_account_evidence_bank_account');
        $this->addSql('DROP TABLE bank_account_evidence');
    }
}
