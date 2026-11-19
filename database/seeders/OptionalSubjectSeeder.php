<?php

namespace Database\Seeders;

use App\Helpers\UpscData;
use App\Models\OptionalSubject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OptionalSubjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $subjects = UpscData::getOptionalSubjects();

        $codeMap = [
            'Agriculture' => ['code' => 'AGRI', 'cat' => 'Science & Mathematics'],
            'Animal Husbandry and Veterinary Science' => ['code' => 'AHVS', 'cat' => 'Science & Mathematics'],
            'Anthropology' => ['code' => 'ANTH', 'cat' => 'Humanities & Social Sciences'],
            'Botany' => ['code' => 'BOT', 'cat' => 'Science & Mathematics'],
            'Chemistry' => ['code' => 'CHEM', 'cat' => 'Science & Mathematics'],
            'Civil Engineering' => ['code' => 'CE', 'cat' => 'Engineering'],
            'Commerce and Accountancy' => ['code' => 'COMM', 'cat' => 'Commerce & Management'],
            'Economics' => ['code' => 'ECON', 'cat' => 'Humanities & Social Sciences'],
            'Electrical Engineering' => ['code' => 'EE', 'cat' => 'Engineering'],
            'Geography' => ['code' => 'GEOG', 'cat' => 'Humanities & Social Sciences'],
            'Geology' => ['code' => 'GEOL', 'cat' => 'Science & Mathematics'],
            'History' => ['code' => 'HIST', 'cat' => 'Humanities & Social Sciences'],
            'Law' => ['code' => 'LAW', 'cat' => 'Humanities & Social Sciences'],
            'Management' => ['code' => 'MGMT', 'cat' => 'Commerce & Management'],
            'Mathematics' => ['code' => 'MATH', 'cat' => 'Science & Mathematics'],
            'Mechanical Engineering' => ['code' => 'ME', 'cat' => 'Engineering'],
            'Medical Science' => ['code' => 'MED', 'cat' => 'Science & Mathematics'],
            'Philosophy' => ['code' => 'PHIL', 'cat' => 'Humanities & Social Sciences'],
            'Physics' => ['code' => 'PHYS', 'cat' => 'Science & Mathematics'],
            'Political Science and International Relations' => ['code' => 'PSIR', 'cat' => 'Humanities & Social Sciences'],
            'Psychology' => ['code' => 'PSYC', 'cat' => 'Humanities & Social Sciences'],
            'Public Administration' => ['code' => 'PUBAD', 'cat' => 'Humanities & Social Sciences'],
            'Sociology' => ['code' => 'SOC', 'cat' => 'Humanities & Social Sciences'],
            'Statistics' => ['code' => 'STAT', 'cat' => 'Science & Mathematics'],
            'Zoology' => ['code' => 'ZOOL', 'cat' => 'Science & Mathematics'],
            'Literature of Assamese Language' => ['code' => 'LIT-ASM', 'cat' => 'Literature of Languages'],
            'Literature of Bengali Language' => ['code' => 'LIT-BEN', 'cat' => 'Literature of Languages'],
            'Literature of Bodo Language' => ['code' => 'LIT-BOD', 'cat' => 'Literature of Languages'],
            'Literature of Dogri Language' => ['code' => 'LIT-DOG', 'cat' => 'Literature of Languages'],
            'Literature of English Language' => ['code' => 'LIT-ENG', 'cat' => 'Literature of Languages'],
            'Literature of Gujarati Language' => ['code' => 'LIT-GUJ', 'cat' => 'Literature of Languages'],
            'Literature of Hindi Language' => ['code' => 'LIT-HIN', 'cat' => 'Literature of Languages'],
            'Literature of Kannada Language' => ['code' => 'LIT-KAN', 'cat' => 'Literature of Languages'],
            'Literature of Kashmiri Language' => ['code' => 'LIT-KAS', 'cat' => 'Literature of Languages'],
            'Literature of Konkani Language' => ['code' => 'LIT-KON', 'cat' => 'Literature of Languages'],
            'Literature of Maithili Language' => ['code' => 'LIT-MAI', 'cat' => 'Literature of Languages'],
            'Literature of Malayalam Language' => ['code' => 'LIT-MAL', 'cat' => 'Literature of Languages'],
            'Literature of Manipuri Language' => ['code' => 'LIT-MAN', 'cat' => 'Literature of Languages'],
            'Literature of Marathi Language' => ['code' => 'LIT-MAR', 'cat' => 'Literature of Languages'],
            'Literature of Nepali Language' => ['code' => 'LIT-NEP', 'cat' => 'Literature of Languages'],
            'Literature of Odia Language' => ['code' => 'LIT-ODI', 'cat' => 'Literature of Languages'],
            'Literature of Punjabi Language' => ['code' => 'LIT-PUN', 'cat' => 'Literature of Languages'],
            'Literature of Sanskrit Language' => ['code' => 'LIT-SKT', 'cat' => 'Literature of Languages'],
            'Literature of Santhali Language' => ['code' => 'LIT-SNT', 'cat' => 'Literature of Languages'],
            'Literature of Sindhi Language' => ['code' => 'LIT-SND', 'cat' => 'Literature of Languages'],
            'Literature of Tamil Language' => ['code' => 'LIT-TAM', 'cat' => 'Literature of Languages'],
            'Literature of Telugu Language' => ['code' => 'LIT-TEL', 'cat' => 'Literature of Languages'],
            'Literature of Urdu Language' => ['code' => 'LIT-URD', 'cat' => 'Literature of Languages'],
        ];

        foreach ($subjects as $name) {
            $cat = $codeMap[$name]['cat'] ?? 'Humanities & Social Sciences';
            $code = $codeMap[$name]['code'] ?? strtoupper(Str::slug($name, ''));

            OptionalSubject::updateOrCreate(
                ['name' => $name],
                [
                    'code' => $code,
                    'category' => $cat,
                    'description' => "Official UPSC Civil Services Examination Optional Subject - {$name}.",
                    'is_active' => true,
                ]
            );
        }
    }
}
