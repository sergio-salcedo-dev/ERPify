<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `erpify.images.max_decoded_pixels` and PHP's `memory_limit` are ONE decision, and until this gate nothing
 * held them together.
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
 * @internal
 */
#[CoversNothing]
final class ImagePixelBudgetGateTest extends TestCase
{
    private const string SERVICES_CONFIG = __DIR__ . '/../../../../config/services.yaml';

    private const string PHP_INI = __DIR__ . '/../../../../frankenphp/conf.d/10-app.ini';

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
        $pixels = $this->maxDecodedPixels();

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
     * Without this the assertion above is satisfied by a ceiling of zero, which is also how a failed parse
     * looks: the arithmetic would pass over a `max_decoded_pixels` line that had been renamed away.
     */
    #[Test]
    public function bothNumbersAreActuallyReadRatherThanDefaulted(): void
    {
        $this->assertGreaterThan(0, $this->maxDecodedPixels());
        $this->assertGreaterThan(0, $this->memoryLimitInBytes());
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

    private function maxDecodedPixels(): int
    {
        $config = (string) \file_get_contents(self::SERVICES_CONFIG);

        if (1 !== \preg_match('/^\s*erpify\.images\.max_decoded_pixels:\s*(\d+)/m', $config, $matches)) {
            throw new RuntimeException('erpify.images.max_decoded_pixels is not declared in services.yaml.');
        }

        return (int) $matches[1];
    }

    private function memoryLimitInBytes(): int
    {
        $ini = (string) \file_get_contents(self::PHP_INI);

        if (1 !== \preg_match('/^memory_limit\s*=\s*(\d+)([KMG]?)/mi', $ini, $matches)) {
            throw new RuntimeException('memory_limit is not declared in frankenphp/conf.d/10-app.ini.');
        }

        $multiplier = match (\strtoupper($matches[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };

        return (int) $matches[1] * $multiplier;
    }
}
