<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops `membership.roles`, leaving `identity_user.roles` the single role authority.
 *
 * The column had no reader: every authorization decision — the session firewall, the permission voter and the
 * active-administrator directory — resolves roles from `identity_user`, so this one was written on every
 * onboarding path and consulted on none. Two writable sources of one fact, in different bounded contexts and
 * with no constraint able to span them, can only diverge silently; the fix is to keep the one that is read.
 *
 * The `down()` restores the column's shape, not its content: the role values are not recoverable from this
 * table and the operative ones were never here. It adds the column with a default so the statement succeeds
 * against a populated table, then drops that default to match the original `JSON NOT NULL` definition.
 */
final class Version20260819091752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop membership.roles — roles are authoritative on identity_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership DROP roles');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE membership ADD roles JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE membership ALTER COLUMN roles DROP DEFAULT');
    }
}
