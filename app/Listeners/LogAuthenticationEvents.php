<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

class LogAuthenticationEvents
{
    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            $user->recordLogin();
        }

        AuditLogService::log(
            event: 'login',
            description: "User {$user->name} ({$user->email}) logged in successfully.",
            auditable: $user,
            userId: $user->getAuthIdentifier()
        );
    }

    public function handleLogout(Logout $event): void
    {
        if ($user = $event->user) {
            AuditLogService::log(
                event: 'logout',
                description: "User {$user->name} ({$user->email}) logged out.",
                auditable: $user,
                userId: $user->getAuthIdentifier()
            );
        }
    }

    public function handleFailed(Failed $event): void
    {
        $credentials = $event->credentials;
        $email = $credentials['email'] ?? ($credentials['name'] ?? 'Unknown');

        $user = $event->user;
        if (! $user && ! empty($credentials['email'])) {
            $user = User::where('email', $credentials['email'])->first();
        }

        if ($user instanceof User) {
            $user->recordFailedLogin();
        }

        AuditLogService::log(
            event: 'failed_login',
            description: "Failed login attempt for account: {$email}.".($user && $user->isLocked() ? ' Account has been locked due to excessive failed attempts.' : ''),
            auditable: $user,
            userId: $user?->getAuthIdentifier()
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            $user->unlockAccount();
        }

        AuditLogService::log(
            event: 'password_reset',
            description: "Password was reset for user {$user->name} ({$user->email}). Account unlocked.",
            auditable: $user,
            userId: $user->getAuthIdentifier()
        );
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            PasswordReset::class => 'handlePasswordReset',
        ];
    }
}
