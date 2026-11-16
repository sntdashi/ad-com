<?php

namespace App\Http\Controllers;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\FonnteMessageSender;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class NotificationController extends Controller
{
    public function index(
        Request $request,
        NotificationCenterService $center,
        FonnteMessageSender $sender,
    ): InertiaResponse|JsonResponse {
        $user = $request->user();
        $filters = [
            'search' => trim((string) $request->string('search')),
            'category' => (string) $request->string('category', 'all'),
            'status' => (string) $request->string('status', 'all'),
            'priority' => (string) $request->string('priority', 'all'),
            'tab' => (string) $request->string('tab', 'inbox'),
            'activity_filter' => (string) $request->string('activity_filter', 'all'),
        ];

        $inbox = $center->paginatedInbox($user, $filters);
        $stats = $center->stats($user);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'unread_count' => $stats['unread'],
                'stats' => $stats,
                'items' => $inbox->items(),
            ]);
        }

        $roleCategories = NotificationCatalog::categoriesForRole($user->role);
        $categoryOptions = collect($roleCategories)
            ->map(fn (array $meta, string $slug): array => [
                'value' => $slug,
                'label' => $meta['label'],
                'mandatory' => $meta['mandatory'],
            ])
            ->values()
            ->all();

        $activityFilter = $filters['activity_filter'];
        $allowedActivityFilters = in_array($user->role, ['operator', 'admin', 'supervisor'], true)
            ? ['all', 'orders', 'sla', 'workforce', 'inventory', 'warranty', 'audit']
            : ($user->role === 'customer'
                ? ['all', 'orders', 'workforce', 'warranty']
                : ['all', 'orders', 'sla', 'workforce', 'inventory']);

        if (! in_array($activityFilter, $allowedActivityFilters, true)) {
            $activityFilter = 'all';
        }

        return Inertia::render('notifications/index', [
            'inbox' => $inbox,
            'stats' => $stats,
            'filters' => [
                ...$filters,
                'activity_filter' => $activityFilter,
            ],
            'categoryOptions' => $categoryOptions,
            'activityFilterOptions' => $allowedActivityFilters,
            'priorityAlerts' => $center->priorityAlerts($user),
            'activityFeed' => $center->activityFeed($user, $activityFilter),
            'deliveries' => $center->deliveryLogs($user, $sender),
            'preferences' => $center->preferencesForUser($user),
            'fonnteConfigured' => $sender->isConfigured(),
        ]);
    }

    public function markAsRead(Request $request, string $notificationId): RedirectResponse
    {
        DB::table('notifications')
            ->where('id', $notificationId)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $request->user()->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $request->user()->id)
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return back()->with('success', 'Semua notifikasi aktif ditandai telah dibaca.');
    }

    public function archive(Request $request, string $notificationId): RedirectResponse
    {
        $updated = DB::table('notifications')
            ->where('id', $notificationId)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $request->user()->id)
            ->update([
                'read_at' => DB::raw('COALESCE(read_at, CURRENT_TIMESTAMP)'),
                'archived_at' => now(),
                'updated_at' => now(),
            ]);

        abort_if($updated === 0, 404);

        return back()->with('success', 'Notifikasi diarsipkan.');
    }

    public function destroy(Request $request, string $notificationId): RedirectResponse
    {
        $deleted = DB::table('notifications')
            ->where('id', $notificationId)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $request->user()->id)
            ->delete();

        abort_if($deleted === 0, 404);

        return back()->with('success', 'Notifikasi dihapus dari kotak masuk.');
    }

    public function updatePreferences(Request $request, NotificationCenterService $center): RedirectResponse
    {
        $allowedCategories = array_keys(NotificationCatalog::categoriesForRole($request->user()->role));

        $validated = $request->validate([
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.category' => ['required', 'string', Rule::in($allowedCategories)],
            'preferences.*.in_app_enabled' => ['required', 'boolean'],
            'preferences.*.whatsapp_enabled' => ['required', 'boolean'],
        ]);

        /** @var list<array{category: string, in_app_enabled: bool, whatsapp_enabled: bool}> $items */
        $items = $validated['preferences'];
        $center->updatePreferences($request->user(), $items, $request->ip());

        return back()->with('success', 'Preferensi notifikasi berhasil diperbarui.');
    }

    public function retryDelivery(
        Request $request,
        NotificationDelivery $delivery,
        NotificationCenterService $center,
    ): RedirectResponse {
        abort_unless($request->user()->role === 'operator', 403);

        $center->retryDelivery($delivery, $request->user(), $request->ip());

        return back()->with('success', 'Pengiriman WhatsApp dimasukkan kembali ke antrean.');
    }
}
