<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('study_materials', function (Blueprint $table) {
            if (! Schema::hasColumn('study_materials', 'thumbnail_path')) {
                $table->string('thumbnail_path', 500)->nullable()->after('external_url');
            }
            if (! Schema::hasColumn('study_materials', 'thumbnail_url')) {
                $table->string('thumbnail_url', 1000)->nullable()->after('thumbnail_path');
            }
            if (! Schema::hasColumn('study_materials', 'class_date')) {
                $table->date('class_date')->nullable()->after('thumbnail_url')->index();
            }
            if (! Schema::hasColumn('study_materials', 'display_order')) {
                $table->unsignedInteger('display_order')->default(0)->after('is_pinned')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('study_materials', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('study_materials', 'thumbnail_path')) {
                $columns[] = 'thumbnail_path';
            }
            if (Schema::hasColumn('study_materials', 'thumbnail_url')) {
                $columns[] = 'thumbnail_url';
            }
            if (Schema::hasColumn('study_materials', 'class_date')) {
                $columns[] = 'class_date';
            }
            if (Schema::hasColumn('study_materials', 'display_order')) {
                $columns[] = 'display_order';
            }
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
