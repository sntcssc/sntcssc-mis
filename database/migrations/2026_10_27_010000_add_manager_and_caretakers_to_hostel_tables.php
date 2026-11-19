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
        // 1. Add manager details to hostel_buildings
        Schema::table('hostel_buildings', function (Blueprint $table) {
            $table->string('manager_name')->nullable()->after('warden_email');
            $table->string('manager_contact', 50)->nullable()->after('manager_name');
            $table->string('manager_email')->nullable()->after('manager_contact');
        });

        // 2. Create hostel_caretakers table for multiple caretakers per hostel
        Schema::create('hostel_caretakers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_building_id')->constrained('hostel_buildings')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 50);
            $table->string('email')->nullable();
            $table->string('shift', 100)->default('Day Shift'); // e.g. Day Shift, Night Shift, Rotating, 24x7
            $table->boolean('is_primary')->default(false)->index();
            $table->string('status', 30)->default('active')->index(); // active, inactive, on_leave
            $table->text('notes')->nullable(); // Special duties, emergency desk, gate station
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hostel_building_id', 'status'], 'hc_building_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hostel_caretakers');

        Schema::table('hostel_buildings', function (Blueprint $table) {
            $table->dropColumn(['manager_name', 'manager_contact', 'manager_email']);
        });
    }
};
