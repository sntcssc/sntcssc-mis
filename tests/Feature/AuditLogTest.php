<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Setting::flushCache();
});

/* -------------------------------------------------------------------------- */
/*  AuditLogService Unit Tests */
/* -------------------------------------------------------------------------- */

test('AuditLogService::log creates an audit record with full HTTP context', function () {
    $log = AuditLogService::log(
        event: 'setting_updated',
        description: 'Test setting change',
        newValues: ['key' => 'val'],
        userId: $this->user->id
    );

    expect($log)->toBeInstanceOf(AuditLog::class)
        ->and($log->event)->toBe('setting_updated')
        ->and($log->description)->toBe('Test setting change')
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->new_values)->toBe(['key' => 'val']);

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'setting_updated',
        'user_id' => $this->user->id,
    ]);
});

test('AuditLogService::prune removes records older than specified days', function () {
    AuditLog::factory()->create(['created_at' => now()->subDays(100)]);
    AuditLog::factory()->create(['created_at' => now()->subDays(50)]);
    AuditLog::factory()->create(['created_at' => now()->subDays(10)]);

    $deleted = AuditLogService::prune(90);

    expect($deleted)->toBe(1);
    $this->assertDatabaseCount('audit_logs', 2);
});

/* -------------------------------------------------------------------------- */
/*  AuditLog Model Helpers */
/* -------------------------------------------------------------------------- */

test('AuditLog eventBadgeColor returns correct color for known events', function (string $event, string $expectedColor) {
    $log = AuditLog::factory()->create(['event' => $event]);

    expect($log->eventBadgeColor())->toBe($expectedColor);
})->with([
    ['created', 'success'],
    ['deleted', 'destructive'],
    ['login', 'emerald'],
    ['failed_login', 'destructive'],
    ['setting_updated', 'primary'],
]);

test('AuditLog eventIcon returns correct icon for known events', function (string $event, string $expectedIcon) {
    $log = AuditLog::factory()->create(['event' => $event]);

    expect($log->eventIcon())->toBe($expectedIcon);
})->with([
    ['created', 'plus'],
    ['deleted', 'trash-2'],
    ['login', 'log-in'],
    ['logout', 'log-out'],
    ['theme_changed', 'palette'],
    ['maintenance_mode_toggled', 'alert-triangle'],
]);

test('AuditLog targetLabel returns model class and id when auditable is set', function () {
    $log = AuditLog::factory()->create([
        'auditable_type' => 'App\\Models\\Setting',
        'auditable_id' => 42,
    ]);

    expect($log->targetLabel())->toBe('Setting #42');
});

test('AuditLog targetLabel returns em-dash when no auditable', function () {
    $log = AuditLog::factory()->create([
        'auditable_type' => null,
        'auditable_id' => null,
    ]);

    expect($log->targetLabel())->toBe('—');
});

/* -------------------------------------------------------------------------- */
/*  AuditLog Scopes */
/* -------------------------------------------------------------------------- */

test('AuditLog scopeInDateRange filters records by date range', function () {
    AuditLog::factory()->create(['created_at' => '2026-01-15 10:00:00']);
    AuditLog::factory()->create(['created_at' => '2026-06-01 10:00:00']);
    AuditLog::factory()->create(['created_at' => '2026-12-31 10:00:00']);

    $count = AuditLog::query()->inDateRange('2026-01-01', '2026-07-01')->count();

    expect($count)->toBe(2);
});

/* -------------------------------------------------------------------------- */
/*  Audit Logs Page (Livewire) */
/* -------------------------------------------------------------------------- */

test('audit logs page filters by event type', function () {
    AuditLog::factory()->create(['event' => 'login', 'description' => 'User signed in successfully']);
    AuditLog::factory()->create(['event' => 'setting_updated', 'description' => 'Setting was changed here']);

    Livewire::test('pages::admin.audit-logs')
        ->set('eventFilter', 'login')
        ->assertSee('User signed in successfully')
        ->assertDontSee('Setting was changed here');
});

test('audit logs page filters by search term in description', function () {
    AuditLog::factory()->create(['event' => 'login', 'description' => 'Unique xk99 marker login']);
    AuditLog::factory()->create(['event' => 'logout', 'description' => 'Another beta logout']);

    Livewire::test('pages::admin.audit-logs')
        ->set('search', 'xk99')
        ->assertSee('Unique xk99 marker login')
        ->assertDontSee('Another beta logout');
});

test('audit logs page inspect action populates selectedLog', function () {
    $log = AuditLog::factory()->create([
        'user_id' => $this->user->id,
        'event' => 'setting_updated',
        'old_values' => ['key' => 'old'],
        'new_values' => ['key' => 'new'],
    ]);

    Livewire::test('pages::admin.audit-logs')
        ->call('inspect', $log->id)
        ->assertSet('inspectingLogId', $log->id);
});

test('audit logs page prune action deletes records older than threshold', function () {
    AuditLog::factory()->create(['created_at' => now()->subDays(100)]);
    AuditLog::factory()->create(['created_at' => now()->subDays(10)]);

    Livewire::test('pages::admin.audit-logs')
        ->set('pruneDays', 90)
        ->call('pruneLogs');

    $this->assertDatabaseCount('audit_logs', 1);
});

test('audit logs reset filters clears all filter properties', function () {
    Livewire::test('pages::admin.audit-logs')
        ->set('search', 'something')
        ->set('eventFilter', 'login')
        ->set('dateFrom', '2026-01-01')
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('eventFilter', '')
        ->assertSet('dateFrom', null);
});
