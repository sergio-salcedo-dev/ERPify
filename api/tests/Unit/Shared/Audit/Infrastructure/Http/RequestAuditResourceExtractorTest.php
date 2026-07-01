<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Http;

use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Http\RequestAuditResourceExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[CoversClass(RequestAuditResourceExtractor::class)]
final class RequestAuditResourceExtractorTest extends TestCase
{
    private const string RESOURCE_ID = '0190f5d1-2222-7000-8000-000000000001';

    public function testItResolvesTheResourceTheRouteDeclaresWithItsIdParameter(): void
    {
        $resource = (new RequestAuditResourceExtractor())->extract(
            $this->request(['_audit_resource_type' => 'Bank', 'id' => self::RESOURCE_ID]),
        );

        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame('Bank', $resource->type);
        $this->assertSame(self::RESOURCE_ID, $resource->id);
    }

    public function testItYieldsNoResourceWhenTheRouteDeclaresNoType(): void
    {
        $resource = (new RequestAuditResourceExtractor())->extract($this->request(['id' => self::RESOURCE_ID]));

        $this->assertNotInstanceOf(
            AuditResource::class,
            $resource,
            'a route that declares no resource type is not record-scoped',
        );
    }

    public function testItYieldsNoResourceForACollectionRouteWithoutAnId(): void
    {
        $resource = (new RequestAuditResourceExtractor())->extract($this->request(['_audit_resource_type' => 'Bank']));

        $this->assertNotInstanceOf(
            AuditResource::class,
            $resource,
            'a list view touches no single record, so it seals no resource id',
        );
    }

    public function testItYieldsNoResourceWhenTheIdIsNotAUuid(): void
    {
        $resource = (new RequestAuditResourceExtractor())->extract(
            $this->request(['_audit_resource_type' => 'Bank', 'id' => 'not-a-uuid']),
        );

        $this->assertNotInstanceOf(
            AuditResource::class,
            $resource,
            'a non-uuid path id is no audit-keyed resource; the terminate-time log must degrade, not throw',
        );
    }

    /**
     * @param array<string, string> $attributes
     */
    private function request(array $attributes): Request
    {
        $request = Request::create('/api/v1/backoffice/banks/' . self::RESOURCE_ID);
        $request->attributes->add($attributes);

        return $request;
    }
}
