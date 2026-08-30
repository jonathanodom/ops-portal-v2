<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LimitApiRequestSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $maximum = max(1, (int) config('jarvis.api_max_request_bytes', 262144));
        $declaredLength = $request->server('CONTENT_LENGTH');

        if (is_numeric($declaredLength) && (int) $declaredLength > $maximum) {
            throw new HttpException(413, 'The API request body exceeds the allowed size.');
        }

        if (strlen($request->getContent()) > $maximum) {
            throw new HttpException(413, 'The API request body exceeds the allowed size.');
        }

        return $next($request);
    }
}
