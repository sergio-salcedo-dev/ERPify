<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use ValueError;

/**
 * The single definition of which range bound a search will honour, and the single place that turns one
 * into an instant.
 *
 * **It exists because the copy of it diverged.** Two appliers carried this parse verbatim — same formats,
 * same gates, same round-trip — and one of them silently lost its UTC-offset check somewhere along the
 * way. The result was that `2026-01-01T00:00:00+99:00` was a 422 on one list and, on the other, a bound
 * accepted and shifted by four days with nothing reported. A review measured the divergence; the two
 * bodies were otherwise identical character for character, which is exactly the shape a second copy takes
 * just before it stops matching.
 *
 * Kept free of {@see Filter} and of {@see Exception\InvalidSearchValue} on
 * purpose: this answers "is this string an instant we can honour", and *which* error a refusal becomes —
 * with which field name and which position — belongs to the applier that owns the error contract.
 */
final class StrictRangeBound
{
    /**
     * Accepted bound formats, tried in order: ATOM (`+00:00`/`Z` at second precision), the millisecond
     * `toISOString()` a JS client emits, and the microsecond form the audit timeline carries. `P` matches
     * both an offset and `Z`, spanning the RFC 3339 surface without a lax parse that would also accept
     * "now" or "tomorrow".
     */
    private const array SUPPORTED_FORMATS = [
        DateTimeInterface::ATOM,
        DateTimeInterface::RFC3339_EXTENDED,
        'Y-m-d\TH:i:s.uP',
    ];

    /** Easternmost real-world UTC offset (UTC+14, e.g. Kiribati); east of it a bound is nonsensical. */
    private const int MAX_UTC_OFFSET_EAST_SECONDS = 14 * 3600;

    /** Westernmost real-world UTC offset (UTC-12, e.g. Baker Island); west of it a bound is nonsensical. */
    private const int MIN_UTC_OFFSET_WEST_SECONDS = -12 * 3600;

    /** The year PostgreSQL's calendar does not have; see {@see self::carriesAStorableYear()}. */
    private const string UNSTORABLE_YEAR = '0000';

    private function __construct()
    {
    }

    /**
     * The instant the value denotes, normalised to UTC — or `null` when no accepted format renders it
     * back byte for byte, when its offset is not one the world has, or when its year is one the database
     * cannot store.
     */
    public static function parse(string $value): ?DateTimeImmutable
    {
        foreach (self::SUPPORTED_FORMATS as $format) {
            $dateTime = self::parseStrict($format, $value);

            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime->setTimezone(new DateTimeZone('UTC'));
            }
        }

        return null;
    }

    private static function parseStrict(string $format, string $value): ?DateTimeImmutable
    {
        try {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value);
        } catch (ValueError) {
            // A value with a null byte makes `createFromFormat` throw rather than return, and that is
            // client input too: caught here so it becomes a refusal rather than escaping as an engine 500.
            return null;
        }

        // A real instant is required before any offset or year is read; `createFromFormat` returns false
        // on an unparseable value and on a hard error, so the instance test subsumes both and is what
        // narrows the type for the three gates behind it.
        if (
            !$dateTime instanceof DateTimeImmutable
            || !self::carriesAStorableYear($dateTime)
            || !self::carriesRealWorldOffset($dateTime)
            || !self::isCanonicalUnder($dateTime, $format, $value)
        ) {
            return null;
        }

        return $dateTime;
    }

    /**
     * PostgreSQL's calendar runs from 1 BC straight to 1 AD, so it has no year zero and rejects `0000-…`
     * with SQLSTATE 22008. PHP parses that year happily and it round-trips byte-identically, so every
     * other gate here passes it: measured, the bound reached the driver and surfaced as a 500 on input
     * any client can send for free. A four-digit year makes it the only unstorable instant these formats
     * can express; `0001-01-01` and `9999-12-31` both store, measured against the running server.
     */
    private static function carriesAStorableYear(DateTimeImmutable $dateTime): bool
    {
        return self::UNSTORABLE_YEAR !== $dateTime->format('Y');
    }

    /**
     * The real-world offset span is asymmetric (UTC-12 to UTC+14), so each side is checked separately; a
     * symmetric abs() would admit the non-existent -13/-14h offsets. PHP parses an offset all the way to
     * ±99:00 and every one of them round-trips canonically, so without this gate a bound is accepted and
     * the instant silently shifts by up to four days.
     */
    private static function carriesRealWorldOffset(DateTimeImmutable $dateTime): bool
    {
        return $dateTime->getOffset() <= self::MAX_UTC_OFFSET_EAST_SECONDS
            && $dateTime->getOffset() >= self::MIN_UTC_OFFSET_WEST_SECONDS;
    }

    /**
     * Round-trip gate — the sole canonicality check: the value is canonical under `$format` only if
     * formatting the parsed instant reproduces it byte-identically. That is what makes the parse strict
     * without trusting either of two unreliable signals: `createFromFormat()` tolerates non-canonical
     * digit widths (a single-digit month) without raising a warning, and `getLastErrors()` exposes global
     * state any adjacent datetime call may clobber. Trailing data, calendar rollover and defaulted-missing
     * fields all shift the render away from the input.
     *
     * UTC has two canonical spellings: `P` parses both but emits `+00:00`, while `p` emits the literal `Z`
     * a JS `toISOString()` client sends — so either rendering of the same instant is accepted.
     */
    private static function isCanonicalUnder(DateTimeImmutable $dateTime, string $format, string $value): bool
    {
        return $dateTime->format($format) === $value
            || $dateTime->format(\str_replace('P', 'p', $format)) === $value;
    }
}
