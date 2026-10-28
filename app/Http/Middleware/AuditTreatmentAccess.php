<?php

namespace App\Http\Middleware;

use App\Models\ClientEnrollment;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class AuditTreatmentAccess
{
    public function handle(Request $request, Closure $next, string $section): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403) {
                $this->record($request, $section, 'denied');
            }

            throw $exception;
        }

        if ($response->isSuccessful()) {
            $this->record($request, $section, 'viewed');
        } elseif ($response->getStatusCode() === 403) {
            $this->record($request, $section, 'denied');
        }

        return $response;
    }

    private function record(Request $request, string $section, string $outcome): void
    {
        $enrollment = $request->route('enrollment');
        if ($enrollment instanceof ClientEnrollment) {
            app(\App\Support\SensitiveAccessAudit::class)->record($request, $enrollment, $section, $outcome);
        }
    }
}
