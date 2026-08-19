<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Monolog;

use DateTimeImmutable;
use Erpify\Iam\Identity\Infrastructure\Security\UserProvider;
use Erpify\Shared\Monitoring\Infrastructure\Monolog\PersonDataRedactionProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use RuntimeException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\JsonLoginAuthenticator;

/**
 * The two live shapes here were captured off the running stack rather than imagined — `username` from an
 * authenticated `GET /api/v1/backoffice/banks` and `token` from the login before it, read back out of the
 * container's stderr — because they are the ones nobody guesses: `token` is an OBJECT the formatter spells
 * out later, and the record before it carries `token_class`, which must survive. **The rest are constructed
 * boundary cases and say so**: `impersonator_username` has no producer on this configuration (no
 * `switch_user` firewall), `received` is a shape only a corrupted session yields, and every row of the
 * untouched-cases provider is a key chosen for what it would break if the rule widened.
 *
 * **Each carrier case is asserted at the deployed formatter, in both directions.** Asserting only that the key
 * became the sentinel is satisfied by a shape carrying nothing worth redacting, which is how a rule keeps a
 * green over a case that could never have leaked; so every case first has to produce the subject's address in
 * the bytes prod's `JsonFormatter` writes, and then not produce it. That positive control is also what covers
 * the bypass a KEY rule has to answer for: `token` is a `TokenInterface`, PHP 8 makes any class declaring
 * `__toString()` a `Stringable`, and the formatter spells the identifier out DOWNSTREAM of every processor —
 * so a rule that inspected strings alone would leave that record reaching `php://stderr` with the address in
 * it, and no assertion over `$processed->context` would notice.
 *
 * **The carrier set fails in two independent directions**, and only one of them is here: a carrier nobody
 * exercises is a rule asserted nowhere, which the reflection gate below refuses. The other — a carrier nobody
 * EMITS, a rule guarding a key the dependency stopped writing — needs vendor sources rather than fabricated
 * records, and is `PersonDataCarrierEmitterGateTest`.
 *
 * @internal
 */
#[CoversClass(PersonDataRedactionProcessor::class)]
final class PersonDataRedactionProcessorTest extends TestCase
{
    private const string SUBJECT_EMAIL = 'capture-subject@erpify.test';

    /**
     * @param array<string, mixed> $context
     */
    #[Test]
    #[DataProvider('provideItRedactsTheCarrierOfAPersonIdentifierCases')]
    public function itRedactsTheCarrierOfAPersonIdentifier(string $carrier, array $context): void
    {
        $record = $this->securityRecord($context);

        $this->assertStringContainsString(
            self::SUBJECT_EMAIL,
            $this->asProdBytes($record),
            'this shape never carried the address, so redacting it proves nothing',
        );

        $processed = (new PersonDataRedactionProcessor())($record);

        $this->assertSame(PersonDataRedactionProcessor::SENTINEL, $processed->context[$carrier] ?? null);
        $this->assertStringNotContainsString(
            self::SUBJECT_EMAIL,
            $this->asProdBytes($processed),
            'the address reaches the sink the prod formatter writes to',
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function provideItRedactsTheCarrierOfAPersonIdentifierCases(): iterable
    {
        yield 'the reload written on every authenticated request' => ['username', [
            'provider' => UserProvider::class,
            'username' => self::SUBJECT_EMAIL,
        ]];
        yield 'a session token the firewall could not use' => ['received', [
            'key' => '_security_main',
            'received' => 'O:74:"Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken":1:{'
                . 's:4:"user";s:' . \strlen(self::SUBJECT_EMAIL) . ':"' . self::SUBJECT_EMAIL . '";}',
        ]];
        yield 'the identity behind an impersonation' => ['impersonator_username', [
            'provider' => UserProvider::class,
            'username' => 'impersonated@erpify.test',
            'impersonator_username' => self::SUBJECT_EMAIL,
        ]];
        // `ContextListener` reaches this branch only when the session did NOT deserialise to a
        // `TokenInterface`, so the value is an object of unknown type — the same formatter bypass `token`
        // has, on the carrier whose docblock claims it.
        yield 'a session value the firewall could not classify' => ['received', [
            'key' => '_security_main',
            'received' => new UsernamePasswordToken(
                new InMemoryUser(self::SUBJECT_EMAIL, null, ['ROLE_ADMIN']),
                'main',
                ['ROLE_ADMIN'],
            ),
        ]];
        yield 'the token a credential exchange authenticated' => ['token', [
            'token' => new UsernamePasswordToken(
                new InMemoryUser(self::SUBJECT_EMAIL, null, ['ROLE_ADMIN']),
                'main',
                ['ROLE_ADMIN'],
            ),
            'authenticator' => JsonLoginAuthenticator::class,
        ]];
    }

    /**
     * The declared set, read back off the class by reflection, against the set the cases exercise: a carrier
     * added to the constant without a case reds here rather than sitting behind a green build, and so does a
     * case naming a carrier the class no longer declares. Reflection rather than a count literal, because a
     * count restated here is a tautology that passes whatever the constant becomes.
     *
     * The set's OTHER obligation — that each carrier is still a key the installed dependency writes — is
     * `PersonDataCarrierEmitterGateTest`, which reads vendor sources and belongs with the architecture gates.
     */
    #[Test]
    public function theDeclaredCarriersAndTheExercisedOnesAgree(): void
    {
        $declared = $this->declaredCarriers();

        $exercised = [];

        foreach (self::provideItRedactsTheCarrierOfAPersonIdentifierCases() as [$carrier, $context]) {
            $this->assertArrayHasKey($carrier, $context, 'a case names a carrier its own context does not carry');
            $exercised[] = $carrier;
        }

        // Deduplicated, so a carrier may be exercised in more than one shape: `received` has two branches
        // that carry different things, and a gate that turned covering both into a red would refuse exactly
        // the coverage it exists to encourage.
        $exercised = \array_values(\array_unique($exercised));
        \sort($exercised);

        $this->assertSame($declared, $exercised, 'the declared carriers and the exercised ones have drifted');
    }

    /**
     * A record whose whole context is gone is useless in an incident, and the throwable is load-bearing beyond
     * diagnosis: `HttpCodeActivationStrategy` reads `context['exception']` to decide whether an excluded status
     * flushes the buffer at all, so a processor that touched it would silently widen what reaches the sink.
     */
    #[Test]
    public function itKeepsEveryDiagnosticSiblingIncludingTheThrowable(): void
    {
        $throwable = new RuntimeException('Basic authentication failed.');

        $processed = (new PersonDataRedactionProcessor())($this->securityRecord([
            'username' => self::SUBJECT_EMAIL,
            'provider' => UserProvider::class,
            'firewall_name' => 'main',
            'exception' => $throwable,
        ]));

        $this->assertSame(
            UserProvider::class,
            $processed->context['provider'] ?? null,
        );
        $this->assertSame('main', $processed->context['firewall_name'] ?? null);
        $this->assertSame($throwable, $processed->context['exception'] ?? null);
        $this->assertSame('User was reloaded from a user provider.', $processed->message);
        $this->assertSame('security', $processed->channel);
        $this->assertSame(Level::Debug, $processed->level);
    }

    /**
     * The boundary, made falsifiable in the direction nothing else watches. Widening the rule to a substring
     * match — the shape {@see \Erpify\Shared\ErrorContract\Application\RedactionDenylist} uses for response
     * bodies — reds on `token_class`; folding `command` or `given` into the carrier set reds on the others;
     * and case-folding the comparison reds on the last. `route_parameters`, the fourth declined key, is
     * `DeclinedRouteParameterCarrierTest`, which owns the argument for declining it as well.
     *
     * @param array<string, mixed> $context
     */
    #[Test]
    #[DataProvider('provideItLeavesARecordCarryingNoIdentityUntouchedCases')]
    public function itLeavesARecordCarryingNoIdentityUntouched(array $context): void
    {
        $record = $this->securityRecord($context);

        $processed = (new PersonDataRedactionProcessor())($record);

        $this->assertSame($context, $processed->context);
        $this->assertSame($record, $processed, 'an untouched record is not worth re-allocating');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideItLeavesARecordCarryingNoIdentityUntouchedCases(): iterable
    {
        yield 'the class of a deserialised token, which names no person' => [[
            'key' => '_security_main',
            'token_class' => UsernamePasswordToken::class,
        ]];
        yield 'the argv of a failed command, which is a disclosure of its own' => [[
            'command' => "iam:invitation:create 'someone@erpify.test'",
            'code' => 1,
        ]];
        // The whole diagnostic of the record it appears in, and no route declares a requirement that could
        // put a person's id there.
        yield 'the parameter a generated URL was refused for' => [[
            'parameter' => 'id',
            'route' => 'backoffice_user_get',
            'expected' => '[^/]++',
            'given' => '',
        ]];
        // Not case-folded, and the fold is what a green here refuses: every deployed emitter spells its
        // carriers in lower case, so folding would buy a spelling nobody writes at a cost paid per record.
        yield 'a carrier spelled in another case, which no emitter writes' => [['Username' => self::SUBJECT_EMAIL]];
        // `HttpBasicAuthenticator` logs `username => $request->headers->get('PHP_AUTH_USER')`, which is null
        // when the request carried no user segment. Stamping the sentinel there would answer "an identifier
        // was here and we hid it" where the diagnostic is "none was sent", and would hide nothing.
        yield 'a carrier the emitter had nothing to put in' => [['username' => null, 'firewall_name' => 'main']];
        yield 'a context with nothing in it' => [[]];
    }

    /**
     * @return list<string>
     */
    private function declaredCarriers(): array
    {
        $declared = (new ReflectionClassConstant(PersonDataRedactionProcessor::class, 'CARRIERS'))->getValue();
        $this->assertIsArray($declared);
        $this->assertNotEmpty($declared, 'the class declares no carrier, so nothing here asserts anything');

        $carriers = [];

        foreach ($declared as $carrier) {
            $this->assertIsString($carrier);
            $carriers[] = $carrier;
        }

        \sort($carriers);

        return $carriers;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function securityRecord(
        array $context,
        string $message = 'User was reloaded from a user provider.',
        Level $level = Level::Debug,
    ): LogRecord {
        return new LogRecord(
            new DateTimeImmutable('2026-08-19T17:09:33+00:00'),
            'security',
            $level,
            $message,
            $context,
        );
    }

    /**
     * The record as the deployed sink receives it: prod's nested handler formats with
     * `monolog.formatter.json`, and that formatter is where a `Stringable` finally becomes text. Its shape is
     * the formatter's own — `JsonFormatter` writes the stringified value directly, where `LineFormatter`
     * (dev's) wraps it in a single-entry map keyed by the class.
     */
    private function asProdBytes(LogRecord $record): string
    {
        return (new JsonFormatter())->format($record);
    }
}
