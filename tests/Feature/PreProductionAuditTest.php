<?php

namespace Tests\Feature;

use App\Support\LucideIcons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreProductionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_recently_missing_icons_are_resolved(): void
    {
        $icons = [
            'building-library',
            'adjustments-horizontal',
            'academic-cap',
            'paper-airplane',
            'navigation',
            'film',
            'book-plus',
            'graduation-cap',
            'landmark',
            'sliders-horizontal',
            'send',
        ];

        foreach ($icons as $iconName) {
            $svg = LucideIcons::get($iconName);
            $this->assertNotNull($svg, "Icon [{$iconName}] failed to resolve in LucideIcons.");
            $this->assertNotEmpty($svg, "Icon [{$iconName}] returned empty SVG path.");
        }
    }

    public function test_translation_files_are_valid_and_synchronized(): void
    {
        $enPath = base_path('lang/en.json');
        $hiPath = base_path('lang/hi.json');
        $bnPath = base_path('lang/bn.json');

        $this->assertFileExists($enPath);
        $this->assertFileExists($hiPath);
        $this->assertFileExists($bnPath);

        $en = json_decode(file_get_contents($enPath), true);
        $hi = json_decode(file_get_contents($hiPath), true);
        $bn = json_decode(file_get_contents($bnPath), true);

        $this->assertIsArray($en);
        $this->assertIsArray($hi);
        $this->assertIsArray($bn);

        $this->assertGreaterThan(10000, count($en), 'en.json should have over 10,000 translation keys.');
        $this->assertGreaterThan(10000, count($hi), 'hi.json should have over 10,000 translation keys.');
        $this->assertGreaterThan(10000, count($bn), 'bn.json should have over 10,000 translation keys.');

        // Critical UI keys must exist
        $criticalKeys = [
            'Hostel Policies',
            'Processing…',
            'Crop',
            'Rotate & Flip',
            'Draw',
            'Stickers',
            'End Call',
            'Active Call',
            'Save Photo',
            'Application No.',
            'Remove Photo',
            'Replace Photo',
            'Upload Photo',
        ];

        foreach ($criticalKeys as $key) {
            $this->assertArrayHasKey($key, $en, "Missing [{$key}] in en.json");
            $this->assertArrayHasKey($key, $hi, "Missing [{$key}] in hi.json");
            $this->assertArrayHasKey($key, $bn, "Missing [{$key}] in bn.json");
        }
    }

    public function test_api_v1_endpoints_require_authentication(): void
    {
        // Unauthenticated access to protected API route must return 401
        $response = $this->getJson('/api/v1/academics/dashboard');
        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'error' => [
                'code' => 'API_KEY_MISSING',
            ],
        ]);
    }

    public function test_ui_components_render_correctly(): void
    {
        $buttonHtml = (string) $this->blade('<x-ui.button variant="primary">Submit</x-ui.button>');
        $this->assertStringContainsString('bg-primary', $buttonHtml);
        $this->assertStringContainsString('Submit', $buttonHtml);

        $successButtonHtml = (string) $this->blade('<x-ui.button variant="success">Approve</x-ui.button>');
        $this->assertStringContainsString('bg-emerald-600', $successButtonHtml);

        $dangerButtonHtml = (string) $this->blade('<x-ui.button variant="danger">Delete</x-ui.button>');
        $this->assertStringContainsString('bg-destructive', $dangerButtonHtml);

        $badgeHtml = (string) $this->blade('<x-ui.badge variant="success">Active</x-ui.badge>');
        $this->assertStringContainsString('bg-emerald-500/15', $badgeHtml);
        $this->assertStringContainsString('Active', $badgeHtml);

        $iconHtml = (string) $this->blade('<x-icon name="academic-cap" class="size-4" />');
        $this->assertStringContainsString('<svg', $iconHtml);
        $this->assertStringNotContainsString('bg-secondary', $iconHtml);
    }
}
