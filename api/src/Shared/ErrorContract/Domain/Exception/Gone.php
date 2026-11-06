<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Domain\Exception;

/**
 * Marker for a target that existed and will not exist again for this caller: 410, not 404. The distinction is
 * for the client, which must stop retrying the same target and start over, where a 404 invites it to check the
 * address. It is also the answer an access check gives when "absent" and "forbidden" must be indistinguishable
 * on a target the caller holds an id for.
 */
interface Gone extends ClientError
{
}
