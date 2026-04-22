<?php

declare(strict_types=1);

namespace Erpify\Shared\Guzzle\Enum;

enum GuzzleContextTypeEnum: string
{
    case JSON = 'json';

    case QUERY = 'query';

    case FORM_PARAMS = 'form_params';

    case MULTIPART = 'multipart';

    case HEADERS = 'headers';

    case BODY = 'body';
}
