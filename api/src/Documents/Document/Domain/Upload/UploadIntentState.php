<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Upload;

/**
 * The lifecycle of an upload intent (`docs/adr/documents-custody-log.md` D6). `Declared` is created by the
 * declare step; the PUT claims it into `Streaming` before a byte is read; one short transaction moves it to
 * `Committed` once the document row exists. `Expired` is reached by the writer's own abort, by the person
 * purge, and implicitly by time: a `Declared` intent past its TTL or a `Streaming` one past its lease or
 * deadline is treated as expired before anything stamps it so.
 */
enum UploadIntentState: string
{
    case Declared = 'declared';
    case Streaming = 'streaming';
    case Committed = 'committed';
    case Expired = 'expired';
}
