<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain;

/**
 * Search filter operators. The backing string IS the wire contract
 * (`filters[N][operator]`), so renaming a value is a breaking API change.
 *
 * This enum is the single extension point for new operators: add a case
 * only when a real consumer needs it, together with its applier branch.
 */
enum FilterOperator: string
{
    case Eq = 'eq';

    case In = 'in';

    case Contains = 'contains';

    case Gt = 'gt';

    case Gte = 'gte';

    case Lt = 'lt';

    case Lte = 'lte';
}
