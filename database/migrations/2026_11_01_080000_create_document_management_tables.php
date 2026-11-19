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
        // 1. Document Categories (Taxonomy)
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 50)->default('folder');
            $table->string('color', 50)->default('blue');
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'display_order'], 'doc_cat_active_order_idx');
        });

        // 2. Master Documents Table
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 50)->unique();
            $table->string('title', 255);
            $table->string('purpose', 255)->nullable();
            $table->foreignId('document_category_id')->nullable()->constrained('document_categories')->nullOnDelete();
            $table->string('department', 100)->nullable();
            $table->json('tags')->nullable();
            $table->enum('access_level', ['public', 'internal', 'confidential', 'restricted'])->default('internal');
            $table->enum('status', ['draft', 'published', 'archived'])->default('published');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->integer('total_versions_count')->default(1);
            $table->integer('total_downloads_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('document_number', 'doc_number_idx');
            $table->index(['document_category_id', 'status'], 'doc_cat_status_idx');
            $table->index(['department', 'access_level'], 'doc_dept_access_idx');
            $table->index('created_at', 'doc_created_at_idx');
        });

        // 3. Document Versions (Version Control & Revisions Archive)
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('version_number', 30); // e.g. "1.0", "1.1", "2.0"
            $table->unsignedInteger('revision_sequence')->default(1); // 1, 2, 3...
            $table->string('version_title', 255)->nullable();
            $table->string('file_path', 500);
            $table->string('original_file_name', 255);
            $table->string('file_type', 20); // pdf, docx, xlsx, pptx, jpg, png, zip, etc.
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('file_size')->default(0); // in bytes
            $table->string('file_hash', 64)->nullable(); // SHA-256
            $table->text('change_summary')->nullable(); // reason or brief notes of this revision
            $table->text('change_description')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_current')->default(false);
            $table->integer('download_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('effective_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_id', 'is_current'], 'doc_ver_doc_cur_idx');
            $table->index(['document_id', 'revision_sequence'], 'doc_ver_doc_rev_idx');
            $table->index('file_type', 'doc_ver_file_type_idx');
        });

        // Add foreign key constraint for current_version_id on documents table
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id', 'doc_cur_ver_fk')
                ->references('id')
                ->on('document_versions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign('doc_cur_ver_fk');
        });

        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_categories');
    }
};
