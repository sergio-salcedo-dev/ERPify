<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain;

/**
 * The logical lifecycle of a document, persisted as the snapshot's `state`. The transitions between these
 * and who may drive them belong to the stories that deliver each one; this is only the closed vocabulary the
 * column is checked against (`document_state_check`), so adding a state is a decision with a migration.
 */
enum DocumentState: string
{
    case Received = 'received';
    case Scanning = 'scanning';
    case Ready = 'ready';
    case Quarantined = 'quarantined';
    case ScanFailed = 'scan_failed';
    case Erased = 'erased';
}
