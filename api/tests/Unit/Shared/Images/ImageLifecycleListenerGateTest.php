<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No Doctrine lifecycle hook anywhere in this module removes bytes.
 *
 * It is the module's own rule, so it is filed on the module rather than in the gate home — but it is a
 * gate: it reads the source tree as data and exercises nothing. It sat inside a behavioural test until
 * that made it invisible to the placement registry, whose shape heuristic skips any file that also credits
 * production coverage.
 *
 * What it defends is invariant 4 of the conservation-contract ADR, whose named counterexample was a real
 * `postRemove` listener that deleted a stored object during the owner's flush — inside the owner's
 * transaction, where a storage failure rolls back the owner's business write and leaves a live reference
 * over destroyed bytes. The seam that replaces it is a queue consumer, and this is what stops the
 * convenient shortcut coming back.
 *
 * **The scope IS the limit, and it is narrower than the rule.** The listener the ADR names lived OUTSIDE
 * `Shared/Images/`, and one registered by service tag or by `#[AsDoctrineListener]` elsewhere would not be
 * seen either. This covers the letter, not the purpose.
 *
 * @internal
 */
#[CoversNothing]
final class ImageLifecycleListenerGateTest extends TestCase
{
    private const string MODULE = '/src/Shared/Images';

    /**
     * **Matched without regard to case, and that is not tidiness.** The lower-cased spellings are the
     * `Events::` constants an `#[AsEntityListener(event: Events::postRemove)]` names. Doctrine's other —
     * and more idiomatic — form is an attribute ON the entity: `#[ORM\HasLifecycleCallbacks]` plus
     * `#[ORM\PostRemove]`, spelled with capitals. A case-sensitive sweep over the lower-cased tokens
     * therefore missed exactly the shape a developer in a hurry would write, and `HasLifecycleCallbacks`
     * — the switch that makes those callbacks run at all — was on no list at all.
     */
    private const array FORBIDDEN = [
        'AsEntityListener',
        'AsDoctrineListener',
        'HasLifecycleCallbacks',
        'postRemove',
        'preRemove',
    ];

    public function testTheModuleContainsNoDoctrineLifecycleListener(): void
    {
        $sources = $this->moduleSources();

        $this->assertNotSame([], $sources, 'the sweep must see the module, or it asserts nothing');

        foreach ($sources as $name => $source) {
            foreach (self::FORBIDDEN as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $source,
                    $name . ' must declare no lifecycle hook',
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function moduleSources(): array
    {
        $sources = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(\dirname(__DIR__, 4) . self::MODULE),
        );

        foreach ($files as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $contents = (string) \file_get_contents($file->getPathname());
                $sources[$file->getFilename()] = $this->codeWithoutComments($contents);
            }
        }

        return $sources;
    }

    /**
     * The subject is CODE, so comments are dropped before matching. A text sweep cannot tell a hook from
     * the prose explaining why there is no hook — measured, the deletion handler's own docblock naming
     * `postRemove` as the shape it exists to avoid was enough to fail this check.
     */
    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
