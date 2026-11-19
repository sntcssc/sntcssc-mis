<?php

namespace Database\Seeders;

use App\Enums\TeamRole;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\RbacService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds to initialize the Super Administrator.
     */
    public function run(): void
    {
        // 1. Ensure default RBAC roles and permissions exist
        RbacService::seedDefaults();

        // 2. Find or create the primary Super Administrator account
        $adminEmail = 'admin@sntcssc.in';

        $user = User::withTrashed()->where('email', $adminEmail)->first();

        if ($user) {
            if ($user->trashed()) {
                $user->restore();
            }

            $user->update([
                'name' => 'Super Administrator',
                'first_name' => 'Super',
                'last_name' => 'Administrator',
                'phone' => '+919876543210',
                'whatsapp_no' => '+919876543210',
                'dob' => '1990-01-01',
                'gender' => 'male',
                'tenth_roll' => 'WB-10-000001',
                'id_type' => 'aadhaar',
                'id_number' => '1111-2222-3333',
                'designation' => 'System Administrator',
                'status' => 'active',
                'locked_untill' => null,
                'failed_login_attempts' => 0,
                'password' => Hash::make('Password@1234'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]);
        } else {
            $user = User::create([
                'name' => 'Super Administrator',
                'first_name' => 'Super',
                'last_name' => 'Administrator',
                'email' => $adminEmail,
                'phone' => '+919876543210',
                'whatsapp_no' => '+919876543210',
                'dob' => '1990-01-01',
                'gender' => 'male',
                'tenth_roll' => 'WB-10-000001',
                'id_type' => 'aadhaar',
                'id_number' => '1111-2222-3333',
                'designation' => 'System Administrator',
                'status' => 'active',
                'password' => Hash::make('Password@1234'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]);
        }

        // 3. Assign Super Administrator role
        if (! $user->hasRole('Super Administrator')) {
            $user->assignRole('Super Administrator');
        }

        // 4. Ensure primary organization team exists and is assigned
        $team = Team::where('slug', 'sntcssc')
            ->orWhere('slug', 'super-admins-team')
            ->first();

        if (! $team) {
            $team = Team::create([
                'name' => 'SNT CSSC',
                'is_personal' => false,
                'slug' => 'sntcssc',
            ]);
        } else {
            $team->update([
                'name' => 'SNT CSSC',
                'slug' => 'sntcssc',
                'is_personal' => false,
            ]);
        }

        $team->members()->syncWithoutDetaching([
            $user->id => ['role' => TeamRole::Owner->value],
        ]);

        $user->switchTeam($team);
    }
}
