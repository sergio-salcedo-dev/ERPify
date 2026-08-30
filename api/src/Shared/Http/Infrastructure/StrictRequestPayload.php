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
 * The accepted format is fixed at `['json']` and the constructor declares no parameter for it, so no call site
 * can name it at all. That is a stronger statement than a validated argument, because of how the resolver
 * reads the value: it runs its format check only while `acceptFormat` is TRUTHY —
 * `if ($attribute->acceptFormat && !\in_array(...))` — so a falsy value does not loosen the check, it SKIPS
 * it, and the endpoint accepts form-encoded and multipart after all. A guard rejecting that value can only be
 * as complete as its own predicate; a parameter that does not exist has no predicate to fall short of, and
 * the state becomes unrepresentable rather than rejected.
 *
 * Every body this API maps is JSON. An endpoint that one day needs another format reintroduces the parameter
 * deliberately — at the same cost as declaring it here, with better information about what it should admit.
 *
 * The cost is stated because a call site cannot see it: `serializationContext` is the first positional
 * parameter, so `new StrictRequestPayload(['json'])` feeds a format list into the serializer context instead
 * of being refused. Nothing constructs this positionally — every annotated endpoint passes nothing at all.
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
    /** @var non-empty-list<string> */
    private const array ACCEPTED_FORMATS = ['json'];

    /**
     * @param array<string, mixed>                    $serializationContext extra context; cannot loosen strictness
     * @param string|GroupSequence|array<string>|null $validationGroups     the validation groups to apply
     * @param class-string|string|null                $type                 the element type for array deserialization
     */
    public function __construct(
        array $serializationContext = [],
        array|GroupSequence|string|null $validationGroups = null,
        ?string $type = null,
    ) {
        parent::__construct(
            acceptFormat: self::ACCEPTED_FORMATS,
            serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false] + $serializationContext,
            validationGroups: $validationGroups,
            type: $type,
        );
    }
}
