<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

/**
 * Which argument of {@see RedactingMailer::send()} a message could not be assembled from.
 *
 * An enum rather than a string, for the reason {@see MailDeliveryFailed} gives for every other field it
 * composes: the diagnosis is only safe while its alphabet is bounded, and a `string $field` parameter would
 * put the one unbounded thing back into the message — a caller could pass the rejected value itself. Five
 * cases spell the five parameter NAMES, which are compile-time constants of this file, so no address can be
 * reported as the field that refused one.
 *
 * The names are the parameter names rather than the header names (`recipientEmail`, not `To`) because what an
 * operator needs is the call site to correct, and a `from` that no `Address` accepts is a deployment fault
 * while a `recipientEmail` that none accepts is one person's row.
 */
enum MailAssemblyField: string
{
    case From = 'from';

    case Recipient = 'recipientEmail';

    case Subject = 'subject';

    case Text = 'text';

    case Html = 'html';
}
