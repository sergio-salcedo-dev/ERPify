<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context\Json;

use Behat\Gherkin\Node\TableNode;
use Behat\Step\Then;
use Closure;

/**
 * The steps that apply a single-node assertion to every row of a Gherkin table.
 *
 * They are the same walk three times over — skip the empty key, refuse a row whose value the table
 * parser did not hand back as a string, delegate — differing only in which assertion runs per row.
 * Kept apart from the node steps because that walk is their whole subject: what a row *means* is
 * owned by the step it delegates to, so a table form never grows an opinion of its own.
 */
trait JsonNodeTableStepsTrait
{
    /**
     * Validate the JSON `nodes` are equal to `text`.
     */
    #[Then('the JSON nodes should be equal to:')]
    #[Then('/^the JSON nodes should be equal to \(if path not empty\):$/')]
    public function theJsonNodesShouldBeEqualTo(TableNode $tableNode): void
    {
        $this->eachNodeInTable($tableNode, $this->theJsonNodeShouldBeEqualTo(...));
    }

    /**
     * Validate the JSON properties `nodes` contains `text`.
     */
    #[Then('the JSON nodes should contain:')]
    public function theJsonNodesShouldContain(TableNode $tableNode): void
    {
        $this->eachNodeInTable($tableNode, $this->theJsonNodeShouldContain(...));
    }

    /**
     * Validate the JSON properties `nodes` does not contain `text`.
     */
    #[Then('the JSON nodes should not contain:')]
    public function theJsonNodesShouldNotContain(TableNode $tableNode): void
    {
        $this->eachNodeInTable($tableNode, $this->theJsonNodeShouldNotContain(...));
    }

    /**
     * @param Closure(string, string): void $assertion
     */
    private function eachNodeInTable(TableNode $tableNode, Closure $assertion): void
    {
        foreach ($tableNode->getRowsHash() as $node => $text) {
            if ('' === $node) {
                continue;
            }

            if (!\is_string($text)) {
                self::fail(\sprintf(self::EXPECTED_STRING_FORMAT, $node, \gettype($text)));
            }

            $assertion($node, $text);
        }
    }
}
