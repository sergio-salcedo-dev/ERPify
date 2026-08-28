<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Gate over the property that decides whether a line the operator needs ever reaches them: the CHANNEL.
 *
 * In prod the default channel is a `fingers_crossed` handler with `action_level: error` and
 * `buffer_size: 50`. That gives a line on a path which never produces a 5xx two ways to be wrong, and they
 * are opposites:
 *
 * - **Below `error`, it is discarded.** The class did the honest thing, the test passed, and production saw
 *   nothing — which is how a swallowed failure becomes indistinguishable from no failure.
 * - **At `error` or above, it ARRIVES AND ACTIVATES**, flushing up to fifty records the request had
 *   accumulated. Reporting a lost email at the price of whatever else that request logged is not a fix, and
 *   on an authenticated request those records include the user's address under a vendor key no redaction
 *   list reaches.
 *
 * `observability` escapes both: `monolog.yaml` gives it its own always-on stream from `info` and excludes it
 * from the buffered handler by name. So the rule is **not** about levels — a line that must arrive on a path
 * which does not end in 5xx belongs on that channel, at whatever level says what it means.
 *
 * **Two populations, both derived.** Swallowers report a failure their caller must survive, and carry a
 * `catch (Throwable`; sweeps report a FINDING from a scheduled run that completes normally, and carry none.
 * Neither list drives the derivation — {@see REPORTERS} only pins what the derivation must find, which is
 * the difference between a pin and a self-fulfilling one.
 *
 * **What a green does not prove:**
 *
 * - **That a binding is in effect.** `FileLoader::registerClasses()` overwrites definitions unconditionally,
 *   so an explicit block moved ABOVE the `Erpify\` prototype in `services.yaml` silently reverts a class to
 *   the autowired channel, and this gate reads the file as a map and cannot see order.
 * - That `observability` stays always-on ({@see ObservabilityChannelGateTest}), that the sink is read, or
 *   that any line ever fired.
 * - The attribute branch is a byte scan, so a commented-out attribute — or a docblock quoting one —
 *   satisfies it, unlike the `services.yaml` branch, which is parsed. The compensating control is thin and
 *   worth stating plainly: two arrival tests read the bytes Monolog wrote
 *   ({@see \Erpify\Tests\Functional\Shared\Audit\ActivityAuditWriteFailureArrivalTest} for an
 *   attribute-bound class, {@see \Erpify\Tests\Functional\Iam\Identity\LockoutAuditWriteFailureArrivalTest}
 *   for a `services.yaml`-bound one), so each BRANCH has a witness — but only two of the pinned classes do.
 * - It reads `config/services.yaml` only; an override in an env-specific file is invisible, and
 *   `config/services_test.yaml` already demonstrates that shape by rebinding `$logger` for the error
 *   contract's responder.
 * - It matches short class names, so a same-named class in another namespace would satisfy it for another.
 *
 * @internal
 */
#[CoversNothing]
final class BestEffortReportChannelGateTest extends TestCase
{
    private const string OBSERVABILITY_SERVICE = 'monolog.logger.observability';

    /**
     * The attribute spelling, available only to `Infrastructure`: deptrac refuses `Application` a dependency
     * on the DI container's attributes, so those classes are bound in `services.yaml` instead. Both spellings
     * satisfy this gate, because both produce the same wiring — what it refuses is neither.
     */
    private const string OBSERVABILITY_AUTOWIRE = "#[Autowire(service: 'monolog.logger.observability')]";

    private const string SERVICES_CONFIG = __DIR__ . '/../../../config/services.yaml';

    /**
     * Every class this gate holds to the channel — a PIN, not an input.
     *
     * The two predicates below derive the population on their own; this constant only asserts what they must
     * find. That separation is the point: a shape that used the list to BUILD the population would let a
     * deleted name leave both sides of the comparison, and the class would leave the gate with nothing red.
     *
     * The two halves it covers, and why they are one rule rather than two:
     *
     * - **Swallowers** report a failure their caller must survive — a lost email, a session left standing, an
     *   audit row that was owed. Derived from `catch (Throwable` plus a logger call.
     * - **Sweeps** report a FINDING from a scheduled run that completes normally. No `catch` at all, so no
     *   syntax distinguishes their `error` from any other; they are derived by that absence.
     *
     * Both live on paths that never produce a 5xx, which is the whole rule. As it happens the union is today
     * every class in `api/src` that injects a logger and uses it, bar the two in {@see NOT_REPORTERS} — an
     * observation about this tree rather than a design goal.
     */
    private const array REPORTERS = [
        'DbalAuditLogPruner.php',
        'DbalFailedMessagePruner.php',
        'DoctrineDatabaseHealthChecker.php',
        'FlysystemImageStorage.php',
        'InspectStoredIdentityHandler.php',
        'InterventionImageProcessor.php',
        'ReauthenticateDeviceBestEffort.php',
        'ReconcilePersonReferencesHandler.php',
        'ReconcileSubjectErasuresHandler.php',
        'RecordLockoutAuditBestEffort.php',
        'RecordLockoutNoticeAuditBestEffort.php',
        'RecordRecoveryThrottleAuditBestEffort.php',
        'ReportDeadLetterBacklogHandler.php',
        'RevokeCurrentSessionController.php',
        'RevokeSessionsBestEffort.php',
        'SearchObservabilityListener.php',
        'SendAccountLockedEmailBestEffort.php',
        'SendEmailOnBankChanged.php',
        'SendInvitationEmailBestEffort.php',
        'SendPasswordChangedEmailBestEffort.php',
        'SendPasswordResetEmailBestEffort.php',
        'SymfonyAuditLogger.php',
    ];

    /**
     * Classes the derivation reaches that this gate deliberately does NOT hold, with the reason.
     *
     * Both belong to `Shared/ErrorContract`, the RFC 9457 pipeline, whose channel and level policy is owned
     * by `docs/api-error-contract.md` (NFR26) — a contract with its own per-error log-line shape, level
     * tiering and `exception_category` dispatch. Moving those lines is a decision of that contract, not of
     * this one, and taking it here would change a documented error surface as a side effect of a channel
     * sweep.
     *
     * **The residue is real and is declared rather than hidden.** `ExceptionResponder::resolveLogLevel()`
     * returns `warning` for every status below 500, and `ProblemDetailsFactory` logs its sentinel at
     * `notice` — so on a 4xx those lines meet exactly the discard this gate exists to refuse. They survive
     * only when something else on the request reaches `error` and flushes the buffer, which for a 5xx is the
     * intended behaviour and for a 4xx is nothing. Whether the error contract wants that is a question for
     * its own document.
     */
    private const array NOT_REPORTERS = [
        'ExceptionResponder.php',
        'ProblemDetailsFactory.php',
    ];

    /**
     * Where every excluded class must live for its exclusion to hold.
     */
    private const string ERROR_CONTRACT_PATH = '/Shared/ErrorContract/';

    #[Test]
    public function everyReporterOnANonErrorPathUsesTheAlwaysOnChannel(): void
    {
        $bound = $this->classesBoundInServicesConfig();
        $offenders = [];

        foreach ($this->reporters() as $file => $contents) {
            if (\str_contains($contents, self::OBSERVABILITY_AUTOWIRE)) {
                continue;
            }

            if (\in_array(\basename($file, '.php'), $bound, true)) {
                continue;
            }

            $offenders[] = $file;
        }

        $this->assertSame([], $offenders, \sprintf(
            "These classes report on the DEFAULT channel:\n  %s\n"
            . 'In prod that is a `fingers_crossed` handler, so below `error` the line is discarded and at '
            . "`error` it arrives only by flushing up to fifty of the request's other records. Bind "
            . '`$logger` to `@%s` in services.yaml — which is what an `Application` class must do, since '
            . 'deptrac refuses that layer the container attributes — or add %s at the parameter when the '
            . 'class lives in `Infrastructure`.',
            \implode("\n  ", $offenders),
            self::OBSERVABILITY_SERVICE,
            self::OBSERVABILITY_AUTOWIRE,
        ));
    }

    #[Test]
    public function theDerivationStillFindsEveryClassItIsMeantToHold(): void
    {
        // Without this the gate is vacuous in the direction that matters: a reformatted catch or a renamed
        // class empties the derivation, and an empty derivation satisfies the assertion above perfectly.
        $found = \array_map(\basename(...), \array_keys($this->reporters()));
        \sort($found);

        $expected = self::REPORTERS;
        \sort($expected);

        $this->assertSame($expected, $found, 'The population no longer matches. Either a reporter was added '
            . '— classify it in REPORTERS and give it the channel — or one was renamed or reworded out of '
            . 'reach of the derivation.');
    }

    #[Test]
    public function everyExclusionStillBelongsToTheErrorContract(): void
    {
        // The exclusion is an argument about ownership, so it is read rather than trusted: a class that
        // leaves `Shared/ErrorContract` takes the reason with it and must rejoin the rule.
        foreach (self::NOT_REPORTERS as $excluded) {
            $path = $this->pathOf($excluded);

            $this->assertStringContainsString(self::ERROR_CONTRACT_PATH, $path, \sprintf(
                '%s no longer lives in the RFC 9457 pipeline, so "its channel belongs to the error contract" '
                . 'no longer holds. Either it joins the population, or the exclusion needs a new argument.',
                $excluded,
            ));
        }
    }

    /**
     * @throws RuntimeException
     *
     * @return array<string, string> path => contents
     */
    private function reporters(): array
    {
        $reporters = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $path = $file->getPathname();

            if (\in_array(\basename($path), self::NOT_REPORTERS, true)) {
                continue;
            }

            $contents = \file_get_contents($path);

            if (false === $contents) {
                throw new RuntimeException(\sprintf(
                    'Could not read "%s". A file skipped here leaves its reporter out of the population '
                    . 'without failing anything.',
                    $path,
                ));
            }

            if (!$this->swallowsAndLogs($contents) && !$this->sweepsAndLogs($contents)) {
                continue;
            }

            $reporters[$path] = $contents;
        }

        return $reporters;
    }

    /**
     * A swallowed failure reported to a logger.
     *
     * Deliberately covers BOTH arrangements the tree uses, because scoping to the catch body missed one: a
     * report written INSIDE the catch (the best-effort use cases) and a report inside the `try` that a catch
     * then guards ({@see \Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger}, whose own report may not
     * become a 5xx). Both are lines on a path that does not end in 5xx, which is the rule.
     */
    private function swallowsAndLogs(string $contents): bool
    {
        return \str_contains($contents, 'LoggerInterface')
            && \str_contains($contents, 'catch (Throwable')
            && \str_contains($contents, '$this->logger->');
    }

    /**
     * A scheduled sweep's report: it logs and never swallows, because nothing on its path throws at it.
     *
     * The absence of `catch (Throwable` is the whole discriminator, and it is enough here — measured, the
     * classes it selects are exactly the sweeps, with no false positive outside {@see NOT_REPORTERS}.
     */
    private function sweepsAndLogs(string $contents): bool
    {
        return \str_contains($contents, 'LoggerInterface')
            && !\str_contains($contents, 'catch (Throwable')
            && \str_contains($contents, '$this->logger->');
    }

    /**
     * @throws RuntimeException
     */
    private function pathOf(string $basename): string
    {
        foreach (ApiSourceFiles::phpFiles() as $file) {
            if (\basename($file->getPathname()) === $basename) {
                return $file->getPathname();
            }
        }

        throw new RuntimeException(\sprintf(
            '"%s" is classified out of this gate and no longer exists in api/src — drop the classification '
            . 'rather than leaving it to describe nothing.',
            $basename,
        ));
    }

    /**
     * Short class names whose `$logger` argument `services.yaml` binds to the observability channel.
     *
     * Parsed as YAML rather than grepped: a commented-out binding reads as live wiring to a byte scan, which
     * is the failure this gate exists to refuse one level up.
     *
     * @throws RuntimeException
     *
     * @return list<string>
     */
    private function classesBoundInServicesConfig(): array
    {
        // `PARSE_CUSTOM_TAGS`, because the file legitimately carries `!tagged_iterator`; without it the
        // parser throws and this gate would fail for a reason that has nothing to do with what it checks.
        $parsed = Yaml::parseFile(self::SERVICES_CONFIG, Yaml::PARSE_CUSTOM_TAGS);

        if (!\is_array($parsed) || !\is_array($parsed['services'] ?? null)) {
            throw new RuntimeException('services.yaml declares no services to read.');
        }

        $bound = [];

        foreach ($parsed['services'] as $id => $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            if (!\is_array($definition['arguments'] ?? null)) {
                continue;
            }

            if (('@' . self::OBSERVABILITY_SERVICE) !== ($definition['arguments']['$logger'] ?? null)) {
                continue;
            }

            $id = (string) $id;
            $bound[] = \substr($id, (int) \strrpos($id, '\\') + 1);
        }

        return $bound;
    }
}
