<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriberService
{
    public function __construct(
        protected EmailService $emailService,
        protected SmsService $smsService
    ) {}

    /**
     * Subscribe or reactivate an email/WhatsApp contact.
     *
     * @param  array{type?: string, email?: string|null, phone?: string|null, country_code?: string|null, name?: string|null, source?: string, tags?: array<string>|null, ip_address?: string|null, user_agent?: string|null, metadata?: array|null}  $data
     */
    public function subscribe(array $data, ?User $actor = null): Subscriber
    {
        return DB::transaction(function () use ($data, $actor) {
            $email = ! empty($data['email']) ? strtolower(trim($data['email'])) : null;
            $phone = ! empty($data['phone']) ? preg_replace('/[^\d+]/', '', trim($data['phone'])) : null;
            $countryCode = $data['country_code'] ?? '+91';

            $type = $data['type'] ?? ($email && $phone ? Subscriber::TYPE_BOTH : ($phone ? Subscriber::TYPE_WHATSAPP : Subscriber::TYPE_EMAIL));
            $source = $data['source'] ?? Subscriber::SOURCE_WELCOME;
            $name = ! empty($data['name']) ? trim($data['name']) : null;
            $tags = ! empty($data['tags']) && is_array($data['tags']) ? array_values(array_unique($data['tags'])) : ['general'];

            // Find existing (including soft-deleted)
            $existing = Subscriber::withTrashed()
                ->where(function ($q) use ($email, $phone) {
                    if ($email && $phone) {
                        $q->where('email', $email)->orWhere('phone', $phone);
                    } elseif ($email) {
                        $q->where('email', $email);
                    } elseif ($phone) {
                        $q->where('phone', $phone);
                    }
                })
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                $mergedTags = array_values(array_unique(array_merge($existing->tags ?? [], $tags)));

                $updates = [
                    'status' => Subscriber::STATUS_ACTIVE,
                    'type' => $type,
                    'tags' => $mergedTags,
                    'unsubscribed_at' => null,
                    'unsubscribe_reason' => null,
                    'updated_by' => $actor?->id,
                ];

                if ($name && empty($existing->name)) {
                    $updates['name'] = $name;
                }
                if ($email) {
                    $updates['email'] = $email;
                }
                if ($phone) {
                    $updates['phone'] = $phone;
                    $updates['country_code'] = $countryCode;
                }

                $existing->update($updates);

                AuditLogService::log(
                    event: 'subscriber_reactivated',
                    description: "Reactivated subscription for '{$existing->email}' / '{$existing->phone}'.",
                    userId: $actor?->id
                );

                $subscriber = $existing;
            } else {
                $subscriber = Subscriber::create([
                    'uuid' => (string) Str::uuid(),
                    'type' => $type,
                    'email' => $email,
                    'country_code' => $countryCode,
                    'phone' => $phone,
                    'name' => $name,
                    'status' => Subscriber::STATUS_ACTIVE,
                    'source' => $source,
                    'tags' => $tags,
                    'ip_address' => $data['ip_address'] ?? null,
                    'user_agent' => $data['user_agent'] ?? null,
                    'subscribed_at' => now(),
                    'unsubscribe_token' => Str::random(40),
                    'metadata' => $data['metadata'] ?? null,
                    'created_by' => $actor?->id,
                ]);

                AuditLogService::log(
                    event: 'subscriber_created',
                    description: "New subscription added via {$source}: '{$subscriber->email}' / '{$subscriber->phone}'.",
                    newValues: [
                        'type' => $type,
                        'email' => $email,
                        'phone' => $phone,
                        'source' => $source,
                    ],
                    userId: $actor?->id
                );
            }

            // Dispatch welcome notifications
            $this->notifyWelcome($subscriber);

            return $subscriber;
        });
    }

    /**
     * Unsubscribe a contact via secure one-click token.
     */
    public function unsubscribe(string $token, ?string $reason = null): ?Subscriber
    {
        $subscriber = Subscriber::where('unsubscribe_token', $token)->first();
        if (! $subscriber) {
            return null;
        }

        $subscriber->update([
            'status' => Subscriber::STATUS_UNSUBSCRIBED,
            'unsubscribed_at' => now(),
            'unsubscribe_reason' => $reason,
        ]);

        AuditLogService::log(
            event: 'subscriber_unsubscribed',
            description: "Contact unsubscribed: '{$subscriber->email}' / '{$subscriber->phone}'. Reason: {$reason}",
            userId: $subscriber->created_by
        );

        return $subscriber;
    }

    /**
     * Update an existing subscriber record.
     *
     * @param  array{type?: string, email?: string|null, phone?: string|null, country_code?: string|null, name?: string|null, status?: string, tags?: array<string>|null, metadata?: array|null}  $data
     */
    public function updateSubscriber(Subscriber $subscriber, array $data, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($subscriber, $data, $actor) {
            $old = $subscriber->only(['email', 'phone', 'status', 'type', 'tags']);

            $updates = [
                'updated_by' => $actor?->id,
            ];

            if (isset($data['email'])) {
                $updates['email'] = strtolower(trim($data['email']));
            }
            if (isset($data['phone'])) {
                $updates['phone'] = preg_replace('/[^\d+]/', '', trim($data['phone']));
            }
            if (isset($data['country_code'])) {
                $updates['country_code'] = $data['country_code'];
            }
            if (isset($data['name'])) {
                $updates['name'] = trim($data['name']);
            }
            if (isset($data['status'])) {
                $updates['status'] = $data['status'];
                if ($data['status'] === Subscriber::STATUS_UNSUBSCRIBED && is_null($subscriber->unsubscribed_at)) {
                    $updates['unsubscribed_at'] = now();
                } elseif ($data['status'] === Subscriber::STATUS_ACTIVE) {
                    $updates['unsubscribed_at'] = null;
                }
            }
            if (isset($data['type'])) {
                $updates['type'] = $data['type'];
            }
            if (isset($data['tags'])) {
                $updates['tags'] = array_values(array_unique((array) $data['tags']));
            }
            if (isset($data['metadata'])) {
                $updates['metadata'] = $data['metadata'];
            }

            $subscriber->update($updates);

            AuditLogService::log(
                event: 'subscriber_updated',
                description: "Updated subscriber #{$subscriber->id} ({$subscriber->email}/{$subscriber->phone}).",
                oldValues: $old,
                newValues: $subscriber->only(['email', 'phone', 'status', 'type', 'tags']),
                userId: $actor?->id
            );

            return true;
        });
    }

    /**
     * Delete a subscriber (soft delete by default, or force delete).
     */
    public function deleteSubscriber(Subscriber $subscriber, ?User $actor = null, bool $force = false): bool
    {
        return DB::transaction(function () use ($subscriber, $actor, $force) {
            if ($force) {
                $subscriber->forceDelete();
                $event = 'subscriber_force_deleted';
                $desc = "Permanently purged subscriber #{$subscriber->id}.";
            } else {
                $subscriber->update(['deleted_by' => $actor?->id]);
                $subscriber->delete();
                $event = 'subscriber_deleted';
                $desc = "Soft-deleted subscriber #{$subscriber->id} ({$subscriber->email}/{$subscriber->phone}).";
            }

            AuditLogService::log(
                event: $event,
                description: $desc,
                userId: $actor?->id
            );

            return true;
        });
    }

    /**
     * Restore a soft-deleted subscriber.
     */
    public function restoreSubscriber(int $id, ?User $actor = null): bool
    {
        $subscriber = Subscriber::onlyTrashed()->findOrFail($id);
        $subscriber->restore();

        AuditLogService::log(
            event: 'subscriber_restored',
            description: "Restored subscriber #{$subscriber->id} ({$subscriber->email}/{$subscriber->phone}).",
            userId: $actor?->id
        );

        return true;
    }

    /**
     * Perform bulk operations across multiple subscribers.
     */
    public function bulkAction(string $action, array $ids, mixed $value = null, ?User $actor = null): int
    {
        $count = 0;

        if ($action === 'restore') {
            $subscribers = Subscriber::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($subscribers as $s) {
                if ($this->restoreSubscriber($s->id, $actor)) {
                    $count++;
                }
            }

            return $count;
        }

        if ($action === 'force_delete') {
            $subscribers = Subscriber::withTrashed()->whereIn('id', $ids)->get();
            foreach ($subscribers as $s) {
                if ($this->deleteSubscriber($s, $actor, true)) {
                    $count++;
                }
            }

            return $count;
        }

        $subscribers = Subscriber::whereIn('id', $ids)->get();

        foreach ($subscribers as $s) {
            $success = match ($action) {
                'status' => $this->updateSubscriber($s, ['status' => (string) $value], $actor),
                'tag_add' => $this->updateSubscriber($s, ['tags' => array_merge($s->tags ?? [], [(string) $value])], $actor),
                'tag_remove' => $this->updateSubscriber($s, ['tags' => array_diff($s->tags ?? [], [(string) $value])], $actor),
                'delete' => $this->deleteSubscriber($s, $actor, false),
                default => false,
            };

            if ($success) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Import subscribers from a CSV file.
     *
     * @return array{imported: int, skipped: int, errors: array<string>}
     */
    public function importCsv(UploadedFile $file, string $defaultSource = 'import', array $defaultTags = [], ?User $actor = null): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Unable to read CSV file.']];
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0, 'errors' => ['CSV file is empty.']];
        }

        // Normalize header indices
        $headerMap = [];
        foreach ($headers as $idx => $header) {
            $norm = strtolower(trim(str_replace([' ', '_', '-'], '', $header)));
            $headerMap[$norm] = $idx;
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (empty(array_filter($row))) {
                continue;
            }

            $emailIdx = $headerMap['email'] ?? ($headerMap['emailaddress'] ?? null);
            $phoneIdx = $headerMap['phone'] ?? ($headerMap['mobile'] ?? ($headerMap['whatsapp'] ?? null));
            $nameIdx = $headerMap['name'] ?? ($headerMap['fullname'] ?? ($headerMap['contactname'] ?? null));
            $tagsIdx = $headerMap['tags'] ?? ($headerMap['tag'] ?? null);

            $email = $emailIdx !== null && isset($row[$emailIdx]) ? trim($row[$emailIdx]) : null;
            $phone = $phoneIdx !== null && isset($row[$phoneIdx]) ? trim($row[$phoneIdx]) : null;
            $name = $nameIdx !== null && isset($row[$nameIdx]) ? trim($row[$nameIdx]) : null;
            $rowTags = $tagsIdx !== null && isset($row[$tagsIdx]) ? array_map('trim', explode(',', $row[$tagsIdx])) : [];

            if (empty($email) && empty($phone)) {
                $skipped++;
                $errors[] = "Row {$rowNum}: No email or phone provided.";

                continue;
            }

            if (! empty($email) && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = "Row {$rowNum}: Invalid email format '{$email}'.";

                continue;
            }

            try {
                $this->subscribe([
                    'email' => $email,
                    'phone' => $phone,
                    'name' => $name,
                    'source' => $defaultSource,
                    'tags' => array_merge($defaultTags, $rowTags),
                ], $actor);

                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Row {$rowNum}: ".$e->getMessage();
            }
        }

        fclose($handle);

        AuditLogService::log(
            event: 'subscribers_imported',
            description: "Bulk imported {$imported} subscribers from CSV (skipped: {$skipped}).",
            userId: $actor?->id
        );

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 10), // return top 10 errors
        ];
    }

    /**
     * Dispatch welcome email or SMS upon subscription.
     */
    protected function notifyWelcome(Subscriber $subscriber): void
    {
        $vars = [
            'name' => $subscriber->name ?: 'Subscriber',
            'email' => $subscriber->email ?: '',
            'phone' => $subscriber->phone ?: '',
            'app_name' => Setting::appName(),
            'unsubscribe_url' => $subscriber->unsubscribeUrl(),
        ];

        // 1. Welcome Email
        if ($subscriber->isEmail() && EmailService::isEnabled()) {
            $this->emailService->sendTemplate(
                email: $subscriber->email,
                templateCode: 'subscriber_welcome_email',
                variables: $vars
            );
        }

        // 2. Welcome SMS / WhatsApp
        if ($subscriber->isWhatsapp() && SmsService::isEnabled()) {
            $this->smsService->sendTemplate(
                phone: $subscriber->phone,
                templateCode: 'subscriber_welcome_sms',
                variables: $vars
            );
        }
    }
}
