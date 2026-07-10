<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Security\SymfonySessionCorrelationStore;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * @internal
 */
#[CoversClass(SymfonySessionCorrelationStore::class)]
final class SymfonySessionCorrelationStoreTest extends TestCase
{
    private const string BAG_KEY = 'iamSessionId';

    public function testGetReturnsNullWhenThereIsNoRequest(): void
    {
        $store = new SymfonySessionCorrelationStore(new RequestStack());

        $this->assertNotInstanceOf(SessionId::class, $store->get());
    }

    public function testGetReturnsNullWhenTheBagHasNoCorrelation(): void
    {
        $requestStack = $this->requestStackWithSession(new Session(new MockArraySessionStorage()));
        $store = new SymfonySessionCorrelationStore($requestStack);

        $this->assertNotInstanceOf(SessionId::class, $store->get());
    }

    public function testGetReturnsNullWhenTheStoredCorrelationIsMalformed(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(self::BAG_KEY, 'not-a-uuid');

        $store = new SymfonySessionCorrelationStore($this->requestStackWithSession($session));

        $this->assertNotInstanceOf(SessionId::class, $store->get());
    }

    public function testGetReturnsTheStoredSessionId(): void
    {
        $value = Uuid::generate();
        $session = new Session(new MockArraySessionStorage());
        $session->set(self::BAG_KEY, $value);

        $store = new SymfonySessionCorrelationStore($this->requestStackWithSession($session));
        $reference = $store->get();

        $this->assertInstanceOf(SessionId::class, $reference);
        $this->assertSame($value, $reference->toString());
    }

    public function testSetWritesTheCorrelationIntoTheBag(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $sessionId = SessionId::generate();

        $store = new SymfonySessionCorrelationStore($this->requestStackWithSession($session));
        $store->set($sessionId);

        $this->assertSame($sessionId->toString(), $session->get(self::BAG_KEY));
    }

    private function requestStackWithSession(Session $session): RequestStack
    {
        $request = Request::create('/api/v1/me');
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }
}
