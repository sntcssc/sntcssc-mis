<?php

use App\Models\User;

test('guests are redirected from admin pages to login', function (string $path) {
    $user = User::factory()->create();

    $response = $this->get("/{$user->currentTeam->slug}/{$path}");

    $response->assertRedirect(route('login'));
})->with([
    'students' => ['students'],
    'courses' => ['courses'],
    'reports' => ['reports'],
]);

test('authenticated users can visit every admin page', function (string $path) {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get("/{$user->currentTeam->slug}/{$path}");

    $response->assertOk();
})->with([
    'dashboard' => ['dashboard'],
    'students' => ['students'],
    'admissions' => ['admissions'],
    'enrollments' => ['enrollments'],
    'courses' => ['courses'],
    'batches' => ['batches'],
    'tests' => ['tests'],
    'users' => ['users'],
    'roles' => ['roles'],
    'reports' => ['reports'],
    'saved reports' => ['reports/saved'],
    'profile' => ['profile'],
]);

test('the verify otp page can be rendered by guests', function () {
    $response = $this->get(route('verify-otp'));

    $response->assertOk();
});
