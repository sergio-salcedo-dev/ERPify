<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bank logo: the opaque id of the image a bank holds. Nullable with no default (a bank without a logo is the normal
 * case) and no foreign key into `image` — the aggregate references it by id only, and the release of the image is a
 * queued request, never a cascade.
 */
final class Version20261117210036 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bank.logo_image_id (the id of the logo a bank holds).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank ADD logo_image_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank DROP logo_image_id');
    }
}
