<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImpersonationService
{
    public const SESSION_KEY = 'impersonator_id';

    public const SESSION_TIME_KEY = 'impersonated_at';

    /**
     * Determine whether user impersonation is globally enabled in system settings.
     */
    public static function isAllowed(): bool
    {
        return (bool) Setting::get('system.allow_user_impersonation', true);
    }

    /**
     * Determine whether the current session is actively impersonating another user.
     */
    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /**
     * Get the ID of the original administrator impersonating the current user.
     */
    public function getImpersonatorId(): ?int
    {
        $id = session(self::SESSION_KEY);

        return $id ? (int) $id : null;
    }

    /**
     * Get the User model instance of the impersonating administrator.
     */
    public function getImpersonator(): ?User
    {
        $id = $this->getImpersonatorId();

        return $id ? User::find($id) : null;
    }

    /**
     * Determine if an actor user is authorized to impersonate a target user.
     */
    public function canImpersonate(?User $actor, ?User $target): bool
    {
        if (! self::isAllowed() || ! $actor || ! $target) {
            return false;
        }

        // Cannot impersonate oneself
        if ($actor->id === $target->id) {
            return false;
        }

        // Cannot impersonate soft-deleted accounts
        if ($target->trashed()) {
            return false;
        }

        // Actor must have administrative privileges
        $isActorSuperAdmin = $actor->hasRole(['Super Administrator', 'Super Admin']);
        $isActorAdmin = $isActorSuperAdmin || $actor->hasRole(['Administrator', 'Admin']) || $actor->can('users.impersonate');

        if (! $isActorAdmin) {
            return false;
        }

        // If target is Super Administrator, only Super Administrator can impersonate
        $isTargetSuperAdmin = $target->hasRole(['Super Administrator', 'Super Admin']);
        if ($isTargetSuperAdmin && ! $isActorSuperAdmin) {
            return false;
        }

        return true;
    }

    /**
     * Start an anonymous impersonation session.
     *
     * @return array{success: bool, message: string, user: ?User}
     */
    public function impersonate(User $actor, User $target): array
    {
        if (! self::isAllowed()) {
            return [
                'success' => false,
                'message' => __('User impersonation is currently disabled in system settings.'),
                'user' => null,
            ];
        }

        if (! $this->canImpersonate($actor, $target)) {
            return [
                'success' => false,
                'message' => __('You do not have authorization to access this user dashboard.'),
                'user' => null,
            ];
        }

        try {
            // Keep the root administrator ID if chained
            $originalImpersonatorId = session(self::SESSION_KEY, $actor->id);

            session()->put(self::SESSION_KEY, $originalImpersonatorId);
            session()->put(self::SESSION_TIME_KEY, now()->toIso8601String());

            AuditLogService::log(
                event: 'user_impersonation_started',
                description: "Admin '{$actor->name}' started anonymous impersonation session for user '{$target->name}' ({$target->email}).",
                newValues: [
                    'impersonator_id' => $actor->id,
                    'impersonator_name' => $actor->name,
                    'target_user_id' => $target->id,
                    'target_user_email' => $target->email,
                ],
                userId: $actor->id
            );

            Auth::login($target);

            return [
                'success' => true,
                'message' => __("Now browsing as ':name'.", ['name' => $target->name]),
                'user' => $target,
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to initiate user impersonation: '.$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'message' => __('An error occurred while switching to user dashboard.'),
                'user' => null,
            ];
        }
    }

    /**
     * Terminate the active impersonation session and restore administrator account.
     *
     * @return array{success: bool, message: string, user: ?User}
     */
    public function leave(): array
    {
        $impersonatorId = $this->getImpersonatorId();

        if (! $impersonatorId) {
            return [
                'success' => false,
                'message' => __('No active impersonation session found.'),
                'user' => null,
            ];
        }

        $admin = User::find($impersonatorId);
        $currentUser = Auth::user();

        if (! $admin) {
            session()->forget([self::SESSION_KEY, self::SESSION_TIME_KEY]);

            return [
                'success' => false,
                'message' => __('Original administrator account could not be found.'),
                'user' => null,
            ];
        }

        try {
            AuditLogService::log(
                event: 'user_impersonation_ended',
                description: "Admin '{$admin->name}' terminated impersonation session for user '".($currentUser?->name ?? 'User')."'.",
                newValues: [
                    'impersonator_id' => $admin->id,
                    'target_user_id' => $currentUser?->id,
                ],
                userId: $admin->id
            );

            session()->forget([self::SESSION_KEY, self::SESSION_TIME_KEY]);
            Auth::login($admin);

            return [
                'success' => true,
                'message' => __('Switched back to your administrator account.'),
                'user' => $admin,
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to terminate user impersonation: '.$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'message' => __('An error occurred while switching back to administrator account.'),
                'user' => null,
            ];
        }
    }
}
