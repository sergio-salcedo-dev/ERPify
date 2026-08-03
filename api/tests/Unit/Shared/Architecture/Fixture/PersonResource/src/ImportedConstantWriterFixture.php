<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonResource\src;

use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * Writes a resource type it never spells: the literal lives in {@see ImportedConstantHolderFixture}, so this
 * file names the type only through an imported constant.
 *
 * This is the form the sweep was once blind to, and being blind to it is the worst failure the registry can
 * have — an unseen writer means no type in the universe, so no line demanded, so nobody named as obliged to
 * erase it. The fixture keeps that resolution pinned rather than trusted.
 *
 * Nothing executes it; the gate reads source as text.
 *
 * @internal test fixture
 */
final class ImportedConstantWriterFixture
{
    public function write(string $id): AuditResource
    {
        return AuditResource::of(ImportedConstantHolderFixture::IMPORTED_TYPE, $id);
    }
}
