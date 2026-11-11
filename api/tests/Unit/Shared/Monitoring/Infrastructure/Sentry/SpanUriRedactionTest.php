<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\ErrorContract\Application\RequestUriRedaction;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SpanUriRedaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A span names its request under one of several keys depending on the SDK version, and carries its query
 * detached from that name. Whichever key names the upload route, the detached query is withheld whole and no
 * spelling of the URI keeps its query.
 *
 * @internal
 */
#[CoversClass(SpanUriRedaction::class)]
final class SpanUriRedactionTest extends TestCase
{
    private const string SECRET = 'SECRET-name-of-a-person.pdf';

    private const string UPLOAD_PATH = '/api/v1/documents/uploads/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c/content';

    /**
     * @param array<string, string> $data
     */
    #[DataProvider('provideTheUploadRouteKeepsNoQueryUnderAnySpellingCases')]
    public function testTheUploadRouteKeepsNoQueryUnderAnySpelling(array $data): void
    {
        $redacted = SpanUriRedaction::apply($data);

        $this->assertStringNotContainsString(self::SECRET, (string) \json_encode($redacted));

        foreach (['url.query', 'http.query'] as $queryKey) {
            if (isset($redacted[$queryKey])) {
                $this->assertSame(RequestUriRedaction::SENTINEL, $redacted[$queryKey], $queryKey);
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function provideTheUploadRouteKeepsNoQueryUnderAnySpellingCases(): iterable
    {
        $query = 'file_name=' . self::SECRET;

        yield 'http.url' => [['http.url' => 'https://erpify.test' . self::UPLOAD_PATH, 'http.query' => $query]];
        yield 'url.full' => [['url.full' => 'https://erpify.test' . self::UPLOAD_PATH, 'url.query' => $query]];
        yield 'url' => [['url' => self::UPLOAD_PATH, 'url.query' => $query]];
        yield 'url.path' => [['url.path' => self::UPLOAD_PATH, 'url.query' => $query]];
        yield 'http.target with its query' => [['http.target' => self::UPLOAD_PATH . '?' . $query]];
        yield 'http.target and a detached query' => [['http.target' => self::UPLOAD_PATH, 'http.query' => $query]];
        yield 'url.path through the front controller' => [
            ['url.path' => '/index.php' . self::UPLOAD_PATH, 'url.query' => $query],
        ];
        yield 'url.path with an encoded segment' => [
            ['url.path' => \str_replace('/content', '/%63ontent', self::UPLOAD_PATH), 'url.query' => $query],
        ];
    }

    /**
     * The keys of one span describe one request: when `url.path` names the upload route, a `url.full` spelling the
     * path in a way only that key's own rule would miss still loses its query.
     */
    public function testEveryUriKeyOfASpanNamingTheRouteLosesItsQuery(): void
    {
        $redacted = SpanUriRedaction::apply([
            'url.path' => self::UPLOAD_PATH,
            'url.full' => 'https://erpify.test/some/rewritten/path?file_name=' . self::SECRET,
            'http.target' => '/other?file_name=' . self::SECRET,
        ]);

        $this->assertSame(
            'https://erpify.test/some/rewritten/path?' . RequestUriRedaction::SENTINEL,
            $redacted['url.full'] ?? null,
        );
        $this->assertSame('/other?' . RequestUriRedaction::SENTINEL, $redacted['http.target'] ?? null);
    }

    public function testAnotherRouteKeepsItsHarmlessPairs(): void
    {
        $redacted = SpanUriRedaction::apply(['url.path' => '/api/v1/backoffice/banks', 'url.query' => 'page=2']);

        $this->assertSame('page=2', $redacted['url.query'] ?? null);
    }
}
