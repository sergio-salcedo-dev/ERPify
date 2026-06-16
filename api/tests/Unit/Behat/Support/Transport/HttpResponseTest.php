<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\Transport;

use Erpify\Tests\Behat\Support\Transport\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(HttpResponse::class)]
final class HttpResponseTest extends TestCase
{
    #[Test]
    public function itMergesStreamedJsonObjectLines(): void
    {
        $response = new HttpResponse('', "{\"a\":1}\n{\"b\":2}\n");

        $streamed = $response->getStreamedResponse();
        $content = (string) $streamed->getContent();

        $this->assertSame(Response::HTTP_OK, $streamed->getStatusCode(), $content);
        $this->assertSame(['a' => 1, 'b' => 2], \json_decode($content, true));
    }

    #[Test]
    public function itThrowsWhenAStreamedLineDecodesToAScalar(): void
    {
        $response = new HttpResponse('', "42\n");

        $this->expectException(UnexpectedValueException::class);

        $response->getStreamedResponse();
    }
}
