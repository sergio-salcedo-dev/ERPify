<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Security;

/**
 * The `document` permission vocabulary this module gates its routes with, consumed by
 * `#[IsGranted(DocumentPermission::WRITE)]` and resolved by the shared `PermissionVoter` against the tier policy.
 * `write` is tier-granted (editor and above). The PUT authorises the caller a second time, as the intent's own
 * actor, from the locked row: the upload id is not a capability.
 */
final class DocumentPermission
{
    public const string WRITE = 'document.write';

    /**
     * Reopens a document whose scan failed. A verb no tier carries, so only the ADMIN wildcard reaches it: an
     * editor, who may file evidence, may not decide that a failed scan is worth trying again.
     */
    public const string RESCAN = 'document.rescan';
}
