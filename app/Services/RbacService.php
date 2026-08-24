<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RbacService
{
    /**
     * Standard System Role Definitions.
     *
     * @return array<string, array{description: string, color: string, is_system: bool}>
     */
    public static function defaultRoles(): array
    {
        return [
            'Super Administrator' => [
                'description' => 'Unrestricted root-level access across all system modules, configurations, and audit trails.',
                'color' => 'violet',
                'is_system' => true,
            ],
            'Administrator' => [
                'description' => 'Full administrative access to manage users, roles, academics, students, and settings.',
                'color' => 'emerald',
                'is_system' => true,
            ],
            'Admissions Officer' => [
                'description' => 'Manage student admission inquiries, applications, selection lists, and candidate verification.',
                'color' => 'sky',
                'is_system' => false,
            ],
            'Faculty' => [
                'description' => 'Manage batches, attendance, course study materials, assignments, and examination test scores.',
                'color' => 'blue',
                'is_system' => false,
            ],
            'Accountant' => [
                'description' => 'Manage fee collections, financial receipts, installment schedules, and refund transactions.',
                'color' => 'amber',
                'is_system' => false,
            ],
            'Staff' => [
                'description' => 'Front-desk operations, general student record search, and general communications.',
                'color' => 'zinc',
                'is_system' => false,
            ],
            'Student' => [
                'description' => 'Student portal access for timetable, attendance, test scores, notifications, and profile details.',
                'color' => 'indigo',
                'is_system' => false,
            ],
        ];
    }

    /**
     * Standard System Grouped Permissions.
     *
     * @return array<string, array<string, string>> Module => [Permission Name => Description]
     */
    public static function defaultPermissions(): array
    {
        return [
            'Users' => [
                'users.view' => 'View users listing and profile cards',
                'users.create' => 'Create new system users and send invitations',
                'users.edit' => 'Update user details, identity information, and designations',
                'users.delete' => 'Soft-delete and purge system user accounts',
                'users.restore' => 'Restore soft-deleted user accounts',
                'users.lock' => 'Lock and unlock user login accounts',
                'users.export' => 'Export users to Excel, CSV, and PDF',
                'users.import' => 'Import batch users from Excel and CSV',
                'users.impersonate' => 'Login into any student/user dashboard anonymously',
            ],
            'Roles & Access' => [
                'roles.view' => 'View roles and permission matrices',
                'roles.create' => 'Create new custom roles',
                'roles.edit' => 'Modify role definitions and assigned permissions',
                'roles.delete' => 'Delete custom non-system roles',
                'roles.assign' => 'Assign and remove roles from users',
                'permissions.manage' => 'Create and customize system permission keys',
            ],
            'Students' => [
                'students.view' => 'View student master list and profile dossiers',
                'students.create' => 'Register and enroll new students',
                'students.edit' => 'Update student academic and personal information',
                'students.delete' => 'Archive and delete student profiles',
                'students.export' => 'Export student data and reports',
                'students.import' => 'Bulk import students from Excel and CSV',
            ],
            'Admissions' => [
                'admissions.view' => 'View candidate applications and registrations',
                'admissions.review' => 'Review, verify, and approve admission applications',
                'admissions.manage_tests' => 'Schedule entrance tests and publish selection lists',
                'admissions.export' => 'Export admission candidates and merit lists',
            ],
            'Academics' => [
                'courses.manage' => 'Create and configure academic courses and syllabus',
                'batches.manage' => 'Create and manage academic batches and schedules',
                'tests.manage' => 'Create academic assessments and record test scores',
                'attendance.manage' => 'Record and manage classroom student attendance',
            ],
            'Communications' => [
                'communications.view' => 'View SMS and Email delivery audit logs',
                'communications.send' => 'Compose and dispatch broadcast messages and campaigns',
                'communications.export' => 'Export message delivery audit logs to Excel and CSV',
                'templates.manage' => 'Create and update SMS and Email message templates',
            ],
            'Audit & Security' => [
                'audit.view' => 'Inspect comprehensive system audit logs and diff records',
                'audit.export' => 'Export audit logs to CSV and compliance reports',
                'audit.prune' => 'Purge historical audit log entries beyond retention window',
            ],
            'Reports & BI' => [
                'reports.view' => 'View institutional analytics and performance reports',
                'reports.export' => 'Export high-resolution analytical reports to PDF/Excel',
            ],
            'CMS & Content' => [
                'pages.manage' => 'Create, edit, and publish dynamic CMS pages and policies',
                'contacts.manage' => 'Manage contact inquiries, departments, and support tickets',
            ],
            'Support & Helpdesk' => [
                'tickets.view' => 'View support tickets list and conversations',
                'tickets.create' => 'Open new support tickets on behalf of users',
                'tickets.reply' => 'Post public responses to support tickets',
                'tickets.internal_notes' => 'Post internal staff-only notes on tickets',
                'tickets.assign' => 'Assign and reassign ticket owners and agents',
                'tickets.manage' => 'Update ticket statuses, priorities, SLAs, and delete tickets',
                'tickets.categories' => 'Manage support ticket categories and SLA rules',
                'tickets.canned_responses' => 'Manage canned responses and response macros',
            ],
            'Marketing & Subscribers' => [
                'subscribers.view' => 'View newsletter & WhatsApp subscribers list and metrics',
                'subscribers.manage' => 'Add, edit, change status, and delete subscribers',
                'subscribers.export' => 'Export subscribers list to CSV and Excel',
                'subscribers.import' => 'Import subscribers in bulk from CSV files',
            ],
            'System Settings' => [
                'settings.general' => 'Configure institution identity, logos, and general settings',
                'settings.appearance' => 'Configure themes, logos, branding, and color palettes',
                'settings.localization' => 'Configure default language, timezones, and date formats',
                'settings.security' => 'Configure authentication rules and lockout security',
                'settings.email' => 'Configure SMTP, email delivery, and testing',
                'settings.sms' => 'Configure SMS gateways (2Factor, Fast2SMS, MSG91)',
                'settings.payment' => 'Configure payment gateways (Razorpay, PhonePe)',
                'settings.backup' => 'Manage, trigger on-demand backups, and download database/media archives',
                'settings.cron' => 'Manage automated scheduled system tasks and cron runners',
            ],
        ];
    }

    /**
     * Seed or sync default system roles and permissions in database.
     */
    public static function seedDefaults(): void
    {
        DB::transaction(function () {
            // Seed permissions
            foreach (static::defaultPermissions() as $module => $permissions) {
                foreach ($permissions as $name => $description) {
                    Permission::firstOrCreate(
                        ['name' => $name, 'guard_name' => 'web'],
                        ['module' => $module, 'description' => $description]
                    );
                }
            }

            // Seed roles
            foreach (static::defaultRoles() as $roleName => $meta) {
                $role = Role::firstOrCreate(
                    ['name' => $roleName, 'guard_name' => 'web'],
                    [
                        'description' => $meta['description'],
                        'color' => $meta['color'],
                        'is_system' => $meta['is_system'],
                    ]
                );

                // Assign default permissions to Super Administrator & Administrator
                if ($roleName === 'Super Administrator') {
                    $role->syncPermissions(Permission::where('guard_name', 'web')->get());
                } elseif ($roleName === 'Administrator') {
                    $role->syncPermissions(Permission::where('guard_name', 'web')->where('name', 'not like', 'audit.prune')->get());
                } elseif ($roleName === 'Admissions Officer') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'students.view', 'students.create', 'admissions.view', 'admissions.review', 'admissions.manage_tests', 'communications.view',
                    ])->get());
                } elseif ($roleName === 'Faculty') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'students.view', 'courses.manage', 'batches.manage', 'tests.manage', 'attendance.manage',
                    ])->get());
                } elseif ($roleName === 'Staff') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'students.view', 'admissions.view', 'contacts.manage', 'tickets.view', 'tickets.reply', 'tickets.internal_notes',
                    ])->get());
                }
            }
        });
    }

    /**
     * Create a new role with DB transaction and audit logging.
     *
     * @param  array{name: string, description?: string|null, color?: string, permissions?: array<string>}  $data
     */
    public static function createRole(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
                'description' => $data['description'] ?? null,
                'color' => $data['color'] ?? 'emerald',
                'is_system' => false,
            ]);

            if (! empty($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            AuditLogService::log(
                event: 'role_created',
                description: "Created new role '{$role->name}' with ".(count($data['permissions'] ?? [])).' permissions.',
                auditable: $role,
                newValues: ['name' => $role->name, 'permissions' => $data['permissions'] ?? []]
            );

            return $role;
        });
    }

    /**
     * Update an existing role.
     *
     * @param  array{name?: string, description?: string|null, color?: string, permissions?: array<string>}  $data
     */
    public static function updateRole(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            $oldPermissions = $role->permissions->pluck('name')->all();

            $updateData = [];
            if (! $role->is_system && isset($data['name'])) {
                $updateData['name'] = $data['name'];
            }
            if (isset($data['description'])) {
                $updateData['description'] = $data['description'];
            }
            if (isset($data['color'])) {
                $updateData['color'] = $data['color'];
            }

            if (! empty($updateData)) {
                $role->update($updateData);
            }

            if (isset($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            AuditLogService::log(
                event: 'role_updated',
                description: "Updated role '{$role->name}' configuration and permissions.",
                auditable: $role,
                oldValues: ['permissions' => $oldPermissions],
                newValues: ['permissions' => $data['permissions'] ?? $oldPermissions]
            );

            return $role;
        });
    }

    /**
     * Clone an existing role to a new name.
     */
    public static function cloneRole(Role $sourceRole, string $newName, ?string $description = null): Role
    {
        return DB::transaction(function () use ($sourceRole, $newName, $description) {
            $newRole = Role::create([
                'name' => $newName,
                'guard_name' => 'web',
                'description' => $description ?? "Cloned from {$sourceRole->name}",
                'color' => $sourceRole->color,
                'is_system' => false,
            ]);

            $permissions = $sourceRole->permissions->pluck('name')->all();
            $newRole->syncPermissions($permissions);

            AuditLogService::log(
                event: 'role_cloned',
                description: "Cloned role '{$sourceRole->name}' into new role '{$newRole->name}'.",
                auditable: $newRole,
                newValues: ['name' => $newRole->name, 'cloned_from' => $sourceRole->name]
            );

            return $newRole;
        });
    }

    /**
     * Safely delete a role.
     */
    public static function deleteRole(Role $role): bool
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'System protected roles cannot be deleted.',
            ]);
        }

        $userCount = $role->users()->count();
        if ($userCount > 0) {
            throw ValidationException::withMessages([
                'role' => "Cannot delete role '{$role->name}' because {$userCount} users are currently assigned to it.",
            ]);
        }

        return DB::transaction(function () use ($role) {
            $roleName = $role->name;
            $role->syncPermissions([]);
            $deleted = $role->delete();

            AuditLogService::log(
                event: 'role_deleted',
                description: "Deleted custom role '{$roleName}'.",
                oldValues: ['name' => $roleName]
            );

            return $deleted;
        });
    }

    /**
     * Create a dynamic custom permission.
     */
    public static function createPermission(string $name, string $module = 'General', ?string $description = null): Permission
    {
        return DB::transaction(function () use ($name, $module, $description) {
            $perm = Permission::create([
                'name' => $name,
                'guard_name' => 'web',
                'module' => $module,
                'description' => $description,
            ]);

            AuditLogService::log(
                event: 'permission_created',
                description: "Created new permission key '{$perm->name}' under module '{$module}'.",
                auditable: $perm,
                newValues: ['name' => $perm->name, 'module' => $module]
            );

            return $perm;
        });
    }

    /**
     * Update a dynamic permission.
     */
    public static function updatePermission(Permission $permission, string $name, string $module, ?string $description = null): Permission
    {
        return DB::transaction(function () use ($permission, $name, $module, $description) {
            $old = $permission->toArray();

            $permission->update([
                'name' => $name,
                'module' => $module,
                'description' => $description,
            ]);

            AuditLogService::log(
                event: 'permission_updated',
                description: "Updated permission key '{$permission->name}'.",
                auditable: $permission,
                oldValues: $old,
                newValues: $permission->toArray()
            );

            return $permission;
        });
    }

    /**
     * Delete a permission key.
     */
    public static function deletePermission(Permission $permission): bool
    {
        return DB::transaction(function () use ($permission) {
            $name = $permission->name;
            $deleted = $permission->delete();

            AuditLogService::log(
                event: 'permission_deleted',
                description: "Deleted permission key '{$name}'.",
                oldValues: ['name' => $name]
            );

            return $deleted;
        });
    }

    /**
     * Assign / synchronize roles for a user.
     *
     * @param  array<string>|string  $roles
     */
    public static function syncUserRoles(User $user, array|string $roles): void
    {
        DB::transaction(function () use ($user, $roles) {
            $rolesList = (array) $roles;
            $oldRoles = $user->roles->pluck('name')->all();

            $user->syncRoles($rolesList);

            AuditLogService::log(
                event: 'user_roles_synced',
                description: "Synchronized roles for user {$user->name} ({$user->email}) to: ".implode(', ', $rolesList),
                auditable: $user,
                oldValues: ['roles' => $oldRoles],
                newValues: ['roles' => $rolesList]
            );
        });
    }

    /**
     * Assign / synchronize direct permissions for a user.
     *
     * @param  array<string>  $permissions
     */
    public static function syncUserPermissions(User $user, array $permissions): void
    {
        DB::transaction(function () use ($user, $permissions) {
            $oldPermissions = $user->getDirectPermissions()->pluck('name')->all();

            $user->syncPermissions($permissions);

            AuditLogService::log(
                event: 'user_permissions_synced',
                description: "Direct permissions synchronized for user {$user->name} ({$user->email}).",
                auditable: $user,
                oldValues: ['permissions' => $oldPermissions],
                newValues: ['permissions' => $permissions]
            );
        });
    }

    /**
     * Lock a user account with audit record.
     */
    public static function lockUser(User $user, ?string $reason = null, int $durationMinutes = 60): bool
    {
        return DB::transaction(function () use ($user, $reason, $durationMinutes) {
            $until = now()->addMinutes($durationMinutes);
            $user->lockAccount($until);

            AuditLogService::log(
                event: 'user_locked',
                description: "User account {$user->name} ({$user->email}) locked until {$until->format('d M Y H:i')}. Reason: ".($reason ?: 'Administrative lock'),
                auditable: $user,
                newValues: ['locked_untill' => $until->toIso8601String(), 'reason' => $reason]
            );

            return true;
        });
    }

    /**
     * Unlock a user account with audit record.
     */
    public static function unlockUser(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $user->unlockAccount();

            AuditLogService::log(
                event: 'user_unlocked',
                description: "User account {$user->name} ({$user->email}) was unlocked by administrator.",
                auditable: $user,
                newValues: ['locked_untill' => null, 'status' => $user->status]
            );

            return true;
        });
    }

    /**
     * Soft delete user account.
     */
    public static function softDeleteUser(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $deleted = $user->delete();

            AuditLogService::log(
                event: 'user_soft_deleted',
                description: "Soft-deleted user account {$user->name} ({$user->email}).",
                auditable: $user,
                oldValues: $user->toArray()
            );

            return (bool) $deleted;
        });
    }

    /**
     * Restore soft deleted user.
     */
    public static function restoreUser(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $restored = $user->restore();

            AuditLogService::log(
                event: 'user_restored',
                description: "Restored soft-deleted user account {$user->name} ({$user->email}).",
                auditable: $user,
                newValues: $user->toArray()
            );

            return (bool) $restored;
        });
    }

    /**
     * Permanently force delete user account.
     */
    public static function forceDeleteUser(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $userData = $user->toArray();
            $user->roles()->detach();
            $user->permissions()->detach();
            $deleted = $user->forceDelete();

            AuditLogService::log(
                event: 'user_permanently_deleted',
                description: "Permanently purged user record {$userData['name']} ({$userData['email']}).",
                oldValues: $userData
            );

            return (bool) $deleted;
        });
    }
}
