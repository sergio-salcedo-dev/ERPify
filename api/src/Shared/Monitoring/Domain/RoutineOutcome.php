<?php

declare(strict_types=1);

namespace Erpify\Shared\Monitoring\Domain;

/**
 * Marker for a domain exception whose occurrence is routine traffic rather than an incident, so error
 * tracking keeps it out while it is raised on an HTTP request. It maps no status — the error-contract marker
 * beside it does — and it outranks a security signal marker on the same exception, which is the point of it.
 *
 * Earn it on both counts, never on one: its volume follows normal use (every returning user produces it), and
 * a hostile occurrence is indistinguishable from a benign one by anything the event carries. An exception
 * failing either count stays reported — a rare outcome is cheap to keep, and a distinguishable one is a signal.
 *
 * Consumed by {@see \Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter}.
 */
interface RoutineOutcome
{
}
