<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Profiler;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Pins that the Symfony Profiler is wired in the test environment so functional
 * tests can assert on collected data. The profiler is dev+test only; this guards
 * the test half of that decision (web_profiler.yaml `when@test`).
 *
 * @internal
 */
#[CoversNothing]
final class ProfilerEnabledFunctionalTest extends WebTestCase
{
    public function testProfilerIsEnabledAndRegistersCoreCollectors(): void
    {
        $client = self::createClient();
        $client->enableProfiler();

        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/api/v1/backoffice/health',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        $this->assertInstanceOf(Profile::class, $profile, 'The profiler must be enabled in the test environment.');
        $this->assertTrue($profile->hasCollector('db'), 'The Doctrine (db) collector must be registered.');
        $this->assertTrue($profile->hasCollector('time'), 'The time collector must be registered.');
    }
}
