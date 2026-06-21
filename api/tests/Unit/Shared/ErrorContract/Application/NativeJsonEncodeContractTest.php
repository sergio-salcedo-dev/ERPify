<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Application;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pins the "native `\json_encode` only" invariant on the Problem Details
 * error contract path.
 *
 * The body-serialisation pipeline ({@see \Erpify\Shared\ErrorContract\Application}
 * + {@see \Erpify\Shared\ErrorContract\Infrastructure\Http\ProblemDetailsResponder}
 * + {@see \Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\ExceptionResponder})
 * MUST:
 *   1. Carry no `use Symfony\Component\Serializer` import — the Serializer
 *      component (and its normalizer chain) is intentionally excluded so a
 *      custom normalizer cannot smuggle a leak path into the body.
 *   2. Reference no `SerializerInterface` or `NormalizerInterface` in the
 *      source — whether imported, fully-qualified, or used by alias.
 *   3. Pass `JSON_THROW_ON_ERROR` to every `\json_encode(` call so encode
 *      failures surface as a throw the listener's outer try/catch catches,
 *      rather than silently emitting a `false` body.
 *
 * Curated grep — narrowest possible scope, mirrors the pattern in
 * {@see BannedDoctrineApisTest}. The walk targets only the explicitly-listed
 * files / directories so the success-payload resource responders under
 * `Shared/Http/Infrastructure/Responder/` (e.g. `ResourceResponder`,
 * `SearchResponder`) — which are NOT part of the Problem Details error
 * contract — keep their Symfony Serializer dependency without tripping this
 * gate. (`SearchExceptionListener` and its `JsonApiErrorBuilder` envelope
 * were retired once {@see ProblemDetailsFactory} subsumed their mappings
 * natively.)
 *
 * Comments and docblocks are stripped before scanning so a docblock that names
 * `\json_encode([...])` to document an intentional NON-encode (e.g. the
 * last-resort static body) does not produce a false positive.
 *
 * Out of scope: tests under `tests/` use Symfony Serializer freely (test
 * fixtures, client helpers); the contract is about the production source on
 * the Problem Details error path.
 *
 * @internal
 */
#[CoversNothing]
final class NativeJsonEncodeContractTest extends TestCase
{
    public function testNoSerializerImports(): void
    {
        $hits = [];

        foreach ($this->errorPathPhpFiles() as $file) {
            $contents = $this->stripCommentsAndDocblocks($this->readFile($file));

            if (1 === \preg_match('/use\s+Symfony\\\Component\\\Serializer\b/', $contents)) {
                $hits[] = $this->relativePath($file->getPathname()) . ' (use Symfony\Component\Serializer)';

                continue;
            }

            if (1 === \preg_match('/\bSerializerInterface\b/', $contents)) {
                $hits[] = $this->relativePath($file->getPathname()) . ' (SerializerInterface)';

                continue;
            }

            if (1 === \preg_match('/\bNormalizerInterface\b/', $contents)) {
                $hits[] = $this->relativePath($file->getPathname()) . ' (NormalizerInterface)';
            }
        }

        $this->assertSame(
            [],
            $hits,
            'Problem Details error-path source MUST use native \json_encode with '
            . 'JSON_THROW_ON_ERROR only. No Symfony Serializer component, no normalizer, '
            . 'no reflection-based encoder may be introduced under the listener / factory / '
            . 'responder triple. Offending files: ' . \implode(', ', $hits),
        );
    }

    /**
     * Asserts every `\json_encode(` call within the error-path subtrees passes
     * `JSON_THROW_ON_ERROR` somewhere in its flag list. The matcher walks each
     * call's argument list up to the matching close-paren so multi-line encode
     * calls (which the codebase uses freely) are scanned in full.
     */
    public function testEveryJsonEncodeUsesJsonThrowOnError(): void
    {
        $offenders = [];
        $totalCalls = 0;

        foreach ($this->errorPathPhpFiles() as $file) {
            $contents = $this->stripCommentsAndDocblocks($this->readFile($file));

            if (false === \preg_match_all('/\\\?json_encode\(/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $match) {
                ++$totalCalls;
                $startOffset = $match[1];
                $callSnippet = $this->extractCallSnippet($contents, $startOffset);

                if (!\str_contains($callSnippet, 'JSON_THROW_ON_ERROR')) {
                    $offenders[] = \sprintf(
                        '%s@%d :: %s',
                        $this->relativePath($file->getPathname()),
                        $startOffset,
                        $this->collapseWhitespace($callSnippet),
                    );
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $totalCalls,
            'json_encode grep gate scanned zero calls — the directory roots no longer '
            . 'cover any error-path encode site. Either the gate is misconfigured or the '
            . 'serialisation moved out of Shared/ErrorContract/Application and Shared/Http/Infrastructure.',
        );

        $this->assertSame(
            [],
            $offenders,
            'Every json_encode() call on the error path must pass JSON_THROW_ON_ERROR '
            . 'so encode failures throw cleanly into the listener self-failure path '
            . 'instead of returning a silent `false`. Offending calls: '
            . \implode(' | ', $offenders),
        );
    }

    /**
     * Extracts the call site starting at `\json_encode(` (offset = position of `j` /
     * preceding `\`) up to the matching close-paren. Tracks paren depth across newlines
     * so a multi-line call is captured in full.
     */
    private function extractCallSnippet(string $contents, int $startOffset): string
    {
        $length = \strlen($contents);
        $openParenOffset = \strpos($contents, '(', $startOffset);

        if (false === $openParenOffset) {
            return \substr($contents, $startOffset, 64);
        }

        $depth = 1;
        $cursor = $openParenOffset + 1;

        while ($cursor < $length && $depth > 0) {
            $char = $contents[$cursor];

            if ('(' === $char) {
                ++$depth;
            } elseif (')' === $char) {
                --$depth;
            }

            ++$cursor;
        }

        return \substr($contents, $startOffset, $cursor - $startOffset);
    }

    private function collapseWhitespace(string $snippet): string
    {
        return \trim((string) \preg_replace('/\s+/', ' ', $snippet));
    }

    /**
     * Yields the explicit Problem Details error-contract files / directory: the entire
     * `Shared/ErrorContract/Application/` subtree (factory + value objects + denylist) plus
     * the responder and listener that emit the wire body. The success-payload resource
     * responders under `Shared/Http/Infrastructure/Responder/` (e.g. `ResourceResponder`,
     * `SearchResponder`) are intentionally excluded — they serialize success bodies, not
     * error bodies, so they live in `Shared/Http/Infrastructure/` but are not part of the
     * listener / factory / responder triple. (`SearchExceptionListener` and its
     * `JsonApiErrorBuilder` envelope were retired once the factory subsumed their
     * mappings natively.).
     *
     * @return iterable<SplFileInfo>
     */
    private function errorPathPhpFiles(): iterable
    {
        $apiRoot = $this->resolveApiRoot();

        $directoryRoot = $apiRoot . '/src/Shared/ErrorContract/Application';

        if (\is_dir($directoryRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directoryRoot, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo) {
                    continue;
                }

                if (!$file->isFile()) {
                    continue;
                }

                if ('php' !== $file->getExtension()) {
                    continue;
                }

                yield $file;
            }
        }

        $explicitFiles = [
            $apiRoot . '/src/Shared/ErrorContract/Infrastructure/Http/ProblemDetailsResponder.php',
            $apiRoot . '/src/Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponder.php',
        ];

        foreach ($explicitFiles as $explicitFile) {
            if (!\is_file($explicitFile)) {
                continue;
            }

            yield new SplFileInfo($explicitFile);
        }
    }

    /**
     * Strips PHP comments and docblocks from `$source` so the grep matchers operate
     * on executable code only. Without this, a docblock that names `\json_encode([...])`
     * to document an intentional non-encode (e.g. the last-resort static body's
     * docblock referencing `\json_encode([...])`) would produce a false positive.
     * Uses `\token_get_all` so multi-line / nested docblocks are handled correctly
     * without a regex misfire.
     */
    private function stripCommentsAndDocblocks(string $source): string
    {
        $tokens = \token_get_all($source);
        $stripped = '';

        foreach ($tokens as $token) {
            if (\is_array($token)) {
                if (T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0]) {
                    // Preserve newlines so byte offsets remain stable enough for
                    // diagnostic snippets — replacing each line of a multi-line
                    // docblock with a blank line keeps line counts intact.
                    $stripped .= \str_repeat("\n", \substr_count($token[1], "\n"));

                    continue;
                }

                $stripped .= $token[1];

                continue;
            }

            $stripped .= $token;
        }

        return $stripped;
    }

    private function readFile(SplFileInfo $file): string
    {
        $contents = \file_get_contents($file->getPathname());
        $this->assertNotFalse($contents, \sprintf('Failed to read %s.', $file->getPathname()));

        return $contents;
    }

    private function relativePath(string $absolute): string
    {
        $apiPrefix = $this->resolveApiRoot() . '/';

        if (\str_starts_with($absolute, $apiPrefix)) {
            return \substr($absolute, \strlen($apiPrefix));
        }

        return $absolute;
    }

    private function resolveApiRoot(): string
    {
        return \dirname(__DIR__, 5);
    }
}
