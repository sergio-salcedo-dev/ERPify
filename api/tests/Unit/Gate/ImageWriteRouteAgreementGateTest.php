<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Http\Infrastructure\Upload\RawBodyRoute;
use Erpify\Tests\Support\ImageRouteAgreement;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The router and the Caddyfile's `@imageWrite` matcher say the same thing about which `PUT` is an image write, in BOTH
 * directions, read from the router's own inventory (`api/.route-manifest.json`) and from each route's source:
 *
 *  - a `PUT` the matcher admits must carry `RawBodyRoute::ATTRIBUTE`, or the plaintext spool of its body is never
 *    destroyed;
 *  - a `PUT` that carries the attribute or reaches `ImageAttachment` must be admitted by the matcher, or the edge cuts
 *    its body at 1MB and the application stores a truncated image — which can still decode.
 *
 * It reads `#[Route]` attributes and constructor types, never a route's name or how its path ends, so a consumer that
 * spells its path differently from the three planned ones is judged all the same. {@see ImageRouteAgreement} is the
 * engine; the sweep and the checker are each exercised below, so a red can be provoked in both.
 *
 * What a green does not prove: that a route reaches `ImageAttachment` through a service locator, a callable or a
 * property it does not take by constructor type (the walk follows constructor types of application classes only),
 * that the matcher's cookie requirement or byte bound is right (`UploadRoutePathAgreementGateTest`), or that a body
 * over 1MB reaches PHP whole through the running Caddy (`BankLogoThroughCaddyTest`).
 *
 * @internal
 *
 * @phpstan-import-type RouteFacts from ImageRouteAgreement
 */
#[CoversNothing]
final class ImageWriteRouteAgreementGateTest extends TestCase
{
    private const string LIFTED = '/api/v1/backoffice/banks/{id}/logo';

    private const string NOT_LIFTED = '/api/v1/backoffice/banks/{id}/emblem';

    #[Test]
    public function theRouterAndTheImageMatcherAgreeInBothDirections(): void
    {
        $pattern = ImageRouteAgreement::imageWritePattern();
        $problems = ImageRouteAgreement::problems(ImageRouteAgreement::facts(), $pattern);

        $this->assertSame([], $problems);
    }

    /**
     * Anti-vacuity for the sweep above: it reaches the bank logo routes, sees the attribute on the `PUT` and not on the
     * `DELETE`, and sees that both reach the attachment service through their use case — so a reflection that silently
     * found nothing cannot read as agreement.
     */
    #[Test]
    public function theSweepReachesTheBankLogoRoutesAndSeesWhatTheyCarry(): void
    {
        $facts = ImageRouteAgreement::facts();

        $set = $facts['backoffice_bank_logo_set'] ?? $this->fail('the bank logo write route left the sweep');
        $this->assertTrue($set['carriesRawBody']);
        $this->assertTrue($set['reachesImageAttachment']);
        $this->assertSame(['PUT'], $set['methods']);

        $remove = $facts['backoffice_bank_logo_remove'] ?? $this->fail('the bank logo delete route left the sweep');
        $this->assertFalse($remove['carriesRawBody'], 'a DELETE has no body to discard');
        $this->assertTrue($remove['reachesImageAttachment']);

        $this->assertGreaterThan(40, \count($facts), 'the sweep reached the router inventory');
    }

    /**
     * The checker over planted routes, so every red it can produce is provoked: a `PUT` the matcher admits with no
     * attribute, a `PUT` carrying the attribute that the matcher misses, and a `PUT` reaching `ImageAttachment`
     * that the matcher misses — the truncated-image case. A `DELETE` reaching the attachment, an unrelated `PUT` and a
     * route that agrees are the negatives.
     */
    #[Test]
    public function theAgreementCheckReddensEachWayTheTwoCanDisagree(): void
    {
        $pattern = ImageRouteAgreement::imageWritePattern();

        $agrees = ['agrees' => $this->route(self::LIFTED, ['PUT'], true, true)];
        $this->assertSame([], ImageRouteAgreement::problems($agrees, $pattern));

        // Whatever the placeholder is called, it is an id in the public path the edge sees.
        $renamed = ['renamed' => $this->route('/api/v1/backoffice/banks/{bankId}/logo', ['PUT'], true, true)];
        $this->assertSame([], ImageRouteAgreement::problems($renamed, $pattern));
        $this->assertSame([], ImageRouteAgreement::problems(
            ['remove' => $this->route(self::NOT_LIFTED, ['DELETE'], false, true)],
            $pattern,
        ), 'a DELETE has no body, so the matcher (which names PUT) owes it nothing');
        $this->assertSame([], ImageRouteAgreement::problems(
            ['unrelated' => $this->route('/api/v1/backoffice/banks/{id}', ['PUT'], false, false)],
            $pattern,
        ));

        $bare = ImageRouteAgreement::problems(['bare' => $this->route(self::LIFTED, ['PUT'], false, true)], $pattern);
        $this->assertCount(1, $bare);
        $this->assertStringContainsString('bare', $bare[0]);
        $this->assertStringContainsString('carries no ' . RawBodyRoute::ATTRIBUTE, $bare[0]);

        $spooled = ImageRouteAgreement::problems(
            ['spooled' => $this->route(self::NOT_LIFTED, ['PUT'], true, false)],
            $pattern,
        );
        $this->assertCount(1, $spooled);
        $this->assertStringContainsString('spooled', $spooled[0]);

        $truncating = ImageRouteAgreement::problems(
            ['truncating' => $this->route(self::NOT_LIFTED, ['PUT'], false, true)],
            $pattern,
        );
        $this->assertCount(1, $truncating);
        $this->assertStringContainsString('truncating', $truncating[0]);
        $this->assertStringContainsString('truncated image', $truncating[0]);
    }

    /**
     * @param list<string> $methods
     *
     * @return RouteFacts
     */
    private function route(string $path, array $methods, bool $attribute, bool $attachment): array
    {
        return [
            'path' => $path,
            'methods' => $methods,
            'carriesRawBody' => $attribute,
            'reachesImageAttachment' => $attachment,
        ];
    }
}
