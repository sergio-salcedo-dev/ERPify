<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\WallClockReads;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifies the wall-clock-read rule against inline sources, so {@see WallClockReadGateTest} can trust it over
 * the real tree without re-deriving it.
 *
 * Every negative case sits beside a positive one proving the same machinery reports the read, because a
 * detector that finds nothing and a detector that cannot find anything report the same green.
 *
 * @internal test support
 */
#[CoversNothing]
final class WallClockReadRulesGateTest extends TestCase
{
    #[Test]
    #[DataProvider('provideASecondTimeSourceIsReportedAtItsLineCases')]
    public function aSecondTimeSourceIsReportedAtItsLine(string $body, string $import = ''): void
    {
        $this->assertSame([4], WallClockReads::inSource($this->source($body, $import)));
    }

    /**
     * @return iterable<string, array{0: string, 1?: string}>
     */
    public static function provideASecondTimeSourceIsReportedAtItsLineCases(): iterable
    {
        yield 'a date constructor with no argument' => ['$a = new DateTimeImmutable();'];
        yield 'a fully-qualified mutable one' => ['$a = new \DateTime();'];
        yield 'one with no parenthesis at all' => ['$a = new DateTimeImmutable;'];
        yield 'a relative literal' => ["\$a = new DateTimeImmutable('-30 days');"];
        yield 'the word now' => ["\$a = new DateTimeImmutable('now', \$zone);"];
        yield 'a relative spec built by concatenation' => ["\$a = new DateTimeImmutable('-' . \$n . ' days');"];
        yield 'Symfony date point with no argument' => [
            '$a = new DatePoint();',
            'use Symfony\Component\Clock\DatePoint;',
        ];
        yield 'the procedural constructor' => ['$a = date_create_immutable();'];
        yield 'time()' => ['$a = time();'];
        yield 'a fully-qualified time()' => ['$a = \time();'];
        yield 'microtime()' => ['$a = microtime(true);'];
        yield 'date() with a format alone' => ["\$a = date('Y-m-d');"];
        yield 'strtotime() with a spec alone' => ["\$a = strtotime('+1 day');"];
        yield 'getdate() with nothing' => ['$a = getdate();'];
        yield 'the global Symfony clock, imported' => [
            '$a = Clock::get()->now();',
            'use Symfony\Component\Clock\Clock;',
        ];
        yield 'the Symfony now() function, imported' => ['$a = now();', 'use function Symfony\Component\Clock\now;'];
        yield 'the global Symfony clock, spelled by its qualified name' => [
            '$a = ' . \Symfony\Component\Clock\Clock::class . '::get()->now();',
        ];
        yield 'a comment between the name and its parenthesis' => ['$a = time /* why */ ();'];
        yield 'a named timezone and no datetime' => ['$a = new DateTimeImmutable(timezone: $zone);'];
        yield 'a named relative datetime' => ["\$a = new DateTimeImmutable(datetime: 'now');"];
        yield 'an interpolated relative spec' => ['$a = new DateTimeImmutable("-{$n} days");'];
        yield 'a relative heredoc' => ["\$a = new DateTimeImmutable(<<<SPEC\n    -30 days\n    SPEC);"];
        yield 'a date class imported under an alias' => ['$a = new Stamp();', 'use DateTimeImmutable as Stamp;'];
        yield 'the global Symfony clock under an alias' => [
            '$a = GlobalClock::get()->now();',
            'use Symfony\Component\Clock\Clock as GlobalClock;',
        ];
        yield 'the global Symfony clock from a grouped import' => [
            '$a = Clock::get()->now();',
            'use Symfony\Component\Clock\{Clock, DatePoint};',
        ];
        yield 'the Symfony now() function under an alias' => [
            '$a = clockNow();',
            'use function Symfony\Component\Clock\now as clockNow;',
        ];
        yield 'a Symfony wall clock built directly' => [
            '$a = new NativeClock();',
            'use Symfony\Component\Clock\NativeClock;',
        ];
        yield 'a Symfony monotonic clock built directly' => ['$a = new \Symfony\Component\Clock\MonotonicClock();'];
        yield 'the request start time' => ["\$a = \$_SERVER['REQUEST_TIME_FLOAT'];"];
        yield 'date() with a format and a trailing comma' => ["\$a = date('Y',);"];
        yield 'a fixed instant spelled outside ISO form, reported on purpose' => [
            "\$a = new DateTimeImmutable('Jan 1 2026');",
        ];
    }

    #[Test]
    #[DataProvider('provideAnInstantTheCodeWasHandedIsNotAReadCases')]
    public function anInstantTheCodeWasHandedIsNotARead(string $body): void
    {
        $this->assertSame([], WallClockReads::inSource($this->source($body)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnInstantTheCodeWasHandedIsNotAReadCases(): iterable
    {
        yield 'the injected port' => ['$a = $this->clock->now();'];
        yield 'parsing a stored value' => ['$a = new DateTimeImmutable($occurredOn);'];
        yield 'an absolute ISO literal' => ["\$a = new DateTimeImmutable('2026-01-01T00:00:00+00:00');"];
        yield 'a Unix timestamp literal' => ["\$a = new DateTimeImmutable('@1700000000');"];
        yield 'a Unix timestamp built by concatenation' => ["\$a = new DateTimeImmutable('@' . \$timestamp);"];
        yield 'a named absolute datetime' => [
            "\$a = new DateTimeImmutable(timezone: \$zone, datetime: '2026-01-01');",
        ];
        yield 'another superglobal key' => ["\$a = \$_SERVER['REQUEST_URI'];"];
        yield 'a trait use inside a class' => ['class A { use Clock; public function b() { return Clock::get(); } }'];
        yield 'date() with a timestamp' => ["\$a = date('Y', \$timestamp);"];
        yield 'strtotime() relative to a base' => ["\$a = strtotime('+1 day', \$base);"];
        yield 'a method that happens to be called time' => ['$a = $limiter->time();'];
        yield 'a static method called time' => ['$a = Limiter::time();'];
        yield 'a method declaration called time' => ['function time() {}'];
        yield 'a monotonic duration' => ['$a = hrtime(true);'];
        yield 'an unrelated class named Clock' => ['$a = Clock::get();'];
        yield 'an unrelated function named now' => ['$a = now();'];
        yield 'a qualified namespaced function' => ['$a = Some\Ns\time();'];
    }

    #[Test]
    public function eachReadIsReportedOnItsOwnLine(): void
    {
        $this->assertSame([4, 5], WallClockReads::inSource($this->source("\$a = time();\n\$b = new DateTime();")));
    }

    /**
     * The body lands on line 4 whether or not an import precedes it, so every expected line above reads as
     * "the first statement".
     */
    private function source(string $body, string $import = ''): string
    {
        return "<?php\n" . $import . "\n\n" . $body . "\n";
    }
}
