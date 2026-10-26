<?php

use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

it('prevents browsers from caching authenticated clinical application responses', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $cacheDirectives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
    foreach (['private', 'no-store', 'no-cache', 'must-revalidate'] as $directive) {
        expect($cacheDirectives)->toContain($directive);
    }
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('does not emit production transport policy on ordinary local http requests', function () {
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
});

it('reports missing production safeguards without leaking configuration secrets', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', true);
    config()->set('session.encrypt', false);

    $this->artisan('wayalong:security-check')
        ->expectsOutputToContain('security prerequisite(s) failed')
        ->assertFailed();
});
