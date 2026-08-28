<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Context;

use Erpify\Tests\Behat\Context\MessengerConsumerContext;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The verbosity a `messenger:consume` step resolves from the flags its options carry.
 *
 * Read through the resolution itself rather than through the run's output, deliberately: nothing in the
 * worker path logs below `info` (no `->debug(` call exists anywhere in `symfony/messenger` outside
 * `ConsumeMessagesCommand`, which this context bypasses), so the DEBUG-versus-VERY_VERBOSE degradation
 * pinned here produces no observable difference in the buffer a run records. A black-box assertion would
 * be green under both implementations, which is the property that let the defect ship.
 *
 * Separate from {@see MessengerConsumerContextTest} because the resolution is a pure function of the
 * decoded options: it touches no transport, no bus and no recorder, so it needs none of that class's
 * construction — which is why the subject is reached through an uninitialised instance rather than a
 * wired one. Keeping them apart also keeps this defect's falsifier separable from the two consume
 * defects that happen to live in the same context file.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class MessengerVerbosityResolutionTest extends TestCase
{
    /**
     * The strongest flag present wins, whatever order the flag map reaches them in. Resolving by last
     * assignment silently downgrades `-vvv --verbose` to VERY_VERBOSE, because the map declares
     * `--verbose` after `-vvv`.
     */
    public function testTheStrongestVerbosityFlagWinsOverTheLastOneDeclared(): void
    {
        $this->assertSame(
            OutputInterface::VERBOSITY_DEBUG,
            $this->resolveVerbosity(['-vvv' => true, '--verbose' => true]),
        );
    }

    /**
     * The other direction: taking a maximum may not turn every run into a debug run. Without these two
     * a resolution answering DEBUG unconditionally would satisfy the test above.
     */
    public function testVerbosityStillDefaultsToNormalAndFollowsASingleFlag(): void
    {
        $this->assertSame(OutputInterface::VERBOSITY_NORMAL, $this->resolveVerbosity([]));
        $this->assertSame(OutputInterface::VERBOSITY_VERBOSE, $this->resolveVerbosity(['-v' => true]));
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function resolveVerbosity(array $decoded): mixed
    {
        $context = (new ReflectionClass(MessengerConsumerContext::class))->newInstanceWithoutConstructor();
        $resolution = new ReflectionMethod(MessengerConsumerContext::class, 'verbosityFrom');

        return $resolution->invoke($context, $decoded);
    }
}
