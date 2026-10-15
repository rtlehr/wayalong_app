<?php

namespace App\Http\Controllers;

use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ]);

        abort_unless($context->select($request, (int) $validated['organization_id']), 403);

        return redirect()->route('dashboard');
    }
}
