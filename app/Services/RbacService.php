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
            'Live Chat & Calls' => [
                'chat.access' => 'Access live chat portal and send messages',
                'chat.create_group' => 'Create and administer group conversations',
                'chat.create_channel' => 'Create and publish broadcast channels',
                'chat.broadcast' => 'Dispatch bulk personalized broadcast messages to users',
                'chat.voice_call' => 'Initiate and receive WebRTC voice audio calls',
                'chat.video_call' => 'Initiate and receive WebRTC video calls',
                'chat.pin_message' => 'Pin and unpin messages in conversations',
                'chat.delete_for_everyone' => 'Delete messages for everyone in group or channel',
                'chat.manage_settings' => 'Configure live chat and WebRTC calling parameters',
            ],
            'Online Meetings' => [
                'meetings.view' => 'View online meetings list and upcoming sessions',
                'meetings.create' => 'Create instant and schedule online meetings',
                'meetings.manage' => 'Edit, update, and cancel scheduled online meetings',
                'meetings.co_host' => 'Act as co-host, admit waiting room attendees, and manage restrictions',
                'meetings.manage_settings' => 'Configure global online meeting duration and feature policies',
            ],
            'System Settings' => [
                'settings.general' => 'Configure institution identity, logos, and general settings',
                'settings.appearance' => 'Configure themes, logos, branding, and color palettes',
                'settings.localization' => 'Configure default language, timezones, and date formats',
                'settings.security' => 'Configure authentication rules and lockout security',
                'settings.email' => 'Configure SMTP, email delivery, and testing',
                'settings.sms' => 'Configure SMS gateways (2Factor, Fast2SMS, MSG91)',
                'settings.whatsapp' => 'Configure WhatsApp business gateway and notifications',
                'settings.telegram' => 'Configure Telegram bot integration and alerts',
                'settings.payment' => 'Configure payment gateways (Razorpay, PhonePe)',
                'settings.backup' => 'Manage, trigger on-demand backups, and download database/media archives',
                'settings.cron' => 'Manage automated scheduled system tasks and cron runners',
                'settings.chat' => 'Configure live chat, WebRTC, and meeting server parameters',
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
                    Permission::updateOrCreate(
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
                        'chat.access', 'chat.create_group', 'chat.create_channel', 'chat.voice_call', 'chat.video_call',
                        'meetings.view', 'meetings.create', 'meetings.manage', 'meetings.co_host',
                    ])->get());
                } elseif ($roleName === 'Faculty') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'students.view', 'courses.manage', 'batches.manage', 'tests.manage', 'attendance.manage',
                        'chat.access', 'chat.create_group', 'chat.create_channel', 'chat.voice_call', 'chat.video_call',
                        'meetings.view', 'meetings.create', 'meetings.manage', 'meetings.co_host',
                    ])->get());
                } elseif ($roleName === 'Accountant') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'students.view', 'reports.view', 'reports.export',
                        'chat.access', 'chat.voice_call', 'chat.video_call', 'meetings.view',
                    ])->get());
                } elseif ($roleName === 'Staff') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'students.view', 'admissions.view', 'contacts.manage', 'tickets.view', 'tickets.reply', 'tickets.internal_notes',
                        'chat.access', 'chat.create_group', 'chat.create_channel', 'chat.voice_call', 'chat.video_call',
                        'meetings.view', 'meetings.create',
                    ])->get());
                } elseif ($roleName === 'Student') {
                    $role->syncPermissions(Permission::whereIn('name', [
                        'chat.access', 'chat.voice_call', 'chat.video_call', 'meetings.view',
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
            $oldValues = [
                'name' => $role->name,
                'description' => $role->description,
                'color' => $role->color,
                'permissions' => $role->permissions->pluck('name')->all(),
            ];

            // Protect system role names
            $updatePayload = [];
            if (! $role->is_system && isset($data['name'])) {
                $updatePayload['name'] = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $updatePayload['description'] = $data['description'];
            }
            if (isset($data['color'])) {
                $updatePayload['color'] = $data['color'];
            }

            if (! empty($updatePayload)) {
                $role->update($updatePayload);
            }

            if (isset($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            $newValues = [
                'name' => $role->name,
                'description' => $role->description,
                'color' => $role->color,
                'permissions' => $role->permissions()->pluck('name')->all(),
            ];

            AuditLogService::log(
                event: 'role_updated',
                description: "Updated role '{$role->name}'.",
                auditable: $role,
                oldValues: $oldValues,
                newValues: $newValues
            );

            return $role;
        });
    }

    /**
     * Clone an existing role into a new custom role with same permissions.
     */
    public static function cloneRole(Role $sourceRole, string $newName, ?string $newDescription = null): Role
    {
        return DB::transaction(function () use ($sourceRole, $newName, $newDescription) {
            $newRole = Role::create([
                'name' => $newName,
                'guard_name' => 'web',
                'description' => $newDescription ?: "Cloned from {$sourceRole->name}",
                'color' => $sourceRole->color,
                'is_system' => false,
            ]);

            $permissions = $sourceRole->permissions->pluck('name')->all();
            $newRole->syncPermissions($permissions);

            AuditLogService::log(
                event: 'role_cloned',
                description: "Cloned role '{$sourceRole->name}' into new role '{$newRole->name}' with ".count($permissions).' permissions.',
                auditable: $newRole,
                newValues: ['name' => $newRole->name, 'source_role' => $sourceRole->name, 'permissions' => $permissions]
            );

            return $newRole;
        });
    }

    /**
     * Delete a custom role safely.
     */
    public static function deleteRole(Role $role): bool
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'System default roles cannot be deleted to prevent access lockout.',
            ]);
        }

        return DB::transaction(function () use ($role) {
            $userCount = $role->users()->count();
            if ($userCount > 0) {
                throw ValidationException::withMessages([
                    'role' => "Cannot delete role '{$role->name}' because {$userCount} users are currently assigned to it. Reassign these users first.",
                ]);
            }

            AuditLogService::log(
                event: 'role_deleted',
                description: "Deleted role '{$role->name}'.",
                auditable: $role,
                oldValues: ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()]
            );

            return (bool) $role->delete();
        });
    }

    /**
     * Assign roles to a user with audit tracking.
     *
     * @param  array<string>  $roleNames
     */
    public static function assignRolesToUser(User $user, array $roleNames, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $roleNames, $actor) {
            $oldRoles = $user->roles->pluck('name')->all();

            $user->syncRoles($roleNames);

            AuditLogService::log(
                event: 'user_roles_synced',
                description: "Updated roles for user '{$user->name}' to: ".implode(', ', $roleNames),
                auditable: $user,
                userId: $actor?->id,
                oldValues: ['roles' => $oldRoles],
                newValues: ['roles' => $roleNames]
            );
        });
    }

    /**
     * Create a new granular permission.
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
                description: "Created permission key '{$perm->name}' under module '{$perm->module}'.",
                auditable: $perm,
                newValues: ['name' => $perm->name, 'module' => $perm->module, 'description' => $perm->description]
            );

            return $perm;
        });
    }

    /**
     * Update an existing permission.
     */
    public static function updatePermission(Permission $permission, string $name, string $module, ?string $description = null): Permission
    {
        return DB::transaction(function () use ($permission, $name, $module, $description) {
            $oldValues = [
                'name' => $permission->name,
                'module' => $permission->module,
                'description' => $permission->description,
            ];

            $permission->update([
                'name' => $name,
                'module' => $module,
                'description' => $description,
            ]);

            AuditLogService::log(
                event: 'permission_updated',
                description: "Updated permission '{$permission->name}'.",
                auditable: $permission,
                oldValues: $oldValues,
                newValues: ['name' => $permission->name, 'module' => $permission->module, 'description' => $permission->description]
            );

            return $permission;
        });
    }

    /**
     * Delete a permission.
     */
    public static function deletePermission(Permission $permission): bool
    {
        return DB::transaction(function () use ($permission) {
            AuditLogService::log(
                event: 'permission_deleted',
                description: "Deleted permission '{$permission->name}' from module '{$permission->module}'.",
                auditable: $permission,
                oldValues: ['name' => $permission->name, 'module' => $permission->module]
            );

            return (bool) $permission->delete();
        });
    }
}
