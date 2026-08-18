<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * Reads `.public-access-exemptions` lines into classifications.
 *
 * A malformed line throws rather than being skipped. A classification nobody can parse is
 * indistinguishable from a missing one, and the missing direction is the one that fails toward an
 * unclassified hole in the firewall.
 *
 * @internal test support
 *
 * @phpstan-type Classification array{form: string, consumer: string, boundedBy: ?string}
 */
final class PublicAccessExemptionRegistry
{
    /** An exemption that matches one route. Its pattern is anchored. */
    public const string EXACT = 'exact';

    /** An exemption that matches a subtree. Its pattern is not anchored, and it must name what bounds it. */
    public const string PREFIX = 'prefix';

    /**
     * @param list<string> $lines
     *
     * @return array<string, Classification>
     */
    public static function parse(array $lines): array
    {
        $registry = [];

        foreach ($lines as $line) {
            [$pattern, $classification] = self::parseLine($line);

            if (isset($registry[$pattern])) {
                throw new RuntimeException(\sprintf('Duplicate pattern in .public-access-exemptions: "%s".', $pattern));
            }

            $registry[$pattern] = $classification;
        }

        return $registry;
    }

    /**
     * @return array{string, Classification}
     */
    private static function parseLine(string $line): array
    {
        // explode always yields at least one element, so the head is a string; the consumer is what a
        // truncated line is missing.
        $parts = \array_map(\trim(...), \explode('::', $line));
        $head = \array_shift($parts);
        $consumer = \array_shift($parts);

        if (null === $consumer || '' === $consumer || !\str_contains($head, '=>')) {
            throw new RuntimeException(\sprintf(
                'Malformed line in .public-access-exemptions: "%s". Expected '
                . '`<pattern> => exact :: <consumer>` or `<pattern> => prefix :: <consumer> :: <bounding file>`.',
                $line,
            ));
        }

        [$pattern, $form] = \array_map(\trim(...), \explode('=>', $head, 2));
        $boundedBy = \array_shift($parts);

        // A surplus field is refused rather than dropped: a line carrying more than its grammar allows was
        // written against a different grammar, and reading it as valid is how a registry starts meaning
        // something other than what its header says.
        if ([] !== $parts) {
            throw new RuntimeException(\sprintf(
                'Line for "%s" carries %d field(s) beyond its grammar.',
                $pattern,
                \count($parts),
            ));
        }

        return [$pattern, self::classification($pattern, $form, $consumer, $boundedBy)];
    }

    /**
     * @return Classification
     */
    private static function classification(
        string $pattern,
        string $form,
        string $consumer,
        ?string $boundedBy,
    ): array {
        if (self::EXACT !== $form && self::PREFIX !== $form) {
            throw new RuntimeException(\sprintf('Unknown match form "%s" for pattern "%s".', $form, $pattern));
        }

        if (self::EXACT === $form && null !== $boundedBy) {
            throw new RuntimeException(\sprintf(
                'Pattern "%s" is classified `exact` but names a bounding file. Only a prefix has a subtree '
                . 'to bound.',
                $pattern,
            ));
        }

        if (self::PREFIX === $form && (null === $boundedBy || '' === $boundedBy)) {
            throw new RuntimeException(\sprintf(
                'Pattern "%s" is classified as a prefix but names no file bounding it. A prefix exemption '
                . 'covers a subtree, so it must name the thing that keeps that subtree out of production — '
                . 'not a reason somebody wrote.',
                $pattern,
            ));
        }

        return [
            'form' => $form,
            'consumer' => $consumer,
            'boundedBy' => self::PREFIX === $form ? $boundedBy : null,
        ];
    }
}
