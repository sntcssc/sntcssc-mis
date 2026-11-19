<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Course;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialFile;
use App\Models\StudyMaterialType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudyMaterialSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $course = Course::first();
        $batch = Batch::first();
        $user = User::first();

        // 1. Ensure Standard Material Types exist
        $types = [
            [
                'name' => 'Lecture Handouts',
                'code' => 'handout',
                'icon' => 'file-text',
                'badge_color' => 'blue',
                'description' => 'Comprehensive classroom lecture summaries and analytical handouts',
                'is_public' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Current Affairs Capsules',
                'code' => 'current_affairs',
                'icon' => 'globe',
                'badge_color' => 'emerald',
                'description' => 'Monthly compilations of national and international developments',
                'is_public' => true,
                'display_order' => 2,
            ],
            [
                'name' => 'PIB Sandhan',
                'code' => 'pib_sandhan',
                'icon' => 'newspaper',
                'badge_color' => 'amber',
                'description' => 'Press Information Bureau government releases, schemes, and policy digests',
                'is_public' => true,
                'display_order' => 3,
            ],
            [
                'name' => 'E-Books & Compendiums',
                'code' => 'ebook',
                'icon' => 'book-open',
                'badge_color' => 'purple',
                'description' => 'Official standard reference textbooks and subject monographs',
                'is_public' => false,
                'display_order' => 4,
            ],
        ];

        foreach ($types as $t) {
            StudyMaterialType::updateOrCreate(['code' => $t['code']], $t);
        }

        // 2. Create Sample Published Study Material
        $material1 = StudyMaterial::updateOrCreate(
            ['slug' => 'upsc-csat-gs-paper-2-analytical-reasoning'],
            [
                'uuid' => (string) Str::uuid(),
                'course_id' => $course?->id,
                'title' => 'UPSC CSAT General Studies Paper II — Analytical Reasoning & Data Interpretation',
                'subject' => 'General Studies (CSAT)',
                'topic' => 'Analytical Reasoning and Problem Solving',
                'module_code' => 'CSAT-MOD-01',
                'material_type' => 'handout',
                'description' => 'Complete lecture handout containing conceptual clarity, shortcut techniques, and 50 practice problems for UPSC Civil Services preliminary aspirants.',
                'content' => 'Comprehensive analytical problem sets, previous year questions (PYQs) 2018-2025, and step-by-step model solutions.',
                'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
                'status' => StudyMaterial::STATUS_PUBLISHED,
                'is_pinned' => true,
                'display_order' => 1,
                'view_count' => 142,
                'download_count' => 89,
                'class_date' => now()->subDays(2),
                'created_by' => $user?->id,
            ]
        );

        if ($batch) {
            $material1->batches()->syncWithoutDetaching([$batch->id]);
        }

        // Ensure physical sample file exists on storage
        $sampleRelativePath = 'study_materials/sample_csat_reasoning_handout.pdf';
        if (! Storage::disk('local')->exists($sampleRelativePath)) {
            Storage::disk('local')->put($sampleRelativePath, "%PDF-1.4\n1 0 obj\n<< /Title (UPSC CSAT Reasoning Handout) /Author (SNTCSSC) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");
        }

        StudyMaterialFile::updateOrCreate(
            ['study_material_id' => $material1->id, 'file_name' => 'UPSC_CSAT_Analytical_Reasoning_Handout_01.pdf'],
            [
                'file_path' => $sampleRelativePath,
                'file_size' => 1428500,
                'file_type' => 'pdf',
                'mime_type' => 'application/pdf',
                'disk' => 'local',
                'download_count' => 45,
                'display_order' => 1,
            ]
        );

        // 3. Create Sample Public Material (PIB Sandhan / Current Affairs)
        $material2 = StudyMaterial::updateOrCreate(
            ['slug' => 'pib-sandhan-october-2026-monthly-digest'],
            [
                'uuid' => (string) Str::uuid(),
                'course_id' => null, // Public
                'title' => 'PIB Sandhan — October 2026 Monthly Digest of Government Schemes & Initiatives',
                'subject' => 'Current Affairs & Governance',
                'topic' => 'Union Cabinet Decisions & National Schemes',
                'module_code' => 'PIB-2026-10',
                'material_type' => 'pib_sandhan',
                'description' => 'Curated monthly compendium of PIB announcements, official gazette digests, and socio-economic welfare policies for Civil Services preparation.',
                'content' => 'High-yield coverage of flagship schemes, infrastructure corridors, and green energy missions.',
                'access_level' => StudyMaterial::ACCESS_PUBLIC_LINK,
                'status' => StudyMaterial::STATUS_PUBLISHED,
                'is_pinned' => true,
                'display_order' => 2,
                'view_count' => 520,
                'download_count' => 312,
                'class_date' => now()->subDay(),
                'created_by' => $user?->id,
            ]
        );

        $samplePibPath = 'study_materials/pib_sandhan_october_2026.pdf';
        if (! Storage::disk('local')->exists($samplePibPath)) {
            Storage::disk('local')->put($samplePibPath, "%PDF-1.4\n1 0 obj\n<< /Title (PIB Sandhan October 2026) /Author (SNTCSSC) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");
        }

        StudyMaterialFile::updateOrCreate(
            ['study_material_id' => $material2->id, 'file_name' => 'PIB_Sandhan_October_2026_Digest.pdf'],
            [
                'file_path' => $samplePibPath,
                'file_size' => 2854000,
                'file_type' => 'pdf',
                'mime_type' => 'application/pdf',
                'disk' => 'local',
                'download_count' => 120,
                'display_order' => 1,
            ]
        );
    }
}
