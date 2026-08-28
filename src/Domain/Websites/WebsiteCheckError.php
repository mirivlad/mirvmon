<?php

declare(strict_types=1);

namespace App\Domain\Websites;

enum WebsiteCheckError: string
{
    case Dns = 'dns';
    case Connect = 'connect';
    case Timeout = 'timeout';
    case Tls = 'tls';
    case RedirectLoop = 'redirect_loop';
    case RedirectLimit = 'redirect_limit';
    case RedirectScheme = 'redirect_scheme';
    case UnexpectedStatus = 'unexpected_status';
    case ContentMissing = 'content_missing';
    case SlowResponse = 'slow_response';
    case ResponseTooLarge = 'response_too_large';
    case Internal = 'internal_checker';
}
