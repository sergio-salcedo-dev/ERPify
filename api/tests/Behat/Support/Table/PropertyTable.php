<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Table;

use Behat\Gherkin\Node\TableNode;
use RuntimeException;

/**
 * A two-column Gherkin table read as property/value pairs, refusing any shape that would quietly
 * assert less than it looks like it asserts.
 *
 * `TableNode::getRowsHash()` keys on the first column and keeps the rest, so a third column arrives as
 * an array — which can never equal a scalar — and a repeated first column collapses last-wins, dropping
 * the other row's assertion outright. Neither is visible in the feature: both read as a table that was
 * simply not matched.
 *
 * That is survivable where a step asserts each row, and silent where a step reads "nothing matched" as
 * success — the negative outbox table form being exactly that. It refuses out loud instead, and with an
 * exception rather than an assertion failure, so a predicate that catches failed assertions to mean
 * "this is not the row I am looking for" cannot swallow it.
 */
final class PropertyTable
{
    /**
     * @throws RuntimeException when the table is not two columns of distinct properties
     *
     * @return array<string, string>
     */
    public static function rows(TableNode $table): array
    {
        $rows = $table->getRowsHash();

        foreach ($rows as $property => $value) {
            if (!\is_string($value)) {
                throw new RuntimeException(\sprintf(
                    'Row "%s" carries more than one value, so the table has more than two columns. A '
                    . 'value that is not a scalar can never equal the node it is compared against, '
                    . 'which reads as "did not match" — success, wherever that is the passing answer.',
                    $property,
                ));
            }
        }

        if (\count($rows) !== \count($table->getRows())) {
            throw new RuntimeException(
                'The table repeats a property. getRowsHash() keeps the last row for a repeated key, so '
                . "the other row's assertion is dropped without a trace.",
            );
        }

        return $rows;
    }
}
