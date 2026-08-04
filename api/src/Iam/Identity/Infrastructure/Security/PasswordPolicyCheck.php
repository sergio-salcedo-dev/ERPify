<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Shared\Validation\Application\Validator;
use Erpify\Shared\Validation\Infrastructure\PasswordPolicy;
use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Weighs {@see PasswordPolicy} against a plaintext credential outside the HTTP mapping layer, and answers
 * with messages rather than an exception.
 *
 * The three HTTP surfaces get the policy for free: `#[StrictRequestPayload]` runs the constraints while
 * mapping the body and the pipeline turns violations into 422 `validation-failed`. A console command has
 * no such edge, so without this it would have to assemble the constraint list, catch the framework's
 * validation exception and unwrap its violations itself — console I/O and validation plumbing in one
 * class, for a rule it does not own.
 */
final readonly class PasswordPolicyCheck
{
    public function __construct(private Validator $validator)
    {
    }

    /**
     * @return list<string> the violation messages, empty when the credential satisfies the policy
     */
    public function violationsFor(
        #[SensitiveParameter]
        string $plainPassword,
    ): array {
        try {
            $this->validator->ensure(
                $plainPassword,
                [new Assert\NotBlank(message: 'The password must not be empty.'), new PasswordPolicy()],
            );
        } catch (ValidationFailedException $validationFailedException) {
            $messages = [];

            foreach ($validationFailedException->getViolations() as $violation) {
                $messages[] = (string) $violation->getMessage();
            }

            return $messages;
        }

        return [];
    }
}
