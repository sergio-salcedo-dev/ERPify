<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure\Controller;

use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Infrastructure\Controller\ImageGetController;
use Erpify\Shared\Images\Infrastructure\Http\HttpCacheValidator;
use Erpify\Tests\Unit\Shared\Images\Application\Double\CountingImageStorage;
use Erpify\Tests\Unit\Shared\Images\Application\ImageFinderHarness;
use Erpify\Tests\Unit\Shared\Images\Application\InMemoryImageRepository;
use Erpify\Tests\Unit\Shared\Images\Application\InMemoryImageStorage;
use Erpify\Tests\Unit\Shared\Images\Infrastructure\Http\ImageThrottles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read budget is spent BEFORE anything is looked up, a `304` included: a conditional request costs the same
 * verified read as a plain one, so it is the one a revalidation loop would send.
 *
 * @internal
 */
#[CoversClass(ImageGetController::class)]
final class ImageGetReadBudgetTest extends TestCase
{
    private const string BYTES = 'the canonical bytes';

    public function testASpentBudgetRefusesBeforeTheLookupAndBeforeTheConditionalCheck(): void
    {
        $storage = new CountingImageStorage(new InMemoryImageStorage());
        $repository = new InMemoryImageRepository();
        $digest = \hash('sha256', self::BYTES);
        $image = new Image(ImageId::generate(), $digest, 'image/webp', 10, 10, \strlen(self::BYTES));
        $repository->save($image);
        $storage->store($image->id(), self::BYTES);
        $controller = new ImageGetController(
            ImageFinderHarness::finder($repository, $storage),
            new HttpCacheValidator(),
            ImageThrottles::withBudgets(1_000, 1),
        );

        $first = $controller(new Request(), $image->id()->toString());
        $this->assertSame(Response::HTTP_OK, $first->getStatusCode(), (string) $first->getContent());
        $this->assertSame(1, $storage->reads);

        foreach ([new Request(), $this->matching($first)] as $refused) {
            try {
                $controller($refused, $image->id()->toString());
                $this->fail('the budget of one is spent');
            } catch (RateLimitExceeded) {
                $this->assertSame(1, $storage->reads, 'a refused request must not reach the read');
            }
        }
    }

    private function matching(Response $response): Request
    {
        $request = new Request();
        $request->headers->set('If-None-Match', (string) $response->headers->get('ETag'));

        return $request;
    }
}
