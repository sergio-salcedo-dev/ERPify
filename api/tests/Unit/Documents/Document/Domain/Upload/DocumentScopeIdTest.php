<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain\Upload;

use Erpify\Documents\Document\Domain\Upload\DocumentScopeId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DocumentScopeId::class)]
final class DocumentScopeIdTest extends TestCase
{
    public function testAScopeIdIsARandomVersion4UuidThatCarriesNoTime(): void
    {
        $id = DocumentScopeId::mint();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
        $this->assertNotSame($id, DocumentScopeId::mint());
    }
}
