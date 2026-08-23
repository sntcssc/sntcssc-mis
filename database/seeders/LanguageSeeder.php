<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $languages = [
            [
                'code' => 'en',
                'name' => 'English',
                'native_name' => 'English',
                'direction' => 'ltr',
                'flag' => '🇮🇳',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'hi',
                'name' => 'Hindi',
                'native_name' => 'हिन्दी',
                'direction' => 'ltr',
                'flag' => '🇮🇳',
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'code' => 'bn',
                'name' => 'Bengali',
                'native_name' => 'বাংলা',
                'direction' => 'ltr',
                'flag' => '🇮🇳',
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($languages as $data) {
            Language::updateOrCreate(['code' => $data['code']], $data);
        }
    }
}
