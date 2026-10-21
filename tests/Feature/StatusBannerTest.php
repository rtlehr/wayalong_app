<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares dismissible status banner messages with Inertia after redirects', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['success' => 'Changes saved successfully.'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statusBanner.success', 'Changes saved successfully.')
            ->where('statusBanner.error', null)
            ->etc());
});

it('provides error and warning messages without exposing them to other sessions', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['error' => 'Save failed.', 'warning' => 'Review this change.'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statusBanner.error', 'Save failed.')
            ->where('statusBanner.warning', 'Review this change.')
            ->etc());
});
