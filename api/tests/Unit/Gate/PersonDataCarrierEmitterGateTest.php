<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Monitoring\Infrastructure\Monolog\PersonDataRedactionProcessor;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use Symfony\Component\Security\Http\Authentication\AuthenticatorManager;
use Symfony\Component\Security\Http\Authenticator\HttpBasicAuthenticator;
use Symfony\Component\Security\Http\Firewall\ContextListener;
use Symfony\Component\Security\Http\Firewall\SwitchUserListener;

/**
 * `PersonDataRedactionProcessor::CARRIERS` names four context keys the deployed security stack writes a
 * person's identifier under. Every assertion over a fabricated record proves what the rule DOES with a key;
 * none of them proves the key is one anybody still writes — so a `symfony/security-*` bump that renames one
 * leaves the processor guarding a spelling nothing produces, the new spelling flowing to `php://stderr`, and
 * `PersonDataRedactionProcessorTest` entirely green.
 *
 * This is the other direction, and the only cheap instrument for it: each carrier is matched as a quoted
 * literal in the source of the installed class that emits it, located by reflection rather than by a path so
 * a vendor layout change cannot make the check silently read nothing.
 *
 * **What a green does not prove.** That the emitter still LOGS the key — a literal surviving anywhere in the
 * file satisfies this, including in a comment or an unrelated array. And nothing here sees the direction that
 * matters most: a dependency that starts writing a carrier nobody has declared. That one has no instrument at
 * all and is read by a person at the bump.
 *
 * @internal
 */
#[CoversNothing]
final class PersonDataCarrierEmitterGateTest extends TestCase
{
    /**
     * The installed classes that spell each carrier. `username` lists three because it has three emitters, and
     * a carrier is grounded only while EVERY class named for it still spells it — a list that goes stale in
     * either direction is not evidence.
     *
     * Two of them are installed but DORMANT on this configuration: `security.yaml` wires `json_login` alone,
     * so nothing instantiates `SwitchUserListener` or `HttpBasicAuthenticator`. They are named anyway because
     * the only thing between them and this sink is a firewall option, and a carrier set that has to be
     * revisited the day someone turns one on is a set that is wrong exactly when it matters.
     *
     * @var array<string, list<class-string>>
     */
    private const array EMITTERS = [
        'username' => [ContextListener::class, SwitchUserListener::class, HttpBasicAuthenticator::class],
        'impersonator_username' => [ContextListener::class],
        'token' => [AuthenticatorManager::class],
        'received' => [ContextListener::class],
    ];

    #[Test]
    public function everyDeclaredCarrierNamesTheEmittersThatWriteIt(): void
    {
        $grounded = \array_keys(self::EMITTERS);
        \sort($grounded);

        $this->assertSame(
            $this->declaredCarriers(),
            $grounded,
            'the declared carriers and their named emitters have drifted',
        );
    }

    #[Test]
    public function everyCarrierIsStillSpelledByTheEmittersThisFileNames(): void
    {
        // Driven off the DECLARED carriers rather than off the map, so the map stays subordinate to the
        // constant it grounds — and so the emptiness guard below is a real question. Iterating the map lets
        // PHPStan resolve every value as a literal list and narrow that guard away into a tautology.
        foreach ($this->declaredCarriers() as $carrier) {
            $emitters = self::EMITTERS[$carrier] ?? [];
            $this->assertNotEmpty($emitters, \sprintf('"%s" names no emitter, so nothing grounds it', $carrier));

            foreach ($emitters as $emitter) {
                // The predicate is named rather than inlined into the assertion: a string-containment
                // assertion over a vendor file prints the whole file on failure and buries the one sentence a
                // reader needs.
                $spelled = $this->emitterSpells($emitter, $carrier);

                $this->assertTrue(
                    $spelled,
                    \sprintf('"%s" no longer spells "%s", so it guards a key nobody writes', $emitter, $carrier),
                );
            }
        }
    }

    /**
     * @param class-string $emitter
     */
    private function emitterSpells(string $emitter, string $carrier): bool
    {
        $file = (new ReflectionClass($emitter))->getFileName();
        $this->assertIsString($file);

        $source = \file_get_contents($file);
        $this->assertIsString($source);

        return \str_contains($source, \sprintf("'%s'", $carrier));
    }

    /**
     * Reflection rather than a list restated here, which would be a tautology that passes whatever the
     * constant becomes.
     *
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
}
