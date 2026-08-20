<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use Erpify\Shared\Monitoring\Infrastructure\Monolog\PersonDataRedactionProcessor;
use Erpify\Tests\Functional\EnumeratesChannelLoggers;
use Erpify\Tests\Functional\SeedsAnOrganizationMember;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authenticator\JsonLoginAuthenticator;

/**
 * The records a real session actually produces, read off the loggers the application enrols, and asked
 * whether the subject's address survives into the bytes the deployed formatter writes.
 *
 * A unit test over the processor can only prove the rule; it cannot prove the rule is REACHED, and nothing
 * else here observes enrolment. With the processor unenrolled the login record reaches the formatter with the
 * address inside its stringified token, and this class is the only thing that reds.
 *
 * **Unenrolling it takes BOTH mechanisms, which is why neither is asserted on its own.** The explicit
 * `monolog.processor` tag in `services.yaml` and `MonologExtension`'s
 * `registerForAutoconfiguration(ProcessorInterface::class)` each enrol the class independently: measured
 * against the compiled prod container over a purged cache, tag + autoconfiguration gives 14 `pushProcessor`
 * sites, the tag with `autoconfigure: false` gives 14, no definition at all gives 14, and only removing the
 * tag AND setting `autoconfigure: false` gives 0. Reading either declaration would therefore answer for
 * neither; the loggers are what can say.
 *
 * Driving it through HTTP is also the only way the two carriers appear at all: `AuthenticatorManager` writes
 * its token on a real credential exchange, and `ContextListener` writes its `username` only when a LATER
 * request reloads the identity out of the session.
 *
 * **The channel filter is a denylist, and that is the point.** An allowlist would discard the finding worth
 * having — a carrier arriving on a channel nobody expected — in silence, so every channel is asserted over
 * unless it is argued OUT here. One is: `doctrine` logs each statement's bound parameters, so the login
 * `SELECT … WHERE t0.email = ?` carries the address under a NESTED `params` key no rule here touches. That
 * middleware is bound to `%kernel.debug%` — off in prod, on here — so it is a property of this environment
 * and not of the sink this change is about; it is recorded in `PRODUCTION_SECURITY_CHECKLIST.md` rather than
 * closed by widening a key rule into a tree walk.
 *
 * What a green proves: the rule is on EVERY channel logger this container builds and therefore ahead of every
 * handler, neither carrier reaches the formatter on a real session, the record that carried one still carries
 * its diagnostic siblings, and the class is not conditioned into or out of any environment — which is what
 * lets a test container answer for the prod one at all.
 *
 * What it does not prove: that the prod container was compiled (`php.lint.prod-container` does that, and its
 * enrolment count is a reading rather than a gate); that the rule runs FIRST, since a processor pushed after
 * it runs before it; or anything about a record produced by a path no request here takes — notably an
 * exception message, which HttpKernel's `ErrorListener` composes before Monolog exists.
 *
 * @internal
 */
#[CoversNothing]
final class PersonDataRedactionArrivalTest extends WebTestCase
{
    use EnumeratesChannelLoggers;
    use SeedsAnOrganizationMember;

    private const string LOGIN_ROUTE = '/api/v1/backoffice/login';

    private const string ME_ROUTE = '/api/v1/me';

    /** The login CSRF guard compares against the request's own origin; a foreign one never authenticates. */
    private const string ALLOWED_ORIGIN = 'http://localhost';

    /** Named so a reader chasing a row a crashed run left in the shared functional database can attribute it. */
    private const string SUBJECT_EMAIL = 'redaction-arrival@erpify.test';

    private const string SUBJECT_ID = '01a01aff-87d3-7902-a5f3-c986d20d7f11';

    private const string SUBJECT_PASSWORD = 'redaction-arrival-password';

    /** Argued out of the sweep above, by name rather than by leaving the assertion narrow. */
    private const array EXCLUDED_CHANNELS = ['doctrine'];

    /** The two channels the carriers travel on; their absence would make the sweep prove nothing. */
    private const array MANDATORY_CHANNEL_IDS = ['monolog.logger.security', 'monolog.logger.request'];

    /** @var array<string, TestHandler> */
    private array $sinks = [];

    /** @var array<string, Logger> */
    private array $sunkLoggers = [];

    #[Test]
    public function noRecordOfAnAuthenticatedSessionCarriesTheSubjectsAddress(): void
    {
        $client = self::createClient();
        // The container the requests resolve from has to be the one the sinks were attached to.
        $client->disableReboot();

        $this->seedAnOrganizationMember(self::SUBJECT_ID, self::SUBJECT_EMAIL, self::SUBJECT_PASSWORD);
        $this->attachASinkToEveryChannelLogger();

        $this->login($client);
        $this->readOwnIdentity($client);

        $records = $this->sweptRecords();

        // Without the controls the emptiness below is satisfied by a request that logged nothing at all —
        // and it is these two records, not the count, that the change exists for.
        $token = $this->theRecordSaying($records, 'Authenticator successful!');
        $this->assertInstanceOf(
            LogRecord::class,
            $token,
            'the credential exchange never reached the logger, so nothing here observed its token',
        );
        $this->assertInstanceOf(
            LogRecord::class,
            $this->theRecordSaying($records, 'User was reloaded from a user provider.'),
            'no request reloaded the identity from the session, so the per-request carrier never appeared',
        );

        // Absence alone is satisfied by a processor that blanked the whole context; the record has to still be
        // worth reading in an incident, and this is the sibling an operator identifies the failure by.
        $this->assertSame(
            JsonLoginAuthenticator::class,
            $token->context['authenticator'] ?? null,
            'redaction took the diagnostic with it',
        );

        $formatter = new JsonFormatter();

        foreach ($records as $record) {
            $this->assertStringNotContainsString(
                self::SUBJECT_EMAIL,
                $formatter->format($record),
                \sprintf(
                    'a %s record ("%s") carries the subject address into the unrotated sink',
                    $record->channel,
                    $record->message,
                ),
            );
        }
    }

    /**
     * The behavioural assertion above proves the rule was reached on the channels ONE session happened to
     * write on; every other channel is green there by having logged nothing. This is the complement: the rule
     * is on the logger — the object every handler hangs off — for every channel the container builds, so a
     * carrier arriving on a channel no request here exercises still meets it.
     *
     * Read off `Logger::getProcessors()` and not off the tag, because the tag is only one of the two
     * mechanisms that enrol this: `MonologExtension` autoconfigures `ProcessorInterface` as well, and either
     * one alone still enrols it. Reading the tag would therefore answer for neither — what unenrols the rule
     * is losing BOTH, and only the loggers can say whether that happened.
     *
     * It asserts PRESENCE, never POSITION. `Logger::pushProcessor` is an `array_unshift`, so a processor
     * enrolled after this one runs BEFORE it and sees the record unredacted — which is what dev's
     * `DebugProcessor` does, into the profiler. That is outside the deployed sink and undefended.
     */
    #[Test]
    public function everyChannelLoggerCarriesTheRuleAheadOfItsHandlers(): void
    {
        self::createClient();

        $loggers = $this->channelLoggers();
        $this->assertTheMandatoryChannelsArePresent(\array_keys($loggers));

        foreach ($loggers as $id => $logger) {
            $enrolled = \array_filter(
                $logger->getProcessors(),
                static fn (callable $processor): bool => $processor instanceof PersonDataRedactionProcessor,
            );

            $this->assertCount(
                1,
                $enrolled,
                \sprintf('"%s" does not carry the redaction rule ahead of its handlers', $id),
            );
        }
    }

    /**
     * The behavioural and structural assertions above both read the TEST container, and what lets it stand in
     * for prod is that the enrolment is environment-free. An attribute conditioning the class into or out of
     * a named environment would leave every assertion here green while removing the rule from production —
     * the failure mode `CLAUDE.md` records under declaring a class out of production. Both attributes,
     * because `WhenNot` does not extend `When`.
     */
    #[Test]
    public function theRuleIsNotConditionedIntoOrOutOfAnyEnvironment(): void
    {
        $reflection = new ReflectionClass(PersonDataRedactionProcessor::class);

        $this->assertSame(
            [],
            $reflection->getAttributes(When::class, ReflectionAttribute::IS_INSTANCEOF),
            'the rule is conditioned into named environments, so production may not have it',
        );
        $this->assertSame(
            [],
            $reflection->getAttributes(WhenNot::class, ReflectionAttribute::IS_INSTANCEOF),
            'the rule is conditioned out of a named environment, so production may not have it',
        );
    }

    protected function tearDown(): void
    {
        // The loggers are the container's, shared with whatever runs next in this kernel.
        foreach ($this->sunkLoggers as $sunkLogger) {
            $sunkLogger->popHandler();
        }

        $this->sunkLoggers = [];
        $this->sinks = [];

        // The subject carries a known plaintext credential; this suite shares one database with no rollback,
        // so leaving it behind would leave a usable login in it.
        self::bootKernel();
        $this->forgetTheOrganizationMember(self::SUBJECT_ID, self::SUBJECT_EMAIL);

        parent::tearDown();
    }

    private function login(KernelBrowser $client): void
    {
        $client->request(
            Request::METHOD_POST,
            self::LOGIN_ROUTE,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => self::ALLOWED_ORIGIN],
            content: \json_encode(
                ['email' => self::SUBJECT_EMAIL, 'password' => self::SUBJECT_PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );

        $this->assertSame(
            Response::HTTP_NO_CONTENT,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * The second request is what makes `ContextListener` reload the identity out of the session — the carrier
     * every authenticated request writes, and the one the login request cannot produce.
     */
    private function readOwnIdentity(KernelBrowser $client): void
    {
        $client->request(Request::METHOD_GET, self::ME_ROUTE);

        $this->assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
    }

    private function attachASinkToEveryChannelLogger(): void
    {
        foreach ($this->channelLoggers() as $id => $logger) {
            $sink = new TestHandler();
            $logger->pushHandler($sink);
            $this->sinks[$id] = $sink;
            $this->sunkLoggers[$id] = $logger;
        }

        $this->assertTheMandatoryChannelsArePresent(\array_keys($this->sinks));
    }

    /**
     * `assertNotEmpty` would be satisfied by one logger of the seventeen this container builds, and the two
     * that carry the identifiers are among the twelve MonologBundle keeps private — only the five named
     * under `monolog.channels` are public.
     *
     * @param list<string> $ids
     */
    private function assertTheMandatoryChannelsArePresent(array $ids): void
    {
        foreach (self::MANDATORY_CHANNEL_IDS as $mandatory) {
            $this->assertContains(
                $mandatory,
                $ids,
                \sprintf('"%s" was not reached, so nothing here asserts anything about it', $mandatory),
            );
        }
    }

    /**
     * @return list<LogRecord>
     */
    private function sweptRecords(): array
    {
        $records = [];

        foreach ($this->sinks as $sink) {
            foreach ($sink->getRecords() as $record) {
                if (!\in_array($record->channel, self::EXCLUDED_CHANNELS, true)) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    /**
     * @param list<LogRecord> $records
     */
    private function theRecordSaying(array $records, string $message): ?LogRecord
    {
        return \array_find($records, static fn (LogRecord $record): bool => $record->message === $message);
    }
}
