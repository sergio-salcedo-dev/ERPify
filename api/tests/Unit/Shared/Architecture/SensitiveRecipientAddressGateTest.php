<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SensitiveParameter;
use Throwable;

/**
 * A recipient's address is a person's personal data, and it rides a stack trace as an argument.
 *
 * **Two controls, and this pins both.** `zend.exception_ignore_args = On` strips EVERY argument of EVERY
 * frame, vendor frames included, and it is the one that holds the line today — yet nothing in the tree
 * referenced it, so deleting that ini line turned nothing red. `#[SensitiveParameter]` is the narrower half:
 * it survives a change to the ini, and it is what renders a marked value as `Object(SensitiveParameterValue)`
 * rather than in clear. Pinning only the narrow half would have gated the redundant control and left the
 * load-bearing one guarded by a comment.
 *
 * **PHP does not enforce attribute agreement**, in either direction: an implementation written without the
 * attribute satisfies an interface that carries it, and nothing reports the divergence. So a sweep that marked
 * every declaration once is a control nothing preserves — the next signature written bare compiles and passes
 * PHPStan, deptrac, PHPMD, PHPUnit, Behat and CI.
 *
 * **The population is pinned as a SET, not counted.** A floor was measured to be too weak here: it defends only
 * while the tree sits at the floor, and a net-zero swap, a parameter rename on an implementation (PHP allows
 * it) or a trait refactor all pass under it. The set makes each of those an explicit edit, which is the
 * answer this repository already reached for `SchedulerCheckpointDurabilityGateTest`.
 *
 * What a green proves: the deployed ini declares the argument-stripping default; and every parameter named
 * `recipientEmail` under `api/src` is exactly this set, each carrying the attribute.
 *
 * What it does not prove, and why no `php.lint.*` rule was written instead:
 * - It keys on the parameter NAME. An address carried under another name is invisible, and roughly twenty
 *   deployed parameters hold one right now — `RequestPasswordReset::request(string $email)` and
 *   `SendInvitation::invite(string $email, …)` are the direct CALLERS of two sites pinned here. That axis is
 *   issue #769; a rule covering it needs a definition of "carries a person's address" that a gate can evaluate.
 * - Anonymous classes, closures and plain functions are unreachable: the walk derives a type from a file path.
 * - The five test doubles under `api/tests` declare the parameter bare on purpose (none is deployed), so the
 *   tree contains five examples a future adapter may be copied from, and this gate reds only afterwards.
 * - It says nothing about `getMessage()`, which is where an address actually reaches a sink (closed by the
 *   mail boundary for both the assembly and the send of every mail path `api/src` writes, and NOT for a
 *   message a vendor command assembles), nor about vendor frames holding the same string
 *   (`SmtpTransport::doRcptToCommand(string $address)`), which cannot be marked at all.
 *
 * @internal
 */
#[CoversNothing]
final class SensitiveRecipientAddressGateTest extends TestCase
{
    /** The parameter this gate governs, matched case-insensitively so a re-spelling cannot slip the net. */
    private const string GOVERNED_PARAMETER = 'recipientEmail';

    /** The ini that strips every frame argument, and the value that does it. */
    private const string ARGUMENT_STRIPPING_INI = '/frankenphp/conf.d/10-app.ini';

    /**
     * Every declaration under `api/src`. Adding a mail sender is a deliberate edit here, on purpose: the
     * alternative is a count, and a count cannot tell a new sender from a lost one.
     *
     * @var list<string>
     */
    private const array GOVERNED_SITES = [
        \Erpify\Iam\Identity\Application\AccountLockedEmailSender::class . '::send($recipientEmail)',
        \Erpify\Iam\Identity\Application\PasswordChangedEmailSender::class . '::send($recipientEmail)',
        \Erpify\Iam\Identity\Application\PasswordResetEmailSender::class . '::send($recipientEmail)',
        \Erpify\Iam\Identity\Application\SendAccountLockedEmailBestEffort::class . '::send($recipientEmail)',
        \Erpify\Iam\Identity\Application\SendPasswordChangedEmailBestEffort::class . '::send($recipientEmail)',
        \Erpify\Iam\Identity\Application\SendPasswordResetEmailBestEffort::class . '::send($recipientEmail)',
        \Erpify\Iam\Identity\Infrastructure\Mail\SymfonyAccountLockedEmailSender::class . '::send($recipientEmail)',
        \Erpify\Iam\Identity\Infrastructure\Mail\SymfonyPasswordChangedEmailSender::class . '::send($recipientEmail)',
        \Erpify\Iam\Identity\Infrastructure\Mail\SymfonyPasswordResetEmailSender::class . '::send($recipientEmail)',
        \Erpify\Iam\Invitation\Application\InvitationEmailSender::class . '::send($recipientEmail)',
        \Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort::class . '::send($recipientEmail)',
        \Erpify\Iam\Invitation\Infrastructure\Mail\SymfonyInvitationEmailSender::class . '::send($recipientEmail)',
        \Erpify\Shared\Mailer\Infrastructure\RedactingMailer::class . '::send($recipientEmail)',
        \Erpify\Shared\Mailer\Infrastructure\SecurityLinkMailer::class . '::send($recipientEmail)',
    ];

    #[Test]
    public function everyRecipientAddressParameterIsDeclaredSensitive(): void
    {
        $swept = $this->governedParameters();
        $found = \array_keys($swept);
        \sort($found);

        $this->assertSame(
            self::GOVERNED_SITES,
            $found,
            'the swept declarations are not the pinned set: a sender was added, renamed or lost, or the walk '
            . 'stopped reaching one — verify which before touching the constant',
        );

        $bare = \array_keys(\array_filter($swept, static fn (bool $isSensitive): bool => !$isSensitive));
        \sort($bare);

        $this->assertSame(
            [],
            $bare,
            \sprintf(
                "a recipient's address is declared without #[SensitiveParameter], so its value rides the trace:\n- %s",
                \implode("\n- ", $bare),
            ),
        );
    }

    /**
     * The attribute survives an ini change; the ini is what strips arguments today, in every frame including
     * the vendor ones no attribute of ours can reach. Nothing else in the tree referenced it.
     */
    #[Test]
    public function theDeployedIniStripsEveryStackTraceArgument(): void
    {
        $ini = \dirname(__DIR__, 4) . self::ARGUMENT_STRIPPING_INI;
        $this->assertFileExists($ini, 'the ini that strips frame arguments is gone, so every argument is captured');

        $declared = \file_get_contents($ini);
        $this->assertIsString($declared);

        $this->assertMatchesRegularExpression(
            '/^\s*zend\.exception_ignore_args\s*=\s*On\s*$/mi',
            $declared,
            'the deployed ini no longer strips frame arguments, so every unmarked parameter rides the trace',
        );
    }

    /**
     * Every `$recipientEmail` parameter declared under `api/src`, mapped to whether it carries the attribute.
     *
     * @return array<string, bool>
     */
    private function governedParameters(): array
    {
        $root = ApiSourceFiles::root();
        $sites = [];
        $unresolved = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $relative = \substr($file->getPathname(), \strlen($root) + 1);
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', \substr($relative, 0, -4));

            try {
                $declared = \class_exists($fqcn) || \interface_exists($fqcn) || \trait_exists($fqcn);
            } catch (Throwable $throwable) {
                $this->fail(\sprintf('"%s" could not be loaded: %s', $relative, $throwable->getMessage()));
            }

            if (!$declared) {
                // Counted rather than skipped: a file that silently leaves the population takes the gate's
                // reason to demand anything with it, and the build stays green.
                $unresolved[] = $relative;

                continue;
            }

            $sites += $this->sitesIn(new ReflectionClass($fqcn), $file->getPathname());
        }

        $this->assertSame(
            [],
            $unresolved,
            'these files declared no type at their PSR-4 name, so they were never swept',
        );

        return $sites;
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return array<string, bool>
     */
    private function sitesIn(ReflectionClass $class, string $path): array
    {
        $sites = [];

        foreach ($class->getMethods() as $reflectionMethod) {
            // Compared by FILE and not by declaring class: for a trait-imported method PHP reports the USING
            // class as the declaring one, so a shared `send()` would be counted once per adapter — buying
            // headroom under a check whose whole job is to notice a declaration going missing.
            if ($reflectionMethod->getFileName() !== $path) {
                continue;
            }

            $sites += $this->parametersOf($reflectionMethod);
        }

        return $sites;
    }

    /**
     * @return array<string, bool>
     */
    private function parametersOf(ReflectionMethod $method): array
    {
        $sites = [];

        foreach ($method->getParameters() as $reflectionParameter) {
            if (0 !== \strcasecmp(self::GOVERNED_PARAMETER, $reflectionParameter->getName())) {
                continue;
            }

            $site = \sprintf(
                '%s::%s($%s)',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $reflectionParameter->getName(),
            );
            $sites[$site] = [] !== $reflectionParameter->getAttributes(SensitiveParameter::class);
        }

        return $sites;
    }
}
