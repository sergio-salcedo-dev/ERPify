<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Support;

use RuntimeException;

/**
 * The `api/.behat-step-vocabulary` file as a map of step pattern to classification.
 *
 * Parsing lives here rather than inside the gate so every way the file can be malformed is reachable
 * from a test with a string, instead of only from whatever the committed file happens to contain. A
 * parser whose refusals are unfalsifiable is the shape of defect this whole registry exists to remove.
 *
 * It refuses rather than degrades. A line it cannot read, a classification it does not know and a
 * pattern classified twice each throw: the last one matters most, because PHP array assignment makes a
 * repeated key silently keep the later value, so one of the two classifications would never be
 * evaluated while the file still looked complete.
 */
final class StepVocabularyRegistry
{
    public const string USED = 'used';

    public const string IDLE = 'idle';

    public const string MANUAL = 'manual';

    public const string REFUSED = 'refused';

    private const string SEPARATOR = ' => ';

    /**
     * @return list<string>
     */
    public static function classifications(): array
    {
        return [self::USED, self::IDLE, self::MANUAL, self::REFUSED];
    }

    /**
     * @throws RuntimeException on a line that cannot be read, an unknown classification, a repeated
     *                          pattern, or a file carrying no classification at all
     *
     * @return array<string, string> pattern => classification
     */
    public static function parse(string $contents): array
    {
        $registry = [];

        foreach (\explode("\n", $contents) as $line) {
            $line = \trim($line);

            if ('' === $line) {
                continue;
            }

            if (\str_starts_with($line, '#')) {
                continue;
            }

            $position = \strrpos($line, self::SEPARATOR);

            if (false === $position) {
                throw new RuntimeException(\sprintf('Malformed line, no "%s": %s', self::SEPARATOR, $line));
            }

            $classification = \substr($line, $position + \strlen(self::SEPARATOR));

            if (!\in_array($classification, self::classifications(), true)) {
                throw new RuntimeException(\sprintf('Unknown classification "%s" on: %s', $classification, $line));
            }

            $pattern = \substr($line, 0, $position);

            if (\array_key_exists($pattern, $registry)) {
                throw new RuntimeException(\sprintf(
                    'Pattern classified twice: %s. The later line would win silently, so one of the two '
                    . 'classifications is never evaluated while the file still reads as complete.',
                    $pattern,
                ));
            }

            $registry[$pattern] = $classification;
        }

        if ([] === $registry) {
            throw new RuntimeException('The registry carries no classification at all.');
        }

        return $registry;
    }
}
