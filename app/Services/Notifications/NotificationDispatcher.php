<?php

namespace App\Services\Notifications;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\NotificationDelivery;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationDispatcher
{
    /**
     * Dispatch an in-app notification and optionally queue a WhatsApp delivery.
     *
     * Respects global integration settings, user notification preferences (unless category
     * is mandatory), and optional deduplication keys to avoid duplicate notifications.
     *
     * @param  iterable<int, User>  $users
     * @param  array<string, mixed>  $meta
     */
    public function notifyUsers(
        iterable $users,
        string $eventKey,
        string $title,
        string $body,
        ?string $href = null,
        array $meta = [],
        bool $sendWhatsapp = true,
        ?string $dedupeKey = null,
        ?string $priority = null,
    ): void {
        $configKey = str_replace('.', '_', $eventKey);
        $settings = SystemSetting::getMany([
            "notif_event_{$configKey}_enabled" => '1',
            "notif_event_{$configKey}_template" => '',
        ]);

        $category = NotificationCatalog::categoryForEvent($eventKey);
        $isMandatory = NotificationCatalog::isMandatoryCategory($category);

        if (! $isMandatory && $settings["notif_event_{$configKey}_enabled"] !== '1') {
            return;
        }

        $resolvedPriority = NotificationCatalog::priorityForEvent(
            $eventKey,
            is_string($priority) ? $priority : (is_string($meta['priority'] ?? null) ? $meta['priority'] : null),
        );

        $customTemplate = trim($settings["notif_event_{$configKey}_template"]);
        $formattedBody = $customTemplate !== ''
            ? str_replace(['{title}', '{body}'], [$title, $body], $customTemplate)
            : $body;

        $recipientList = [];
        $seenIds = [];
        foreach ($users as $user) {
            if (isset($seenIds[$user->id]) || ! $user->is_active) {
                continue;
            }
            $seenIds[$user->id] = true;
            $recipientList[] = $user;
        }

        if ($recipientList === []) {
            return;
        }

        // Load explicit per-user preferences for this category in a single query
        $preferences = UserNotificationPreference::query()
            ->whereIn('user_id', array_keys($seenIds))
            ->where('category', $category)
            ->get()
            ->keyBy('user_id');

        $normalizedDedupe = $dedupeKey !== null && trim($dedupeKey) !== ''
            ? mb_substr(trim($dedupeKey), 0, 120)
            : null;
        $now = now();

        foreach ($recipientList as $user) {
            if (! array_key_exists($category, NotificationCatalog::categoriesForRole($user->role))) {
                continue;
            }

            /** @var UserNotificationPreference|null $pref */
            $pref = $preferences->get($user->id);
            $allowInApp = $isMandatory || ($pref === null ? true : $pref->in_app_enabled);
            $allowWhatsapp = $isMandatory || ($pref === null ? true : $pref->whatsapp_enabled);

            if ($normalizedDedupe !== null) {
                $alreadyNotified = DB::table('notifications')
                    ->where('notifiable_type', User::class)
                    ->where('notifiable_id', $user->id)
                    ->where('dedupe_key', $normalizedDedupe)
                    ->whereNull('archived_at')
                    ->exists();

                if ($alreadyNotified) {
                    continue;
                }
            }

            if ($allowInApp) {
                DB::table('notifications')->insert([
                    'id' => (string) Str::uuid(),
                    'type' => 'adcom.operational',
                    'category' => $category,
                    'priority' => $resolvedPriority,
                    'dedupe_key' => $normalizedDedupe,
                    'notifiable_type' => User::class,
                    'notifiable_id' => $user->id,
                    'data' => json_encode([
                        'event_key' => $eventKey,
                        'category' => $category,
                        'priority' => $resolvedPriority,
                        'title' => $title,
                        'body' => $formattedBody,
                        'href' => $href,
                        'meta' => $meta,
                    ]),
                    'read_at' => null,
                    'archived_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (! $sendWhatsapp || ! $allowWhatsapp || ! is_string($user->phone) || trim($user->phone) === '') {
                continue;
            }

            if ($normalizedDedupe !== null) {
                $alreadyDelivered = NotificationDelivery::query()
                    ->where('user_id', $user->id)
                    ->where('channel', 'whatsapp')
                    ->where('dedupe_key', $normalizedDedupe)
                    ->exists();

                if ($alreadyDelivered) {
                    continue;
                }
            }

            $maskedPhone = $this->maskPhone($user->phone);
            $delivery = NotificationDelivery::query()->create([
                'user_id' => $user->id,
                'channel' => 'whatsapp',
                'event_key' => $eventKey,
                'dedupe_key' => $normalizedDedupe,
                'recipient_phone' => preg_replace('/\D+/', '', $user->phone) ?: $user->phone,
                'status' => 'queued',
                'attempts' => 0,
                'payload_summary' => [
                    'title' => $title,
                    'target' => $maskedPhone,
                    ...$meta,
                ],
            ]);

            SendWhatsAppNotificationJob::dispatch(
                $delivery->id,
                "[AD Com] {$title}\n{$formattedBody}",
            );
        }
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? $phone;
        if (strlen($digits) <= 4) {
            return '****';
        }

        return substr($digits, 0, 4).'****'.substr($digits, -2);
    }
}
