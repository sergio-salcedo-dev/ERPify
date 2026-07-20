<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure;

use Attribute;
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
     * @param array<string>|string|null               $acceptFormat         the payload formats to accept
     * @param array<string, mixed>                    $serializationContext extra context; cannot loosen strictness
     * @param string|GroupSequence|array<string>|null $validationGroups     the validation groups to apply
     * @param class-string|string|null                $type                 the element type for array deserialization
     */
    public function __construct(
        array|string|null $acceptFormat = null,
        array $serializationContext = [],
        array|GroupSequence|string|null $validationGroups = null,
        ?string $type = null,
    ) {
        parent::__construct(
            acceptFormat: $acceptFormat,
            serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false] + $serializationContext,
            validationGroups: $validationGroups,
            type: $type,
        );
    }
}
