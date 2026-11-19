<?php

namespace App\Helpers;

class CategoryData
{
    private static ?array $data = null;

    private static function load(): array
    {
        if (self::$data === null) {
            $path = resource_path('data/categories.json');
            self::$data = json_decode(file_get_contents($path), true);
        }

        return self::$data;
    }

    public static function getCategories(): array
    {
        return self::load()['categories'] ?? [];
    }

    public static function getCategory(?string $code): ?array
    {
        return self::getCategories()[$code] ?? null;
    }

    public static function getCategoryOptions(): array
    {
        $categories = self::getCategories();
        $options = [];

        foreach ($categories as $code => $config) {
            $options[$code] = $config['label'];
        }

        return $options;
    }

    public static function getSubcastes(?string $categoryCode): array
    {
        $category = self::getCategory($categoryCode);

        if (! $category || ! ($category['has_subcaste'] ?? false)) {
            return [];
        }

        $subcastes = $category['subcastes'] ?? [];

        // Handle nested structure (OBC has A/B subcategories)
        if (is_array($subcastes) && ! empty($subcastes) && isset($subcastes[array_key_first($subcastes)]['items'])) {
            $flat = [];
            foreach ($subcastes as $subKey => $subGroup) {
                foreach ($subGroup['items'] as $item) {
                    $flat[] = $item;
                }
            }

            return $flat;
        }

        return $subcastes;
    }

    public static function getSubcasteGroups(?string $categoryCode): array
    {
        $category = self::getCategory($categoryCode);

        if (! $category || ! ($category['has_subcaste'] ?? false)) {
            return [];
        }

        $subcastes = $category['subcastes'] ?? [];

        // Check if it's a grouped structure (like OBC)
        if (is_array($subcastes) && ! empty($subcastes) && isset($subcastes[array_key_first($subcastes)]['items'])) {
            return $subcastes;
        }

        // Return as a single flat group
        return [
            'default' => [
                'label' => $category['label'],
                'items' => $subcastes,
            ],
        ];
    }

    public static function hasSubcategory(?string $categoryCode): bool
    {
        $category = self::getCategory($categoryCode);

        return $category && ($category['has_subcategory'] ?? false);
    }

    public static function hasSubcaste(?string $categoryCode): bool
    {
        $category = self::getCategory($categoryCode);

        return $category && ($category['has_subcaste'] ?? false);
    }

    public static function hasCreamyLayer(?string $categoryCode): bool
    {
        $category = self::getCategory($categoryCode);

        return $category && ($category['has_creamy_layer'] ?? false);
    }

    public static function getSubcategoryOptions(?string $categoryCode): array
    {
        $category = self::getCategory($categoryCode);

        return $category['subcategory_options'] ?? [];
    }
}
