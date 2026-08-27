<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * Derivation rules behind the accepted-risk structural gate, kept out of the assertions so
 * {@see \Erpify\Tests\Unit\Gate\AcceptedRiskTagRulesGateTest} can falsify them against fixtures while
 * {@see \Erpify\Tests\Unit\Gate\AcceptedRiskTagGateTest} runs them against the real tree.
 *
 * Three layers, kept separate: source extraction (this class reads the raw file), paragraph segmentation
 * (`phpDocParagraphs()` / `markdownParagraphs()`), and tag extraction (`tagsIn()`). The gate never
 * identifies "the disposition sentence" semantically — that is a human judgement. What it CAN check is a
 * structural proxy: the tag's paragraph must carry a minimum floor of prose besides the tag itself
 * (`MIN_PARAGRAPH_PROSE_CHARS`), so a tag floating alone with no accompanying rationale fails on the floor,
 * never on a claim about which sentence is "the" disposition.
 *
 * Inline code spans (`` `like this` ``) and table rows (`| like | this |`) are stripped before any tag is
 * searched for — a documentation example quoting the grammar (exactly what this repo's own specs do) must
 * never be misread as a live declaration.
 *
 * **Write a PHP tag inline, at the end of an existing prose line — never as its own leading-`@` line.**
 * `make php.quality`'s PHP-CS-Fixer pass always blank-line-isolates a PHPDoc line whose first token is
 * `@word` (the same treatment it gives `@param`/`@return`/`@see`), which would silently detach the tag
 * from its own rationale the next time anyone runs the mandatory local quality gate — turning a
 * well-formed declaration into exactly the "floating tag" shape {@see MIN_PARAGRAPH_PROSE_CHARS} exists to
 * refuse. Measured against this repo's own fixer, not assumed: `@accepted-risk #860` on its own line in
 * {@see \Erpify\Iam\Identity\Application\NotifyLockedIdentities} got isolated by a blank line on both
 * sides the first time `php.quality` ran over it; appended inline at the end of the preceding sentence, the
 * same fixer pass leaves it untouched. Markdown specs have no such fixer and are not affected.
 *
 * @internal test support
 */
final class AcceptedRiskTags
{
    public const string TAG_TOKEN = '@accepted-risk';

    public const int MIN_PARAGRAPH_PROSE_CHARS = 15;

    /**
     * @return list<AcceptedRiskTag>
     */
    public static function scanFile(string $path): array
    {
        $tags = [];

        foreach (self::paragraphsIn($path) as $acceptedRiskParagraph) {
            foreach (self::tagsIn($acceptedRiskParagraph, $path) as $tag) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<AcceptedRiskTag>
     */
    public static function scanFiles(array $paths): array
    {
        $tags = [];

        foreach ($paths as $path) {
            foreach (self::scanFile($path) as $tag) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * Every paragraph that contains at least one tag occurrence — the unit the content floor is asserted
     * over, once per paragraph rather than once per tag (a paragraph with two tags is one floor to satisfy,
     * not two).
     *
     * @param list<string> $paths
     *
     * @return list<AcceptedRiskParagraph>
     */
    public static function taggedParagraphs(array $paths): array
    {
        $paragraphs = [];

        foreach ($paths as $path) {
            foreach (self::paragraphsIn($path) as $paragraph) {
                if (\str_contains($paragraph->text(), self::TAG_TOKEN)) {
                    $paragraphs[] = $paragraph;
                }
            }
        }

        return $paragraphs;
    }

    public static function paragraphSatisfiesFloor(AcceptedRiskParagraph $paragraph): bool
    {
        return self::paragraphProseLength($paragraph) >= self::MIN_PARAGRAPH_PROSE_CHARS;
    }

    public static function paragraphProseLength(AcceptedRiskParagraph $paragraph): int
    {
        $withoutTags = \preg_replace('/@accepted-risk[ \t]*#?[0-9]*/', '', $paragraph->text()) ?? '';
        $prose = \preg_replace('/\s+/', '', $withoutTags) ?? '';

        // mb_strlen, not strlen: this repo's spec prose is Spanish, and a byte count over UTF-8 text makes
        // the floor easier to satisfy than MIN_PARAGRAPH_PROSE_CHARS claims -- wrong on its own terms even
        // though today's real paragraphs clear either count by a wide margin.
        return \mb_strlen($prose);
    }

    /**
     * @return list<AcceptedRiskParagraph>
     */
    private static function paragraphsIn(string $path): array
    {
        $content = \file_get_contents($path);

        if (false === $content) {
            throw new RuntimeException(\sprintf('Unable to read %s.', $path));
        }

        return \str_ends_with($path, '.md')
            ? self::markdownParagraphs($content)
            : self::phpDocParagraphs($content);
    }

    /**
     * PHPDoc blocks only — code outside `/** ... *\/` is never a source of a disposition paragraph. A
     * blank comment line is the only boundary in v1 (no list-item unit inside a docblock — no real
     * instance needs one yet).
     *
     * @return list<AcceptedRiskParagraph>
     */
    private static function phpDocParagraphs(string $content): array
    {
        $paragraphs = [];

        if (0 === \preg_match_all('/\/\*\*(.*?)\*\//s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $paragraphs;
        }

        foreach ($matches[1] as [$body, $bodyOffset]) {
            self::appendPhpDocBlockParagraphs($paragraphs, $content, $body, $bodyOffset);
        }

        return $paragraphs;
    }

    /**
     * @param list<AcceptedRiskParagraph> $paragraphs
     */
    private static function appendPhpDocBlockParagraphs(
        array &$paragraphs,
        string $content,
        string $body,
        int $bodyOffset,
    ): void {
        $current = [];
        $currentStartLine = null;
        $consumed = 0;
        $lines = \explode("\n", $body);
        $lastIndex = \count($lines) - 1;
        $lastLineNo = self::lineAt($content, $bodyOffset);

        foreach ($lines as $index => $rawLine) {
            $lastLineNo = self::lineAt($content, $bodyOffset + $consumed);
            $consumed += \strlen($rawLine) + ($index === $lastIndex ? 0 : 1);

            $stripped = \preg_replace('/^[ \t]*\*\/?[ \t]?/', '', $rawLine) ?? $rawLine;
            $stripped = self::stripInlineCode(\rtrim($stripped));

            if ('' === \trim($stripped)) {
                self::closeParagraph($paragraphs, $current, $currentStartLine, $lastLineNo - 1);

                continue;
            }

            if ([] === $current) {
                $currentStartLine = $lastLineNo;
            }

            $current[] = $stripped;
        }

        self::closeParagraph($paragraphs, $current, $currentStartLine, $lastLineNo);
    }

    /**
     * Markdown: a blank line, a heading, or a list-item marker each start a new unit; a list item is a
     * single-line unit in v1 (its own wrapped continuation line is out of scope — no real instance needs
     * it yet). Fenced code blocks and table rows are excluded entirely, never contributing a paragraph.
     *
     * @return list<AcceptedRiskParagraph>
     */
    private static function markdownParagraphs(string $content): array
    {
        $paragraphs = [];
        $lines = \explode("\n", $content);
        $current = [];
        $currentStartLine = null;
        $fenceDelimiter = null;

        foreach ($lines as $index => $rawLine) {
            $lineNo = $index + 1;
            $delimiterHere = self::fenceDelimiterOf($rawLine);

            if (null !== $fenceDelimiter) {
                // Only the delimiter that OPENED the fence can close it -- a stray ~~~ line while a ``` fence
                // is open is ordinary fenced content, never a boundary. Without this check either marker
                // toggling either fence let a mismatched pair swallow everything to EOF or close too early.
                if ($delimiterHere === $fenceDelimiter) {
                    $fenceDelimiter = null;
                }

                self::closeParagraph($paragraphs, $current, $currentStartLine, $lineNo - 1);

                continue;
            }

            if (null !== $delimiterHere) {
                $fenceDelimiter = $delimiterHere;
                self::closeParagraph($paragraphs, $current, $currentStartLine, $lineNo - 1);

                continue;
            }

            if (self::isTableRow($rawLine) || '' === \trim($rawLine)) {
                self::closeParagraph($paragraphs, $current, $currentStartLine, $lineNo - 1);

                continue;
            }

            if (self::isUnitBoundary($rawLine)) {
                self::closeParagraph($paragraphs, $current, $currentStartLine, $lineNo - 1);
                self::appendSingleLineUnit($paragraphs, $rawLine, $lineNo);

                continue;
            }

            if ([] === $current) {
                $currentStartLine = $lineNo;
            }

            $current[] = self::stripInlineCode($rawLine);
        }

        self::closeParagraph($paragraphs, $current, $currentStartLine, \count($lines));

        return $paragraphs;
    }

    private static function fenceDelimiterOf(string $line): ?string
    {
        return 1 === \preg_match('/^\s*(```|~~~)/', $line, $match) ? $match[1] : null;
    }

    private static function isTableRow(string $line): bool
    {
        return 1 === \preg_match('/^\s*\|/', $line);
    }

    private static function isUnitBoundary(string $line): bool
    {
        return 1 === \preg_match('/^\s*(#{1,6})\s/', $line)
            || 1 === \preg_match('/^\s*([-*]|\d+\.)\s/', $line);
    }

    /**
     * @param list<AcceptedRiskParagraph> $paragraphs
     */
    private static function appendSingleLineUnit(array &$paragraphs, string $rawLine, int $lineNo): void
    {
        $stripped = self::stripInlineCode($rawLine);

        if ('' !== \trim($stripped)) {
            $paragraphs[] = new AcceptedRiskParagraph($lineNo, $lineNo, [$stripped]);
        }
    }

    /**
     * @param list<AcceptedRiskParagraph> $paragraphs
     * @param list<string>                $current
     *
     * @param-out null $startLine
     */
    private static function closeParagraph(array &$paragraphs, array &$current, ?int &$startLine, int $endLine): void
    {
        if ([] !== $current && null !== $startLine) {
            $paragraphs[] = new AcceptedRiskParagraph($startLine, $endLine, $current);
        }

        $current = [];
        $startLine = null;
    }

    private static function stripInlineCode(string $line): string
    {
        return \preg_replace('/`[^`\n]*`/', '', $line) ?? $line;
    }

    /**
     * @return list<AcceptedRiskTag>
     */
    private static function tagsIn(AcceptedRiskParagraph $paragraph, string $path): array
    {
        $tags = [];
        $text = $paragraph->text();
        $offset = 0;

        while (($pos = \strpos($text, self::TAG_TOKEN, $offset)) !== false) {
            // No fixed-length window: $text is one already-bounded paragraph, never a whole file, so
            // slicing an arbitrary 32 chars bought nothing but a way for an oversized digit run to be
            // silently truncated into a shorter, DIFFERENT, wrong-looking issue number instead of failing
            // as malformed. The regexes below stop naturally where the grammar stops matching.
            $tail = \substr($text, $pos + \strlen(self::TAG_TOKEN));
            $line = $paragraph->startLine + \substr_count(\substr($text, 0, $pos), "\n");

            $tags[] = new AcceptedRiskTag(
                sourceFile: $path,
                line: $line,
                paragraphStartLine: $paragraph->startLine,
                paragraphEndLine: $paragraph->endLine,
                issueNumber: self::strictIssueNumber($tail),
                rawTag: self::TAG_TOKEN . self::rawTagTail($tail),
            );

            $offset = $pos + \strlen(self::TAG_TOKEN);
        }

        return $tags;
    }

    private static function strictIssueNumber(string $tail): ?int
    {
        if (1 === \preg_match('/^[ \t]+#([1-9][0-9]*)/', $tail, $strict)) {
            return (int) $strict[1];
        }

        return null;
    }

    private static function rawTagTail(string $tail): string
    {
        \preg_match('/^[ \t]+#[1-9][0-9]*|^[ \t]*\S*/', $tail, $match);

        return $match[0] ?? '';
    }

    private static function lineAt(string $content, int $offset): int
    {
        return \substr_count(\substr($content, 0, $offset), "\n") + 1;
    }
}
