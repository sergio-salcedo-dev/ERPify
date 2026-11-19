<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119071028 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documents derivatives: the origin of a dependent image and the release marker on document.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD derivatives_released_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_document_erased_derivatives_pending ON document (id) WHERE (((state)::text = \'erased\'::text) AND (derivatives_released_at IS NULL))');
        $this->addSql('ALTER TABLE image ADD origin_type VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE image ADD origin_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_image_origin ON image (origin_type, origin_id) WHERE (origin_id IS NOT NULL)');
        // An origin is a pair: a type without an id, or an id without a type, would be released by no re-query.
        $this->addSql('ALTER TABLE image ADD CONSTRAINT chk_image_origin_paired CHECK ((origin_type IS NULL) = (origin_id IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_document_erased_derivatives_pending');
        $this->addSql('ALTER TABLE document DROP derivatives_released_at');
        $this->addSql('ALTER TABLE image DROP CONSTRAINT chk_image_origin_paired');
        $this->addSql('DROP INDEX idx_image_origin');
        $this->addSql('ALTER TABLE image DROP origin_type');
        $this->addSql('ALTER TABLE image DROP origin_id');
    }
}
