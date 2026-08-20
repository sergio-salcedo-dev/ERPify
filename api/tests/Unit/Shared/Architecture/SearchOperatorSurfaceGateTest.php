<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionParameter;
use SplFileInfo;

/**
 * `In` is the one operator in the filter vocabulary whose value is a LIST, so it is the one whose wire
 * spelling grows a sub-index (`filters[N][value][]`, `filters[N][value][0]`). A field admits it only by
 * declaring it; the default operator set of {@see FieldMapping} must not hand it out.
 *
 * The rule earned a gate because the default handed it out for a long time and nothing said so. Six field
 * mappings took the default, three of them over a person's email address, an account holder's name and an
 * IBAN — and the class that declined to redact that spelling at the log edge justified the declination as
 * "a form no field mapping currently admits", which was false while it was written.
 *
 * **The universe is derived, not listed.** Both assertions read the field mappings out of the tree, so a
 * repository added tomorrow joins them without anybody remembering to. A gate whose expectation and whose
 * subject come from the same hand-maintained list cannot detect a hole in that list.
 *
 * **What a green does NOT prove.** That admitting `In` on some field is *safe* — that is a judgement, and
 * the control on it is review. Nor anything about the access log: the query string never reaches it
 * (`AccessLogQueryContainmentGateTest`), which is what makes this a capability gate rather than a
 * confidentiality one. It reads constructor arguments as source text, so a mapping built from a computed
 * array, or by a factory that spreads its arguments, is invisible here.
 *
 * @internal
 */
#[CoversNothing]
final class SearchOperatorSurfaceGateTest extends TestCase
{
    /**
     * Reads the parameter's own default rather than constructing a mapping, because a construction would
     * pass through the guards and a future guard could make the probe unrepresentative.
     */
    #[Test]
    public function theDefaultOperatorSetDoesNotHandOutTheListOperator(): void
    {
        $operators = $this->defaultOperators();

        $this->assertNotContains(FilterOperator::In, $operators, \sprintf(
            "The default operator set of FieldMapping grants `In` again (%s).\nEvery field mapping that "
                . 'does not name its own operators then admits a list value, including the ones over a '
                . "person's address, an account holder's name and an IBAN — none of which any call site "
                . 'or scenario in this tree filters with a list. Name `In` at the fields that want it, as '
                . 'the bank name and short code do.',
            \implode(', ', \array_map(static fn (FilterOperator $o): string => $o->value, $operators)),
        ));

        $this->assertNotEmpty(
            $operators,
            'The default operator set is empty, so this assertion holds vacuously. It must still describe '
            . 'the operators a field gets without asking.',
        );
    }

    /**
     * The case above defends a default, and a default nothing reaches defends nothing — it would keep
     * passing while every field named its own operators, and then keep passing on the day one stopped.
     * So the reach is asserted rather than assumed: some field mapping in the tree still takes the
     * default, which is what makes that pin live rather than decorative.
     */
    #[Test]
    public function theDefaultIsStillReachedSoThatPinIsNotVacuous(): void
    {
        $declarations = $this->fieldMappingDeclarations();

        $this->assertNotEmpty(
            $declarations,
            'No `new FieldMapping(` was found under src, so both directions of this gate are vacuous. The '
            . 'search surface has moved and this gate has to follow it.',
        );

        $inheritingTheDefault = \array_filter(
            $declarations,
            static fn (string $arguments): bool => !\str_contains($arguments, 'operators:'),
        );

        $this->assertNotEmpty($inheritingTheDefault, \sprintf(
            'Every one of the %d field mappings now names its own operators, so the default this gate '
                . 'guards reaches nothing and its first assertion no longer defends anything reachable. '
                . 'Either the default should be removed outright or this gate should be.',
            \count($declarations),
        ));

        // Nothing to assert per declaration beyond the default itself: a mapping that names its operators
        // has made the decision, and one that does not gets whatever the default holds — which the case
        // above pins. What this case adds is that the default is still REACHED, so that pin is live.
        $this->assertNotContains(FilterOperator::In, $this->defaultOperators(), \sprintf(
            '%d field mappings inherit the default operator set, and it grants `In`. Those fields admit a '
                . 'list value nobody declared for them.',
            \count($inheritingTheDefault),
        ));
    }

    /**
     * @return list<FilterOperator>
     */
    private function defaultOperators(): array
    {
        $parameter = new ReflectionParameter([FieldMapping::class, '__construct'], 'operators');

        $this->assertTrue(
            $parameter->isDefaultValueAvailable(),
            'FieldMapping::$operators no longer has a default, so this gate reads nothing. If the default '
            . 'was removed deliberately every field now declares its operators and this gate should go '
            . 'with it, not be relaxed.',
        );

        $default = $parameter->getDefaultValue();

        $this->assertIsArray($default, 'FieldMapping::$operators has a non-array default.');

        $operators = [];

        foreach ($default as $operator) {
            $this->assertInstanceOf(FilterOperator::class, $operator);
            $operators[] = $operator;
        }

        return $operators;
    }

    /**
     * The argument list of every `new FieldMapping(...)` under `src`, balanced-paren matched so a nested
     * call (a normalizer, an array literal) does not truncate it.
     *
     * @return list<string>
     */
    private function fieldMappingDeclarations(): array
    {
        $declarations = [];

        foreach ($this->sourceFiles() as $source) {
            $offset = 0;

            while (false !== ($start = \strpos($source, 'new FieldMapping(', $offset))) {
                $cursor = $start + \strlen('new FieldMapping(');
                $depth = 1;

                while ($depth > 0 && $cursor < \strlen($source)) {
                    $depth += match ($source[$cursor]) {
                        '(' => 1,
                        ')' => -1,
                        default => 0,
                    };
                    ++$cursor;
                }

                $declarations[] = \substr($source, $start, $cursor - $start);
                $offset = $cursor;
            }
        }

        return $declarations;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $sources = [];
        $directory = new RecursiveDirectoryIterator(
            \dirname(__DIR__, 4) . '/src',
            FilesystemIterator::SKIP_DOTS,
        );

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());

            if (false !== $contents) {
                $sources[] = $contents;
            }
        }

        return $sources;
    }
}
