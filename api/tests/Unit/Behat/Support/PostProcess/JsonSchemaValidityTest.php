<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\PostProcess;

use Behat\Gherkin\Node\PyStringNode;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Behat\Support\Json\JsonSchema;
use Erpify\Tests\Unit\Behat\Support\PostProcess\Fixtures\JsonAssertions;
use JsonSchema\Exception\RuntimeException as JsonSchemaException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The three outcomes "the JSON should be invalid according to this schema" has to keep apart.
 *
 * Only one of them is the claim the step makes. An unresolvable `$ref`, an unreadable schema or a
 * missing library all end the validation without the schema ever being applied, and reading any of
 * them as "invalid" makes the step pass hardest exactly when it has checked least.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class JsonSchemaValidityTest extends TestCase
{
    private const string PAYLOAD = '{"node": "a string"}';

    public function testItHoldsWhenTheSchemaRejectsTheDocument(): void
    {
        // The step asserts nothing when the schema does reject: it returns. Reaching the next line is
        // the result, and it is the only one of the three that is expressed by not throwing.
        $this->expectNotToPerformAssertions();

        JsonAssertions::withScalarModifiers()->jsonShouldNotBeValid(
            new Json(self::PAYLOAD),
            $this->schema('{"type": "object", "properties": {"node": {"type": "integer"}}}'),
        );
    }

    public function testItFailsWhenTheSchemaAcceptsTheDocument(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('Given JSON validates the schema');

        JsonAssertions::withScalarModifiers()->jsonShouldNotBeValid(
            new Json(self::PAYLOAD),
            $this->schema('{"type": "object", "properties": {"node": {"type": "string"}}}'),
        );
    }

    public function testItDoesNotHoldWhenTheSchemaWasNeverLoaded(): void
    {
        $this->expectException(JsonSchemaException::class);

        JsonAssertions::withScalarModifiers()->jsonShouldNotBeValid(
            new Json(self::PAYLOAD),
            new JsonSchema(new PyStringNode(['{}'], 0), 'file:///erpify/no/such/schema.json'),
        );
    }

    private function schema(string $payload): JsonSchema
    {
        return new JsonSchema(new PyStringNode([$payload], 0));
    }
}
