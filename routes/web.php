<?php

use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\OrganizationAdministrationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::post('organizations/switch', OrganizationSwitchController::class)->name('organizations.switch');

    Route::prefix('organization/admin')->middleware('organization.required')->name('organization.admin.')->group(function () {
        Route::get('/', [OrganizationAdministrationController::class, 'index'])->name('index');
        Route::patch('/settings', [OrganizationAdministrationController::class, 'update'])->name('update');
        Route::post('/facilities', [OrganizationAdministrationController::class, 'storeFacility'])->name('facilities.store');
        Route::patch('/facilities/{facility}', [OrganizationAdministrationController::class, 'updateFacility'])->name('facilities.update');
        Route::delete('/facilities/{facility}', [OrganizationAdministrationController::class, 'deleteFacility'])->name('facilities.destroy');
    });
});

require __DIR__.'/settings.php';
