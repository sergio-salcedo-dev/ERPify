<?php

declare(strict_types=1);

namespace Erpify\Shared\Console\Infrastructure;

use Throwable;

/**
 * What a command may print about a failure while it is naming nobody.
 *
 * A database driver's message can quote the values involved (`DETAIL: Key (actor_id)=(…) is not present`, or a whole
 * `Failing row contains (…)`), and the erasure commands run against exactly the identifier whose erasure they are
 * reporting on — so the message is printed with every UUID, every address-shaped token and every IP address replaced.
 * It stays a message an operator can act on: the SQLSTATE, the constraint and the table are kept; the value is not.
 * A pattern is a floor and not a proof — an identifier in another spelling still passes (a 32-hex id, a quoted
 * local part with a space, a compressed IPv6 form, a name or an account number quoted in a failing row) — which is
 * why the commands that use it print nothing else about the subject.
 */
final class ThrowableSummary
{
    /** @var array<string, string> pattern => placeholder, applied in order */
    private const array MASKS = [
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '<id>',
        // Any whitespace-free run holding an `@`, parentheses excluded so a driver's `(value)` frame survives:
        // a quote or an apostrophe is legal in a local part, so excluding them would leave `O'` behind.
        '/[^\s()<>]*@[^\s()<>]*/' => '<address>',
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '<ip>',
        '/\b(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}\b/i' => '<ip>',
    ];

    public static function of(Throwable $throwable): string
    {
        $message = $throwable->getMessage();

        foreach (self::MASKS as $pattern => $placeholder) {
            $message = \preg_replace($pattern, $placeholder, $message);

            if (null === $message) {
                return $throwable::class;
            }
        }

        return '' === $message ? $throwable::class : $message;
    }
}
