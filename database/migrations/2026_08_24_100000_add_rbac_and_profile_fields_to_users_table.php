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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('users', 'urn')) {
                $table->string('urn', 50)->nullable()->unique()->after('uuid');
            }
            if (! Schema::hasColumn('users', 'first_name')) {
                $table->string('first_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('users', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
            if (! Schema::hasColumn('users', 'whatsapp_no')) {
                $table->string('whatsapp_no', 25)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'dob')) {
                $table->date('dob')->nullable()->after('whatsapp_no');
            }
            if (! Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 20)->nullable()->after('dob');
            }
            if (! Schema::hasColumn('users', 'tenth_roll')) {
                $table->string('tenth_roll', 50)->nullable()->after('gender');
            }
            if (! Schema::hasColumn('users', 'id_type')) {
                $table->string('id_type', 50)->nullable()->after('tenth_roll');
            }
            if (! Schema::hasColumn('users', 'id_number')) {
                $table->string('id_number', 100)->nullable()->after('id_type');
            }
            if (! Schema::hasColumn('users', 'designation')) {
                $table->string('designation', 150)->nullable()->after('id_number');
            }
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 30)->default('active')->index()->after('designation');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('users', 'last_login_ip')) {
                $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            }
            if (! Schema::hasColumn('users', 'failed_login_attempts')) {
                $table->unsignedInteger('failed_login_attempts')->default(0)->after('last_login_ip');
            }
            if (! Schema::hasColumn('users', 'locked_untill')) {
                $table->timestamp('locked_untill')->nullable()->after('failed_login_attempts');
            }
            if (! Schema::hasColumn('users', 'deleted_by')) {
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete()->after('locked_untill');
            }
            if (! Schema::hasColumn('users', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete()->after('deleted_by');
            }
            if (! Schema::hasColumn('users', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('updated_by');
            }
            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes()->after('created_by');
            }
        });

        // Add additional metadata columns to roles table if present
        if (Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table) {
                if (! Schema::hasColumn('roles', 'description')) {
                    $table->string('description', 500)->nullable()->after('name');
                }
                if (! Schema::hasColumn('roles', 'color')) {
                    $table->string('color', 30)->default('emerald')->after('description');
                }
                if (! Schema::hasColumn('roles', 'is_system')) {
                    $table->boolean('is_system')->default(false)->after('color');
                }
            });
        }

        // Add additional metadata columns to permissions table if present
        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                if (! Schema::hasColumn('permissions', 'module')) {
                    $table->string('module', 50)->default('General')->index()->after('name');
                }
                if (! Schema::hasColumn('permissions', 'description')) {
                    $table->string('description', 500)->nullable()->after('module');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [
                'uuid', 'urn', 'first_name', 'last_name', 'whatsapp_no', 'dob',
                'gender', 'tenth_roll', 'id_type', 'id_number', 'designation',
                'status', 'last_login_at', 'last_login_ip', 'failed_login_attempts',
                'locked_untill', 'deleted_by', 'updated_by', 'created_by', 'deleted_at',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table) {
                foreach (['description', 'color', 'is_system'] as $col) {
                    if (Schema::hasColumn('roles', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                foreach (['module', 'description'] as $col) {
                    if (Schema::hasColumn('permissions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
