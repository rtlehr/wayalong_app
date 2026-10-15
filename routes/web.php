<?php

use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\AdminCommandCenterController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OrganizationAdministrationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('invitations/{token}', [OrganizationInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}/accept', [OrganizationInvitationController::class, 'accept'])->name('invitations.accept')->middleware('throttle:10,1');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('admin', AdminCommandCenterController::class)->middleware('organization.required')->name('admin.command-center');
    Route::post('organizations/switch', OrganizationSwitchController::class)->name('organizations.switch');

    Route::prefix('organization/admin')->middleware('organization.required')->name('organization.admin.')->group(function () {
        Route::get('/', [OrganizationAdministrationController::class, 'index'])->name('index');
        Route::get('/staff', [OrganizationAdministrationController::class, 'staffDirectory'])->name('staff.index');
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
