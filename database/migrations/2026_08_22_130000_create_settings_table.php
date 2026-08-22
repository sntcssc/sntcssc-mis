<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Application settings stored as key/value pairs grouped by area
     * (general, appearance, seo, localization, system, sms, payment, email).
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general')->index();
            $table->string('type')->default('string')->comment('string, text, boolean, number, select, image, file, secret, json');
            $table->string('label')->nullable();
            $table->json('options')->nullable()->comment('choices for select-type settings');
            $table->boolean('status')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['group', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
