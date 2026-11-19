<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Dynamic Study Material Types Catalog
        Schema::create('study_material_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 50)->default('file-text');
            $table->string('badge_color', 30)->default('primary');
            $table->boolean('is_public')->default(true)->index(); // Allowed for external web / mobile download catalogue
            $table->boolean('is_system')->default(false); // Core default system type
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('display_order')->default(0)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Allow course_id on study_materials to be nullable for general/standalone publications
        if (Schema::hasTable('study_materials') && Schema::hasColumn('study_materials', 'course_id')) {
            Schema::table('study_materials', function (Blueprint $table) {
                $table->unsignedBigInteger('course_id')->nullable()->change();
            });
        }

        // 3. Extend study_material_access_logs to record public visitor leads and verification
        if (Schema::hasTable('study_material_access_logs')) {
            Schema::table('study_material_access_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('study_material_access_logs', 'subscriber_id')) {
                    $table->foreignId('subscriber_id')->nullable()->after('user_id')->constrained('subscribers')->nullOnDelete();
                }
                if (! Schema::hasColumn('study_material_access_logs', 'guest_name')) {
                    $table->string('guest_name')->nullable()->after('action');
                }
                if (! Schema::hasColumn('study_material_access_logs', 'guest_email')) {
                    $table->string('guest_email')->nullable()->after('guest_name');
                }
                if (! Schema::hasColumn('study_material_access_logs', 'guest_phone')) {
                    $table->string('guest_phone')->nullable()->after('guest_email');
                }
                if (! Schema::hasColumn('study_material_access_logs', 'is_verified')) {
                    $table->boolean('is_verified')->default(false)->after('guest_phone')->index();
                }
                if (! Schema::hasColumn('study_material_access_logs', 'metadata')) {
                    $table->json('metadata')->nullable()->after('user_agent');
                }
            });
        }

        // 4. Seed default types
        $defaultTypes = [
            [
                'name' => 'Monthly Current Affairs',
                'code' => 'current_affairs',
                'description' => 'Comprehensive monthly current affairs compendium for UPSC Civil Services examination.',
                'icon' => 'newspaper',
                'badge_color' => 'emerald',
                'is_public' => true,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'PIB Sandhan (In-House Initiative of SNTCSSC)',
                'code' => 'pib_sandhan',
                'description' => 'Exclusive in-house initiative by SNTCSSC synthesizing key Press Information Bureau (PIB) releases and policy developments.',
                'icon' => 'book-marked',
                'badge_color' => 'primary',
                'is_public' => true,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 2,
            ],
            [
                'name' => 'Lecture Notes / Handouts',
                'code' => 'lecture_notes',
                'description' => 'Classroom faculty notes, synopsis, and handout modules.',
                'icon' => 'file-text',
                'badge_color' => 'blue',
                'is_public' => false,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 3,
            ],
            [
                'name' => 'E-Book / Reference PDF',
                'code' => 'e_book_pdf',
                'description' => 'Curated reference books, standard texts, and government publications.',
                'icon' => 'book-open',
                'badge_color' => 'amber',
                'is_public' => true,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 4,
            ],
            [
                'name' => 'Presentation / Slide Deck',
                'code' => 'slide_deck',
                'description' => 'Faculty slide presentations and visual teaching aids.',
                'icon' => 'presentation',
                'badge_color' => 'purple',
                'is_public' => false,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 5,
            ],
            [
                'name' => 'Video Lecture / Recording',
                'code' => 'video_recording',
                'description' => 'High-definition classroom lecture recordings and recorded masterclasses.',
                'icon' => 'video',
                'badge_color' => 'rose',
                'is_public' => false,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 6,
            ],
            [
                'name' => 'Audio Lecture / Podcast',
                'code' => 'audio_lecture',
                'description' => 'Audio discussions, expert roundtables, and recorded lectures.',
                'icon' => 'headphones',
                'badge_color' => 'cyan',
                'is_public' => false,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 7,
            ],
            [
                'name' => 'Question Bank / Mock Test',
                'code' => 'question_bank',
                'description' => 'Previous years question analyses, model answer blueprints, and mock test papers.',
                'icon' => 'help-circle',
                'badge_color' => 'indigo',
                'is_public' => true,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 8,
            ],
            [
                'name' => 'Syllabus & Blueprint',
                'code' => 'syllabus',
                'description' => 'Detailed curriculum roadmaps, micro-syllabi, and subject blueprints.',
                'icon' => 'compass',
                'badge_color' => 'teal',
                'is_public' => true,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 9,
            ],
            [
                'name' => 'Assignment / Worksheet',
                'code' => 'assignment_practice',
                'description' => 'Mains answer writing exercises, case study evaluations, and worksheets.',
                'icon' => 'clipboard-list',
                'badge_color' => 'slate',
                'is_public' => false,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 10,
            ],
            [
                'name' => 'Reference Compendium',
                'code' => 'handout_reference',
                'description' => 'Annual reports, economic surveys, budget summaries, and reference compendia.',
                'icon' => 'bookmark',
                'badge_color' => 'zinc',
                'is_public' => true,
                'is_system' => true,
                'is_active' => true,
                'display_order' => 11,
            ],
        ];

        $now = now();
        foreach ($defaultTypes as $type) {
            DB::table('study_material_types')->insertOrIgnore(array_merge($type, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('study_material_access_logs')) {
            Schema::table('study_material_access_logs', function (Blueprint $table) {
                if (Schema::hasColumn('study_material_access_logs', 'subscriber_id')) {
                    $table->dropForeign(['subscriber_id']);
                    $table->dropColumn(['subscriber_id', 'guest_name', 'guest_email', 'guest_phone', 'is_verified', 'metadata']);
                }
            });
        }

        if (Schema::hasTable('study_materials') && Schema::hasColumn('study_materials', 'course_id')) {
            Schema::table('study_materials', function (Blueprint $table) {
                $table->unsignedBigInteger('course_id')->nullable(false)->change();
            });
        }

        Schema::dropIfExists('study_material_types');
    }
};
