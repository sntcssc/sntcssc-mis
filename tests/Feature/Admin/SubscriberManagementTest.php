<?php

use App\Models\Subscriber;
use App\Models\Team;
use App\Models\User;
use App\Services\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    RbacService::seedDefaults();

    $this->team = Team::factory()->create([
        'name' => 'Main Center',
        'slug' => 'main-center',
    ]);

    $this->admin = User::factory()->create([
        'current_team_id' => $this->team->id,
    ]);
    $this->admin->teams()->attach($this->team->id, ['role' => 'admin']);
    $this->admin->assignRole('Super Administrator');
});

test('admin can view subscribers management page', function () {
    Subscriber::create([
        'uuid' => (string) Str::uuid(),
        'type' => Subscriber::TYPE_EMAIL,
        'email' => 'student1@example.com',
        'name' => 'Aarav Sharma',
        'status' => Subscriber::STATUS_ACTIVE,
        'source' => Subscriber::SOURCE_WELCOME,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('admin.subscribers.index', ['current_team' => $this->team->slug]));

    $response->assertOk();
    $response->assertSee('Newsletter & WhatsApp Subscribers');
    $response->assertSee('student1@example.com');
});

test('admin can create email and whatsapp subscriber', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscribers')
        ->set('subscriberForm.type', 'both')
        ->set('subscriberForm.email', 'ananya.sen@example.com')
        ->set('subscriberForm.country_code', '+91')
        ->set('subscriberForm.phone', '9876543210')
        ->set('subscriberForm.name', 'Ananya Sen')
        ->set('subscriberForm.status', 'active')
        ->set('subscriberForm.source', 'admin_manual')
        ->set('subscriberForm.tags_text', 'upsc, batch_2026')
        ->call('saveSubscriber')
        ->assertDispatched('modal-close', name: 'subscriber-form-modal');

    $this->assertDatabaseHas('subscribers', [
        'email' => 'ananya.sen@example.com',
        'phone' => '9876543210',
        'name' => 'Ananya Sen',
        'type' => 'both',
        'status' => 'active',
    ]);
});

test('admin can update subscriber details and tags', function () {
    $sub = Subscriber::create([
        'uuid' => (string) Str::uuid(),
        'type' => Subscriber::TYPE_EMAIL,
        'email' => 'rahul.roy@example.com',
        'name' => 'Rahul',
        'status' => Subscriber::STATUS_ACTIVE,
        'source' => Subscriber::SOURCE_WELCOME,
        'tags' => ['old_tag'],
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscribers')
        ->call('openEditModal', $sub->id)
        ->set('subscriberForm.name', 'Rahul Roy')
        ->set('subscriberForm.status', 'unsubscribed')
        ->set('subscriberForm.tags_text', 'upsc, admissions')
        ->call('saveSubscriber');

    $sub->refresh();
    expect($sub->name)->toBe('Rahul Roy');
    expect($sub->status)->toBe('unsubscribed');
    expect($sub->tags)->toContain('upsc', 'admissions');
});

test('admin can soft delete and restore subscriber using modal actions', function () {
    $sub = Subscriber::create([
        'uuid' => (string) Str::uuid(),
        'type' => Subscriber::TYPE_WHATSAPP,
        'phone' => '919988776655',
        'name' => 'Priya Das',
        'status' => Subscriber::STATUS_ACTIVE,
        'source' => Subscriber::SOURCE_WELCOME,
    ]);

    // Soft delete
    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscribers')
        ->call('confirmDelete', $sub->id)
        ->call('executeDelete')
        ->assertDispatched('modal-close', name: 'subscriber-delete-modal');

    $this->assertSoftDeleted('subscribers', ['id' => $sub->id]);

    // Restore
    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscribers')
        ->set('viewTab', 'trash')
        ->call('confirmRestore', $sub->id)
        ->call('executeRestore')
        ->assertDispatched('modal-close', name: 'subscriber-restore-modal');

    $this->assertNotSoftDeleted('subscribers', ['id' => $sub->id]);
});

test('admin can perform bulk status updates and bulk deletion', function () {
    $sub1 = Subscriber::create([
        'uuid' => (string) Str::uuid(),
        'type' => Subscriber::TYPE_EMAIL,
        'email' => 'bulk1@example.com',
        'status' => Subscriber::STATUS_ACTIVE,
    ]);
    $sub2 = Subscriber::create([
        'uuid' => (string) Str::uuid(),
        'type' => Subscriber::TYPE_EMAIL,
        'email' => 'bulk2@example.com',
        'status' => Subscriber::STATUS_ACTIVE,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscribers')
        ->set('selectedIds', [$sub1->id, $sub2->id])
        ->set('bulkActionType', 'status')
        ->set('bulkActionValue', 'unsubscribed')
        ->call('executeBulkAction');

    expect($sub1->fresh()->status)->toBe('unsubscribed');
    expect($sub2->fresh()->status)->toBe('unsubscribed');
});
