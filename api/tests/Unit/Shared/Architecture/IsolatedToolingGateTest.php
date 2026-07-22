<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over which development tools may live in the app's Composer tree.
 *
 * A tool may not impose dependency constraints on `api/composer.json` unless it is needed to run the
 * application or its test suite. Two tools broke that rule and were dealt with differently: Behat
 * pins `symfony/*` to `^7` while the app is on 8, so it owns an isolated tree at `api/tools/behat/`;
 * Psalm capped `sebastian/diff` at `^8`, which dictated which PHPUnit the app could install, and was
 * removed outright once its taint analysis had gone 164 merged PRs without a finding.
 *
 * Composer reintroduces either silently — a single `composer require --dev` resolves with no visible
 * failure — so this gate pins both decisions textually.
 *
 * @internal
 */
#[CoversNothing]
final class IsolatedToolingGateTest extends TestCase
{
    #[DataProvider('provideBannedToolIsAbsentFromTheAppComposerTreeCases')]
    public function testBannedToolIsAbsentFromTheAppComposerTree(string $package, string $reason): void
    {
        $this->assertNotContains(
            $package,
            $this->declaredPackages($this->apiRoot() . '/composer.json', 'require', 'require-dev'),
            \sprintf('%s is back in api/composer.json — %s.', $package, $reason),
        );
    }

    /**
     * Packages that must not appear in the app tree at all — no isolated tree owns them.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideBannedToolIsAbsentFromTheAppComposerTreeCases(): iterable
    {
        yield 'psalm' => ['vimeo/psalm', 'it caps sebastian/diff at ^8, which pins the app to an older PHPUnit'];
        yield 'psalm symfony plugin' => ['psalm/plugin-symfony', 'it drags vimeo/psalm back in transitively'];
    }

    #[DataProvider('isolatedPackages')]
    public function testIsolatedToolIsAbsentFromTheAppComposerTree(string $package, string $tree): void
    {
        $this->assertNotContains(
            $package,
            $this->declaredPackages($this->apiRoot() . '/composer.json', 'require', 'require-dev'),
            \sprintf(
                '%s is back in api/composer.json. It belongs to the isolated tree api/tools/%s '
                . '— installing it in-app lets its constraints govern the application\'s dependencies.',
                $package,
                $tree,
            ),
        );
    }

    #[DataProvider('isolatedPackages')]
    public function testIsolatedToolIsDeclaredByItsOwningTree(string $package, string $tree): void
    {
        $manifest = $this->apiRoot() . '/tools/' . $tree . '/composer.json';

        $this->assertContains(
            $package,
            $this->declaredPackages($manifest, 'require', 'require-dev'),
            \sprintf('api/tools/%s/composer.json no longer declares %s.', $tree, $package),
        );
    }

    /**
     * Packages that belong to an isolated tree under `api/tools/<tree>/`.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function isolatedPackages(): iterable
    {
        yield 'behat' => ['behat/behat', 'behat'];
        yield 'behat symfony extension' => ['friends-of-behat/symfony-extension', 'behat'];
    }

    /**
     * Package names are lower-cased: Composer accepts a mismatched-case `require` key with only a
     * warning, so a case-sensitive comparison would let `Vimeo/Psalm` walk straight past the gate.
     *
     * @return list<string>
     */
    private function declaredPackages(string $path, string ...$sections): array
    {
        $this->assertFileExists($path);

        $contents = \is_file($path) ? \file_get_contents($path) : false;
        $this->assertIsString($contents, $path . ' is not readable.');

        try {
            $manifest = \json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            $this->fail($path . ' is not valid JSON: ' . $jsonException->getMessage());
        }

        $this->assertIsArray($manifest, $path . ' does not decode to an array.');

        $packages = [];

        foreach ($sections as $section) {
            $block = $manifest[$section] ?? [];
            $this->assertIsArray($block, \sprintf('%s has a malformed "%s" block.', $path, $section));

            foreach (\array_keys($block) as $name) {
                $packages[] = \strtolower((string) $name);
            }
        }

        return $packages;
    }

    private function apiRoot(): string
    {
        $root = \dirname(__DIR__, 4);

        $this->assertFileExists(
            $root . '/composer.json',
            'apiRoot() no longer resolves to api/ — this test moved without its dirname() depth being updated.',
        );

        return $root;
    }
}
