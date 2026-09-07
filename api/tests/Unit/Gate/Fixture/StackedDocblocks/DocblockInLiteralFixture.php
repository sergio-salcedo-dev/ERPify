<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\StackedDocblocks;

/**
 * Doc-comment syntax inside string literals, which a line-oriented probe reads as declarations and the
 * tokenizer never does. This file has exactly one doc comment; everything below is data.
 */
final class DocblockInLiteralFixture
{
    public function samples(): string
    {
        $single = '/** not a doc comment */';
        $double = "/** nor this one */\n/** nor this */";
        $heredoc = <<<'SAMPLE'
            /**
             * Stacked doc-comment syntax that is text, not code.
             */
            /**
             * And a second one, immediately after.
             */
            SAMPLE;

        return $single . $double . $heredoc;
    }
}
