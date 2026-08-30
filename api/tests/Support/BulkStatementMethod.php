<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * One named method in `api/src` whose body runs an ORM statement, as {@see BulkStatementMethods} reads it.
 *
 * @internal test support
 */
final readonly class BulkStatementMethod
{
    public function __construct(
        public string $file,
        public string $name,
        public string $returnType,
        public bool $narrowsThroughGuard,
    ) {
    }

    /**
     * Whether the method hands the statement's result back to a caller. A `void` method cannot fabricate a
     * count because it returns none — the discriminator is the signature, not a judgement about the body.
     */
    public function yieldsItsResult(): bool
    {
        return 'void' !== $this->returnType && 'never' !== $this->returnType;
    }

    public function describe(): string
    {
        $returnType = '' === $this->returnType ? 'mixed' : $this->returnType;

        return \sprintf('%s::%s(): %s', $this->file, $this->name, $returnType);
    }
}
