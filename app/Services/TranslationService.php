<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranslationService
{
    /**
     * Get all translation strings for a locale from lang/{locale}.json.
     *
     * @return array<string, string>
     */
    public static function getTranslations(string $locale): array
    {
        $path = base_path("lang/{$locale}.json");

        if (! File::exists($path)) {
            return [];
        }

        try {
            $content = File::get($path);
            $decoded = json_decode($content, true);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            Log::warning("Failed to read translation file for [{$locale}]: ".$e->getMessage());

            return [];
        }
    }

    /**
     * Get all translation keys present across English or fallback files.
     *
     * @return array<int, string>
     */
    public static function getAllKeys(): array
    {
        $en = static::getTranslations('en');
        $hi = static::getTranslations('hi');
        $bn = static::getTranslations('bn');

        $keys = array_unique(array_merge(array_keys($en), array_keys($hi), array_keys($bn)));
        sort($keys);

        return $keys;
    }

    /**
     * Save updated translations array for a locale.
     *
     * @param  array<string, string>  $translations
     */
    public static function saveTranslations(string $locale, array $translations): bool
    {
        $dir = base_path('lang');

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $path = "{$dir}/{$locale}.json";

        try {
            ksort($translations);
            $json = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            File::put($path, $json);

            return true;
        } catch (Throwable $e) {
            Log::error("Failed to save translation file for [{$locale}]: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Update a single translation string.
     */
    public static function setTranslation(string $locale, string $key, string $value): bool
    {
        $translations = static::getTranslations($locale);
        $translations[$key] = $value;

        return static::saveTranslations($locale, $translations);
    }

    /**
     * Delete a single translation string.
     */
    public static function deleteTranslation(string $locale, string $key): bool
    {
        $translations = static::getTranslations($locale);
        unset($translations[$key]);

        return static::saveTranslations($locale, $translations);
    }
}
