<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates `image`, the first ORM-mapped table of the shared kernel.
 *
 * Seven columns and deliberately not an eighth. There is no `updated_at`, because the aggregate is
 * immutable and the row is written once and deleted once; no `owner_id`, because an image knows
 * nothing about who references it, and the consuming context holds the reference by id; no
 * `filename`, `url` or `storage_path`, because the storage key is derived from the identifier alone
 * and a physical path is not part of the domain; and no canonicalisation-version column, since
 * canonicalisation is an implicit v1.
 *
 * There is no unique index on `digest` on purpose. Two uploads of identical bytes are two
 * independent images with two identifiers and two storage objects: a uniqueness constraint here
 * would be deduplication introduced through the back door, which the module's contract refuses.
 *
 * `created_at` is `TIMESTAMP(6) WITH TIME ZONE`, matching the other tables this kernel owns rather
 * than the `TIMESTAMP(0)` the ORM's built-in immutable type emits. Whole-second precision would make
 * a preserved stamp indistinguishable from one taken again during the same second, which is the
 * property the hydration test exists to observe.
 *
 * The `down()` is a plain drop and is genuinely reversible for the schema. It does not restore rows,
 * and it does not remove the canonical bytes those rows pointed at: object storage is not
 * transactional with this database, so bytes written under an identifier survive their row.
 */
final class Version20260828134621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the image table — seven columns, no updated_at, no digest uniqueness';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE image (id UUID NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, digest VARCHAR(64) NOT NULL, media_type VARCHAR(64) NOT NULL, width INT NOT NULL, height INT NOT NULL, byte_size INT NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE image');
    }
}
