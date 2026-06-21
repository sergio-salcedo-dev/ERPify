<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Http\Infrastructure;

use Erpify\Shared\Http\Infrastructure\ContentAddressedHttpCache;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(ContentAddressedHttpCache::class)]
final class ContentAddressedHttpCacheTest extends TestCase
{
    private const string HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private ContentAddressedHttpCache $httpCache;

    #[Override]
    protected function setUp(): void
    {
        $this->httpCache = new ContentAddressedHttpCache();
    }

    public function testApplyHeadersSetsImmutableCacheEtagAndNosniff(): void
    {
        $response = new Response();

        $this->httpCache->applyHeaders($response, self::HASH);

        $this->assertTrue($response->headers->getCacheControlDirective('public'));
        $this->assertTrue($response->headers->getCacheControlDirective('immutable'));
        $this->assertSame('31536000', (string) $response->headers->getCacheControlDirective('max-age'));
        $this->assertSame('"' . self::HASH . '"', $response->headers->get('ETag'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[DataProvider('provideIsNotModifiedCases')]
    public function testIsNotModified(?string $ifNoneMatch, bool $expected): void
    {
        $request = Request::create('/media/' . self::HASH);

        if (null !== $ifNoneMatch) {
            $request->headers->set('If-None-Match', $ifNoneMatch);
        }

        $this->assertSame($expected, $this->httpCache->isNotModified($request, self::HASH));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function provideIsNotModifiedCases(): iterable
    {
        yield 'missing header is a fresh request' => [null, false];
        yield 'empty header is a fresh request' => ['', false];
        yield 'strong quoted etag matches' => ['"' . self::HASH . '"', true];
        yield 'bare hash matches' => [self::HASH, true];
        yield 'weak validator matches' => ['W/"' . self::HASH . '"', true];
        yield 'wildcard matches any representation' => ['*', true];
        yield 'one of several tags matches' => ['"' . \str_repeat('a', 64) . '", "' . self::HASH . '"', true];
        yield 'different hash does not match' => ['"' . \str_repeat('b', 64) . '"', false];
        yield 'superstring of the hash is not a false match' => ['"' . self::HASH . 'ff"', false];
    }
}
