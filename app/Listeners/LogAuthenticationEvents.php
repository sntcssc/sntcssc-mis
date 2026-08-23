<?php

namespace App\Listeners;

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

        AuditLogService::log(
            event: 'failed_login',
            description: "Failed login attempt for account: {$email}.",
            auditable: $event->user,
            userId: $event->user?->getAuthIdentifier()
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        AuditLogService::log(
            event: 'password_reset',
            description: "Password was reset for user {$user->name} ({$user->email}).",
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
