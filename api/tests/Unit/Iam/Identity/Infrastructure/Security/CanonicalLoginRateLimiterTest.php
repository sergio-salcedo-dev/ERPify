<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\CanonicalLoginRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * @internal
 */
#[CoversClass(CanonicalLoginRateLimiter::class)]
final class CanonicalLoginRateLimiterTest extends TestCase
{
    private const string IP = '203.0.113.7';

    /**
     * Each spelling resolves to the identity `nora@erpify.test` through `Email`, so each must draw on the one
     * per-mailbox budget instead of opening its own.
     *
     * @return iterable<string, array{string}>
     */
    public static function spellingsOfOneMailbox(): iterable
    {
        yield 'upper case' => ['NORA@ERPIFY.TEST'];
        yield 'mixed case' => ['Nora@Erpify.Test'];
        yield 'leading space' => ['  nora@erpify.test'];
        yield 'trailing space' => ["nora@erpify.test \t"];
        yield 'no-break space' => ["\u{00A0}nora@erpify.test"];
        yield 'zero-width space' => ["nora@erpify.test\u{200B}"];
        yield 'byte order mark' => ["\u{FEFF}nora@erpify.test"];
    }

    #[DataProvider('spellingsOfOneMailbox')]
    public function testEverySpellingOfOneMailboxSharesTheSameBudget(string $spelling): void
    {
        $limiter = $this->limiter(localLimit: 2);

        $this->assertTrue($limiter->consume($this->request('nora@erpify.test'))->isAccepted());
        $this->assertTrue($limiter->consume($this->request($spelling))->isAccepted());
        $this->assertFalse(
            $limiter->consume($this->request('nora@erpify.test'))->isAccepted(),
            'a third attempt on the same mailbox must be refused whatever the spelling',
        );
    }

    public function testDifferentMailboxesFromOneAddressKeepSeparateLocalBudgets(): void
    {
        $limiter = $this->limiter(localLimit: 1);

        $this->assertTrue($limiter->consume($this->request('nora@erpify.test'))->isAccepted());
        $this->assertTrue($limiter->consume($this->request('omar@erpify.test'))->isAccepted());
        $this->assertFalse($limiter->consume($this->request('nora@erpify.test'))->isAccepted());
    }

    public function testTheSameMailboxFromAnotherAddressKeepsItsOwnBudget(): void
    {
        $limiter = $this->limiter(localLimit: 1);

        $this->assertTrue($limiter->consume($this->request('nora@erpify.test'))->isAccepted());
        $this->assertTrue($limiter->consume($this->request('nora@erpify.test', '198.51.100.9'))->isAccepted());
    }

    public function testAClientAddressIsBoundedAcrossMailboxesByTheGlobalBudget(): void
    {
        $limiter = $this->limiter(localLimit: 5, globalLimit: 2);

        $this->assertTrue($limiter->consume($this->request('a@erpify.test'))->isAccepted());
        $this->assertTrue($limiter->consume($this->request('b@erpify.test'))->isAccepted());
        $this->assertFalse($limiter->consume($this->request('c@erpify.test'))->isAccepted());
    }

    public function testASubmissionThatIsNotAnAddressStillSpendsABudget(): void
    {
        $limiter = $this->limiter(localLimit: 1);

        $this->assertTrue($limiter->consume($this->request('not an address'))->isAccepted());
        $this->assertFalse($limiter->consume($this->request('NOT AN ADDRESS '))->isAccepted());
    }

    public function testAnAbsentUsernameSpendsTheSharedEmptyBudgetInsteadOfFailing(): void
    {
        $limiter = $this->limiter(localLimit: 1);
        $request = Request::create('/api/v1/backoffice/login', 'POST', server: ['REMOTE_ADDR' => self::IP]);

        $this->assertTrue($limiter->consume($request)->isAccepted());
        $this->assertFalse($limiter->consume($request)->isAccepted());
    }

    public function testResetClearsTheMailboxBudgetButKeepsTheClientAddressBudget(): void
    {
        $limiter = $this->limiter(localLimit: 1, globalLimit: 2);
        $request = $this->request('nora@erpify.test');

        $this->assertTrue($limiter->consume($request)->isAccepted());
        $limiter->reset($request);

        $this->assertTrue($limiter->consume($request)->isAccepted(), 'the mailbox budget was cleared');
        $this->assertFalse(
            $limiter->consume($this->request('omar@erpify.test'))->isAccepted(),
            'the per-address budget must survive a successful login of one mailbox',
        );
    }

    public function testNeitherTheMailboxNorTheAddressAppearsInAnyStoredKey(): void
    {
        $storage = new InMemoryStorage();
        $limiter = $this->limiter(localLimit: 1, storage: $storage);

        $limiter->consume($this->request('nora@erpify.test'));

        $stored = \serialize((new \ReflectionProperty($storage, 'buckets'))->getValue($storage));
        $this->assertStringNotContainsString('nora', $stored);
        $this->assertStringNotContainsString(self::IP, $stored);
    }

    private function request(string $username, string $ip = self::IP): Request
    {
        $request = Request::create('/api/v1/backoffice/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        return $request;
    }

    private function limiter(
        int $localLimit,
        int $globalLimit = 100,
        ?InMemoryStorage $storage = null,
    ): CanonicalLoginRateLimiter {
        $storage ??= new InMemoryStorage();

        return new CanonicalLoginRateLimiter(
            new RateLimiterFactory(
                ['id' => 'login_global', 'policy' => 'fixed_window', 'limit' => $globalLimit, 'interval' => '1 minute'],
                $storage,
            ),
            new RateLimiterFactory(
                ['id' => 'login_local', 'policy' => 'fixed_window', 'limit' => $localLimit, 'interval' => '1 minute'],
                $storage,
            ),
            'test-secret',
        );
    }
}
