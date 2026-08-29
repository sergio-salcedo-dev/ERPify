<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure;

use Attribute;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Validator\Constraints\GroupSequence;

/**
 * `#[MapRequestPayload]` that refuses a body carrying members the payload does not declare.
 *
 * Symfony accepts unknown members by default and discards them, so a request asking for something the endpoint
 * cannot do is answered `200` — the caller is told the whole request succeeded when only the part the DTO
 * recognised was executed. On a write API that is a safety problem, not a stylistic one: `{"roles":["ADMIN"],
 * "status":"SUSPENDED"}` on the role endpoint reads as a status change that never happened.
 *
 * The policy lives in this type rather than in a `serializationContext` array repeated at each call site: the
 * call site states its intent and the configuration stays an implementation detail here, so a new endpoint
 * cannot half-adopt it. Symfony resolves payload attributes with `ArgumentMetadata::IS_INSTANCEOF`, so this
 * subclass is picked up exactly like the parent — no decoration of the resolver, which is `@final` and does its
 * mapping from an event subscriber rather than from the resolver method.
 *
 * `acceptFormat` defaults to `['json']` and cannot be disabled. The resolver runs its format check only while
 * the value is TRUTHY — `if ($attribute->acceptFormat && !\in_array(...))` — so a falsy value does not loosen
 * that check, it skips it, and the endpoint accepts form-encoded and multipart after all. That is the opposite
 * of what this type exists for, and it would be chosen by silence rather than by a call site.
 *
 * The guard therefore MIRRORS the resolver's own predicate rather than enumerating the values that satisfy it.
 * Enumerating is the shape that has already failed twice in this repository, and it fails the same way here:
 * a list of `null`, `[]` and `''` reads as exhaustive and admits `'0'`, a falsy string, which the resolver
 * treats exactly like the other three. One truthiness test cannot be short by one member.
 * Every body this API maps is JSON; a call site that needs another format still passes its own truthy list.
 *
 * Query strings are deliberately NOT covered: `#[MapQueryString]` is a separate attribute and stays permissive,
 * because unknown query parameters are ambient (analytics, cache-busting, a pasted campaign URL) rather than
 * instructions, and failing a read because of one would be self-inflicted.
 *
 * The serializer raises {@see \Symfony\Component\Serializer\Exception\ExtraAttributesException}, which the
 * resolver does not catch — {@see UnknownPayloadMemberListener} turns it into the 422 the rest of the contract
 * already speaks.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class StrictRequestPayload extends MapRequestPayload
{
    /**
     * @param array<string>|string                    $acceptFormat         the payload formats to accept; never falsy
     * @param array<string, mixed>                    $serializationContext extra context; cannot loosen strictness
     * @param string|GroupSequence|array<string>|null $validationGroups     the validation groups to apply
     * @param class-string|string|null                $type                 the element type for array deserialization
     *
     * @throws InvalidArgumentException when $acceptFormat is falsy, which would skip the format check entirely.
     *                                  Symfony builds payload attributes per request in
     *                                  `ArgumentMetadataFactory`, so this surfaces as a 500 on the endpoint's
     *                                  next call — not at container compile, and not in CI.
     */
    public function __construct(
        array|string $acceptFormat = ['json'],
        array $serializationContext = [],
        array|GroupSequence|string|null $validationGroups = null,
        ?string $type = null,
    ) {
        if (!$acceptFormat) {
            throw new InvalidArgumentException(
                'StrictRequestPayload cannot accept a falsy $acceptFormat: the resolver skips its format check '
                . "whenever the value is falsy, so `[]`, `''` and `'0'` alike admit the form-encoded and "
                . 'multipart bodies this attribute exists to refuse. Pass the formats to accept, or omit the '
                . 'argument.',
            );
        }

        parent::__construct(
            acceptFormat: $acceptFormat,
            serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false] + $serializationContext,
            validationGroups: $validationGroups,
            type: $type,
        );
    }
}
