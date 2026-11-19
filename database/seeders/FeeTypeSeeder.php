<?php

namespace Database\Seeders;

use App\Models\FeeType;
use Illuminate\Database\Seeder;

class FeeTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'code' => 'tuition',
                'name' => 'Monthly Tuition Fee',
                'category' => FeeType::CATEGORY_ACADEMIC,
                'frequency' => FeeType::FREQUENCY_MONTHLY,
                'description' => 'Standard monthly academic tuition and mentoring fees for course enrollments.',
                'default_amount' => 1000.00,
                'is_refundable' => false,
                'status' => 'active',
            ],
            [
                'code' => 'library_security',
                'name' => 'Library Security Deposit',
                'category' => FeeType::CATEGORY_LIBRARY,
                'frequency' => FeeType::FREQUENCY_ONE_TIME,
                'description' => 'Fully refundable institutional security deposit for reading room, book lending, and digital library resources.',
                'default_amount' => 1000.00,
                'is_refundable' => true,
                'status' => 'active',
            ],
            [
                'code' => 'registration',
                'name' => 'Admission Registration Fee',
                'category' => FeeType::CATEGORY_ADMISSION,
                'frequency' => FeeType::FREQUENCY_ONE_TIME,
                'description' => 'One-time student enrolment and document verification administrative fee.',
                'default_amount' => 500.00,
                'is_refundable' => false,
                'status' => 'active',
            ],
            [
                'code' => 'admission_test',
                'name' => 'Entrance Screening Test Fee',
                'category' => FeeType::CATEGORY_ADMISSION,
                'frequency' => FeeType::FREQUENCY_ONE_TIME,
                'description' => 'Preliminary candidate screening and computer-based admission test processing charge.',
                'default_amount' => 200.00,
                'is_refundable' => false,
                'status' => 'active',
            ],
            [
                'code' => 'examination',
                'name' => 'Periodic Assessment & Test Series Fee',
                'category' => FeeType::CATEGORY_ACADEMIC,
                'frequency' => FeeType::FREQUENCY_QUARTERLY,
                'description' => 'Evaluation, OMR scanning, mock interview, and question booklet printing charge.',
                'default_amount' => 300.00,
                'is_refundable' => false,
                'status' => 'active',
            ],
        ];

        foreach ($types as $type) {
            FeeType::updateOrCreate(['code' => $type['code']], $type);
        }
    }
}
