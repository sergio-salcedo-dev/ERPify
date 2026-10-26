<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

/**
 * Session fixation, asserted over the wire rather than over the framework's own call: a flow that signs a
 * device in has to answer with a session id the browser did not bring, the id it issued has to admit, and the
 * id the browser brought has to admit nobody afterwards.
 *
 * All three are needed. A new id alone is satisfied by a framework that issues a second session while the
 * first still carries the token — the attacker who fixed the old id then shares the victim's login — and by a
 * re-login that failed and was swallowed, since the fail-closed path drops the native session and regenerates
 * its id. A dead old id alone is satisfied by a flow that signs nobody in. Reading both ids back through
 * `GET /me` is what joins them: the old one is the request the attacker would make.
 *
 * The id that matters differs by flow. An anonymous flow (accept an invitation, reset a password, redeem a
 * recovery secret) is fixed by planting an id the attacker chose — {@see plantSessionId()}. A flow run from a
 * device already signed in (`POST /login` again, change my password) re-authenticates the session in hand, so
 * the id to retire is the one the caller holds — {@see sessionIdHeldBy()}.
 */
trait AssertsSessionIdRotation
{
    private const string ME_PATH = '/api/v1/me';

    /**
     * Seats an attacker-chosen session id in the browser, so the next request runs on it. The id is first
     * written to the session store, as an attacker who obtained it from the application would hold it: an id
     * the store has never seen could be refused or replaced for that reason alone, and the rotation would then
     * be credited to the wrong mechanism.
     */
    private function plantSessionId(KernelBrowser $client): string
    {
        $planted = \bin2hex(\random_bytes(32));

        $factory = $client->getContainer()->get('session.factory');
        $this->assertInstanceOf(SessionFactoryInterface::class, $factory);
        $session = $factory->createSession();
        $session->setId($planted);
        $session->start();
        $session->set('planted', true);
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $planted));

        return $planted;
    }

    private function sessionIdHeldBy(KernelBrowser $client): string
    {
        $cookie = $client->getCookieJar()->get($this->sessionCookieName($client));
        $this->assertInstanceOf(Cookie::class, $cookie, 'the browser holds no session to rotate');

        return $cookie->getValue();
    }

    /**
     * MUST run right after the flow's response, and leaves the browser holding only the fixed id.
     */
    private function assertSessionIdRotatedAwayFrom(KernelBrowser $client, string $fixed): void
    {
        $name = $this->sessionCookieName($client);
        $issued = null;

        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($name === $cookie->getName()) {
                $issued = $cookie->getValue();
            }
        }

        $this->assertNotNull($issued, 'the flow signed the device in without issuing a session cookie');
        $this->assertNotSame($fixed, $issued, 'the flow kept the session id the browser brought');

        // A re-login that failed and was swallowed also hands back a fresh id — the fail-closed path drops the
        // native session — so the issued id has to be shown to admit before its rotation counts as one.
        $this->assertSame(
            Response::HTTP_OK,
            $this->meStatusOn($client, $name, $issued),
            'the issued session id does not admit, so the flow signed nobody in: '
            . $client->getResponse()->getContent(),
        );
        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->meStatusOn($client, $name, $fixed),
            'the session id the browser brought still admits whoever holds it: '
            . $client->getResponse()->getContent(),
        );
    }

    private function meStatusOn(KernelBrowser $client, string $name, string $sessionId): int
    {
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set(new Cookie($name, $sessionId));
        $client->request(Request::METHOD_GET, self::ME_PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        return $client->getResponse()->getStatusCode();
    }

    private function sessionCookieName(KernelBrowser $client): string
    {
        $factory = $client->getContainer()->get('session.factory');
        $this->assertInstanceOf(SessionFactoryInterface::class, $factory);

        return $factory->createSession()->getName();
    }
}
