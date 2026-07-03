<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The `json_login` check path. On success the firewall authenticates the request, establishes the httpOnly
 * session cookie and — with no success handler configured — lets it fall through to here, which answers 204
 * with no body (who-am-I is a separate read concern; echoing identity here would leak roles on the login
 * path). A failed login never reaches this: the failure handler answers it earlier via the RFC 9457 pipeline.
 * The API routes default the request `_format` to `json`, so `json_login` attends every POST to this path
 * regardless of `Content-Type`; a non-JSON body then fails to decode and is answered 400 by the pipeline
 * before this controller runs — so it only executes for an already-authenticated request.
 */
final class LoginController
{
    #[Route('/login', name: 'identity_login', methods: ['POST'])]
    public function __invoke(): Response
    {
        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
