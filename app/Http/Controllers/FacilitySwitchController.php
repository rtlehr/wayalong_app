<?php

namespace App\Http\Controllers;

use App\Support\FacilityContext;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FacilitySwitchController extends Controller
{
    public function __invoke(Request $request, FacilityContext $facilities, OrganizationContext $organizations): RedirectResponse
    {
        $data = $request->validate(['facility_id' => ['required', 'integer', 'exists:facilities,id']]);
        abort_unless($facilities->select($request, $organizations, (int) $data['facility_id']), 403);

        return redirect()->route('dashboard')->with('success', 'Facility selected.');
    }
}
