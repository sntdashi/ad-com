<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AssistantSetting;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\FonnteMessageSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AdminControlCenterController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('manage-team');
        $assistantSettings = AssistantSetting::query()->first();
        $activeProvider = AiProvider::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
        $auditEvent = $request->string('audit_event')->trim()->value();

        $auditQuery = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->orderByDesc('audit_logs.created_at');

        if ($auditEvent !== '') {
            $auditQuery->where('audit_logs.event', 'like', "{$auditEvent}%");
        }

        return Inertia::render('settings/admin-control-center', [
            'isSuperadmin' => $request->user()->role === 'operator',
            'auditEvent' => $auditEvent,
            'servicesCount' => Service::query()->where('is_active', true)->count(),
            'roleCounts' => User::query()->select('role', DB::raw('count(*) as total'))->groupBy('role')->pluck('total', 'role'),
            'signals' => [
                'open_orders' => ServiceOrder::query()->whereNotIn('status', ['completed', 'closed', 'cancelled'])->count(),
                'unassigned_orders' => ServiceOrder::query()->whereNull('assigned_technician_id')->whereNotIn('status', ['completed', 'closed', 'cancelled'])->count(),
                'pending_transactions' => FinancialTransaction::query()->where('status', 'pending')->count(),
                'low_stock_items' => InventoryItem::query()->where('is_active', true)->whereColumn('quantity_on_hand', '<=', 'reorder_level')->count(),
                'inactive_accounts' => User::query()->where('is_active', false)->count(),
                'accounts_missing_phone' => User::query()->where(function ($query): void {
                    $query->whereNull('phone')->orWhere('phone', '');
                })->count(),
            ],
            'recentActivity' => $auditQuery
                ->limit(15)
                ->get(['audit_logs.event', 'audit_logs.auditable_type', 'audit_logs.created_at', 'users.name as actor'])
                ->map(fn (object $event): array => [
                    'event' => $event->event,
                    'target' => class_basename((string) $event->auditable_type),
                    'actor' => $event->actor ?? 'Akun telah dihapus',
                    'created_at' => $event->created_at,
                ]),
            'assistant' => [
                'provider' => $activeProvider ? $activeProvider->name : 'OpenAI',
                'model' => $activeProvider?->model_id ?: ($assistantSettings->model ?? config('services.openai.model')),
                'key_configured' => $activeProvider
                    ? filled($activeProvider->api_token)
                    : (filled($assistantSettings?->api_key) || filled(config('services.openai.key'))),
                'is_enabled' => $assistantSettings ? (bool) $assistantSettings->is_enabled : true,
            ],
            'whatsapp' => ['gateway_configured' => app(FonnteMessageSender::class)->isConfigured()],
        ]);
    }
}
