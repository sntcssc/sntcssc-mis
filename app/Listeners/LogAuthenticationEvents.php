<?php

namespace App\Listeners;

use App\Models\AppNotification;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;

class LogAuthenticationEvents
{
    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            $user->recordLogin();

            try {
                /** @var NotificationService $notificationService */
                $notificationService = app(NotificationService::class);
                $ip = request()->ip() ?? '127.0.0.1';
                $time = now()->format('d M Y, h:i A');

                $notificationService->send(
                    user: $user,
                    title: __('Security Alert: New Sign-in Detected'),
                    message: __('New login detected from IP :ip on :time.', [
                        'ip' => $ip,
                        'time' => $time,
                    ]),
                    category: AppNotification::CATEGORY_SECURITY,
                    options: [
                        'type' => 'security_login',
                        'icon' => 'shield-check',
                        'metadata' => [
                            'ip_address' => $ip,
                            'timestamp' => $time,
                            'guard' => $event->guard ?? 'web',
                        ],
                    ]
                );
            } catch (\Throwable) {
                // Keep login flow robust
            }
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

            try {
                /** @var NotificationService $notificationService */
                $notificationService = app(NotificationService::class);
                $ip = request()->ip() ?? '127.0.0.1';

                $notificationService->send(
                    user: $user,
                    title: __('Security Alert: Failed Sign-in Attempt'),
                    message: __('A failed login attempt was detected for your account from IP :ip. If this was not you, please secure your account immediately.', [
                        'ip' => $ip,
                    ]),
                    category: AppNotification::CATEGORY_SECURITY,
                    options: [
                        'type' => 'security_failed_login',
                        'icon' => 'shield-alert',
                        'metadata' => [
                            'ip_address' => $ip,
                            'time' => now()->format('d M Y, h:i A'),
                        ],
                    ]
                );
            } catch (\Throwable) {
                // Keep auth flow robust
            }
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

            try {
                /** @var NotificationService $notificationService */
                $notificationService = app(NotificationService::class);
                $time = now()->format('d M Y, h:i A');

                $notificationService->send(
                    user: $user,
                    title: __('Security Alert: Password Changed'),
                    message: __('Your account password was successfully reset on :time. If you did not make this change, please contact support immediately.', [
                        'time' => $time,
                    ]),
                    category: AppNotification::CATEGORY_SECURITY,
                    options: [
                        'type' => 'security_password_reset',
                        'icon' => 'shield-alert',
                        'metadata' => [
                            'time' => $time,
                            'ip_address' => request()->ip() ?? '127.0.0.1',
                        ],
                    ]
                );
            } catch (\Throwable) {
                // Keep reset flow robust
            }
        }

        AuditLogService::log(
            event: 'password_reset',
            description: "Password was reset for user {$user->name} ({$user->email}). Account unlocked.",
            auditable: $user,
            userId: $user->getAuthIdentifier()
        );
    }

    public function handleRegistered(Registered $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            try {
                /** @var NotificationService $notificationService */
                $notificationService = app(NotificationService::class);

                $notificationService->send(
                    user: $user,
                    title: __('Welcome to :app!', ['app' => Setting::appName()]),
                    message: __('Hello :name, your account has been registered successfully. Welcome to our portal!', [
                        'name' => $user->name,
                    ]),
                    category: AppNotification::CATEGORY_SYSTEM,
                    options: [
                        'type' => 'account_welcome',
                        'icon' => 'user-check',
                        'metadata' => [
                            'registered_at' => now()->format('d M Y, h:i A'),
                            'email' => $user->email,
                        ],
                    ]
                );
            } catch (\Throwable) {
                // Keep registration flow robust
            }

            AuditLogService::log(
                event: 'user_registered',
                description: "New user registered: {$user->name} ({$user->email}).",
                auditable: $user,
                userId: $user->getAuthIdentifier()
            );
        }
    }

    public function handleVerified(Verified $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            try {
                /** @var NotificationService $notificationService */
                $notificationService = app(NotificationService::class);

                $notificationService->send(
                    user: $user,
                    title: __('Security Alert: Email Verified'),
                    message: __('Your email address :email was successfully verified.', [
                        'email' => $user->email,
                    ]),
                    category: AppNotification::CATEGORY_SECURITY,
                    options: [
                        'type' => 'email_verified',
                        'icon' => 'shield-check',
                    ]
                );
            } catch (\Throwable) {
                // Keep verification flow robust
            }

            AuditLogService::log(
                event: 'email_verified',
                description: "Email verified for user: {$user->name} ({$user->email}).",
                auditable: $user,
                userId: $user->getAuthIdentifier()
            );
        }
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
            Registered::class => 'handleRegistered',
            Verified::class => 'handleVerified',
        ];
    }
}
