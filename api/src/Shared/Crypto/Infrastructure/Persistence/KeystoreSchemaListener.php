<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\ToolEvents;
use Erpify\Shared\Persistence\Infrastructure\InjectedTableSchemaListener;

/**
 * Injects the `dek_keystore` table into Doctrine's in-memory schema. It is written through plain DBAL
 * ({@see DbalKeystore}) with no ORM entity, so without this listener `make db.diff` would generate a DROP
 * for it. This listener is the source of truth for the table's shape — the migration is generated from it.
 *
 * One wrapped data-encryption key per encryption scope: `wrapped_dek` is base64 of the KEK-sealed key and
 * becomes NULL on destruction, while the row survives with a `destroyed_at` so a crypto-shredding can be
 * reconciled. No foreign key to any domain table — the scope is a cryptographic identity, not a domain one
 * (ADR D13/D17). No column default: the writer supplies every value, as in `audit_log`.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class KeystoreSchemaListener extends InjectedTableSchemaListener
{
    public function __construct()
    {
        parent::__construct('dek_keystore');
    }

    protected function define(TableEditor $table): TableEditor
    {
        return $table
            ->setColumns(
                $this->column('encryption_scope_id', Types::STRING)->setLength(160)->create(),
                $this->column('wrapped_dek', Types::TEXT)->setNotNull(false)->create(),
                $this->column('kek_version', Types::SMALLINT)->create(),
                $this->column('created_at', Types::DATETIMETZ_IMMUTABLE)->create(),
                $this->column('destroyed_at', Types::DATETIMETZ_IMMUTABLE)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('encryption_scope_id')->create(),
            )
            ->setIndexes(
                $this->index('dek_keystore_destroyed_idx', 'destroyed_at')->create(),
            )
        ;
    }
}
