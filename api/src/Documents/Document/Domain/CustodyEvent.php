<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain;

/**
 * A fact the custody log records, named for what happened and never for the document's state afterwards: a
 * stored value keeps its meaning for ever, whereas the state a fact led to is a function of the lifecycle at
 * the time.
 *
 * Holds only what is written today. `document_custody.event` carries no database CHECK on purpose, so a later
 * story adds its case here without a migration.
 */
enum CustodyEvent: string
{
    case Received = 'received';
}
