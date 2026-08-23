<?php

namespace App\Support;

use App\Models\Setting;

class ThemePresets
{
    public const DEFAULT = 'emerald';

    /**
     * @return array<string, array{
     *     name: string,
     *     description: string,
     *     swatch: string,
     *     light: array{primary: string, ring: string, sidebar_primary: string, chart: array<string>},
     *     dark: array{primary: string, ring: string, sidebar_primary: string, chart: array<string>}
     * }>
     */
    public static function all(): array
    {
        $presets = [
            'emerald' => [
                'name' => 'Emerald Classic',
                'description' => 'Original clean emerald green theme with fresh accent tones.',
                'swatch' => '#059669',
                'light' => [
                    'primary' => '#059669',
                    'primary_foreground' => '#ffffff',
                    'ring' => '#059669',
                    'sidebar_primary' => '#059669',
                    'chart' => ['#10b981', '#06b6d4', '#f59e0b', '#8b5cf6', '#f43f5e'],
                ],
                'dark' => [
                    'primary' => '#10b981',
                    'primary_foreground' => '#09090b',
                    'ring' => '#10b981',
                    'sidebar_primary' => '#10b981',
                    'chart' => ['#10b981', '#06b6d4', '#f59e0b', '#8b5cf6', '#f43f5e'],
                ],
            ],
            'indigo' => [
                'name' => 'Royal Indigo',
                'description' => 'Deep royal indigo palette tailored for modern SaaS and universities.',
                'swatch' => '#4f46e5',
                'light' => [
                    'primary' => '#4f46e5',
                    'primary_foreground' => '#ffffff',
                    'ring' => '#4f46e5',
                    'sidebar_primary' => '#4f46e5',
                    'chart' => ['#6366f1', '#3b82f6', '#10b981', '#f59e0b', '#ec4899'],
                ],
                'dark' => [
                    'primary' => '#6366f1',
                    'primary_foreground' => '#09090b',
                    'ring' => '#6366f1',
                    'sidebar_primary' => '#6366f1',
                    'chart' => ['#6366f1', '#3b82f6', '#10b981', '#f59e0b', '#ec4899'],
                ],
            ],
            'ocean' => [
                'name' => 'Ocean Blue',
                'description' => 'Corporate azure blue with high-contrast accessibility.',
                'swatch' => '#2563eb',
                'light' => [
                    'primary' => '#2563eb',
                    'primary_foreground' => '#ffffff',
                    'ring' => '#2563eb',
                    'sidebar_primary' => '#2563eb',
                    'chart' => ['#3b82f6', '#06b6d4', '#10b981', '#8b5cf6', '#f43f5e'],
                ],
                'dark' => [
                    'primary' => '#3b82f6',
                    'primary_foreground' => '#09090b',
                    'ring' => '#3b82f6',
                    'sidebar_primary' => '#3b82f6',
                    'chart' => ['#3b82f6', '#06b6d4', '#10b981', '#8b5cf6', '#f43f5e'],
                ],
            ],
            'teal' => [
                'name' => 'Teal Cyan',
                'description' => 'Modern sea teal palette bringing vibrant elegance.',
                'swatch' => '#0d9488',
                'light' => [
                    'primary' => '#0d9488',
                    'primary_foreground' => '#ffffff',
                    'ring' => '#0d9488',
                    'sidebar_primary' => '#0d9488',
                    'chart' => ['#14b8a6', '#0284c7', '#84cc16', '#a855f7', '#f43f5e'],
                ],
                'dark' => [
                    'primary' => '#14b8a6',
                    'primary_foreground' => '#09090b',
                    'ring' => '#14b8a6',
                    'sidebar_primary' => '#14b8a6',
                    'chart' => ['#14b8a6', '#0284c7', '#84cc16', '#a855f7', '#f43f5e'],
                ],
            ],
            'rose' => [
                'name' => 'Crimson Rose',
                'description' => 'Bold ruby rose accent for vibrant, energetic dashboards.',
                'swatch' => '#e11d48',
                'light' => [
                    'primary' => '#e11d48',
                    'primary_foreground' => '#ffffff',
                    'ring' => '#e11d48',
                    'sidebar_primary' => '#e11d48',
                    'chart' => ['#f43f5e', '#fb7185', '#f59e0b', '#8b5cf6', '#06b6d4'],
                ],
                'dark' => [
                    'primary' => '#f43f5e',
                    'primary_foreground' => '#09090b',
                    'ring' => '#f43f5e',
                    'sidebar_primary' => '#f43f5e',
                    'chart' => ['#f43f5e', '#fb7185', '#f59e0b', '#8b5cf6', '#06b6d4'],
                ],
            ],
            'amber' => [
                'name' => 'Sunset Amber',
                'description' => 'Warm golden amber hues with welcoming contrast.',
                'swatch' => '#d97706',
                'light' => [
                    'primary' => '#d97706',
                    'primary_foreground' => '#ffffff',
                    'ring' => '#d97706',
                    'sidebar_primary' => '#d97706',
                    'chart' => ['#f59e0b', '#ea580c', '#10b981', '#3b82f6', '#8b5cf6'],
                ],
                'dark' => [
                    'primary' => '#f59e0b',
                    'primary_foreground' => '#09090b',
                    'ring' => '#f59e0b',
                    'sidebar_primary' => '#f59e0b',
                    'chart' => ['#f59e0b', '#ea580c', '#10b981', '#3b82f6', '#8b5cf6'],
                ],
            ],
            'violet' => [
                'name' => 'Violet Velvet',
                'description' => 'Rich purple & violet tones for premium educational portals.',
                'swatch' => '#7c3aed',
                'light' => [
                    'primary' => '#7c3aed',
                    'primary_foreground' => '#ffffff',
                    'ring' => '#7c3aed',
                    'sidebar_primary' => '#7c3aed',
                    'chart' => ['#8b5cf6', '#a855f7', '#ec4899', '#3b82f6', '#10b981'],
                ],
                'dark' => [
                    'primary' => '#8b5cf6',
                    'primary_foreground' => '#09090b',
                    'ring' => '#8b5cf6',
                    'sidebar_primary' => '#8b5cf6',
                    'chart' => ['#8b5cf6', '#a855f7', '#ec4899', '#3b82f6', '#10b981'],
                ],
            ],
            'slate' => [
                'name' => 'Slate Monochrome',
                'description' => 'Minimalist modern slate gray theme with sophisticated neutral styling.',
                'swatch' => '#334155',
                'light' => [
                    'primary' => '#334155',
                    'primary_foreground' => '#ffffff',
                    'ring' => '#334155',
                    'sidebar_primary' => '#334155',
                    'chart' => ['#475569', '#64748b', '#0ea5e9', '#10b981', '#f59e0b'],
                ],
                'dark' => [
                    'primary' => '#94a3b8',
                    'primary_foreground' => '#09090b',
                    'ring' => '#94a3b8',
                    'sidebar_primary' => '#94a3b8',
                    'chart' => ['#94a3b8', '#cbd5e1', '#0ea5e9', '#10b981', '#f59e0b'],
                ],
            ],
        ];

        foreach ($presets as &$preset) {
            $preset['colors'] = $preset['light'];
        }

        return $presets;
    }

    public static function presets(): array
    {
        return static::all();
    }

    public static function get(string $key): ?array
    {
        return static::all()[$key] ?? null;
    }

    /**
     * Resolve active dynamic CSS variables string for HTML head injection.
     */
    public static function generateCss(): string
    {
        $presetKey = (string) Setting::get('appearance.theme_preset', self::DEFAULT);
        $preset = static::get($presetKey) ?? static::get(self::DEFAULT);

        // Custom color overrides if specified
        $customPrimary = (string) Setting::get('appearance.primary_color', '');
        $customPrimaryDark = (string) Setting::get('appearance.primary_dark_color', '');
        $customRadius = (string) Setting::get('appearance.radius', '0.625rem');
        $fontFamily = (string) Setting::get('appearance.font_family', 'Geist');
        $customCss = (string) Setting::get('appearance.custom_css', '');

        $lightPrimary = $customPrimary ?: $preset['light']['primary'];
        $darkPrimary = $customPrimaryDark ?: ($customPrimary ?: $preset['dark']['primary']);
        $lightRing = $customPrimary ?: $preset['light']['ring'];
        $darkRing = $customPrimaryDark ?: ($customPrimary ?: $preset['dark']['ring']);
        $lightSidebarPrimary = $customPrimary ?: $preset['light']['sidebar_primary'];
        $darkSidebarPrimary = $darkPrimary ?: $preset['dark']['sidebar_primary'];

        $chart1Light = $preset['light']['chart'][0] ?? '#10b981';
        $chart2Light = $preset['light']['chart'][1] ?? '#06b6d4';
        $chart3Light = $preset['light']['chart'][2] ?? '#f59e0b';
        $chart4Light = $preset['light']['chart'][3] ?? '#8b5cf6';
        $chart5Light = $preset['light']['chart'][4] ?? '#f43f5e';

        $fontRule = match ($fontFamily) {
            'Inter' => "'Inter', ui-sans-serif, system-ui, sans-serif",
            'Plus Jakarta Sans' => "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif",
            'Roboto' => "'Roboto', ui-sans-serif, system-ui, sans-serif",
            'system-ui' => "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
            default => "'Geist', 'Instrument Sans', ui-sans-serif, system-ui, sans-serif",
        };

        $sidebarTheme = (string) Setting::get('appearance.sidebar_theme', 'default');

        $sidebarLightCss = match ($sidebarTheme) {
            'dark' => '
    --sidebar: #0f0f12;
    --sidebar-foreground: #d4d4d8;
    --sidebar-border: rgba(255, 255, 255, 0.08);
    --sidebar-accent: rgba(255, 255, 255, 0.08);
    --sidebar-accent-foreground: #fafafa;',
            'light' => '
    --sidebar: #ffffff;
    --sidebar-foreground: #0f172a;
    --sidebar-border: #e2e8f0;
    --sidebar-accent: #f1f5f9;
    --sidebar-accent-foreground: #0f172a;',
            default => '
    --sidebar: #f8fafc;
    --sidebar-foreground: #334155;
    --sidebar-border: #e2e8f0;
    --sidebar-accent: #e2e8f0;
    --sidebar-accent-foreground: #0f172a;',
        };

        $css = "
:root {
    --primary: {$lightPrimary};
    --primary-foreground: #ffffff;
    --ring: {$lightRing};
    --sidebar-primary: {$lightSidebarPrimary};
    --sidebar-primary-foreground: #ffffff;
    --sidebar-ring: {$lightRing};
    --chart-1: {$chart1Light};
    --chart-2: {$chart2Light};
    --chart-3: {$chart3Light};
    --chart-4: {$chart4Light};
    --chart-5: {$chart5Light};
    --radius: {$customRadius};
    --font-sans: {$fontRule};{$sidebarLightCss}
}
.dark {
    --primary: {$darkPrimary};
    --primary-foreground: #09090b;
    --ring: {$darkRing};
    --sidebar-primary: {$darkSidebarPrimary};
    --sidebar-primary-foreground: #fafafa;
    --sidebar-ring: {$darkRing};
    --chart-1: {$darkPrimary};
    --sidebar: #0f0f12;
    --sidebar-foreground: #d4d4d8;
    --sidebar-border: rgba(255, 255, 255, 0.06);
    --sidebar-accent: rgba(255, 255, 255, 0.06);
    --sidebar-accent-foreground: #fafafa;
}
body {
    font-family: var(--font-sans);
}
";

        if (! empty($customCss)) {
            $css .= "\n/* Custom CSS Overrides */\n".$customCss;
        }

        return $css;
    }
}
