<?php

use App\Models\Language;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PageSeeder;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

beforeEach(function () {
    Language::flushCache();
    Setting::flushCache();
    $this->seed(LanguageSeeder::class);
    $this->seed(PageSeeder::class);
    $this->user = User::factory()->create();
});

test('seeded institutional policy pages exist in database with translations', function () {
    $slugs = [
        'about-us',
        'privacy-policy',
        'terms-and-conditions',
        'refund-and-cancellation-policy',
        'legal-disclaimer',
        'copyright-policy',
        'hyperlink-policy',
    ];

    foreach ($slugs as $slug) {
        $page = Page::where('slug', $slug)->first();
        expect($page)->not->toBeNull()
            ->and($page->status)->toBe(Page::STATUS_PUBLISHED)
            ->and($page->is_system)->toBeTrue();

        // Check English translation
        expect($page->hasTranslation('en'))->toBeTrue()
            ->and($page->hasTranslation('hi'))->toBeTrue()
            ->and($page->hasTranslation('bn'))->toBeTrue();

        $enTrans = $page->translation('en');
        expect($enTrans->title)->not->toBeEmpty()
            ->and($enTrans->content)->not->toBeEmpty();
    }
});

test('public dynamic pages render successfully in different locales', function () {
    // English view
    App::setLocale('en');
    $response = $this->get(route('public.page', 'about-us'));
    $response->assertOk()
        ->assertSee('About Us')
        ->assertSee('Satyendranath Tagore Civil Services Study Centre');

    // Hindi view
    App::setLocale('hi');
    $response = $this->get(route('public.page', 'about-us'));
    $response->assertOk()
        ->assertSee('हमारे बारे में')
        ->assertSee('सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र');

    // Bengali view
    App::setLocale('bn');
    $response = $this->get(route('public.page', 'about-us'));
    $response->assertOk()
        ->assertSee('আমাদের সম্পর্কে')
        ->assertSee('সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টার');
});

test('public direct alias routes render correctly', function () {
    $response = $this->get('/about-us');
    $response->assertOk()->assertSee('About Us');

    $response = $this->get('/privacy-policy');
    $response->assertOk()->assertSee('Privacy Policy');

    $response = $this->get('/terms-and-conditions');
    $response->assertOk()->assertSee('Terms and Conditions');
});

test('public page returns 404 for non-existent or inactive page', function () {
    $response = $this->get('/pages/non-existent-sample-page');
    $response->assertNotFound();

    $draftPage = Page::create([
        'slug' => 'draft-sample-page',
        'status' => Page::STATUS_DRAFT,
        'sort_order' => 99,
    ]);
    PageTranslation::create([
        'page_id' => $draftPage->id,
        'locale' => 'en',
        'title' => 'Draft Page',
        'content' => 'Sample draft content',
    ]);

    $response = $this->get('/pages/draft-sample-page');
    $response->assertNotFound();
});

test('visiting a public page increments view count', function () {
    $page = Page::where('slug', 'about-us')->first();
    $initialViews = $page->view_count;

    $this->get(route('public.page', 'about-us'));

    expect($page->fresh()->view_count)->toBe($initialViews + 1);
});

test('admin can manage pages via livewire component', function () {
    $this->actingAs($this->user);

    // Test component rendering
    Livewire::test('pages::admin.pages')
        ->assertSee('Pages & Content Management')
        ->assertSee('About Us')
        ->assertSee('privacy-policy')
        // Test Create new page
        ->call('openCreateModal')
        ->set('form.slug', 'scholarship-guidelines')
        ->set('form.status', 'published')
        ->set('form.sort_order', 10)
        ->set('translations.en.title', 'Scholarship Guidelines')
        ->set('translations.en.meta_description', 'Information on scholarships for meritorious civil service aspirants.')
        ->set('translations.en.content', '<h2>Scholarship Rules</h2><p>Criteria for fee concessions.</p>')
        ->set('translations.hi.title', 'छात्रवृत्ति दिशा-निर्देश')
        ->set('translations.hi.content', '<p>छात्रवृत्ति नियम</p>')
        ->call('save')
        ->assertHasNoErrors();

    $newPage = Page::where('slug', 'scholarship-guidelines')->first();
    expect($newPage)->not->toBeNull()
        ->and($newPage->hasTranslation('en'))->toBeTrue()
        ->and($newPage->hasTranslation('hi'))->toBeTrue()
        ->and($newPage->getTranslation('title', 'hi'))->toBe('छात्रवृत्ति दिशा-निर्देश');

    // Test Edit Page
    Livewire::test('pages::admin.pages')
        ->call('editPage', $newPage->id)
        ->set('translations.en.title', 'Updated Scholarship Guidelines')
        ->call('save')
        ->assertHasNoErrors();

    expect($newPage->fresh()->getTranslation('title', 'en'))->toBe('Updated Scholarship Guidelines');

    // Test Toggle Status
    Livewire::test('pages::admin.pages')
        ->call('toggleStatus', $newPage->id);

    expect($newPage->fresh()->status)->toBe('draft');

    // Test Soft Delete & Restore
    Livewire::test('pages::admin.pages')
        ->call('deletePage', $newPage->id);

    expect($newPage->fresh()->trashed())->toBeTrue();

    Livewire::test('pages::admin.pages')
        ->set('showTrashed', true)
        ->call('restorePage', $newPage->id);

    expect($newPage->fresh()->trashed())->toBeFalse();
});
