<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `erpify.images.max_decoded_pixels`, `erpify.images.max_served_bytes` and PHP's `memory_limit` are ONE
 * decision, and until this gate nothing held them together.
 *
 * The defect it exists to refuse shipped and sat for two stories: the pixel ceiling was 40 MP against a
 * `memory_limit` the repository never set at all — the base image's 128M — and measured against the real
 * worker, an ordinary 12 MP phone photo died with `Allowed memory size exhausted` INSIDE `scaleDown()`, 3.3x
 * inside the ceiling that was configured to accept it. **A fatal error is not a `Throwable`**: the RFC 9457
 * pipeline never runs, no Problem Details response is produced, and in worker mode the worker itself goes
 * down. Every gate in the tree was green over it, because nothing in the tree related the two numbers.
 *
 * **The coefficient is a measurement, not an estimate.** GD holds a truecolor frame at 4 bytes per pixel and
 * `ResizeModifier::resizeFrame()` clones it before resizing, so the peak runs at roughly 8 bytes per INPUT
 * pixel plus the decoder's own buffers. Measured with `memory_get_peak_usage()` against this container —
 * never `docker stats`, whose sampling misses a peak that lives for the length of one decode — with the test
 * image generated in a SEPARATE process so the harness's own frame never entered the reading:
 *
 * | input   | format | peak      | memory_limit | outcome |
 * |---------|--------|-----------|--------------|---------|
 * | 6.0 MP  | jpeg   | 78.9 MiB  | 128M         | ok      |
 * | 7.5 MP  | jpeg   | 108.5 MiB | 128M         | ok      |
 * | 12.0 MP | jpeg   | —         | 128M         | FATAL   |
 * | 12.0 MP | jpeg   | 151.9 MiB | 256M         | ok      |
 * | 20.0 MP | png    | 191.2 MiB | 256M         | ok      |
 * | 24.0 MP | png    | 190.7 MiB | 256M         | ok      |
 * | 40.0 MP | jpeg   | 253.2 MiB | 256M         | ok      |
 * | 40.0 MP | png    | —         | 256M         | FATAL   |
 *
 * The last two rows are why the ceiling is 20 MP and not 40: at 40 MP the outcome depends on the FORMAT, and
 * a limit that holds for one encoder and not another is not a limit. 10 bytes per pixel is the coefficient
 * those rows support, rounded up from the ~9.5 the worst of them measures.
 *
 * **What a green proves, and what it does not.** It proves the two committed numbers are arithmetically
 * compatible under a coefficient measured on this container. It does NOT decode anything — that would cost a
 * second and 200 MiB in CI to re-derive a number already written down — so it cannot see a library release
 * that changes the coefficient, and it says nothing about the OUTER bound, which is the container rather
 * than this ini: prod caps the php service at 1 GiB and FrankenPHP runs about two workers per core, so a
 * concurrent burst of large uploads is bounded by `PHP_MEM_LIMIT`, which no gate here reads. Sizing that
 * belongs to the epic that exposes an upload endpoint; there is none today.
 *
 * **The serving budget is DERIVED, and that is the point of holding it here.** `max_served_bytes` governs
 * the same memory from the other end: the storage port returns the whole object as one string, so the read
 * path materialises it in full. Choosing that number by hand is what produced the defect this gate was
 * extended to refuse — 20 MiB against a pipeline that can legitimately emit far more, so an image was
 * storable and then permanently unservable, answering 500 for ever and indistinguishable from a broken
 * deployment. Measured: a palette PNG of 4472x4472 weighing 8.85 MiB canonicalises to 49.23 MiB.
 *
 * So the gate asserts the RELATION rather than the number. The canonical object is bounded by
 * `max_output_dimension`, because that is what caps either side of the output: at 4 bytes per pixel plus
 * PNG's one filter byte per scanline, `d x (d x 4 + 1)` is the pre-deflate stream, and deflate on
 * incompressible data adds stored-block overhead rather than removing any. Note this is why a round 64 MiB
 * is NOT the ceiling at d=4096 — it is 4096 bytes short of it. The consequence that matters is the one a
 * literal cannot give: move `max_output_dimension` and the serving budget must be re-evaluated or this
 * goes red.
 *
 * **The 2x factor is a conservative assumption, not a measurement.** `Response::sendContent()` is
 * `echo $this->content;` — no copy at the PHP level — so whether a second copy exists depends on output
 * buffering. Doubling is the safe reading; it is labelled here rather than asserted so nobody later cites
 * it as measured.
 *
 * @internal
 */
#[CoversNothing]
final class ImagePixelBudgetGateTest extends TestCase
{
    private const string SERVICES_CONFIG = __DIR__ . '/../../../../config/services.yaml';

    private const string PHP_INI = __DIR__ . '/../../../../frankenphp/conf.d/10-app.ini';

    /**
     * The other two files landing in the same scanned directory. PHP reads the directory alphabetically and
     * the last declaration wins, so a `memory_limit` in either of these governs the runtime while this gate
     * reads a number that never takes effect.
     */
    private const array SIBLING_INI = [
        __DIR__ . '/../../../../frankenphp/conf.d/20-app.dev.ini',
        __DIR__ . '/../../../../frankenphp/conf.d/20-app.prod.ini',
    ];

    /** Bytes per pixel of the widest canonical frame, plus PNG's one filter byte per scanline. */
    private const int CANONICAL_BYTES_PER_PIXEL = 4;

    /** Deflate on incompressible data does not shrink it; stored blocks add a little. */
    private const float INCOMPRESSIBLE_OVERHEAD = 1.001;

    /** See the class docblock: assumed, not measured. */
    private const int CONCURRENT_COPIES_OF_A_SERVED_OBJECT = 2;

    /** Measured, see the class docblock. Rounded UP from the worst observed ~9.5 B/px. */
    private const int PEAK_BYTES_PER_INPUT_PIXEL = 10;

    /** What the interpreter and the encoded input occupy before a single pixel is decoded, measured. */
    private const int BASELINE_BYTES = 16 * 1024 * 1024;

    /**
     * The share of `memory_limit` one decode may claim. Not 100%: a request does more than decode, and a
     * ceiling that only just fits is a ceiling that fails on the first input the measurement did not cover.
     */
    private const float BUDGET_FRACTION = 0.90;

    #[Test]
    public function theConfiguredPixelCeilingFitsTheConfiguredMemoryBudget(): void
    {
        $memoryLimit = $this->memoryLimitInBytes();
        $pixels = $this->parameter('max_decoded_pixels');

        $projectedPeak = self::BASELINE_BYTES + ($pixels * self::PEAK_BYTES_PER_INPUT_PIXEL);
        $available = (int) ($memoryLimit * self::BUDGET_FRACTION);

        $this->assertLessThanOrEqual($available, $projectedPeak, \sprintf(
            'A single decode at the configured ceiling projects to %.1f MiB against a %.1f MiB budget '
            . "(memory_limit %.0f MiB x %.0f%%).\n"
            . 'These two numbers are one decision: lower `erpify.images.max_decoded_pixels` in '
            . 'config/services.yaml, or raise `memory_limit` in frankenphp/conf.d/10-app.ini. Exceeding it '
            . 'is not a slow degradation — it is a FATAL error, which produces no Problem Details response '
            . 'and takes the worker down with it.',
            $projectedPeak / 1048576,
            $available / 1048576,
            $memoryLimit / 1048576,
            self::BUDGET_FRACTION * 100,
        ));
    }

    /**
     * The serving budget must admit everything the pipeline can legitimately emit. Below this line an image
     * is storable and then permanently unservable — a condition created at ingest and answered at read,
     * where nothing can act on it.
     */
    #[Test]
    public function theServingBudgetAdmitsTheLargestObjectThePipelineCanProduce(): void
    {
        $side = $this->parameter('max_output_dimension');
        $ceiling = (int) \ceil(
            $side * ($side * self::CANONICAL_BYTES_PER_PIXEL + 1) * self::INCOMPRESSIBLE_OVERHEAD,
        );

        $this->assertGreaterThanOrEqual($ceiling, $this->parameter('max_served_bytes'), \sprintf(
            'The canonical output is capped at %d px per side, so it can reach %.1f MiB, but the serving '
            . "budget is %.1f MiB.\n"
            . 'Everything above the budget is storable and then answers 500 for ever. Raise '
            . '`erpify.images.max_served_bytes` or lower `erpify.images.max_output_dimension` — they are '
            . 'one decision, which is why neither may move alone.',
            $side,
            $ceiling / 1048576,
            $this->parameter('max_served_bytes') / 1048576,
        ));
    }

    /**
     * And the other end of the same budget: what the read path materialises has to fit the process it is
     * materialised in, or the fix for the assertion above is a fatal error instead of a 500.
     */
    #[Test]
    public function theServingBudgetFitsTheConfiguredMemoryBudget(): void
    {
        $projectedPeak = self::BASELINE_BYTES
            + ($this->parameter('max_served_bytes') * self::CONCURRENT_COPIES_OF_A_SERVED_OBJECT);
        $available = (int) ($this->memoryLimitInBytes() * self::BUDGET_FRACTION);

        $this->assertLessThanOrEqual($available, $projectedPeak, \sprintf(
            'Serving one object at the configured budget projects to %.1f MiB against %.1f MiB. Raising '
            . '`erpify.images.max_served_bytes` without raising `memory_limit` moves the failure from a 500 '
            . 'to a FATAL error, which produces no Problem Details response at all.',
            $projectedPeak / 1048576,
            $available / 1048576,
        ));
    }

    /**
     * Without this the assertions above are satisfied by a ceiling of zero, which is also how a failed parse
     * looks: the arithmetic would pass over a key that had been renamed away.
     */
    #[Test]
    public function everyNumberIsActuallyReadRatherThanDefaulted(): void
    {
        $this->assertGreaterThan(0, $this->parameter('max_decoded_pixels'));
        $this->assertGreaterThan(0, $this->parameter('max_output_dimension'));
        $this->assertGreaterThan(0, $this->parameter('max_served_bytes'));
        $this->assertGreaterThan(0, $this->memoryLimitInBytes());
    }

    /**
     * `10-app.ini` is not the only file in the scanned directory, and PHP lets the last declaration win. A
     * `memory_limit` in either sibling would govern the runtime while every assertion here reads a number
     * that never takes effect — the gate's verdict would be about a deployment nobody runs.
     */
    #[Test]
    public function noSiblingIniRedeclaresTheMemoryBudget(): void
    {
        foreach (self::SIBLING_INI as $path) {
            if (!\is_file($path)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/^\s*memory_limit\s*=/m',
                (string) \file_get_contents($path),
                \sprintf(
                    '%s declares memory_limit. The conf.d files are read alphabetically and the last one '
                    . 'wins, so this value governs the runtime while this gate reads 10-app.ini. Keep the '
                    . 'budget in one place.',
                    \basename($path),
                ),
            );
        }
    }

    /**
     * `memory_limit` must be declared by the REPOSITORY and not inherited. Left unset it is whichever
     * `php.ini-*` the base image happened to copy — 128M from `php.ini-production` — which is how the
     * pixel ceiling came to be 3.3x a budget nobody had chosen.
     */
    #[Test]
    public function theMemoryBudgetIsDeclaredInTheRepositoryRatherThanInheritedFromTheBaseImage(): void
    {
        $this->assertMatchesRegularExpression(
            '/^memory_limit\s*=/m',
            (string) \file_get_contents(self::PHP_INI),
            'frankenphp/conf.d/10-app.ini declares no memory_limit, so the budget is whatever the base '
            . 'image supplies and this gate is comparing against a number nobody in this repository chose.',
        );
    }

    /**
     * Reads one `erpify.images.*` parameter, and refuses three shapes a prefix match used to swallow.
     *
     * **Exactly one declaration.** A duplicated key is read by this gate as the FIRST and by the container
     * as the LAST, which was measured in both directions — the gate reading 1 while the container used
     * 40000000, and the gate reading 256M while PHP used 64M. A gate that can disagree with the runtime it
     * describes is worse than no gate.
     *
     * **The whole scalar, not a prefix.** `20_000_000` and `2e7` are valid integers to Symfony's YAML
     * parser and read as `20` and `2` to `(\d+)` — small enough to satisfy every arithmetic assertion here
     * AND the non-vacuity guard written to catch exactly that.
     */
    private function parameter(string $name): int
    {
        $config = (string) \file_get_contents(self::SERVICES_CONFIG);
        $pattern = \sprintf('/^\s*erpify\.images\.%s:[ \t]*(\S+)/m', \preg_quote($name, '/'));
        $found = \preg_match_all($pattern, $config, $matches);

        if (1 !== $found) {
            throw new RuntimeException(\sprintf(
                'erpify.images.%s is declared %d times in services.yaml; it must be declared exactly once, '
                . 'because a duplicate is read here as the first and by the container as the last.',
                $name,
                $found,
            ));
        }

        $value = $matches[1][0] ?? '';

        if (1 !== \preg_match('/^\d+$/', $value)) {
            throw new RuntimeException(\sprintf(
                'erpify.images.%s is `%s`. It must be plain digits: YAML accepts `20_000_000` and `2e7` as '
                . 'integers while a numeric read of them here yields 20 and 2.',
                $name,
                $value,
            ));
        }

        return (int) $value;
    }

    /**
     * **The whole value, and only PHP's own shorthand.** `256MB` is 256 MiB to a human and **256 bytes** to
     * PHP, which reads the trailing character as the multiplier and falls back to the base image's default;
     * a prefix match here called it 256 MiB. And `-1` — unlimited — used to match the presence check in
     * `theMemoryBudgetIsDeclaredInTheRepositoryRatherThanInheritedFromTheBaseImage` while failing this one,
     * so two tests of the same file disagreed about whether the budget was declared.
     */
    private function memoryLimitInBytes(): int
    {
        $ini = (string) \file_get_contents(self::PHP_INI);
        $found = \preg_match_all('/^\s*memory_limit\s*=\s*(\S+)/mi', $ini, $matches);

        if (1 !== $found) {
            throw new RuntimeException(\sprintf(
                'memory_limit is declared %d times in frankenphp/conf.d/10-app.ini; PHP lets the last one '
                . 'win, so exactly one is the only shape this gate can describe.',
                $found,
            ));
        }

        $value = $matches[1][0];

        if (1 !== \preg_match('/^(\d+)([KMG]?)$/i', $value, $parts)) {
            throw new RuntimeException(\sprintf(
                'memory_limit is `%s`. PHP accepts an integer with an optional K, M or G suffix: `-1` is '
                . 'unlimited and leaves this budget undefined, and `256MB` is 256 BYTES to PHP because it '
                . 'reads only the final character as the multiplier.',
                $value,
            ));
        }

        $multiplier = match (\strtoupper($parts[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };

        return (int) $parts[1] * $multiplier;
    }
}
