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
        if (Schema::hasTable('chat_call_participants') && ! Schema::hasColumn('chat_call_participants', 'deleted_at')) {
            Schema::table('chat_call_participants', function (Blueprint $table) {
                $table->softDeletes()->after('updated_at');
                $table->index('deleted_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('chat_call_participants') && Schema::hasColumn('chat_call_participants', 'deleted_at')) {
            Schema::table('chat_call_participants', function (Blueprint $table) {
                $table->dropIndex(['deleted_at']);
                $table->dropSoftDeletes();
            });
        }
    }
};
