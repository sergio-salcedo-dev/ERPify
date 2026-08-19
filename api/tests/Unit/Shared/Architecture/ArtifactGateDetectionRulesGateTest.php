<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ArtifactGateSweep;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of the detector behind {@see ArtifactGatePlacementGateTest}: which test classes count as
 * artifact gates. The gate over the real tree is green by construction once the registry matches it, so on
 * its own it cannot tell a detector that discriminates from one that answers the same thing to everything.
 *
 * Each source below is hand-built, and each is a shape the answer has to change on: the three routes into
 * the category, the shapes kept out of it, and the comment-stripping without which prose about reading the
 * tree would be enough to be filed as reading it. Four are shapes an adversarial pass measured the first
 * version of this detector getting wrong.
 *
 * @internal
 */
#[CoversNothing]
final class ArtifactGateDetectionRulesGateTest extends TestCase
{
    private const string ELSEWHERE = 'tests/Unit/Backoffice/Bank/Domain/ProbeTest.php';

    private const string IN_HOME = ArtifactGateSweep::HOME . '/ProbeTest.php';

    /**
     * The three routes a gate takes to the tree — a path it writes, a rule engine that writes one for it,
     * and reflection, which writes none at all. One rule, so one test; each route carries its own message.
     */
    #[Test]
    public function itDetectsEveryRouteAGateTakesToTheTree(): void
    {
        $this->assertTrue(ArtifactGateSweep::isArtifactGate(self::ELSEWHERE, <<<'PHP_WRAP'
            <?php
            use PHPUnit\Framework\Attributes\CoversNothing;
            use PHPUnit\Framework\TestCase;
            #[CoversNothing]
            final class ProbeTest extends TestCase
            {
                public function testItSweeps(): void
                {
                    $root = \dirname(__DIR__, 5) . '/src';
                }
            }
            PHP_WRAP), 'A gate writing its own path was not detected.');

        // Most gates never name a path: the walk lives in the engine. A detector keyed on the path
        // expression alone was measured seeing 27 of the 52 files in the home.
        $this->assertTrue(ArtifactGateSweep::isArtifactGate(self::ELSEWHERE, <<<'PHP_WRAP'
            <?php
            use Erpify\Tests\Support\ApiSourceFiles;
            use PHPUnit\Framework\Attributes\CoversNothing;
            use PHPUnit\Framework\TestCase;
            #[CoversNothing]
            final class ProbeTest extends TestCase
            {
                public function testItSweeps(): void
                {
                    foreach (ApiSourceFiles::phpFiles() as $file) {
                    }
                }
            }
            PHP_WRAP), 'A gate reaching the tree through a rule engine was not detected.');

        // A gate that asks a class where it lives writes no path at all. One in the tree reaches the tree
        // only this way, and it sat unclassified through this gate's first green run.
        $this->assertTrue(ArtifactGateSweep::isArtifactGate(self::ELSEWHERE, <<<'PHP_WRAP'
            <?php
            use PHPUnit\Framework\Attributes\CoversNothing;
            use PHPUnit\Framework\TestCase;
            use ReflectionClass;
            #[CoversNothing]
            final class ProbeTest extends TestCase
            {
                public function testItReadsTheSourceOfAClass(): void
                {
                    $source = \file_get_contents((new ReflectionClass(self::class))->getFileName());
                }
            }
            PHP_WRAP), 'A gate reaching the tree by reflection was not detected.');
    }

    #[Test]
    public function itRefusesATestThatBootsAKernel(): void
    {
        // A gate that needs a container is a functional test, and its placement follows that of every other
        // functional test. Keeping it out of the category is what stops `tests/Functional` being swept by a
        // rule written for `tests/Unit`.
        $this->assertFalse(ArtifactGateSweep::isArtifactGate(self::ELSEWHERE, <<<'PHP_WRAP'
            <?php
            use PHPUnit\Framework\Attributes\CoversNothing;
            use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
            #[CoversNothing]
            final class ProbeTest extends KernelTestCase
            {
                public function testItSweeps(): void
                {
                    $root = \dirname(__DIR__, 5) . '/src';
                }
            }
            PHP_WRAP));
    }

    #[Test]
    public function itRefusesABehaviouralTestThatCreditsProductionCoverage(): void
    {
        // The common shape: a unit test of a production class that also reads that class's source for one
        // assertion. It is not wholly a gate, and its placement is settled by the subject it covers — so
        // filing it by this rule would move it away from the thing it tests.
        $this->assertFalse(ArtifactGateSweep::isArtifactGate(self::ELSEWHERE, <<<'PHP_WRAP'
            <?php
            use Erpify\Backoffice\Bank\Domain\Bank;
            use PHPUnit\Framework\Attributes\CoversClass;
            use PHPUnit\Framework\TestCase;
            #[CoversClass(Bank::class)]
            final class ProbeTest extends TestCase
            {
                public function testItReadsItsOwnSubject(): void
                {
                    $source = \file_get_contents(\dirname(__DIR__, 5) . '/src/Backoffice/Bank/Domain/Bank.php');
                }
            }
            PHP_WRAP));
    }

    #[Test]
    public function itSeesAProductionTargetPastTheFirstMemberOfAGroupedCoversList(): void
    {
        // Anchoring the match on `#[` read only the first attribute of a group, so a production target
        // written second was invisible and the test read as crediting test-namespace coverage alone.
        $this->assertFalse(ArtifactGateSweep::isArtifactGate(self::ELSEWHERE, <<<'PHP_WRAP'
            <?php
            use Erpify\Backoffice\Bank\Domain\Bank;
            use Erpify\Tests\Support\PhpSource;
            use PHPUnit\Framework\Attributes\CoversClass;
            use PHPUnit\Framework\TestCase;
            #[CoversClass(PhpSource::class), CoversClass(Bank::class)]
            final class ProbeTest extends TestCase
            {
                public function testIt(): void
                {
                    $root = \dirname(__DIR__, 5) . '/src';
                }
            }
            PHP_WRAP));
    }

    #[Test]
    public function itDetectsAGateCreditingOnlyTestSupportCoverage(): void
    {
        // Coverage stops at `src`, so a gate has no production line to claim. Crediting its own rule engine
        // is not the same claim, and reading it as one would drop every gate written that way.
        $this->assertTrue(ArtifactGateSweep::isArtifactGate(self::ELSEWHERE, <<<'PHP_WRAP'
            <?php
            use Erpify\Tests\Support\PhpSource;
            use PHPUnit\Framework\Attributes\CoversClass;
            use PHPUnit\Framework\TestCase;
            #[CoversClass(PhpSource::class)]
            final class ProbeTest extends TestCase
            {
                public function testItReadsSource(): void
                {
                    $code = PhpSource::withoutComments('a');
                }
            }
            PHP_WRAP));
    }

    #[Test]
    public function itDetectsAGateWhoseTestCaseImportIsAliased(): void
    {
        // `use … as X` renames the token the `extends` carries, and no fixer objects to it. Matching the
        // literal `extends TestCase` answered false to this.
        $this->assertTrue(ArtifactGateSweep::isArtifactGate(self::ELSEWHERE, <<<'PHP_WRAP'
            <?php
            use PHPUnit\Framework\Attributes\CoversNothing;
            use PHPUnit\Framework\TestCase as PHPUnitTestCase;
            #[CoversNothing]
            final class ProbeTest extends PHPUnitTestCase
            {
                public function testItSweeps(): void
                {
                    $root = \dirname(__DIR__, 5) . '/src';
                }
            }
            PHP_WRAP));
    }

    #[Test]
    public function itRefusesATestThatOnlyReadsItsOwnDirectory(): void
    {
        // Loading a fixture next to yourself is not reading the repository. Without this the category would
        // absorb every test with a JSON fixture, and the registry would classify the whole suite.
        $this->assertFalse(ArtifactGateSweep::isArtifactGate(self::ELSEWHERE, <<<'PHP_WRAP'
            <?php
            use PHPUnit\Framework\Attributes\CoversNothing;
            use PHPUnit\Framework\TestCase;
            #[CoversNothing]
            final class ProbeTest extends TestCase
            {
                public function testItReadsAFixture(): void
                {
                    $payload = \file_get_contents(__DIR__ . '/Fixture/payload.json');
                }
            }
            PHP_WRAP));
    }

    #[Test]
    public function itRefusesAFileThatIsNotPhpAtAll(): void
    {
        // A `.php` name is not a PHP file, and a text matcher over prose answers whatever the prose says.
        $this->assertFalse(ArtifactGateSweep::isArtifactGate(
            self::ELSEWHERE,
            'Prose. use PHPUnit\Framework\TestCase; final class X extends TestCase, \dirname(__DIR__, 4).',
        ));
    }

    #[Test]
    public function itDetectsEveryFileInTheCategoryHomeWhateverItContains(): void
    {
        // The home half of the universe is decided by location and nothing else, so no detector gap can
        // leave a file sitting there unclassified.
        $this->assertTrue(ArtifactGateSweep::isArtifactGate(self::IN_HOME, <<<'PHP_WRAP'
            <?php
            use PHPUnit\Framework\TestCase;
            final class ProbeTest extends TestCase
            {
            }
            PHP_WRAP));
    }

    #[Test]
    public function itIsNotFooledByAClimbWrittenInADocblock(): void
    {
        // The failure this whole category exists to refuse: prose about what a file does, read as the doing
        // of it. The source is stripped of comments before any signal is looked for.
        $this->assertFalse(ArtifactGateSweep::isArtifactGate(self::ELSEWHERE, <<<'PHP_WRAP'
            <?php
            use PHPUnit\Framework\Attributes\CoversNothing;
            use PHPUnit\Framework\TestCase;
            /**
             * Unlike the gates, this one never reaches \dirname(__DIR__, 5) . '/src'.
             */
            #[CoversNothing]
            final class ProbeTest extends TestCase
            {
                public function testItAssertsSomething(): void
                {
                }
            }
            PHP_WRAP));
    }
}
