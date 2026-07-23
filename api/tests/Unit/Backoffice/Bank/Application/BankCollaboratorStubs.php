<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Shared\Validation\Application\Validator;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Builds the collaborators a Bank write use case ({@see \Erpify\Backoffice\Bank\Application\BankCreator},
 * {@see \Erpify\Backoffice\Bank\Application\BankUpdater}) coordinates. Shared so each use-case test stays
 * under the PHPMD object-coupling budget rather than re-importing every collaborator itself.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait BankCollaboratorStubs
{
    private function passThroughValidator(): Validator
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($validator);
    }
}
