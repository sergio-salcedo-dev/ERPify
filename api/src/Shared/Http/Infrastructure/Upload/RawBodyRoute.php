<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure\Upload;

/**
 * The route default that marks a route whose body is a raw upload read from `php://input`: a route declares
 * `defaults: [RawBodyRoute::ATTRIBUTE => true]` beside its path. The listener that destroys the server's spool of that
 * body keys on it, so a new upload route opts in where it is declared instead of being added to a list kept elsewhere.
 */
final readonly class RawBodyRoute
{
    public const string ATTRIBUTE = '_raw_body_upload';

    private function __construct()
    {
    }
}
