<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Super Administrator', 'Administrator']))->first()
            ?? User::first();

        // 1. Seed Categories
        $categoriesData = [
            [
                'name' => 'Academic Circulars & Syllabi',
                'slug' => 'academic-circulars-syllabi',
                'description' => 'Official curriculum guidelines, course syllabi, foundational reading plans, and batch notices.',
                'icon' => 'graduation-cap',
                'color' => 'blue',
                'display_order' => 1,
            ],
            [
                'name' => 'Administrative Orders & Gazette',
                'slug' => 'administrative-orders-gazette',
                'description' => 'Government gazettes, executive orders, faculty postings, and governing board resolutions.',
                'icon' => 'landmark',
                'color' => 'indigo',
                'display_order' => 2,
            ],
            [
                'name' => 'Examinations & Evaluation Guidelines',
                'slug' => 'examinations-evaluation-guidelines',
                'description' => 'Test series rubrics, UPSC prelims/mains evaluative criteria, and mock marksheet templates.',
                'icon' => 'clipboard-check',
                'color' => 'red',
                'display_order' => 3,
            ],
            [
                'name' => 'Policy Manuals & Regulations',
                'slug' => 'policy-manuals-regulations',
                'description' => 'Hostel conduct regulations, library borrowing bylaws, and IT infrastructure security policies.',
                'icon' => 'shield',
                'color' => 'emerald',
                'display_order' => 4,
            ],
            [
                'name' => 'MoUs & Institutional Partnerships',
                'slug' => 'mous-institutional-partnerships',
                'description' => 'Bilateral agreements, coaching partner contracts, and university academic linkages.',
                'icon' => 'building-2',
                'color' => 'purple',
                'display_order' => 5,
            ],
            [
                'name' => 'Financial Reports & Tenders',
                'slug' => 'financial-reports-tenders',
                'description' => 'Audited financial balance sheets, procurement tender notices, and treasury allocations.',
                'icon' => 'banknote',
                'color' => 'amber',
                'display_order' => 6,
            ],
            [
                'name' => 'Presentations & Institutional Media',
                'slug' => 'presentations-institutional-media',
                'description' => 'Annual orientation slide decks, high-resolution logos, and seminar visual guides.',
                'icon' => 'image',
                'color' => 'pink',
                'display_order' => 7,
            ],
            [
                'name' => 'Archives & Compressed Packages',
                'slug' => 'archives-compressed-packages',
                'description' => 'Multi-document bundles, past year question archives, and consolidated asset packages.',
                'icon' => 'file-archive',
                'color' => 'orange',
                'display_order' => 8,
            ],
        ];

        $categories = [];
        foreach ($categoriesData as $catData) {
            $categories[$catData['slug']] = DocumentCategory::updateOrCreate(
                ['slug' => $catData['slug']],
                array_merge($catData, [
                    'is_active' => true,
                    'created_by' => $admin?->id,
                    'updated_by' => $admin?->id,
                ])
            );
        }

        // Helper to generate real sample files on storage disk
        $createSampleFile = function (string $folder, string $fileName, string $content, string $type = 'text') {
            Storage::disk('public')->makeDirectory($folder);
            $fullPath = $folder.'/'.$fileName;

            if (! Storage::disk('public')->exists($fullPath)) {
                if ($type === 'pdf') {
                    $pdf = Pdf::loadHTML($content)->setPaper('a4', 'portrait');
                    Storage::disk('public')->put($fullPath, $pdf->output());
                } else {
                    Storage::disk('public')->put($fullPath, $content);
                }
            }

            return $fullPath;
        };

        // --- Document 1: UPSC Civil Services Examination Comprehensive Syllabus 2026-27 (With Version 1.0, 1.1, 2.0) ---
        $doc1 = Document::updateOrCreate(
            ['document_number' => 'DOC-2026-0001'],
            [
                'title' => 'UPSC Civil Services Examination Comprehensive Syllabus & Scheme (2026-2027)',
                'purpose' => 'Curriculum Blueprint & Reading Roadmap',
                'document_category_id' => $categories['academic-circulars-syllabi']->id,
                'department' => 'Academics',
                'tags' => ['upsc', 'syllabus', 'prelims', 'mains', 'curriculum', '2026'],
                'access_level' => 'public',
                'status' => 'published',
                'description' => 'Complete institutional breakdown of General Studies Papers I-IV, CSAT Strategy, Essay Paper framework, and Optional Subjects syllabus approved by the Academic Council.',
                'notes' => 'Mandatory distribution during initial student orientation sessions.',
                'remarks' => 'Ratified by Academic Advisory Council on 12 April 2026.',
                'total_versions_count' => 3,
                'total_downloads_count' => 142,
                'created_by' => $admin?->id,
                'updated_by' => $admin?->id,
            ]
        );

        $folder1 = 'documents/'.Str::slug($doc1->document_number);

        // v1.0
        $path1_v1 = $createSampleFile($folder1, 'v1.0_syllabus_initial.pdf', "
            <div style='font-family: sans-serif; padding: 30px; border: 2px solid #1e3a8a;'>
                <h2 style='color:#1e3a8a; text-align:center;'>Satyendranath Tagore Civil Services Study Centre</h2>
                <h3 style='text-align:center;'>UPSC CSE Comprehensive Syllabus (v1.0)</h3>
                <hr/>
                <p><strong>Release Date:</strong> 01 Apr 2026</p>
                <p><strong>Status:</strong> Historical Release</p>
                <p>Initial foundational syllabus approved for Prelims & Mains 2026-27.</p>
            </div>
        ", 'pdf');

        $v1 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc1->id, 'version_number' => '1.0'],
            [
                'revision_sequence' => 1,
                'version_title' => 'Initial Approved Syllabus',
                'file_path' => $path1_v1,
                'original_file_name' => 'SNTCSSC_UPSC_Syllabus_2026_v1.0.pdf',
                'file_type' => 'pdf',
                'mime_type' => 'application/pdf',
                'file_size' => Storage::disk('public')->size($path1_v1),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path1_v1)),
                'change_summary' => 'Initial publication of annual civil services curriculum.',
                'is_current' => false,
                'download_count' => 45,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2026-04-01'),
            ]
        );

        // v1.1
        $path1_v11 = $createSampleFile($folder1, 'v1.1_syllabus_interim.pdf', "
            <div style='font-family: sans-serif; padding: 30px; border: 2px solid #1e3a8a;'>
                <h2 style='color:#1e3a8a; text-align:center;'>Satyendranath Tagore Civil Services Study Centre</h2>
                <h3 style='text-align:center;'>UPSC CSE Comprehensive Syllabus (v1.1 - Interim Revision)</h3>
                <hr/>
                <p><strong>Release Date:</strong> 25 Jun 2026</p>
                <p><strong>Status:</strong> Historical Revision</p>
                <p>Integrated updated Science & Tech and Environment & Ecology reading list.</p>
            </div>
        ", 'pdf');

        $v11 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc1->id, 'version_number' => '1.1'],
            [
                'revision_sequence' => 2,
                'version_title' => 'Interim Science & Environment Module Update',
                'file_path' => $path1_v11,
                'original_file_name' => 'SNTCSSC_UPSC_Syllabus_2026_v1.1_ScienceUpdate.pdf',
                'file_type' => 'pdf',
                'mime_type' => 'application/pdf',
                'file_size' => Storage::disk('public')->size($path1_v11),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path1_v11)),
                'change_summary' => 'Added contemporary Science & Tech references and biodiversity conventions modules.',
                'is_current' => false,
                'download_count' => 38,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2026-06-25'),
            ]
        );

        // v2.0 (Active Current)
        $path1_v2 = $createSampleFile($folder1, 'v2.0_syllabus_final.pdf', "
            <div style='font-family: sans-serif; padding: 30px; border: 2px solid #1e3a8a;'>
                <h2 style='color:#1e3a8a; text-align:center;'>Satyendranath Tagore Civil Services Study Centre</h2>
                <h3 style='text-align:center;'>UPSC CSE Comprehensive Syllabus (v2.0 - Final Gazette Edition)</h3>
                <hr/>
                <p><strong>Release Date:</strong> 15 Oct 2026</p>
                <p><strong>Status:</strong> CURRENT ACTIVE VERSION</p>
                <p>Incorporated latest UPSC Gazette Notification guidelines, revised CSAT qualifying threshold strategies, and dynamic optional papers matrix.</p>
            </div>
        ", 'pdf');

        $v2 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc1->id, 'version_number' => '2.0'],
            [
                'revision_sequence' => 3,
                'version_title' => 'Final Gazette Edition with CSAT Strategy & Ethics Case Matrix',
                'file_path' => $path1_v2,
                'original_file_name' => 'SNTCSSC_UPSC_Syllabus_2026_v2.0_Final.pdf',
                'file_type' => 'pdf',
                'mime_type' => 'application/pdf',
                'file_size' => Storage::disk('public')->size($path1_v2),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path1_v2)),
                'change_summary' => 'Complete overhaul of Ethics Paper IV case study frameworks and CSAT quantitative reasoning guidelines.',
                'is_current' => true,
                'download_count' => 59,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2026-10-15'),
            ]
        );

        $doc1->update(['current_version_id' => $v2->id]);

        // --- Document 2: Hostel Code of Conduct & Residence Regulations (Word Document / DOCX) ---
        $doc2 = Document::updateOrCreate(
            ['document_number' => 'DOC-2026-0002'],
            [
                'title' => 'Institutional Hostel Code of Conduct & Resident Bylaws',
                'purpose' => 'Administrative Regulation & Resident Code',
                'document_category_id' => $categories['policy-manuals-regulations']->id,
                'department' => 'Administration',
                'tags' => ['hostel', 'conduct', 'regulations', 'discipline', 'curfew'],
                'access_level' => 'internal',
                'status' => 'published',
                'description' => 'Statutory regulations governing residential life, attendance check-in biometric procedures, mess timings, visitor norms, and anti-ragging undertakings.',
                'notes' => 'Requires student and guardian physical signatures at check-in.',
                'remarks' => 'Enforced across all boys and girls hostel wings.',
                'total_versions_count' => 2,
                'total_downloads_count' => 88,
                'created_by' => $admin?->id,
                'updated_by' => $admin?->id,
            ]
        );

        $folder2 = 'documents/'.Str::slug($doc2->document_number);
        $path2_v1 = $createSampleFile($folder2, 'v1.0_hostel_code.docx', "SNTCSSC Hostel Code of Conduct v1.0\n1. Attendance Biometrics\n2. Mess Timings\n3. Visitor Security", 'doc');
        $doc2_v1 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc2->id, 'version_number' => '1.0'],
            [
                'revision_sequence' => 1,
                'version_title' => 'Initial Hostel Regulations',
                'file_path' => $path2_v1,
                'original_file_name' => 'Hostel_Rules_and_Discipline_Manual_2026_v1.0.docx',
                'file_type' => 'docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'file_size' => Storage::disk('public')->size($path2_v1),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path2_v1)),
                'change_summary' => 'Initial hostel regulations release.',
                'is_current' => false,
                'download_count' => 28,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2026-04-01'),
            ]
        );

        $path2_v2 = $createSampleFile($folder2, 'v1.1_hostel_code_revised.docx', "SNTCSSC Hostel Code of Conduct v1.1 (Revised)\nIncludes updated biometric gate check-in hours and visitor pre-clearance rules.", 'doc');
        $doc2_v2 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc2->id, 'version_number' => '1.1'],
            [
                'revision_sequence' => 2,
                'version_title' => 'Revised Biometric Curfew Timings & Extension Request Policy',
                'file_path' => $path2_v2,
                'original_file_name' => 'Hostel_Rules_and_Discipline_Manual_2026_v1.1_Revised.docx',
                'file_type' => 'docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'file_size' => Storage::disk('public')->size($path2_v2),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path2_v2)),
                'change_summary' => 'Updated late entry curfew thresholds and emergency contact protocol.',
                'is_current' => true,
                'download_count' => 60,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2026-08-10'),
            ]
        );

        $doc2->update(['current_version_id' => $doc2_v2->id]);

        // --- Document 3: Annual Institutional Budget & Financial Ledger 2026-27 (Excel Spreadsheet / XLSX) ---
        $doc3 = Document::updateOrCreate(
            ['document_number' => 'DOC-2026-0003'],
            [
                'title' => 'Institutional Operational Budget Allocation & Expenditure Tracker (2026-2027)',
                'purpose' => 'Financial Planning & Audit Tracking',
                'document_category_id' => $categories['financial-reports-tenders']->id,
                'department' => 'Finance & Accounts',
                'tags' => ['finance', 'budget', 'excel', 'ledger', 'accounts', '2026'],
                'access_level' => 'confidential',
                'status' => 'published',
                'description' => 'Itemized budgetary heads across academic honoraria, library acquisitions, mock interview panels, hostel maintenance, and scholarship disbursements.',
                'notes' => 'Strict access restricted to Accounts Officer and Principal Secretary.',
                'remarks' => 'Submitted to Higher Education Department, Govt. of West Bengal.',
                'total_versions_count' => 2,
                'total_downloads_count' => 31,
                'created_by' => $admin?->id,
                'updated_by' => $admin?->id,
            ]
        );

        $folder3 = 'documents/'.Str::slug($doc3->document_number);
        $path3_v1 = $createSampleFile($folder3, 'v1.0_budget_initial.xlsx', "Budget Allocation 2026-27\nHeads,Allotted,Disbursed", 'xlsx');
        $doc3_v1 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc3->id, 'version_number' => '1.0'],
            [
                'revision_sequence' => 1,
                'version_title' => 'Approved Annual Budget Plan',
                'file_path' => $path3_v1,
                'original_file_name' => 'SNTCSSC_Budget_Plan_2026_27_Approved.xlsx',
                'file_type' => 'xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'file_size' => Storage::disk('public')->size($path3_v1),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path3_v1)),
                'change_summary' => 'Initial approved budget spreadsheet.',
                'is_current' => false,
                'download_count' => 12,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2026-04-01'),
            ]
        );

        $path3_v2 = $createSampleFile($folder3, 'v2.0_budget_q2_adjusted.xlsx', "Budget Allocation 2026-27 (Q2 Re-allocated)\nHeads,Allotted,Disbursed,Revised", 'xlsx');
        $doc3_v2 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc3->id, 'version_number' => '2.0'],
            [
                'revision_sequence' => 2,
                'version_title' => 'Q2 Supplementary Re-appropriation & Actuals',
                'file_path' => $path3_v2,
                'original_file_name' => 'SNTCSSC_Budget_Tracker_2026_27_Q2_Revised.xlsx',
                'file_type' => 'xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'file_size' => Storage::disk('public')->size($path3_v2),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path3_v2)),
                'change_summary' => 'Mid-term re-allocation of Mock Interview honoraria and digital library funds.',
                'is_current' => true,
                'download_count' => 19,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2026-10-01'),
            ]
        );

        $doc3->update(['current_version_id' => $doc3_v2->id]);

        // --- Document 4: Institutional Orientation Master Slide Deck (PPTX) ---
        $doc4 = Document::updateOrCreate(
            ['document_number' => 'DOC-2026-0004'],
            [
                'title' => 'Foundation Course Induction & Civil Services Strategy Presentation (2026)',
                'purpose' => 'Induction Seminar Visual Guide',
                'document_category_id' => $categories['presentations-institutional-media']->id,
                'department' => 'Academics',
                'tags' => ['presentation', 'induction', 'orientation', 'slides', 'strategy'],
                'access_level' => 'internal',
                'status' => 'published',
                'description' => 'Executive 60-slide induction deck covering curriculum overview, daily study routines, answer writing pedagogy, and previous year topper case studies.',
                'notes' => 'Used by senior faculty during inaugural student bootcamps.',
                'remarks' => 'Updated with 2025 UPSC CSE final results statistics.',
                'total_versions_count' => 1,
                'total_downloads_count' => 64,
                'created_by' => $admin?->id,
                'updated_by' => $admin?->id,
            ]
        );

        $folder4 = 'documents/'.Str::slug($doc4->document_number);
        $path4_v1 = $createSampleFile($folder4, 'v1.0_orientation_slides.pptx', 'SNTCSSC Induction Presentation Deck 2026-27', 'pptx');
        $doc4_v1 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc4->id, 'version_number' => '1.0'],
            [
                'revision_sequence' => 1,
                'version_title' => 'Annual Induction Slide Deck',
                'file_path' => $path4_v1,
                'original_file_name' => 'SNTCSSC_Induction_Strategy_Deck_2026_Final.pptx',
                'file_type' => 'pptx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'file_size' => Storage::disk('public')->size($path4_v1),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path4_v1)),
                'change_summary' => 'Initial release of foundation induction master slides.',
                'is_current' => true,
                'download_count' => 64,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2026-04-10'),
            ]
        );

        $doc4->update(['current_version_id' => $doc4_v1->id]);

        // --- Document 5: Previous 10 Years UPSC Prelims Solved Papers Bundle (ZIP Archive) ---
        $doc5 = Document::updateOrCreate(
            ['document_number' => 'DOC-2026-0005'],
            [
                'title' => 'UPSC Prelims 10-Year Question Papers & Explanatory Solutions Archive (2016-2025)',
                'purpose' => 'Study Repository & Archival Package',
                'document_category_id' => $categories['archives-compressed-packages']->id,
                'department' => 'Examinations',
                'tags' => ['archive', 'zip', 'prelims', 'pyq', 'solutions', 'past-papers'],
                'access_level' => 'public',
                'status' => 'published',
                'description' => 'Comprehensive compressed zip archive packaging past 10 years Prelims GS Paper 1 and CSAT papers with detailed sectional topic-wise analysis.',
                'notes' => 'High-demand package for enrolled batches.',
                'remarks' => 'Verified by Department of Examination Research.',
                'total_versions_count' => 2,
                'total_downloads_count' => 197,
                'created_by' => $admin?->id,
                'updated_by' => $admin?->id,
            ]
        );

        $folder5 = 'documents/'.Str::slug($doc5->document_number);
        $path5_v1 = $createSampleFile($folder5, 'v1.0_prelims_pyq.zip', 'ZIP ARCHIVE CONTENT [PYQ 2015-2024]', 'zip');
        $doc5_v1 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc5->id, 'version_number' => '1.0'],
            [
                'revision_sequence' => 1,
                'version_title' => 'PYQ Archive 2015-2024',
                'file_path' => $path5_v1,
                'original_file_name' => 'UPSC_Prelims_PYQ_Archive_2015_2024.zip',
                'file_type' => 'zip',
                'mime_type' => 'application/zip',
                'file_size' => Storage::disk('public')->size($path5_v1),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path5_v1)),
                'change_summary' => 'Initial package containing papers up to 2024.',
                'is_current' => false,
                'download_count' => 76,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2025-05-15'),
            ]
        );

        $path5_v2 = $createSampleFile($folder5, 'v2.0_prelims_pyq_2025_added.zip', 'ZIP ARCHIVE CONTENT [PYQ 2016-2025 with Official Answer Keys]', 'zip');
        $doc5_v2 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc5->id, 'version_number' => '2.0'],
            [
                'revision_sequence' => 2,
                'version_title' => 'Updated Archive Including 2025 Official Answer Key',
                'file_path' => $path5_v2,
                'original_file_name' => 'UPSC_Prelims_PYQ_Archive_2016_2025_Certified.zip',
                'file_type' => 'zip',
                'mime_type' => 'application/zip',
                'file_size' => Storage::disk('public')->size($path5_v2),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path5_v2)),
                'change_summary' => 'Appended 2025 official question paper with UPSC ratified answer keys and cut-off analysis.',
                'is_current' => true,
                'download_count' => 121,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2026-07-01'),
            ]
        );

        $doc5->update(['current_version_id' => $doc5_v2->id]);

        // --- Document 6: Institutional High-Resolution Logo & Official Seal Package (Image / PNG) ---
        $doc6 = Document::updateOrCreate(
            ['document_number' => 'DOC-2026-0006'],
            [
                'title' => 'Official Institutional Seal, Crest & Emblem Vector Graphic Assets',
                'purpose' => 'Brand & Visual Identity Standards',
                'document_category_id' => $categories['presentations-institutional-media']->id,
                'department' => 'IT & Media',
                'tags' => ['crest', 'logo', 'image', 'png', 'branding', 'graphics'],
                'access_level' => 'public',
                'status' => 'published',
                'description' => 'Certified high-resolution transparent PNG emblem and vector assets for stationery, test question papers, certificates, and publications.',
                'notes' => 'Must not be altered in hue or proportions.',
                'remarks' => 'Approved by Director General.',
                'total_versions_count' => 1,
                'total_downloads_count' => 43,
                'created_by' => $admin?->id,
                'updated_by' => $admin?->id,
            ]
        );

        $folder6 = 'documents/'.Str::slug($doc6->document_number);
        $path6_v1 = $createSampleFile($folder6, 'v1.0_emblem.png', 'PNG IMAGE DATA', 'png');
        $doc6_v1 = DocumentVersion::updateOrCreate(
            ['document_id' => $doc6->id, 'version_number' => '1.0'],
            [
                'revision_sequence' => 1,
                'version_title' => 'Certified Institutional Crest & Emblem',
                'file_path' => $path6_v1,
                'original_file_name' => 'SNTCSSC_Official_Emblem_HighRes_Transparent.png',
                'file_type' => 'png',
                'mime_type' => 'image/png',
                'file_size' => Storage::disk('public')->size($path6_v1),
                'file_hash' => hash_file('sha256', Storage::disk('public')->path($path6_v1)),
                'change_summary' => 'Initial release of high-resolution graphic branding assets.',
                'is_current' => true,
                'download_count' => 43,
                'uploaded_by' => $admin?->id,
                'effective_date' => Carbon::parse('2026-01-01'),
            ]
        );

        $doc6->update(['current_version_id' => $doc6_v1->id]);
    }
}
