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

    /**
     * Erases a document from any non-terminal state: its key is destroyed and its sealed object deleted, so only the
     * ADMIN wildcard reaches it, as with `document.rescan` — no tier carries the verb and no explicit grant row exists.
     */
    public const string ERASE = 'document.erase';

    /**
     * Reads the original of a `Ready` document. Not a tier verb, so no tier carries it and only the ADMIN wildcard
     * reaches it; `StaticAuthorizationPolicy` declares the row. A non-ADMIN reader, or a rule that an uploader may
     * download what they filed, is a decision to take there, not a tier to widen. Filing a new version also asks it,
     * beside `document.write`, so granting it to a role also lets that role's writers replace documents.
     */
    public const string DOWNLOAD = 'document.download';

    /**
     * Lists the documents and reads one's custody trail. Not a tier verb either, like `document.download`: the ADMIN
     * wildcard reaches it and `StaticAuthorizationPolicy` declares the row. It returns the sealed original filename,
     * which can name a person.
     */
    public const string CUSTODY = 'document.custody';
}
