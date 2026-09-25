<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Throwable;

/**
 * Keeps a `change` row's `action` and its `metadata.operation` saying the same thing.
 *
 * The capture listener stamps BOTH from one {@see AuditWriteOperation}: `action` is whatever the aggregate's
 * {@see AuditedEntity::auditAction()} returns for it, `metadata.operation` is the case NAME. Nothing in the
 * type system ties the two, so an aggregate answering `BANK_MODIFIED` for `UPDATED` writes a row that states
 * two different facts about one write, and a reader filtering by either sees a different trail.
 *
 * The rule, per aggregate: every operation's action ends in `_<OPERATION>` (the case name), and what precedes
 * that suffix — the root — is non-empty and the same for every operation. It is a spelling convention,
 * not a vocabulary: the root is the aggregate's own (`BANK`, `BANK_ACCOUNT`), which is what
 * {@see AuditedEntity} leaves to the owning module.
 *
 * What a green proves, and only this: each action the aggregate returns TODAY, for each case the enum declares
 * TODAY, satisfies the convention. It says nothing about an action written by any path other than the capture
 * listener, and nothing about rows already stored.
 *
 * @internal test support
 */
final class AuditActionOperationAgreement
{
    /**
     * Every violation `$entity` commits, each naming `$label` (the class, for a real aggregate) and the
     * operation it concerns. An empty list is conformance.
     *
     * @return list<string>
     */
    public static function violations(AuditedEntity $entity, string $label): array
    {
        $violations = [];
        $roots = [];

        foreach (AuditWriteOperation::cases() as $operation) {
            try {
                $action = $entity->auditAction($operation);
            } catch (Throwable $throwable) {
                $violations[] = \sprintf(
                    '%s::auditAction(%s) throws %s: every write kind must name an action.',
                    $label,
                    $operation->name,
                    $throwable::class,
                );

                continue;
            }

            $suffix = '_' . $operation->name;

            if (!\str_ends_with($action, $suffix)) {
                $violations[] = \sprintf(
                    "%s::auditAction(%s) returns '%s', which does not end in '%s' — the row's `action` would "
                    . 'disagree with the `metadata.operation` stamped beside it.',
                    $label,
                    $operation->name,
                    $action,
                    $suffix,
                );

                continue;
            }

            $root = \substr($action, 0, -\strlen($suffix));

            if ('' === $root) {
                $violations[] = \sprintf(
                    "%s::auditAction(%s) returns '%s', which has no root before '%s' — the action would not say "
                    . 'WHAT was written.',
                    $label,
                    $operation->name,
                    $action,
                    $suffix,
                );

                continue;
            }

            $roots[$operation->name] = $root;
        }

        if (\count(\array_unique($roots)) > 1) {
            $violations[] = \sprintf(
                '%s::auditAction() uses different roots across operations (%s): one aggregate must name its '
                . 'writes from one root.',
                $label,
                \implode(', ', \array_map(
                    static fn (string $operation, string $root): string => $operation . ' => ' . $root,
                    \array_keys($roots),
                    $roots,
                )),
            );
        }

        return $violations;
    }
}
