<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Console\Double;

use RuntimeException;

/**
 * A stdin a previous read has already taken to EOF — the one unanswerable shape neither the
 * `--no-interaction` flag nor the post-`confirm()` re-read can see.
 *
 * `QuestionHelper::doReadInput()` loops `while (!\feof($inputStream))`, so a stream arriving already at
 * EOF never enters the loop, returns `''` rather than `false`, and never raises the `MissingInputException`
 * the re-read depends on: the default is taken as an operator's answer. Reachable through the console's own
 * single-alternative prompt, which drains a pipe whose last byte is not a newline.
 *
 * Shared because every command putting an irreversible confirmation owes this case a test, and a
 * hand-copied stream that stops being drained is a test that stops testing anything while staying green.
 *
 * @internal
 */
final class DrainedInputStream
{
    /**
     * @return resource a stream a read has already taken to EOF, so `\feof()` is true before the question
     */
    public static function open()
    {
        $stream = \fopen('php://memory', 'r+');

        if (!\is_resource($stream)) {
            throw new RuntimeException('could not open the in-memory stream this double is made of');
        }

        \fwrite($stream, 'y');
        \rewind($stream);
        \fread($stream, 1);
        \fread($stream, 1);

        // Checked rather than asserted: `assert()` is compiled out under `zend.assertions=-1`, which no
        // ini in this repo pins, and a stream that is not at EOF is a double that silently stops being
        // the thing every caller named it for — the tests would stay green over a case nobody reached.
        if (!\feof($stream)) {
            throw new RuntimeException('the stream is not drained, so it would not reproduce the EOF case');
        }

        return $stream;
    }
}
