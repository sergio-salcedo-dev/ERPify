<?php

declare(strict_types=1);

namespace Erpify\Shared\Console\Infrastructure;

use Throwable;

/**
 * What a command may print about a failure while it is naming nobody.
 *
 * A database driver's message can quote the values involved (`DETAIL: Key (actor_id)=(…) is not present`), and the
 * erasure commands run against exactly the identifier whose erasure they are reporting on — so the message is printed
 * with every UUID and every address-shaped token replaced. It stays a message an operator can act on: the SQLSTATE,
 * the constraint and the table are kept; the value is not. A pattern is a floor and not a proof — an identifier in
 * another spelling still passes — which is why the commands that use it print nothing else about the subject.
 */
final class ThrowableSummary
{
    private const string UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    private const string ADDRESS = '/[^\s<>()\[\]\'",;:@]+@[^\s<>()\[\]\'",;:@]+/';

    public static function of(Throwable $throwable): string
    {
        $message = \preg_replace(self::UUID, '<id>', $throwable->getMessage());
        $message = null === $message ? null : \preg_replace(self::ADDRESS, '<address>', $message);

        return $message ?? $throwable::class;
    }
}
