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
        // 1. Dynamic Alumni Service Categories Catalog
        Schema::create('alumni_service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->string('short_name', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('icon', 50)->default('award');
            $table->string('badge_color', 30)->default('primary');
            $table->boolean('is_system')->default(false); // Default system core category
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('display_order')->default(0)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Add foreign key service_category_id to alumni table if missing
        if (Schema::hasTable('alumni') && ! Schema::hasColumn('alumni', 'service_category_id')) {
            Schema::table('alumni', function (Blueprint $table) {
                $table->foreignId('service_category_id')->nullable()->after('passing_year')->constrained('alumni_service_categories')->nullOnDelete();
            });
        }

        // 3. Seed default institutional service categories
        $defaultCategories = [
            [
                'name' => 'Union Public Service Commission (UPSC Civil Services)',
                'code' => 'UPSC CSE',
                'short_name' => 'UPSC CSE',
                'description' => 'Indian Administrative Service (IAS), Indian Police Service (IPS), Indian Foreign Service (IFS), and Indian Revenue Service (IRS).',
                'icon' => 'award',
                'badge_color' => 'violet',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'West Bengal Civil Service (Executive)',
                'code' => 'West Bengal Civil Service (WBCS)',
                'short_name' => 'WBCS (Exe)',
                'description' => 'WBCS Executive cadre, Assistant Magistrate, Deputy Magistrate, and Block Development Officer (BDO).',
                'icon' => 'briefcase',
                'badge_color' => 'blue',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 2,
            ],
            [
                'name' => 'West Bengal Police Service',
                'code' => 'West Bengal Police Service (WBPS)',
                'short_name' => 'WBPS',
                'description' => 'Deputy Superintendent of Police (DSP) and Assistant Commissioner of Police (ACP).',
                'icon' => 'shield',
                'badge_color' => 'cyan',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 3,
            ],
            [
                'name' => 'Indian Forest Service',
                'code' => 'Indian Forest Service (IFoS)',
                'short_name' => 'IFoS',
                'description' => 'All India Service for conservation, forestry, ecology, and wildlife administration.',
                'icon' => 'compass',
                'badge_color' => 'emerald',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 4,
            ],
            [
                'name' => 'West Bengal Judicial Service / Higher Judicial',
                'code' => 'Judicial Services',
                'short_name' => 'Judicial Services',
                'description' => 'Civil Judge (Junior Division), Judicial Magistrate, and Higher Judicial Services officers.',
                'icon' => 'scale',
                'badge_color' => 'amber',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 5,
            ],
            [
                'name' => 'Central Armed Police Forces / Allied Services',
                'code' => 'Central Services / Allied',
                'short_name' => 'Central / Allied',
                'description' => 'CAPF Assistant Commandants (BSF, CRPF, CISF, ITBP, SSB), Combined Geo-Scientist, and Defence Services.',
                'icon' => 'flag',
                'badge_color' => 'indigo',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 6,
            ],
            [
                'name' => 'Banking & Finance (RBI / Bank PO / Regulators)',
                'code' => 'Banking & Finance',
                'short_name' => 'Banking & Finance',
                'description' => 'Reserve Bank of India (RBI Grade B), SEBI, NABARD, and Public Sector Bank Probationary Officers.',
                'icon' => 'landmark',
                'badge_color' => 'emerald',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 7,
            ],
            [
                'name' => 'Public Sector Undertaking / Central Autonomous',
                'code' => 'PSU / Central Govt',
                'short_name' => 'PSU / Central Govt',
                'description' => 'Maharatna / Navratna PSUs, SSC CGL Gazetted Officers, Railway Management, and ISRO/DRDO executive administration.',
                'icon' => 'building-2',
                'badge_color' => 'teal',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 8,
            ],
            [
                'name' => 'Other State Civil / Police Services',
                'code' => 'Other State Civil Services',
                'short_name' => 'Other State Services',
                'description' => 'State Public Service Commission recruits across Assam (ACS), Bihar (BPSC), Odisha (OPSC), Jharkhand (JPSC), and other state cadres.',
                'icon' => 'map-pin',
                'badge_color' => 'orange',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 9,
            ],
            [
                'name' => 'Corporate Leadership & Private Sector',
                'code' => 'Corporate / Private',
                'short_name' => 'Corporate',
                'description' => 'Strategic advisory, enterprise leadership, consulting, technology, and multinational corporate roles.',
                'icon' => 'briefcase',
                'badge_color' => 'zinc',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 10,
            ],
            [
                'name' => 'Higher Education & Academia / Faculty',
                'code' => 'Academia & Research',
                'short_name' => 'Academia & Research',
                'description' => 'University Professors, UGC NET/SET qualifiers, research scientists, and academic leaders.',
                'icon' => 'graduation-cap',
                'badge_color' => 'purple',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 11,
            ],
            [
                'name' => 'Other Services / Specialized Sectors',
                'code' => 'Other',
                'short_name' => 'Other',
                'description' => 'Other governmental, international development, and specialized professional services.',
                'icon' => 'help-circle',
                'badge_color' => 'slate',
                'is_system' => true,
                'is_active' => true,
                'display_order' => 12,
            ],
        ];

        $now = now();
        foreach ($defaultCategories as $cat) {
            $cat['created_at'] = $now;
            $cat['updated_at'] = $now;
            $id = DB::table('alumni_service_categories')->insertGetId($cat);

            // Backfill existing alumni with this service_type
            if (Schema::hasTable('alumni') && Schema::hasColumn('alumni', 'service_category_id')) {
                DB::table('alumni')
                    ->where('service_type', $cat['code'])
                    ->whereNull('service_category_id')
                    ->update(['service_category_id' => $id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('alumni') && Schema::hasColumn('alumni', 'service_category_id')) {
            Schema::table('alumni', function (Blueprint $table) {
                $table->dropForeign(['service_category_id']);
                $table->dropColumn('service_category_id');
            });
        }

        Schema::dropIfExists('alumni_service_categories');
    }
};
