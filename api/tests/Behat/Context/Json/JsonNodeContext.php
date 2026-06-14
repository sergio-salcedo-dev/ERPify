<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context\Json;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use DateMalformedStringException;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Behat\Support\Json\JsonResponseAwareTrait;
use Erpify\Tests\Behat\Support\PostProcess\JsonToolTrait;
use Exception;
use JsonException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\Uid\UuidV7;

/**
 * Validates individual JSON nodes of the last HTTP response (equality, nullability,
 * booleans, containment, existence, …).
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class JsonNodeContext extends AbstractContext
{
    use JsonResponseAwareTrait;
    use JsonToolTrait;

    private const string EXPECTED_STRING_FORMAT = 'Expected text for node %s to be a string, %s given';

    /**
     * Validate that the data is a proper JSON.
     */
    #[Then('the response should be in JSON')]
    public function theResponseShouldBeInJson(): void
    {
        try {
            $this->getJson();
        } catch (Exception $exception) {
            self::fail($exception->getMessage());
        }
    }

    /**
     * Validate that the data is not a proper JSON.
     */
    #[Then('the response should not be in JSON')]
    public function theResponseShouldNotBeInJson(): void
    {
        try {
            $this->getJson();
        } catch (Exception) {
            return;
        }

        self::fail(\sprintf('JSON %s is valid but should not', \json_encode($this->getJson()->getContent())));
    }

    /**
     * Validate the JSON property `node` is equal to `text`.
     *
     * @throws JsonException
     * @throws Exception
     */
    #[Then('the JSON node :node should be equal to :text')]
    public function theJsonNodeShouldBeEqualTo(string $node, string $text): void
    {
        $this->jsonPropertyShouldBeEqualTo($this->getJson(), $node, $text);
    }

    /**
     * Validate the JSON property `node` is not equal to `text`.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should not be equal to :text')]
    public function theJsonNodeShouldNotBeEqualTo(string $node, string $text): void
    {
        $this->jsonPropertyShouldNotBeEqualTo($this->getJson(), $node, $text);
    }

    /**
     * Validate the JSON property at `node` is equal to the value of response header `header`.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should be equal to the response header :header')]
    public function theJsonNodeShouldBeEqualToTheResponseHeader(string $node, string $header): void
    {
        $value = $this->getJsonInspector()->evaluate($this->getJson(), $node);
        self::assertEquals(
            $this->getResponseHeaderValue($header),
            $value,
            \sprintf('JSON node "%s" is not equal to response header "%s".', $node, $header),
        );
    }

    /**
     * Validate the JSON property at `nodeA` is not equal to the JSON property at `nodeB`.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :nodeA should not be equal to the JSON node :nodeB')]
    public function theJsonNodeShouldNotBeEqualToTheJsonNode(string $nodeA, string $nodeB): void
    {
        $valueA = $this->getJsonInspector()->evaluate($this->getJson(), $nodeA);
        $valueB = $this->getJsonInspector()->evaluate($this->getJson(), $nodeB);
        self::assertNotSame(
            $valueA,
            $valueB,
            \sprintf('JSON nodes "%s" and "%s" are equal but should not be.', $nodeA, $nodeB),
        );
    }

    /**
     * Validate the JSON `nodes` are equal to `text`.
     *
     * @throws JsonException
     */
    #[Then('the JSON nodes should be equal to:')]
    #[Then('/^the JSON nodes should be equal to \(if path not empty\):$/')]
    public function theJsonNodesShouldBeEqualTo(TableNode $tableNode): void
    {
        foreach ($tableNode->getRowsHash() as $node => $text) {
            if ('' === $node) {
                continue;
            }

            if (!\is_string($text)) {
                self::fail(\sprintf(self::EXPECTED_STRING_FORMAT, $node, \gettype($text)));
            }

            $this->theJsonNodeShouldBeEqualTo($node, $text);
        }
    }

    /**
     * Validate the JSON property `node` match the given pattern.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should match :pattern')]
    public function theJsonNodeShouldMatch(string $node, string $pattern): void
    {
        $this->jsonPropertyShouldMatch($this->getJson(), $node, $pattern);
    }

    /**
     * Validate the JSON property `node` is an RFC 9562 UUID version 7 (the variant minted by
     * {@see \Erpify\Shared\Domain\Uuid\Uuid::generate()} for `instance` / `correlation-id`).
     * `UuidV7::isValid()` checks format, variant and version in one pass — stricter than a regex.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should be a UUID v7')]
    public function theJsonNodeShouldBeAUuidV7(string $node): void
    {
        $value = $this->getJsonInspector()->evaluate($this->getJson(), $node);
        self::assertIsString($value, \sprintf('JSON node "%s" is not a string.', $node));
        self::assertTrue(
            UuidV7::isValid($value),
            \sprintf('JSON node "%s" value "%s" is not a UUID v7.', $node, $value),
        );
    }

    /**
     * Validate the JSON property `node` is null.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should be null')]
    public function theJsonNodeShouldBeNull(string $node): void
    {
        $this->jsonPropertyShouldBeNull($this->getJson(), $node);
    }

    /**
     * Validate the JSON property `node` is not null.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should not be null')]
    public function theJsonNodeShouldNotBeNull(string $node): void
    {
        $this->jsonPropertyShouldNotBeNull($this->getJson(), $node);
    }

    /**
     * Validate the JSON property `node` is true.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should be true')]
    public function theJsonNodeShouldBeTrue(string $node): void
    {
        $this->jsonPropertyShouldBeTrue($this->getJson(), $node);
    }

    /**
     * Validate the JSON property `node` is false.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should be false')]
    public function theJsonNodeShouldBeFalse(string $node): void
    {
        $this->jsonPropertyShouldBeFalse($this->getJson(), $node);
    }

    /**
     * Validate the JSON property `node` is equal, as a string, to `text`.
     *
     * @throws JsonException
     * @throws Exception
     */
    #[Then('the JSON node :node should be equal to the string :text')]
    public function theJsonNodeShouldBeEqualToTheString(string $node, string $text): void
    {
        $this->jsonPropertyShouldBeEqualTo($this->getJson(), $node, $text);
    }

    /**
     * Validate the JSON property `node` is equal, as a number, to `number`.
     *
     * @throws JsonException
     * @throws Exception
     */
    #[Then('the JSON node :node should be equal to the number :number')]
    public function theJsonNodeShouldBeEqualToTheNumber(string $node, float|int $number): void
    {
        $this->jsonPropertyShouldBeEqualTo($this->getJson(), $node, $number);
    }

    /**
     * Validate the JSON property `node` has `count` children.
     */
    #[Then('the JSON node :node should have :count element(s)')]
    public function theJsonNodeShouldHaveElements(string $node, int $count): void
    {
        $this->jsonPropertyShouldHaveElements($this->getJson(), $node, $count);
    }

    /**
     * Validate the JSON property `node` contains `text`.
     */
    #[Then('the JSON node :node should contain :text')]
    public function theJsonNodeShouldContain(string $node, string $text): void
    {
        $this->jsonPropertyShouldContains($this->getJson(), $node, $text);
    }

    /**
     * Validate the JSON properties `nodes` contains `text`.
     */
    #[Then('the JSON nodes should contain:')]
    public function theJsonNodesShouldContain(TableNode $tableNode): void
    {
        foreach ($tableNode->getRowsHash() as $node => $text) {
            if ('' === $node) {
                continue;
            }

            if (!\is_string($text)) {
                self::fail(\sprintf(self::EXPECTED_STRING_FORMAT, $node, \gettype($text)));
            }

            $this->theJsonNodeShouldContain($node, $text);
        }
    }

    /**
     * Validate the JSON propert `node` does not contain `text`.
     */
    #[Then('the JSON node :node should not contain :text')]
    public function theJsonNodeShouldNotContain(string $node, string $text): void
    {
        $this->jsonPropertyShouldNotContains($this->getJson(), $node, $text);
    }

    /**
     * Validate the JSON properties `nodes` does not contain `text`.
     */
    #[Then('the JSON nodes should not contain:')]
    public function theJsonNodesShouldNotContain(TableNode $tableNode): void
    {
        foreach ($tableNode->getRowsHash() as $node => $text) {
            if ('' === $node) {
                continue;
            }

            if (!\is_string($text)) {
                self::fail(\sprintf(self::EXPECTED_STRING_FORMAT, $node, \gettype($text)));
            }

            $this->theJsonNodeShouldNotContain($node, $text);
        }
    }

    /**
     * Validate the JSON property `node` exists.
     */
    #[Then('the JSON node :name should exist')]
    public function theJsonNodeShouldExist(string $node): void
    {
        $this->jsonPropertyShouldExist($this->getJson(), $node);
    }

    /**
     * Validate the JSON property `node` does not exist.
     */
    #[Then('the JSON node :name should not exist')]
    public function theJsonNodeShouldNotExist(string $node): void
    {
        $this->jsonPropertyShouldNotExist($this->getJson(), $node);
    }

    /**
     * Validate that the whole JSON is equal to the given `content`.
     *
     * @throws JsonException
     */
    #[Then('the JSON should be equal to:')]
    public function theJsonShouldBeEqualTo(PyStringNode $content): void
    {
        $actual = $this->getJson();

        $json = new Json($content);

        self::assertEquals(
            (string) $json,
            (string) $actual,
            "The json is equal to:\n" . $actual->encode(),
        );
    }

    /**
     * Validate that the date in JSON property `node` is equal to `expected`.
     *
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    #[Given('the Date in JSON node :node should be equal to :expected')]
    public function theDateInJSONNodeShouldBeEqualTo(string $node, string $expected): void
    {
        $this->jsonPropertyDateShouldBeEqualTo($this->getJson(), $node, $expected);
    }

    /**
     * Debug scenario to display last JSON response.
     */
    #[Then('print last JSON response')]
    public function printLastJsonResponse(): void
    {
        echo $this->getJson()->encode();
    }

    private function getResponseHeaderValue(string $name): string
    {
        $lastResult = $this->httpResponseContainer->getResult();
        self::assertNotNull($lastResult, 'No HTTP Call made');

        $response = $lastResult->getValue();
        self::assertInstanceOf(
            SymfonyResponse::class,
            $response,
            'Response header lookup requires a Symfony Response.',
        );

        $value = $response->headers->get($name);
        self::assertNotNull($value, \sprintf('Response header "%s" is not set.', $name));

        return $value;
    }
}
