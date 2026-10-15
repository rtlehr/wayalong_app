<?php

namespace App\Http\Middleware;

use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(OrganizationContext::class)->current($request) !== null, 403, 'An active organization membership is required.');

        return $next($request);
    }
}
