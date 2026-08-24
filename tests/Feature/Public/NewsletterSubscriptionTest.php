<?php

use App\Livewire\Public\NewsletterSubscribe;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('public user can view newsletter subscription card on welcome page', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Subscribe to Notifications & Newsletter');
});

test('public user can subscribe with email', function () {
    Livewire::test(NewsletterSubscribe::class)
        ->set('type', 'email')
        ->set('email', 'aspirant@example.com')
        ->set('name', 'Vikram Sen')
        ->call('subscribe')
        ->assertSet('subscribed', true);

    $this->assertDatabaseHas('subscribers', [
        'email' => 'aspirant@example.com',
        'name' => 'Vikram Sen',
        'type' => 'email',
        'status' => 'active',
        'source' => 'welcome_page',
    ]);
});

test('public user can subscribe with whatsapp number', function () {
    Livewire::test(NewsletterSubscribe::class)
        ->set('type', 'whatsapp')
        ->set('country_code', '+91')
        ->set('phone', '9830012345')
        ->set('name', 'Pooja Roy')
        ->call('subscribe')
        ->assertSet('subscribed', true);

    $this->assertDatabaseHas('subscribers', [
        'phone' => '9830012345',
        'country_code' => '+91',
        'type' => 'whatsapp',
        'status' => 'active',
    ]);
});

test('honeypot field silently blocks bot spam', function () {
    Livewire::test(NewsletterSubscribe::class)
        ->set('type', 'email')
        ->set('email', 'bot@spammer.com')
        ->set('honeypot', 'I am a robot')
        ->call('subscribe')
        ->assertSet('subscribed', false);

    $this->assertDatabaseMissing('subscribers', [
        'email' => 'bot@spammer.com',
    ]);
});

test('subscriber can unsubscribe using 1-click token', function () {
    $subscriber = Subscriber::create([
        'uuid' => (string) Str::uuid(),
        'type' => Subscriber::TYPE_EMAIL,
        'email' => 'unsub_test@example.com',
        'status' => Subscriber::STATUS_ACTIVE,
        'unsubscribe_token' => 'secure-token-1234567890',
        'subscribed_at' => now(),
    ]);

    $response = $this->get(route('unsubscribe.show', 'secure-token-1234567890'));
    $response->assertOk();
    $response->assertSee('Unsubscribe Confirmation');

    $postResponse = $this->post(route('unsubscribe.process', 'secure-token-1234567890'), [
        'reason' => 'too_frequent',
    ]);

    $postResponse->assertRedirect(route('unsubscribe.show', 'secure-token-1234567890'));

    $subscriber->refresh();
    expect($subscriber->status)->toBe(Subscriber::STATUS_UNSUBSCRIBED);
    expect($subscriber->unsubscribed_at)->not->toBeNull();
    expect($subscriber->unsubscribe_reason)->toBe('too_frequent');
});
