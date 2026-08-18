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

/**
 * A recipient's address is a person's personal data, and it rides a stack trace as an argument.
 * `#[SensitiveParameter]` renders it as `Object(SensitiveParameterValue)` instead — measured with the ini
 * defence disabled, against an unmarked control that renders in clear.
 *
 * **PHP does not enforce attribute agreement**, in either direction: an implementation written without the
 * attribute satisfies an interface that carries it, and nothing reports the divergence. So a sweep that marked
 * every declaration once is a control nothing preserves — it compiles, and PHPStan, deptrac, PHPMD, PHPUnit,
 * Behat and CI all stay green over the next signature written bare. That is the gap this closes.
 *
 * The population is DERIVED from `api/src` rather than listed, so a new sender joins the moment it is
 * declared, and pinned by a floor so a walk that silently finds nothing fails instead of passing.
 *
 * What a green proves: every parameter named `recipientEmail` anywhere in `api/src` carries the attribute.
 *
 * What it does not prove, and this is why no `php.lint.*` gate was written for it: the rule keys on the
 * parameter NAME. An address carried under another name is invisible here — `NotificationMailer::send(string
 * $to, …)` is the live example, deliberately outside this axis because its `$to` is an operations mailbox
 * rather than a person. It says nothing about `getMessage()`, which is where an address actually reaches a
 * sink today and which no attribute can touch, and nothing about vendor frames holding the same string
 * (`SmtpTransport::doRcptToCommand(string $address)`), which cannot be marked at all. It also reads `api/src`
 * only: the test doubles under `api/tests` diverge on purpose, since no double is deployed.
 *
 * @internal
 */
#[CoversNothing]
final class SensitiveRecipientAddressGateTest extends TestCase
{
    /** The parameter this gate governs. */
    private const string GOVERNED_PARAMETER = 'recipientEmail';

    /**
     * Declarations at the time of writing: four ports, four best-effort wrappers, four adapters and the
     * shared link mailer. A floor rather than an equality, because adding a sender should not red the build —
     * losing the walk should.
     */
    private const int KNOWN_DECLARATIONS = 13;

    #[Test]
    public function everyRecipientAddressParameterIsDeclaredSensitive(): void
    {
        $bare = [];
        $seen = 0;

        foreach ($this->governedParameters() as $site => $isSensitive) {
            ++$seen;

            if (!$isSensitive) {
                $bare[] = $site;
            }
        }

        $this->assertGreaterThanOrEqual(
            self::KNOWN_DECLARATIONS,
            $seen,
            \sprintf('only %d declarations were swept, so the walk lost sites rather than the tree losing them', $seen),
        );

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
     * Every `$recipientEmail` parameter declared under `api/src`, mapped to whether it carries the attribute.
     * Keyed by site, so a duplicate declaration cannot hide behind another.
     *
     * @return array<string, bool>
     */
    private function governedParameters(): array
    {
        $root = ApiSourceFiles::root();
        $sites = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $relative = \substr($file->getPathname(), \strlen($root) + 1);
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', \substr($relative, 0, -4));

            if (!\class_exists($fqcn) && !\interface_exists($fqcn) && !\trait_exists($fqcn)) {
                continue;
            }

            foreach ((new ReflectionClass($fqcn))->getMethods() as $method) {
                // Declared here and not inherited: an inherited method would be counted once per subclass,
                // and its declaration is already swept where it lives.
                if ($method->getDeclaringClass()->getName() !== $fqcn) {
                    continue;
                }

                $sites += $this->parametersOf($method, $fqcn);
            }
        }

        return $sites;
    }

    /**
     * @return array<string, bool>
     */
    private function parametersOf(ReflectionMethod $method, string $fqcn): array
    {
        $sites = [];

        foreach ($method->getParameters() as $reflectionParameter) {
            if (self::GOVERNED_PARAMETER !== $reflectionParameter->getName()) {
                continue;
            }

            $site = \sprintf('%s::%s($%s)', $fqcn, $method->getName(), $reflectionParameter->getName());
            $sites[$site] = [] !== $reflectionParameter->getAttributes(SensitiveParameter::class);
        }

        return $sites;
    }
}
