<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $sharedUser = $user ? [
            ...$user->toArray(),
            'avatar' => $user->avatar_url ?? $user->socialAccounts()->value('avatar_url'),
        ] : null;

        if ($user) {
            $allowedCategories = array_keys(NotificationCatalog::categoriesForRole($user->role));
            $baseQuery = DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id)
                ->whereNull('archived_at')
                ->whereIn('category', $allowedCategories);

            $notifications = [
                'unread_count' => (clone $baseQuery)->whereNull('read_at')->count(),
                'recent' => (clone $baseQuery)
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get()
                    ->map(function (object $row): array {
                        /** @var array<string, mixed> $data */
                        $data = json_decode((string) $row->data, true) ?? [];
                        $eventKey = (string) ($data['event_key'] ?? 'system');
                        $category = (string) ($row->category ?: NotificationCatalog::categoryForEvent($eventKey));

                        return [
                            'id' => (string) $row->id,
                            'category' => $category,
                            'category_label' => NotificationCatalog::CATEGORIES[$category]['label'] ?? 'Sistem',
                            'priority' => (string) ($row->priority ?: NotificationCatalog::priorityForEvent($eventKey)),
                            'title' => (string) ($data['title'] ?? 'Notifikasi'),
                            'body' => (string) ($data['body'] ?? ''),
                            'href' => $data['href'] ?? null,
                            'read_at' => $row->read_at,
                            'created_at_relative' => Carbon::parse((string) $row->created_at)->diffForHumans(),
                        ];
                    })
                    ->all(),
            ];
        } else {
            $notifications = ['unread_count' => 0, 'recent' => []];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $sharedUser,
            ],
            'notifications' => $notifications,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
