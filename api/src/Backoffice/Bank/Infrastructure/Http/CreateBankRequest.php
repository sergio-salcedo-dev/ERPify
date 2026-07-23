<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Http;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wire payload for {@see \Erpify\Backoffice\Bank\Infrastructure\Controller\BankPostController}, mapped by
 * `#[StrictRequestPayload]` and translated by the controller into a
 * {@see \Erpify\Backoffice\Bank\Application\Command\CreateBankCommand} plus its uploads.
 *
 * The file parts are declared here because the payload resolver merges `$request->files` into the body before
 * denormalizing. Under a payload closed to undeclared members, an undeclared part is surplus — so a multipart
 * create is only accepted when the wire contract names the parts it accepts. Declaring them buys the strictness
 * rather than spending it: `image` and `storedObject` map onto typed properties, while a part under any other
 * name is refused instead of silently discarded.
 *
 * `UploadedFile` is a behavioral HTTP type and stays on this side of the boundary: `CreateBankCommand` is an
 * Application input a console caller builds with `new`, and it carries no transport vocabulary. This is the
 * split the Iam payloads already use (e.g. {@see \Erpify\Iam\Invitation\Infrastructure\Http\InviteUserRequest}).
 *
 * The scalar constraints are deliberately repeated from the command: this payload guards the HTTP edge, the
 * command stays independently checkable via `Validator::ensure()` for callers that never cross HTTP.
 *
 * Upload size and MIME are NOT constrained here. The ceiling is the container parameter
 * `%erpify.media.max_upload_bytes%`, and an attribute argument must be a constant expression — the controller
 * enforces both through `Validator::ensure()` instead.
 */
final readonly class CreateBankRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'The name field is required.')]
        #[Assert\Length(max: 255, maxMessage: 'The name must not exceed {{ limit }} characters.')]
        public string $name = '',
        #[Assert\NotBlank(message: 'The code field is required.')]
        #[Assert\Length(max: 50, maxMessage: 'The code must not exceed {{ limit }} characters.')]
        public string $shortName = '',
        public ?UploadedFile $image = null,
        public ?UploadedFile $storedObject = null,
    ) {
    }
}
