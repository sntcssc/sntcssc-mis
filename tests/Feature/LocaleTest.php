<?php

use App\Models\User;

test('locale can be switched from the header switcher and persists', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('locale.switch'), ['locale' => 'hi'])
        ->assertRedirect();

    $this->get("/{$user->currentTeam->slug}/dashboard")
        ->assertOk()
        ->assertSee('डैशबोर्ड');
});

test('unsupported locales are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('locale.switch'), ['locale' => 'xx'])
        ->assertSessionHasErrors('locale');
});

test('the locale cookie is queued for logged out visitors', function () {
    $this->post(route('locale.switch'), ['locale' => 'bn'])
        ->assertRedirect()
        ->assertCookie('locale', 'bn');
});
