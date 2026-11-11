<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A file under `api/src` that creates `ImageDeletionRequested` reds the build until it is registered beside the
 * use case that publishes what it records and a functional test of that use case. The observation that test
 * owes is `docs/adr/image-deletion-signal-transport.md` D3; {@see self::RECIPE} is what the failure prints.
 *
 * "Publisher" is split in two because the code is: an aggregate (or a factory) creates the event and records it,
 * and a use case publishes what it pulls from the aggregate without naming the event's class. So the gate keys
 * on the site that CREATES the event — `new ImageDeletionRequested`, or a static call on the class other than
 * `::class`, `::eventName()` and `::aggregateType()`, outside the class's own file — and asks for the use case
 * and its test next to it.
 *
 * Two sets:
 *
 *  - {@see self::NAMING_SITES} — files that name the class and create nothing: the declaration, the handler
 *    consuming it and the port documenting it. A creating site never belongs here; the sweep refuses one;
 *  - {@see self::PUBLISHERS} — `creating site => [use case, functional test]`, empty while nothing creates the
 *    event. Each entry is checked by {@see self::registrationProblems()}, which is driven with planted maps so
 *    its checks are exercised while the real map is empty.
 *
 * What a green proves: the files under `api/src` that create the event are exactly the keys of
 * {@see self::PUBLISHERS}; every file naming it is one of those or a pinned naming site; each registered use
 * case resolves, by `realpath`, to a file under `api/src`; each registered test resolves to a file under
 * `api/tests/Functional` that imports its use case or names it fully qualified; and the sweep reached the tree.
 *
 * What it does not prove:
 * - **That the registered test proves anything.** It is declared, it exists and it references the use case; whether
 *   it drives that use case, rolls back and reads from a second connection is review's to judge, and a test's
 *   existence never shows that the transaction the use case publishes in is the one carrying its business
 *   write.
 * - **A second use case reusing a registered site.** A new caller of an aggregate method that is already
 *   registered names no new file, so it does not trip the gate.
 * - **Anything outside `api/src`**, or a creation the text does not show: a computed class-string, a class
 *   alias, `fromPrimitives()` on a name assembled at runtime.
 * - It is a text match on the short class name, so a docblock or a comment naming the class reds it — the
 *   accepted cost of a match with no configuration of its own.
 *
 * @internal
 */
#[CoversNothing]
final class ImageDeletionRequestedPublisherGateTest extends TestCase
{
    private const string TOKEN = 'ImageDeletionRequested';

    private const string DECLARATION = 'Shared/Images/Domain/Event/ImageDeletionRequested.php';

    /**
     * `new ImageDeletionRequested`, qualified or not, or a static call on the class that is not one of its
     * descriptors (`::class`, `::eventName()`, `::aggregateType()`) — `fromPrimitives()` builds one.
     */
    private const string CREATION = '#\bnew\s+(?:\\\?(?:\w+\\\)*)?ImageDeletionRequested\b'
        . '|\bImageDeletionRequested::(?!class\b|eventName\b|aggregateType\b)#';

    /**
     * @var list<string> paths under `api/src`, sorted
     */
    private const array NAMING_SITES = [
        self::DECLARATION,
        'Shared/Images/Domain/Storage/ImageStorage.php',
        'Shared/Images/Infrastructure/Messenger/DeleteImageOnDeletionRequested.php',
    ];

    /**
     * Creating site under `api/src` => the use case under `api/src` that publishes what it records, and the
     * functional test under `api/tests` that observes that publication.
     *
     * @var array<string, array{useCase: string, test: string}>
     */
    private const array PUBLISHERS = [];

    private const string RECIPE = 'A file that creates ImageDeletionRequested owes the observation #872 defers '
        . 'to it (docs/adr/image-deletion-signal-transport.md D3): a functional test under api/tests/Functional '
        . 'that calls the REAL use case publishing what that file records, lets it make its business write and '
        . 'publish, rolls that transaction back, and reads from a SECOND connection that neither the business '
        . 'row nor the message in messenger_messages is there. Then register it in PUBLISHERS here as '
        . '"creating file => [useCase, test]".';

    /** A real use case, a real functional test that names it, and a site, for the planted registrations. */
    private const string PLANTED_SITE = 'Shared/Images/Domain/Entity/Image.php';

    private const string PLANTED_USE_CASE = 'Shared/Images/Application/DeleteImage.php';

    private const string PLANTED_TEST = 'Functional/Shared/Images/DeleteImageIntegrationTest.php';

    #[Test]
    public function theFilesCreatingTheEventAreExactlyTheRegisteredPublishers(): void
    {
        $creating = $this->filesMatching(self::CREATION);
        $creating = \array_values(\array_diff($creating, [self::DECLARATION]));

        $this->assertSame([], $this->creationProblems($creating, \array_keys($this->publishers())));
    }

    #[Test]
    public function aCreatingFileThatIsNotRegisteredIsReported(): void
    {
        $problems = $this->creationProblems(['Shared/Images/Application/Planted.php'], []);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Shared/Images/Application/Planted.php', $problems[0]);
        $this->assertStringContainsString(self::RECIPE, $problems[0]);
    }

    #[Test]
    public function aRegistrationThatNoLongerCreatesTheEventIsReported(): void
    {
        $problems = $this->creationProblems([], ['Shared/Images/Application/Planted.php']);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Shared/Images/Application/Planted.php', $problems[0]);
        $this->assertStringContainsString('no longer creates', $problems[0]);
    }

    #[Test]
    public function theFilesNamingTheEventAreThePinnedSitesOrTheRegisteredPublishers(): void
    {
        $expected = [...self::NAMING_SITES, ...\array_keys($this->publishers())];
        \sort($expected);

        $naming = $this->filesMatching('#' . self::TOKEN . '#');
        $unexpected = \array_values(\array_diff($naming, $expected));

        $this->assertSame(
            [],
            $unexpected,
            \sprintf(
                '%d new file(s) name ImageDeletionRequested: %s. A file that names it without creating it goes in '
                . 'NAMING_SITES here; one that creates it is reported by the creation check, which says what it owes.',
                \count($unexpected),
                \implode(', ', $unexpected),
            ),
        );
        $this->assertSame(
            [],
            \array_values(\array_diff($expected, $naming)),
            'a pinned file no longer names ImageDeletionRequested; remove it here',
        );
    }

    #[Test]
    public function everyRegisteredPublisherHasItsUseCaseAndAFunctionalTestNamingIt(): void
    {
        $this->assertSame([], $this->registrationProblems($this->publishers()));
    }

    /**
     * @param array<string, array{useCase: string, test: string}> $publishers
     */
    #[DataProvider('provideARegistrationThatCannotHoldTheObservationIsRefusedCases')]
    #[Test]
    public function aRegistrationThatCannotHoldTheObservationIsRefused(array $publishers, string $problem): void
    {
        $problems = $this->registrationProblems($publishers);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString($problem, $problems[0]);
    }

    /**
     * @return iterable<string, array{array<string, array{useCase: string, test: string}>, string}>
     */
    public static function provideARegistrationThatCannotHoldTheObservationIsRefusedCases(): iterable
    {
        yield 'test outside Functional' => [
            [self::PLANTED_SITE => [
                'useCase' => self::PLANTED_USE_CASE,
                'test' => 'Functional/../Unit/Shared/Images/Domain/ImageDeletionRequestedTest.php',
            ]],
            'is not under api/tests/Functional',
        ];
        yield 'test that does not exist' => [
            [self::PLANTED_SITE => ['useCase' => self::PLANTED_USE_CASE, 'test' => 'Functional/Absent/NoSuchTest.php']],
            'does not exist',
        ];
        yield 'test that does not name its use case' => [
            [self::PLANTED_SITE => [
                'useCase' => self::PLANTED_USE_CASE,
                'test' => 'Functional/Shared/Event/AsyncDoctrineTransportFunctionalTest.php',
            ]],
            'does not name its use case',
        ];
        yield 'test that names its use case only in its own class name' => [
            [self::PLANTED_SITE => [
                'useCase' => 'Iam/Identity/Application/ChangeMyPassword.php',
                'test' => 'Functional/Iam/Identity/ChangeMyPasswordFunctionalTest.php',
            ]],
            'does not name its use case',
        ];
        yield 'use case outside api/src' => [
            [self::PLANTED_SITE => [
                'useCase' => '../tests/' . self::PLANTED_TEST,
                'test' => self::PLANTED_TEST,
            ]],
            'does not exist under api/src',
        ];
        yield 'use case that does not exist' => [
            [self::PLANTED_SITE => [
                'useCase' => 'Shared/Images/Application/NoSuchUseCase.php',
                'test' => self::PLANTED_TEST,
            ]],
            'does not exist under api/src',
        ];
    }

    #[Test]
    public function aRegistrationHoldingAFunctionalTestOfAnExistingUseCaseIsAccepted(): void
    {
        $this->assertSame([], $this->registrationProblems([
            self::PLANTED_SITE => ['useCase' => self::PLANTED_USE_CASE, 'test' => self::PLANTED_TEST],
        ]));
    }

    #[Test]
    public function theSweepReachesTheSourceItIsPinnedOver(): void
    {
        $swept = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $swept[] = $file->getPathname();
        }

        $this->assertGreaterThan(500, \count($swept), 'the sweep walked a fraction of api/src');

        foreach ([...self::NAMING_SITES, ...\array_keys($this->publishers())] as $site) {
            $this->assertContains(ApiSourceFiles::root() . '/' . $site, $swept, $site . ' is pinned but never swept');
        }

        // Nothing under api/src creates the event yet, so the creation match is proven on spellings, each of
        // which only one branch of the pattern matches, and on the descriptors it must let through.
        $creating = [
            'new ImageDeletionRequested($id)',
            'new \Erpify\Shared\Images\Domain\Event\ImageDeletionRequested($id)',
            'ImageDeletionRequested::fromPrimitives(',
        ];

        foreach ($creating as $source) {
            $this->assertMatchesRegularExpression(
                self::CREATION,
                $source,
                'the creation match misses a spelling of a creating site',
            );
        }

        $notCreating = [
            'new DeleteImageOnDeletionRequested(',
            'handles: ImageDeletionRequested::class',
            'ImageDeletionRequested::eventName()',
            'ImageDeletionRequested::aggregateType()',
        ];

        foreach ($notCreating as $source) {
            $this->assertDoesNotMatchRegularExpression(
                self::CREATION,
                $source,
                'the creation match counts a reference that creates nothing',
            );
        }
    }

    /**
     * @param list<string> $creating   files under `api/src` that create the event
     * @param list<string> $registered the keys of {@see self::PUBLISHERS}
     *
     * @return list<string>
     */
    private function creationProblems(array $creating, array $registered): array
    {
        $problems = [];

        foreach (\array_diff($creating, $registered) as $unregistered) {
            $problems[] = \sprintf("%s creates ImageDeletionRequested.\n%s", $unregistered, self::RECIPE);
        }

        foreach (\array_diff($registered, $creating) as $stale) {
            $problems[] = \sprintf(
                '%s is registered in PUBLISHERS but no longer creates ImageDeletionRequested; remove it',
                $stale,
            );
        }

        return $problems;
    }

    /**
     * Why each registration cannot hold the observation it stands for; empty when every one can.
     *
     * @param array<string, array{useCase: string, test: string}> $publishers
     *
     * @return list<string>
     */
    private function registrationProblems(array $publishers): array
    {
        $problems = [];

        foreach ($publishers as $site => ['useCase' => $useCase, 'test' => $test]) {
            $problem = $this->registrationProblem($useCase, $test);

            if (null !== $problem) {
                $problems[] = $site . ': ' . $problem . '. ' . self::RECIPE;
            }
        }

        return $problems;
    }

    private function registrationProblem(string $useCase, string $test): ?string
    {
        $testsRoot = \dirname(ApiSourceFiles::root()) . '/tests';
        $functionalRoot = \realpath($testsRoot . '/Functional');
        $this->assertIsString($functionalRoot, 'api/tests/Functional does not exist');

        $srcRoot = \realpath(ApiSourceFiles::root());
        $this->assertIsString($srcRoot, 'api/src does not exist');
        $useCasePath = \realpath($srcRoot . '/' . $useCase);

        if (false === $useCasePath || !\str_starts_with($useCasePath, $srcRoot . '/')) {
            return \sprintf('its use case %s does not exist under api/src', $useCase);
        }

        $testPath = \realpath($testsRoot . '/' . $test);

        if (false === $testPath) {
            return \sprintf('its test %s does not exist', $test);
        }

        if (!\str_starts_with($testPath, $functionalRoot . '/')) {
            return \sprintf('its test %s is not under api/tests/Functional', $test);
        }

        // Imported or written fully qualified: the short name alone is satisfied by the test's own class name.
        $useCaseClass = 'Erpify\\' . \str_replace('/', '\\', \substr($useCasePath, \strlen($srcRoot) + 1, -4));
        $reference = '#(?<![\w\\\])\\\?' . \preg_quote($useCaseClass, '#') . '(?![\w\\\])#';
        $matched = \preg_match($reference, (string) \file_get_contents($testPath));
        $this->assertNotFalse($matched, \preg_last_error_msg());

        if (1 !== $matched) {
            return \sprintf('its test %s does not name its use case %s', $test, $useCaseClass);
        }

        return null;
    }

    /**
     * Read through a method so the sweep is typed by what the constant holds, not by its literal value: while
     * it is empty, the code over it is still the code the first registration runs.
     *
     * @return array<string, array{useCase: string, test: string}>
     */
    private function publishers(): array
    {
        return self::PUBLISHERS;
    }

    /**
     * @return list<string> paths under `api/src` whose source matches `$pattern`, sorted
     */
    private function filesMatching(string $pattern): array
    {
        $root = ApiSourceFiles::root();
        $matching = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $source = \file_get_contents($file->getPathname());
            $this->assertIsString($source, $file->getPathname() . ' could not be read, so it was never swept');

            $matched = \preg_match($pattern, $source);
            $this->assertNotFalse($matched, $file->getPathname() . ': ' . \preg_last_error_msg());

            if (1 === $matched) {
                $matching[] = \substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        \sort($matching);

        return $matching;
    }
}
