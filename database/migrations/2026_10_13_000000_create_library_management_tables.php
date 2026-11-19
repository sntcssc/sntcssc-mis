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
        // 1. Categories
        Schema::create('book_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->string('code', 50)->nullable()->index(); // Dewey Decimal / DDC or custom code
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Authors
        Schema::create('book_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('biography')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 3. Publishers
        Schema::create('book_publishers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->string('contact_person', 150)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('website', 255)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 4. Books (Title / Catalog Master)
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->string('slug', 255)->unique();
            $table->string('isbn', 30)->nullable()->index();
            $table->string('isbn13', 30)->nullable()->index();
            $table->string('edition', 50)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('book_categories')->nullOnDelete();
            $table->foreignId('publisher_id')->nullable()->constrained('book_publishers')->nullOnDelete();
            $table->string('classification_number', 50)->nullable()->index(); // Call number / DDC
            $table->string('language', 50)->default('English');
            $table->integer('pages')->nullable();
            $table->integer('published_year')->nullable();
            $table->text('description')->nullable();
            $table->string('cover_image', 500)->nullable();
            $table->json('images')->nullable(); // Multiple image uploads
            $table->decimal('price', 10, 2)->default(0.00); // Standard replacement / security value
            $table->boolean('is_reference_only')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('total_copies_count')->default(0);
            $table->unsignedInteger('available_copies_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 5. Book Authors Pivot
        Schema::create('book_author_pivot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('book_authors')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unique(['book_id', 'author_id']);
        });

        // 6. Book Copies (Physical Accession Register)
        Schema::create('book_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->string('accession_no', 50)->unique(); // Unique Accession No: ACC-YYYY-XXXXX
            $table->string('barcode', 50)->unique(); // Scannable Barcode / Code 128
            $table->string('rfid_tag', 100)->nullable()->index();
            $table->unsignedInteger('copy_number')->default(1);
            $table->date('acquisition_date');
            $table->decimal('purchase_cost', 10, 2)->default(0.00);
            $table->string('supplier_details', 255)->nullable(); // Supplier / Vendor
            $table->string('invoice_number', 100)->nullable();
            $table->string('funding_source', 100)->nullable(); // State Grant / Institutional / Donation
            $table->string('classification_number', 50)->nullable()->index();
            $table->string('shelf_location', 150)->nullable()->index(); // Rack, Cupboard, Shelf
            $table->string('book_condition', 30)->default('good'); // new, good, fair, damaged, worn, lost
            $table->string('status', 30)->default('available')->index(); // available, issued, reserved, under_maintenance, lost, written_off
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 7. Accession Histories (Permanent copy condition and location audit)
        Schema::create('book_accession_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_copy_id')->constrained('book_copies')->cascadeOnDelete();
            $table->string('action', 50); // acquired, condition_changed, status_changed, relocated, written_off
            $table->string('old_condition', 30)->nullable();
            $table->string('new_condition', 30)->nullable();
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->string('old_shelf_location', 150)->nullable();
            $table->string('new_shelf_location', 150)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 8. Library Members
        Schema::create('library_members', function (Blueprint $table) {
            $table->id();
            $table->string('member_number', 50)->unique(); // e.g. LIB-MEM-2026-0001
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('student_id', 20)->nullable()->index();
            $table->string('member_type', 30)->default('student'); // student, faculty, staff, external
            $table->string('name', 150);
            $table->string('email', 150)->nullable()->index();
            $table->string('phone', 50)->nullable()->index();
            $table->string('gender', 20)->nullable();
            $table->text('address')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('joining_date');
            $table->date('expiry_date')->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->decimal('security_deposit_override', 10, 2)->nullable(); // Custom deposit override
            $table->boolean('bypass_deposit_check')->default(false); // Staff / Temporary bypass
            $table->string('deposit_bypass_reason', 255)->nullable();
            $table->string('status', 30)->default('active')->index(); // active, expired, suspended, cancelled
            $table->unsignedInteger('active_issued_count')->default(0);
            $table->unsignedInteger('total_issued_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 9. Book Issues (Circulation Register)
        Schema::create('book_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_no', 50)->unique(); // e.g. ISS-2026-00001
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('book_copy_id')->constrained('book_copies')->cascadeOnDelete();
            $table->foreignId('library_member_id')->constrained('library_members')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('issue_date');
            $table->date('due_date')->index();
            $table->date('return_date')->nullable();
            $table->string('status', 30)->default('issued')->index(); // issued, returned, overdue, renewed, lost
            $table->unsignedInteger('renewal_count')->default(0);
            $table->unsignedInteger('max_renewals_allowed')->default(2);
            $table->dateTime('last_renewed_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('return_condition', 30)->nullable();
            $table->decimal('book_price_at_issue', 10, 2)->default(0.00);
            $table->boolean('is_deposit_bypassed')->default(false);
            $table->string('bypass_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('last_reminder_sent_at')->nullable();
            $table->unsignedInteger('reminders_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 10. Book Reservations
        Schema::create('book_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_no', 50)->unique(); // e.g. RES-2026-00001
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('book_copy_id')->nullable()->constrained('book_copies')->nullOnDelete();
            $table->foreignId('library_member_id')->constrained('library_members')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reserved_at');
            $table->dateTime('expires_at')->index();
            $table->string('status', 30)->default('pending')->index(); // pending, ready_for_pickup, fulfilled, cancelled, expired
            $table->dateTime('fulfilled_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 11. Book Fines & Overdue Penalties
        Schema::create('book_fines', function (Blueprint $table) {
            $table->id();
            $table->string('fine_no', 50)->unique(); // e.g. FINE-2026-00001
            $table->foreignId('book_issue_id')->nullable()->constrained('book_issues')->nullOnDelete();
            $table->foreignId('library_member_id')->constrained('library_members')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('overdue_days')->default(0);
            $table->decimal('fine_rate_per_day', 10, 2)->default(0.00);
            $table->decimal('calculated_fine', 10, 2)->default(0.00);
            $table->decimal('waived_amount', 10, 2)->default(0.00);
            $table->decimal('payable_amount', 10, 2)->default(0.00);
            $table->decimal('paid_amount', 10, 2)->default(0.00);
            $table->string('status', 30)->default('pending')->index(); // pending, paid, partially_paid, waived
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('waived_at')->nullable();
            $table->string('waiver_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_fines');
        Schema::dropIfExists('book_reservations');
        Schema::dropIfExists('book_issues');
        Schema::dropIfExists('library_members');
        Schema::dropIfExists('book_accession_histories');
        Schema::dropIfExists('book_copies');
        Schema::dropIfExists('book_author_pivot');
        Schema::dropIfExists('books');
        Schema::dropIfExists('book_publishers');
        Schema::dropIfExists('book_authors');
        Schema::dropIfExists('book_categories');
    }
};
