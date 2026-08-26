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
            $table->string('access_mode', 20)->default('open')->after('mode'); // open, invited_only
        });

        Schema::table('chat_meeting_participants', function (Blueprint $table) {
            $table->string('status', 20)->default('invited')->change(); // invited, waiting, joined, left, declined, denied
            $table->string('role', 20)->default('participant')->change(); // host, co_host, participant
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_meetings', function (Blueprint $table) {
            $table->dropColumn('access_mode');
        });
    }
};
