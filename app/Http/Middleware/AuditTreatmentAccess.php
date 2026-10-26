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
        $user = $request->user();
        $enrollment = $request->route('enrollment');
        $organization = app(OrganizationContext::class)->current($request);

        if ($user === null || ! $enrollment instanceof ClientEnrollment || $organization === null) {
            return;
        }

        DB::table('clinical_access_events')->insert([
            'organization_id' => $organization->id,
            'client_enrollment_id' => $enrollment->id,
            'user_id' => $user->id,
            'section' => $section,
            'outcome' => $outcome,
            'created_at' => now(),
        ]);
    }
}
