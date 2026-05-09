<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Problem;

use Erpify\Shared\Application\Problem\ProblemDetails;
use JsonException;
use JsonSchema\Validator as JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
#[CoversClass(ProblemDetails::class)]
final class ProblemDetailsTest extends TestCase
{
    private const string TYPE = 'about:blank';

    private const string TITLE = 'Internal Server Error';

    private const int STATUS = 500;

    private const string DETAIL = 'Something went wrong while processing your request.';

    private const string INSTANCE = 'urn:uuid:0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string CORRELATION_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    public function testOmitsDetailWhenNullAndExtensionsAreEmpty(): void
    {
        $problemDetails = new ProblemDetails(
            type: self::TYPE,
            title: self::TITLE,
            status: self::STATUS,
            detail: null,
            instance: self::INSTANCE,
            correlationId: self::CORRELATION_ID,
        );

        $array = $problemDetails->toArray();

        $expected = [
            'type' => self::TYPE,
            'title' => self::TITLE,
            'status' => self::STATUS,
            'instance' => self::INSTANCE,
            'correlation-id' => self::CORRELATION_ID,
        ];

        $this->assertSame(
            $expected,
            $array,
            'When detail is null and extensions are empty, only the five remaining core fields must appear in spec order with their values.',
        );
        $this->assertArrayNotHasKey('detail', $array);
    }

    public function testIncludesAllFieldsAndExtensions(): void
    {
        $extensions = [
            'violations' => [
                ['field' => 'name', 'message' => 'NotBlank', 'code' => 'IS_BLANK'],
            ],
            'truncated' => false,
        ];

        $problemDetails = new ProblemDetails(
            type: 'validation-failed',
            title: 'Validation failed',
            status: 400,
            detail: self::DETAIL,
            instance: self::INSTANCE,
            correlationId: self::CORRELATION_ID,
            extensions: $extensions,
        );

        $array = $problemDetails->toArray();

        $expected = [
            'type' => 'validation-failed',
            'title' => 'Validation failed',
            'status' => 400,
            'detail' => self::DETAIL,
            'instance' => self::INSTANCE,
            'correlation-id' => self::CORRELATION_ID,
            'violations' => $extensions['violations'],
            'truncated' => false,
        ];

        $this->assertSame(
            $expected,
            $array,
            'All-fields-present output must follow the spec order, then extension keys in their declared order.',
        );
    }

    public function testPreservesExtensionInsertionOrder(): void
    {
        $problemDetails = new ProblemDetails(
            type: self::TYPE,
            title: self::TITLE,
            status: self::STATUS,
            detail: null,
            instance: self::INSTANCE,
            correlationId: self::CORRELATION_ID,
            extensions: ['a' => 1, 'b' => 2, 'c' => 3],
        );

        $extensionTail = \array_slice(\array_keys($problemDetails->toArray()), 5);

        $this->assertSame(['a', 'b', 'c'], $extensionTail);
    }

    public function testEmitsCorrelationIdAsKebabCase(): void
    {
        $problemDetails = new ProblemDetails(
            type: self::TYPE,
            title: self::TITLE,
            status: self::STATUS,
            detail: null,
            instance: self::INSTANCE,
            correlationId: self::CORRELATION_ID,
        );

        $array = $problemDetails->toArray();

        $this->assertArrayHasKey('correlation-id', $array);
        $this->assertArrayNotHasKey('correlationId', $array);
        $this->assertArrayNotHasKey('correlation_id', $array);
    }

    public function testJsonRoundTripPreservesKeyOrderByteForByte(): void
    {
        $problemDetails = new ProblemDetails(
            type: 'validation-failed',
            title: 'Validation failed',
            status: 400,
            detail: self::DETAIL,
            instance: self::INSTANCE,
            correlationId: self::CORRELATION_ID,
            extensions: [
                'violations' => [
                    ['field' => 'name', 'message' => 'NotBlank', 'code' => 'IS_BLANK'],
                ],
            ],
        );

        $firstPass = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $decoded = \json_decode($firstPass, associative: true, flags: JSON_THROW_ON_ERROR);
        $secondPass = \json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame($firstPass, $secondPass, 'json_encode → json_decode → json_encode must be byte-identical.');
    }

    public function testUtf8FidelityIsPreservedWithJsonUnescapedUnicode(): void
    {
        $problemDetails = new ProblemDetails(
            type: 'not-found',
            title: 'Não encontrado — 資源未找到',
            status: 404,
            detail: null,
            instance: self::INSTANCE,
            correlationId: self::CORRELATION_ID,
        );

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $decoded = \json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Não encontrado — 資源未找到', $json, 'JSON_UNESCAPED_UNICODE must emit literal UTF-8.');
        $this->assertStringNotContainsString('\u', $json, 'No \uXXXX escape sequences expected with JSON_UNESCAPED_UNICODE.');
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('title', $decoded);
        $this->assertSame('Não encontrado — 資源未找到', $decoded['title']);
    }

    public function testJsonEncodeThrowsOnNonEncodableExtensionValue(): void
    {
        $resource = \fopen('php://memory', 'r');
        $this->assertNotFalse($resource);

        try {
            $problemDetails = new ProblemDetails(
                type: self::TYPE,
                title: self::TITLE,
                status: self::STATUS,
                detail: null,
                instance: self::INSTANCE,
                correlationId: self::CORRELATION_ID,
                extensions: ['stream' => $resource],
            );

            $this->expectException(JsonException::class);

            \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } finally {
            \fclose($resource);
        }
    }

    public function testRepresentativeBodyValidatesAgainstRfc9457Schema(): void
    {
        $schemaRef = $this->loadSchemaRef();

        $problemDetails = new ProblemDetails(
            type: 'https://erpify.local/errors/validation-failed',
            title: 'Validation failed',
            status: 400,
            detail: self::DETAIL,
            instance: self::INSTANCE,
            correlationId: self::CORRELATION_ID,
            extensions: [
                'violations' => [
                    ['field' => 'name', 'message' => 'NotBlank', 'code' => 'IS_BLANK'],
                ],
            ],
        );

        $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $data = \json_decode($json, associative: false, flags: JSON_THROW_ON_ERROR);

        $validator = new JsonSchemaValidator();
        $validator->validate($data, $schemaRef);

        $errors = $validator->getErrors();
        $this->assertTrue(
            $validator->isValid(),
            \sprintf('Body must validate against RFC 9457 schema; got errors: %s', \json_encode($errors)),
        );
    }

    public function testSchemaRejectsMalformedBodies(): void
    {
        $schemaRef = $this->loadSchemaRef();

        $missingRequired = (object) [
            'type' => 'about:blank',
            'title' => 'Internal Server Error',
            // status missing — required by the schema
        ];
        $statusAsString = (object) [
            'type' => 'about:blank',
            'title' => 'Internal Server Error',
            'status' => '500',
        ];

        $missingValidator = new JsonSchemaValidator();
        $missingValidator->validate($missingRequired, $schemaRef);
        $this->assertFalse(
            $missingValidator->isValid(),
            'Schema must reject a body missing the required `status` member.',
        );

        $stringStatusValidator = new JsonSchemaValidator();
        $stringStatusValidator->validate($statusAsString, $schemaRef);
        $this->assertFalse(
            $stringStatusValidator->isValid(),
            'Schema must reject a body whose `status` is a string instead of an integer.',
        );
    }

    private function loadSchemaRef(): stdClass
    {
        $schemaPath = __DIR__ . '/../../../../Fixtures/Problem/rfc-9457.schema.json';
        $this->assertFileExists($schemaPath, 'RFC 9457 schema fixture must be bundled.');

        $resolvedPath = \realpath($schemaPath);
        $this->assertNotFalse($resolvedPath, 'realpath() must resolve the schema fixture path.');

        return (object) ['$ref' => 'file://' . $resolvedPath];
    }

    public function testSourceFileContainsNoBannedImports(): void
    {
        $sourcePath = \dirname(__DIR__, 5) . '/src/Shared/Application/Problem/ProblemDetails.php';
        $this->assertFileExists($sourcePath);

        $contents = \file_get_contents($sourcePath);
        $this->assertNotFalse($contents);

        $banned = ['Symfony\\', 'Doctrine\\', 'Psr\Http\\', 'Symfony\Component\Messenger\\', 'App\\'];

        foreach ($banned as $prefix) {
            $this->assertStringNotContainsString(
                'use ' . $prefix,
                $contents,
                \sprintf('ProblemDetails.php must not import any %s symbol — wire shape stays framework-free.', $prefix),
            );
        }
    }
}
