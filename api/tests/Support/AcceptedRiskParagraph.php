<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * A logical paragraph inside a scanned file — a blank-comment-line-delimited unit in a PHPDoc block, or a
 * blank-line/list-item/heading-delimited unit in Markdown.
 *
 * @internal test support
 */
final readonly class AcceptedRiskParagraph
{
    /**
     * @param list<string> $lines text with the comment/list-marker prefix and inline code spans already
     *                            stripped, so a tag inside a documentation example never reaches this text
     */
    public function __construct(
        public int $startLine,
        public int $endLine,
        public array $lines,
    ) {
    }

    public function text(): string
    {
        return \implode("\n", $this->lines);
    }
}
