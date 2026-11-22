<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\PhpCallSites;
use Erpify\Tests\Support\PhpSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Only the deferring listener may hold `RequestPasswordReset`, because the use case no longer pays the timing floor.
 *
 * An `ACTIVE` identity costs a `FOR UPDATE` transaction and a synchronous SMTP exchange; every other address costs
 * one indexed read. The use case is uniform in outcome and deliberately NOT in latency, and it is safe only
 * because its one caller runs it on `kernel.terminate`, after the uniform 202 is on the wire. A second caller —
 * another controller, a console command, a handler — that waits for `request()` before answering hands the
 * requester a timing oracle on account existence, with every other gate green: the use case's own test cannot see
 * who calls it. Holding the type is the precondition for calling the method, so the set of holders is pinned.
 *
 * What a green proves: apart from its own file, no file under `api/src` names the class `RequestPasswordReset`
 * in code (comments excluded) except the pinned listener, and that listener still calls `request()` from
 * `onTerminate()`.
 *
 * What it does not prove:
 * - It is a text-level match on the class NAME. A service fetched from the container by id, a class reached
 *   through a computed class-string or an alias, and a caller outside `api/src` (a command in `tools/`, a
 *   script) are invisible to it.
 * - It proves the pinned file holds the use case, never that it only calls it after the response: the
 *   `kernel.terminate` registration and its ordering are the listener's own tests' question, and the SAPI
 *   flushing the response before terminate listeners run is an assumption no test proves.
 * - A file in another namespace that happens to declare a class of the same short name would be counted
 *   as a holder; that errs toward a red, which is the cheap direction.
 *
 * @internal
 */
#[CoversNothing]
final class PasswordResetRequestCallerGateTest extends TestCase
{
    /** The use case's own file, which names itself. */
    private const string DEFINING_FILE = 'Iam/Identity/Application/RequestPasswordReset.php';

    /**
     * The one holder. Adding a second is a decision about the timing oracle, and this constant is where it is made.
     *
     * @var list<string>
     */
    private const array HOLDERS = [
        'Iam/Identity/Infrastructure/Http/PasswordResetRequestListener.php',
    ];

    /** The short name as a whole word, so `RequestPasswordResetController` is not the use case. */
    private const string NAME_PATTERN = '/(?<!\w)RequestPasswordReset(?!\w)/';

    #[Test]
    public function onlyTheTerminateListenerHoldsTheUseCase(): void
    {
        $this->assertSame(
            self::HOLDERS,
            $this->holders($this->apiSources()),
            'a class other than the pinned listener holds RequestPasswordReset: if it runs request() before the '
            . 'response is sent, the requester can tell an ACTIVE account from any other by the latency',
        );
    }

    #[Test]
    public function theListenerStillCallsTheUseCaseFromOnTerminate(): void
    {
        $source = \file_get_contents(ApiSourceFiles::root() . '/' . self::HOLDERS[0]);
        $this->assertIsString($source);

        $bodies = PhpCallSites::methodBodies($source);
        $this->assertArrayHasKey('onTerminate', $bodies, 'the pinned listener no longer declares onTerminate()');
        $this->assertContains(
            ['requestPasswordReset', 'request'],
            PhpCallSites::calls($bodies['onTerminate']),
            'the pinned listener no longer calls the use case from onTerminate(), so the gate pins a bystander',
        );
    }

    #[Test]
    public function theSweepReachesWhatItIsPinnedOver(): void
    {
        $sources = $this->apiSources();

        $this->assertGreaterThan(500, \count($sources), 'the sweep walked a fraction of api/src');
        $this->assertArrayHasKey(self::DEFINING_FILE, $sources);
        $this->assertArrayHasKey(self::HOLDERS[0], $sources);
    }

    #[Test]
    public function theEngineFindsAPlantedCallerAndIgnoresLookalikes(): void
    {
        $holders = $this->holders([
            self::DEFINING_FILE => '<?php final class RequestPasswordReset {}',
            'Planted/ImportingController.php' => "<?php\nuse Erpify\\Iam\\Identity\\Application\\RequestPasswordReset;\n"
                . "final class X { public function __construct(private RequestPasswordReset \$reset) {} }",
            'Planted/QualifiedCommand.php' => '<?php $r = new \\Erpify\\Iam\\Identity\\Application\\RequestPasswordReset();',
            'Planted/DocblockOnly.php' => "<?php\n/** @see RequestPasswordReset */\n// RequestPasswordReset\nfinal class Y {}",
            'Planted/Lookalike.php' => '<?php use Erpify\\Iam\\Identity\\Infrastructure\\Http\\RequestPasswordResetController;',
        ]);

        $this->assertSame(['Planted/ImportingController.php', 'Planted/QualifiedCommand.php'], $holders);
    }

    /**
     * @param iterable<string, string> $sources api-relative path => source
     *
     * @return list<string> the files naming the use case in code, other than its own, sorted
     */
    private function holders(iterable $sources): array
    {
        $holders = [];

        foreach ($sources as $path => $source) {
            if (self::DEFINING_FILE === $path) {
                continue;
            }

            if (1 === \preg_match(self::NAME_PATTERN, PhpSource::withoutComments($source))) {
                $holders[] = $path;
            }
        }

        \sort($holders);

        return $holders;
    }

    /**
     * @return array<string, string> path relative to `api/src` => source
     */
    private function apiSources(): array
    {
        $root = ApiSourceFiles::root();
        $sources = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $source = \file_get_contents($file->getPathname());
            $this->assertIsString($source, $file->getPathname() . ' could not be read, so it was never swept');
            $sources[\substr($file->getPathname(), \strlen($root) + 1)] = $source;
        }

        return $sources;
    }
}
