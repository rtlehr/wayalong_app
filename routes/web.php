<?php

use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\FacilitySwitchController;
use App\Http\Controllers\FacilityStaffRosterController;
use App\Http\Controllers\FacilityClientController;
use App\Http\Controllers\PersonalMessageController;
use App\Http\Controllers\OrganizationLoginController;
use App\Http\Controllers\PlatformOrganizationController;
use App\Http\Controllers\PlatformDashboardController;
use App\Http\Controllers\AdminCommandCenterController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OrganizationAdministrationController;
use App\Http\Controllers\OrganizationUserController;
use App\Http\Controllers\OrganizationPersonController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('login/{organization:slug}', OrganizationLoginController::class)
    ->name('organizations.login');

Route::get('invitations/{token}', [OrganizationInvitationController::class, 'show'])->name('invitations.show');
Route::get('invitations/{token}/sign-in', [OrganizationInvitationController::class, 'signIn'])->name('invitations.sign-in')->middleware('throttle:10,1');
Route::post('invitations/{token}/decline', [OrganizationInvitationController::class, 'decline'])->name('invitations.decline')->middleware(['auth', 'verified', 'throttle:10,1']);
Route::post('invitations/{token}/accept', [OrganizationInvitationController::class, 'accept'])->name('invitations.accept')->middleware('throttle:10,1');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('messages', [PersonalMessageController::class, 'index'])->name('messages.index');
    Route::patch('messages/{notification}/read', [PersonalMessageController::class, 'read'])->name('messages.read');
    Route::get('dashboard', function (\Illuminate\Http\Request $request, \App\Support\OrganizationContext $context, \App\Support\FacilityContext $facilities) {
        if ($request->user()?->is_platform_admin === true) {
            return app(PlatformDashboardController::class)($request);
        }

        $memberships = $request->user()->organizations()
            ->where('organizations.status', 'active')->wherePivot('status', 'active')
            ->orderBy('organizations.name')
            ->get(['organizations.id', 'organizations.name', 'organizations.slug']);
        $assignments = \Illuminate\Support\Facades\DB::table('organization_staff_facilities as assignment')
            ->join('facilities', 'facilities.id', '=', 'assignment.facility_id')
            ->where('assignment.user_id', $request->user()->id)
            ->where('facilities.status', 'active')
            ->whereColumn('facilities.organization_id', 'assignment.organization_id')
            ->whereIn('assignment.organization_id', $memberships->pluck('id'))
            ->select('assignment.organization_id', 'assignment.facility_id', 'assignment.role', 'facilities.name')
            ->orderBy('facilities.name')->get()->groupBy('organization_id');
        $activeOrganization = $context->current($request);
        $activeFacility = $facilities->current($request, $context);
        $facilityPermissions = app(\App\Support\FacilityPermissions::class);

        return Inertia\Inertia::render('Dashboard', [
            'memberships' => $memberships->map(fn ($organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'role' => $organization->pivot->role,
                'facilities' => ($assignments->get($organization->id) ?? collect())->map(fn ($facility) => [
                    'id' => $facility->facility_id, 'name' => $facility->name, 'role' => $facility->role,
                ])->values()->all(),
            ])->values()->all(),
            'activeOrganizationId' => $activeOrganization?->id,
            'activeFacilityId' => $activeFacility?->id,
            'activeFacilityRole' => $activeFacility ? $facilityPermissions->role($request->user(), $activeFacility) : null,
            'activeFacilityPermissions' => $activeFacility ? array_values(array_filter([
                \App\Support\FacilityPermissions::VIEW_STAFF,
                \App\Support\FacilityPermissions::VIEW_CLIENTS,
                \App\Support\FacilityPermissions::MANAGE_CLIENTS,
            ], fn (string $permission) => $facilityPermissions->allows($request->user(), $activeFacility, $permission))) : [],
        ]);
    })->name('dashboard');
    Route::prefix('platform/organizations')->name('platform.organizations.')->controller(PlatformOrganizationController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{organization}', 'show')->name('show');
        Route::get('/{organization}/edit', 'edit')->name('edit');
        Route::patch('/{organization}', 'update')->name('update');
        Route::delete('/{organization}', 'destroy')->name('destroy');
    });
    Route::get('admin', AdminCommandCenterController::class)->middleware('organization.required')->name('admin.command-center');
    Route::post('organizations/switch', OrganizationSwitchController::class)->name('organizations.switch');
    Route::post('facilities/switch', FacilitySwitchController::class)->name('facilities.switch');
    Route::get('facility/staff', FacilityStaffRosterController::class)->name('facility.staff.index');
    Route::get('facility/documents/audit', \App\Http\Controllers\FacilityDocumentAuditController::class)->name('facility.documents.audit');
    Route::get('facility/clients', [FacilityClientController::class, 'index'])->name('facility.clients.index');
    Route::get('facility/clients/create', [FacilityClientController::class, 'create'])->name('facility.clients.create');
    Route::post('facility/clients', [FacilityClientController::class, 'store'])->name('facility.clients.store');
    Route::patch('facility/clients/{enrollment}', [FacilityClientController::class, 'update'])->name('facility.clients.update');
    Route::get('facility/clients/{enrollment}', [FacilityClientController::class, 'show'])->name('facility.clients.show');
    Route::get('facility/clients/{enrollment}/edit', [FacilityClientController::class, 'edit'])->name('facility.clients.edit');
    Route::patch('facility/clients/{enrollment}/profile', [FacilityClientController::class, 'saveProfile'])->name('facility.clients.profile.update');
    Route::post('facility/clients/{enrollment}/insurance', [\App\Http\Controllers\ClientInsurancePolicyController::class, 'store'])->name('facility.clients.insurance.store');
    Route::patch('facility/clients/{enrollment}/insurance/{policy}', [\App\Http\Controllers\ClientInsurancePolicyController::class, 'update'])->name('facility.clients.insurance.update');
    Route::delete('facility/clients/{enrollment}/insurance/{policy}', [\App\Http\Controllers\ClientInsurancePolicyController::class, 'destroy'])->name('facility.clients.insurance.destroy');
    Route::post('facility/clients/{enrollment}/guardian-authorizations', [FacilityClientController::class, 'recordGuardianAuthorization'])->name('facility.clients.guardian-authorizations.store');
    Route::post('facility/clients/{enrollment}/guardians', [FacilityClientController::class, 'createGuardian'])->name('facility.clients.guardians.store');
    Route::get('facility/clients/{enrollment}/guardians/{guardian}', [FacilityClientController::class, 'showGuardian'])->name('facility.clients.guardians.show');
    Route::post('facility/clients/{enrollment}/documents', [\App\Http\Controllers\FacilityClientDocumentController::class, 'store'])->name('facility.clients.documents.store');
    Route::post('facility/clients/{enrollment}/documents/{document}/replace', [\App\Http\Controllers\FacilityClientDocumentController::class, 'replace'])->name('facility.clients.documents.replace');
    Route::get('facility/clients/{enrollment}/documents/{document}/versions/{version}', [\App\Http\Controllers\FacilityClientDocumentController::class, 'downloadVersion'])->name('facility.clients.documents.versions.download');
    Route::post('facility/clients/{enrollment}/documents/{document}/review', [\App\Http\Controllers\FacilityClientDocumentController::class, 'review'])->name('facility.clients.documents.review');
    Route::get('facility/clients/{enrollment}/documents/{document}', [\App\Http\Controllers\FacilityClientDocumentController::class, 'download'])->name('facility.clients.documents.download');

    Route::prefix('organization/admin')->middleware('organization.required')->name('organization.admin.')->group(function () {
        Route::get('/', [OrganizationAdministrationController::class, 'index'])->name('index');
        Route::get('/facilities', [OrganizationAdministrationController::class, 'facilitiesPage'])->name('facilities.index');
        Route::get('/roles', [OrganizationAdministrationController::class, 'rolesPage'])->name('roles.index');
        Route::get('/members', [OrganizationAdministrationController::class, 'membersPage'])->name('members.index');
        Route::get('/people', [OrganizationPersonController::class, 'index'])->name('people.index');
        Route::get('/people/create', [OrganizationPersonController::class, 'create'])->name('people.create');
        Route::post('/people', [OrganizationPersonController::class, 'store'])->name('people.store');
        Route::get('/people/{person}', [OrganizationPersonController::class, 'show'])->name('people.show');
        Route::get('/people/{person}/edit', [OrganizationPersonController::class, 'edit'])->name('people.edit');
        Route::patch('/people/{person}', [OrganizationPersonController::class, 'update'])->name('people.update');
        Route::delete('/people/{person}', [OrganizationPersonController::class, 'destroy'])->name('people.destroy');
        Route::get('/users', [OrganizationUserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [OrganizationUserController::class, 'create'])->name('users.create');
        Route::post('/users', [OrganizationUserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [OrganizationUserController::class, 'show'])->whereNumber('user')->name('users.show');
        Route::get('/users/{user}/edit', [OrganizationUserController::class, 'edit'])->whereNumber('user')->name('users.edit');
        Route::patch('/users/{user}', [OrganizationUserController::class, 'update'])->whereNumber('user')->name('users.update');
        Route::post('/users/{user}/password-reset', [OrganizationUserController::class, 'sendPasswordReset'])->whereNumber('user')->name('users.password-reset');
        Route::patch('/users/{user}/active', [OrganizationUserController::class, 'setActive'])->whereNumber('user')->name('users.active');
        Route::delete('/users/{user}', [OrganizationUserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
        Route::get('/staff', [OrganizationAdministrationController::class, 'staffDirectory'])->name('staff.index');
        Route::get('/staff/create', [OrganizationAdministrationController::class, 'createStaff'])->name('staff.create');
        Route::get('/staff/{member}', [OrganizationAdministrationController::class, 'viewStaff'])->whereNumber('member')->name('staff.show');
        Route::get('/staff/{member}/edit', [OrganizationAdministrationController::class, 'editStaff'])->whereNumber('member')->name('staff.edit');
        Route::patch('/staff/{member}/details', [OrganizationAdministrationController::class, 'updateStaffDetails'])->whereNumber('member')->name('staff.details.update');
        Route::delete('/staff/{member}', [OrganizationAdministrationController::class, 'deleteStaff'])->whereNumber('member')->name('staff.destroy');
        Route::patch('/settings', [OrganizationAdministrationController::class, 'update'])->name('update');
        Route::post('/invitations', [OrganizationInvitationController::class, 'store'])->name('invitations.store');
        Route::post('/roles', [OrganizationAdministrationController::class, 'storeRole'])->name('roles.store');
        Route::patch('/roles/{role}', [OrganizationAdministrationController::class, 'updateRole'])->name('roles.update');
        Route::delete('/roles/{role}', [OrganizationAdministrationController::class, 'deleteRole'])->name('roles.destroy');
        Route::post('/members', [OrganizationAdministrationController::class, 'storeMember'])->name('members.store');
        Route::patch('/members/{member}', [OrganizationAdministrationController::class, 'updateMember'])->name('members.update');
        Route::post('/facilities', [OrganizationAdministrationController::class, 'storeFacility'])->name('facilities.store');
        Route::patch('/facilities/{facility}', [OrganizationAdministrationController::class, 'updateFacility'])->name('facilities.update');
        Route::delete('/facilities/{facility}', [OrganizationAdministrationController::class, 'deleteFacility'])->name('facilities.destroy');
    });
});

require __DIR__.'/settings.php';
