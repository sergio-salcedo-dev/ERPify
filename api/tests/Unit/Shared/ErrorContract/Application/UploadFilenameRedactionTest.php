<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Application;

use DateTimeImmutable;
use Erpify\Shared\ErrorContract\Application\RequestUriRedaction;
use Erpify\Shared\Monitoring\Infrastructure\Monolog\RequestUriRedactionProcessor;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventScrubber;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

/**
 * The original filename of an evidence upload travels in the PUT's query, so every sink that keeps a URI has to
 * drop it — the router's `request_uri`, the per-error log line and Sentry's `url` and `query_string`. Each row
 * runs through all four paths, because the two shapes a sink holds (a whole URI, a detached query) are redacted
 * by two entry points and a rule that reached only one would leave the name in the other.
 *
 * Off the upload route the rule is the `filename` substring (after decoding and padding reduction); on the
 * upload route the whole query goes, misspelt keys included.
 *
 * @internal
 */
#[CoversClass(RequestUriRedaction::class)]
#[CoversClass(RequestUriRedactionProcessor::class)]
#[CoversClass(SentryEventScrubber::class)]
final class UploadFilenameRedactionTest extends TestCase
{
    private const string SECRET = 'SECRET';

    private const string OTHER_PATH = '/api/v1/backoffice/banks';

    private const string UPLOAD_PATH = '/api/v1/documents/uploads/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c/content';

    #[DataProvider('provideTheNameNeverSurvivesAnyPathCases')]
    public function testTheNameNeverSurvivesAnyPath(string $path, string $query): void
    {
        $uri = $path . '?' . $query;

        $this->assertStringNotContainsString(self::SECRET, RequestUriRedaction::redact($uri), 'redact()');
        $this->assertStringNotContainsString(
            self::SECRET,
            RequestUriRedaction::redactQuery($query, $path),
            'redactQuery()',
        );
        $this->assertStringNotContainsString(self::SECRET, $this->throughMonolog($uri), 'Monolog processor');

        $event = Event::createEvent();
        $event->setRequest(['url' => $uri, 'query_string' => $query]);

        $scrubbed = (new SentryEventScrubber())($event)->getRequest();
        $this->assertStringNotContainsString(self::SECRET, (string) \json_encode($scrubbed), 'Sentry scrubber');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTheNameNeverSurvivesAnyPathCases(): iterable
    {
        foreach (
            [
                'filename' => 'filename=SECRET',
                'FILENAME' => 'FILENAME=SECRET',
                'filename[]' => 'filename[]=SECRET',
                'filename[0]' => 'filename[0]=SECRET',
                'encoded name' => '%66ilename=SECRET',
                'padded name' => 'filename%00=SECRET',
                'repeated key' => 'filename=ok.pdf&filename=SECRET',
                'double-encoded name' => '%2566ilename=SECRET',
                'prefixed name' => 'x-filename=SECRET',
            ] as $label => $query
        ) {
            yield 'off the route: ' . $label => [self::OTHER_PATH, $query];
            yield 'on the route: ' . $label => [self::UPLOAD_PATH, $query];
        }

        yield 'on the route: a misspelt key the controller ignores' => [self::UPLOAD_PATH, 'file_name=SECRET'];
        yield 'on the route: an absolute URI' => ['https://erpify.test' . self::UPLOAD_PATH, 'q=SECRET'];

        foreach (self::uploadRouteSpellings() as $label => $path) {
            yield 'spelt ' . $label . ': a misspelt key' => [$path, 'file_name=SECRET'];
        }
    }

    /**
     * Every spelling of the upload route reaches the whole-query rule, and keeps nothing of its query in any sink.
     */
    #[DataProvider('provideEverySpellingOfTheRouteWithholdsTheWholeQueryCases')]
    public function testEverySpellingOfTheRouteWithholdsTheWholeQuery(string $path): void
    {
        $this->assertTrue(RequestUriRedaction::withholdsWholeQuery($path));
        $this->assertSame(
            $path . '?' . RequestUriRedaction::SENTINEL,
            RequestUriRedaction::redact($path . '?file_name=SECRET'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEverySpellingOfTheRouteWithholdsTheWholeQueryCases(): iterable
    {
        foreach (self::uploadRouteSpellings() as $label => $path) {
            yield $label => [$path];
        }
    }

    /**
     * @return array<string, string>
     */
    private static function uploadRouteSpellings(): array
    {
        $id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

        return [
            'through the front controller' => '/index.php' . self::UPLOAD_PATH,
            'absolute, through the front controller' => 'https://erpify.test/index.php' . self::UPLOAD_PATH,
            'with a percent-encoded segment' => '/api/v1/documents/uploads/' . $id . '/%63ontent',
            'with an encoded slash' => '/api/v1/documents/uploads%2F' . $id . '/content',
            'double-encoded' => '/api/v1/documents/uploads/' . $id . '/%2563ontent',
        ];
    }

    public function testTheUploadRouteKeepsNoQueryShapeAtAll(): void
    {
        $this->assertSame(
            self::UPLOAD_PATH . '?' . RequestUriRedaction::SENTINEL,
            RequestUriRedaction::redact(self::UPLOAD_PATH . '?filename=a.pdf&debug=1'),
        );
    }

    public function testAnotherRouteKeepsItsHarmlessPairs(): void
    {
        $this->assertSame(
            self::OTHER_PATH . '?page=2&filename=' . RequestUriRedaction::SENTINEL,
            RequestUriRedaction::redact(self::OTHER_PATH . '?page=2&filename=a.pdf'),
        );
    }

    public function testANearMissPathIsNotTheUploadRoute(): void
    {
        $this->assertFalse(RequestUriRedaction::withholdsWholeQuery('/api/v1/documents/uploads/x/content/more'));
        $this->assertFalse(RequestUriRedaction::withholdsWholeQuery('/index.php/api/v1/documents/uploads/x/contents'));
        $this->assertFalse(RequestUriRedaction::withholdsWholeQuery('/api/v1/documents/uploads/x/%63ontent%2Fmore'));
        $this->assertTrue(RequestUriRedaction::withholdsWholeQuery('/api/v1/documents/uploads/x/content'));
    }

    private function throughMonolog(string $uri): string
    {
        $record = new LogRecord(
            new DateTimeImmutable(),
            'request',
            Level::Info,
            'Matched route.',
            ['request_uri' => $uri],
        );

        $processor = new RequestUriRedactionProcessor();

        return (string) \json_encode($processor->__invoke($record)->context);
    }
}
