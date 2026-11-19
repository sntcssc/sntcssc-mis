<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
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
        // 1. Add preferences JSON column to users table if not exists
        if (! Schema::hasColumn('users', 'preferences')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('preferences')->nullable()->after('status');
            });
        }

        // 2. Register Global Search permission in Spatie permissions
        $searchPermission = Permission::firstOrCreate(
            ['name' => 'global_search.access', 'guard_name' => 'web'],
            [
                'module' => 'Global Search',
                'description' => 'Access and execute global search across system modules and permissible records',
            ]
        );

        // 3. Assign permission to default administrative & staff roles
        $targetRoles = ['Super Administrator', 'Administrator', 'Admissions Officer', 'Faculty', 'Accountant', 'Staff', 'Librarian'];
        foreach ($targetRoles as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($searchPermission)) {
                $role->givePermissionTo($searchPermission);
            }
        }

        // 4. Register default system appearance setting for fullscreen
        $existingSetting = Setting::withTrashed()->where('key', 'appearance.default_fullscreen')->first();
        if (! $existingSetting) {
            Setting::create([
                'key' => 'appearance.default_fullscreen',
                'group' => 'appearance',
                'type' => Setting::TYPE_SELECT,
                'label' => 'Default Admin Viewport Mode',
                'value' => 'normal',
                'options' => json_encode(['normal' => 'Standard Window Mode', 'fullscreen' => 'Full Screen Mode']),
                'status' => true,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'preferences')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('preferences');
            });
        }

        $permission = Permission::where('name', 'global_search.access')->where('guard_name', 'web')->first();
        if ($permission) {
            $permission->delete();
        }

        Setting::where('key', 'appearance.default_fullscreen')->forceDelete();
    }
};
