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
        Schema::table('chat_meetings', function (Blueprint $table) {
            $table->timestamp('ends_at')->nullable()->after('scheduled_at');
            $table->string('repeat_type', 32)->default('none')->after('ends_at');
            $table->timestamp('repeat_until')->nullable()->after('repeat_type');
            $table->unsignedInteger('reminder_offset_minutes')->nullable()->default(15)->after('repeat_until');
            $table->json('reminder_channels')->nullable()->after('reminder_offset_minutes');
            $table->timestamp('reminder_sent_at')->nullable()->after('reminder_channels');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_meetings', function (Blueprint $table) {
            $table->dropColumn([
                'ends_at',
                'repeat_type',
                'repeat_until',
                'reminder_offset_minutes',
                'reminder_channels',
                'reminder_sent_at',
            ]);
        });
    }
};
