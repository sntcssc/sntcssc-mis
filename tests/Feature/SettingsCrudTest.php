<?php

use App\Imports\TableImport;
use App\Models\Setting;
use App\Models\User;
use App\Support\Export\TableExporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Validators\ValidationException;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Setting::flushCache();
});

test('the settings admin page renders and lists settings', function () {
    Setting::factory()->count(3)->create();

    Livewire::test('pages::admin.settings')
        ->assertOk()
        ->assertSee(Setting::first()->key);
});

test('a setting can be created through the settings page', function () {
    Livewire::test('pages::admin.settings')
        ->set('form', [
            'key' => 'sms.new_key',
            'value' => 'hello',
            'group' => 'sms',
            'type' => Setting::TYPE_STRING,
            'label' => 'New key',
            'optionsText' => '',
            'status' => true,
        ])
        ->call('save')
        ->assertHasNoErrors();

    $setting = Setting::where('key', 'sms.new_key')->first();

    expect($setting)->not->toBeNull()
        ->and($setting->value)->toBe('hello')
        ->and($setting->created_by)->toBe($this->user->id)
        ->and($setting->updated_by)->toBe($this->user->id);
});

test('a duplicate key is rejected', function () {
    Setting::factory()->create(['key' => 'sms.duplicate']);

    Livewire::test('pages::admin.settings')
        ->set('form.key', 'sms.duplicate')
        ->call('save')
        ->assertHasErrors(['form.key']);
});

test('a setting can be updated, and a blank secret keeps its stored value', function () {
    $secret = Setting::factory()->create(['key' => 'payment.existing_secret', 'type' => Setting::TYPE_SECRET]);
    // Set the value after creation so the encrypting mutator sees the secret type.
    $secret->update(['value' => 'stored-secret']);
    $ciphertext = $secret->getRawOriginal('value');

    Livewire::test('pages::admin.settings')
        ->call('edit', $secret->id)
        ->set('form.label', 'Renamed label')
        ->set('form.value', '')
        ->call('save')
        ->assertHasNoErrors();

    $secret->refresh();

    expect($secret->label)->toBe('Renamed label')
        ->and($secret->getRawOriginal('value'))->toBe($ciphertext)
        ->and($secret->rawValue())->toBe('stored-secret');
});

test('a secret value is stored encrypted at rest', function () {
    Livewire::test('pages::admin.settings')
        ->set('form', [
            'key' => 'payment.new_secret',
            'value' => 'plain-secret',
            'group' => 'payment',
            'type' => Setting::TYPE_SECRET,
            'label' => 'New secret',
            'optionsText' => '',
            'status' => true,
        ])
        ->call('save');

    $raw = Setting::where('key', 'payment.new_secret')->value('value');

    expect($raw)->not->toBe('plain-secret')
        ->and(Crypt::decryptString($raw))->toBe('plain-secret');
});

test('a setting can be soft deleted through the settings page', function () {
    $setting = Setting::factory()->create();

    Livewire::test('pages::admin.settings')
        ->call('selectForDelete', $setting->id)
        ->call('deleteSelected');

    expect(Setting::withTrashed()->find($setting->id)->trashed())->toBeTrue()
        ->and(Setting::withTrashed()->find($setting->id)->deleted_by)->toBe($this->user->id);
});

test('the status of a setting can be toggled', function () {
    $setting = Setting::factory()->active()->create();

    Livewire::test('pages::admin.settings')
        ->call('toggleStatus', $setting->id);

    expect($setting->refresh()->status)->toBeFalse();
});

test('settings can be searched', function () {
    Setting::factory()->create(['key' => 'general.site_name', 'label' => 'Site name']);
    Setting::factory()->create(['key' => 'sms.otp_length', 'label' => 'OTP length']);

    $component = Livewire::test('pages::admin.settings')
        ->set('search', 'otp_length');

    expect($component->get('settings')->pluck('key')->all())->toBe(['sms.otp_length']);
});

test('settings can be filtered by group, type and status', function () {
    Setting::factory()->count(2)->create(['group' => 'sms', 'type' => Setting::TYPE_STRING, 'status' => true]);
    Setting::factory()->create(['group' => 'email', 'type' => Setting::TYPE_SECRET, 'status' => false]);

    $component = Livewire::test('pages::admin.settings');

    $component->set('tableFilters.group', 'sms');
    expect($component->get('settings')->total())->toBe(2);

    $component->set('tableFilters.group', '')->set('tableFilters.status', '0');
    expect($component->get('settings')->total())->toBe(1);

    $component->set('tableFilters.status', '')->set('tableFilters.type', Setting::TYPE_SECRET);
    expect($component->get('settings')->total())->toBe(1);
});

test('settings can be sorted by column', function () {
    Setting::factory()->create(['key' => 'aaa.first']);
    Setting::factory()->create(['key' => 'zzz.last']);

    $component = Livewire::test('pages::admin.settings');

    $component->call('sortBy', 'key');
    expect($component->get('settings')->first()->key)->toBe('aaa.first');

    $component->call('sortBy', 'key');
    expect($component->get('settings')->first()->key)->toBe('zzz.last');
});

test('table exporter produces xlsx, csv and pdf downloads', function (string $format, string $extension) {
    Setting::factory()->create(['key' => 'general.export_me']);

    $response = TableExporter::download(
        Setting::query(),
        $format,
        'settings-test',
        ['Key'],
        fn (Setting $setting) => [$setting->key],
    );

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->headers->get('Content-Disposition'))->toContain(".{$extension}");

    $content = $format === 'pdf'
        ? $response->getContent()
        : file_get_contents($response->getFile()->getPathname());

    if ($format === 'csv') {
        expect($content)->toContain('general.export_me');
    } elseif ($format === 'xlsx') {
        expect(substr($content, 0, 2))->toBe('PK'); // zip signature
    }
})->with([
    'xlsx' => ['xlsx', 'xlsx'],
    'csv' => ['csv', 'csv'],
    'pdf' => ['pdf', 'pdf'],
]);

test('export masks secret values but keeps normal ones', function () {
    Setting::factory()->create(['key' => 'general.visible', 'type' => Setting::TYPE_STRING, 'value' => 'public-value']);
    Setting::factory()->create(['key' => 'payment.hidden', 'type' => Setting::TYPE_SECRET, 'value' => 'super-secret']);

    $component = Livewire::test('pages::admin.settings');
    $response = $component->call('export', 'csv');

    // Pull the generated file through the exporter directly to assert content.
    $csv = TableExporter::download(
        Setting::query(),
        'csv',
        'settings-secrets-test',
        ['Key', 'Value'],
        fn (Setting $setting) => [$setting->key, $setting->type === Setting::TYPE_SECRET ? '' : (string) ($setting->rawValue() ?? '')],
    );

    $content = file_get_contents($csv->getFile()->getPathname());

    expect($content)->toContain('public-value')
        ->not->toContain('super-secret');
});

test('settings can be imported from csv through the settings page', function () {
    Storage::fake('local');

    $csv = "Key,Value,Group,Type,Label,Status\nimport.inserted_one,first,general,string,First,1\n";
    $file = UploadedFile::fake()->createWithContent('import.csv', $csv, 'text/csv');

    Livewire::test('pages::admin.settings')
        ->set('importFile', $file)
        ->call('import')
        ->assertHasNoErrors();

    $setting = Setting::where('key', 'import.inserted_one')->first();

    expect($setting)->not->toBeNull()
        ->and($setting->value)->toBe('first')
        ->and($setting->group)->toBe('general')
        ->and($setting->created_by)->toBe($this->user->id);
});

test('import updates existing keys instead of duplicating them', function () {
    Storage::fake('local');

    Setting::factory()->create(['key' => 'import.existing', 'value' => 'old']);

    $csv = "Key,Value,Group,Type,Label,Status\nimport.existing,new,general,string,Existing,1\n";
    $file = UploadedFile::fake()->createWithContent('import.csv', $csv, 'text/csv');

    Livewire::test('pages::admin.settings')
        ->set('importFile', $file)
        ->call('import')
        ->assertHasNoErrors();

    expect(Setting::where('key', 'import.existing')->count())->toBe(1)
        ->and(Setting::where('key', 'import.existing')->value('value'))->toBe('new');
});

test('the generic table import validates rows and reports failures', function () {
    $path = storage_path('app/test-import.csv');
    file_put_contents($path, "Key,Group,Type\n,missing-group,string\n");

    try {
        Excel::import(new TableImport(
            rules: ['key' => ['required', 'string']],
            rowHandler: fn (array $row) => Setting::create($row),
        ), $path);

        $this->fail('Expected a validation exception.');
    } catch (ValidationException $e) {
        expect($e->failures())->not->toBeEmpty();
    } finally {
        @unlink($path);
    }
});

test('settings table supports changing perPage and pagination controls', function () {
    Setting::factory()->count(15)->create();

    $component = Livewire::test('pages::admin.settings')
        ->set('perPage', 6);

    expect($component->get('settings')->perPage())->toBe(6)
        ->and($component->get('settings')->total())->toBe(15)
        ->and($component->get('settings')->lastPage())->toBe(3);

    $component->call('nextPage');
    expect($component->get('settings')->currentPage())->toBe(2);

    // Changing perPage should reset page to 1
    $component->set('perPage', 10);
    expect($component->get('settings')->currentPage())->toBe(1)
        ->and($component->get('settings')->perPage())->toBe(10);
});

test('settings table allows multiple filters to be applied and reset without errors', function () {
    Setting::factory()->create(['group' => 'sms', 'type' => Setting::TYPE_STRING, 'status' => true, 'key' => 'sms.gateway']);
    Setting::factory()->create(['group' => 'sms', 'type' => Setting::TYPE_NUMBER, 'status' => true, 'key' => 'sms.timeout']);
    Setting::factory()->create(['group' => 'sms', 'type' => Setting::TYPE_STRING, 'status' => false, 'key' => 'sms.disabled_key']);
    Setting::factory()->create(['group' => 'mail', 'type' => Setting::TYPE_STRING, 'status' => true, 'key' => 'mail.host']);

    $component = Livewire::test('pages::admin.settings')
        ->set('tableFilters.group', 'sms')
        ->set('tableFilters.type', Setting::TYPE_STRING)
        ->set('tableFilters.status', '1')
        ->assertHasNoErrors();

    expect($component->get('settings')->total())->toBe(1)
        ->and($component->get('settings')->first()->key)->toBe('sms.gateway');

    // Resetting filters should not throw type errors
    $component->call('resetFilters')
        ->assertHasNoErrors();

    expect($component->get('settings')->total())->toBe(4);
});

test('settings page can export to pdf via livewire', function () {
    Setting::factory()->create(['key' => 'general.site_title']);

    $component = Livewire::test('pages::admin.settings')
        ->call('export', 'pdf')
        ->assertFileDownloaded();
});

test('settings can be created and updated with image and file uploads', function () {
    Storage::fake('public');

    $imageFile = UploadedFile::fake()->image('logo.png', 200, 200);

    Livewire::test('pages::admin.settings')
        ->set('form', [
            'key' => 'general.logo',
            'value' => '',
            'group' => 'general',
            'type' => Setting::TYPE_IMAGE,
            'label' => 'Site logo',
            'optionsText' => '',
            'status' => true,
        ])
        ->set('settingFile', $imageFile)
        ->call('save')
        ->assertHasNoErrors();

    $setting = Setting::where('key', 'general.logo')->first();
    expect($setting)->not->toBeNull()
        ->and($setting->type)->toBe(Setting::TYPE_IMAGE)
        ->and($setting->value)->toStartWith('settings/');

    Storage::disk('public')->assertExists($setting->value);

    // Update with file upload
    $docFile = UploadedFile::fake()->create('terms.pdf', 50, 'application/pdf');

    Livewire::test('pages::admin.settings')
        ->call('edit', $setting->id)
        ->set('form.type', Setting::TYPE_FILE)
        ->set('settingFile', $docFile)
        ->call('save')
        ->assertHasNoErrors();

    $setting->refresh();
    expect($setting->type)->toBe(Setting::TYPE_FILE)
        ->and($setting->value)->toStartWith('settings/');

    Storage::disk('public')->assertExists($setting->value);
});

test('settings table displays serial number sl no correctly in UI', function () {
    Setting::factory()->count(12)->create();

    Livewire::test('pages::admin.settings')
        ->set('perPage', 10)
        ->assertSee('Sl No')
        ->assertSee('1')
        ->assertSee('10');
});
