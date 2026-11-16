<?php

namespace App\Providers;

use App\Models\CustomerAsset;
use App\Models\Estimate;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\OrderAssignment;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\TechnicianAvailabilityLog;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\Analytics\AnalyticsAccess;
use App\Services\Analytics\AnalyticsCacheManager;
use App\Services\Assistant\Actions\AssignTechnicianAction;
use App\Services\Assistant\Actions\CreateCustomerFollowupAction;
use App\Services\Assistant\Actions\CreateInventoryRestockRequestAction;
use App\Services\Assistant\Actions\CreateQuotationAction;
use App\Services\Assistant\Actions\CreateServiceOrderAction;
use App\Services\Assistant\Actions\UpdateOrderStatusAction;
use App\Services\Assistant\AssistantActionCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AssistantActionCoordinator::class, function ($app): AssistantActionCoordinator {
            return new AssistantActionCoordinator([
                $app->make(CreateServiceOrderAction::class),
                $app->make(CreateQuotationAction::class),
                $app->make(UpdateOrderStatusAction::class),
                $app->make(AssignTechnicianAction::class),
                $app->make(CreateInventoryRestockRequestAction::class),
                $app->make(CreateCustomerFollowupAction::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureAnalyticsCacheInvalidation();
    }

    protected function configureAuthorization(): void
    {
        Gate::define('manage-team', fn (User $user): bool => $user->is_active
            && in_array($user->role, ['operator', 'admin'], true));

        Gate::define('view-workforce', fn (User $user): bool => $user->is_active
            && in_array($user->role, ['operator', 'admin', 'supervisor', 'technician'], true));

        Gate::define('view-analytics', fn (User $user): bool => app(AnalyticsAccess::class)->canAccessHub($user));

        Gate::define('export-analytics', fn (User $user, ?string $type = null): bool => $type !== null
            ? app(AnalyticsAccess::class)->canExportType($user, $type)
            : count(app(AnalyticsAccess::class)->allowedExportTypes($user)) > 0);
    }

    protected function configureAnalyticsCacheInvalidation(): void
    {
        /** @var array<class-string<Model>, list<string>> $domainMap */
        $domainMap = [
            Service::class => ['orders'],
            ServiceOrder::class => ['orders', 'workforce', 'customers', 'finance'],
            OrderAssignment::class => ['orders', 'workforce'],
            TechnicianWorkSession::class => ['workforce', 'orders'],
            TechnicianAvailabilityLog::class => ['workforce'],
            Estimate::class => ['finance', 'orders'],
            Invoice::class => ['finance', 'orders', 'customers'],
            FinancialTransaction::class => ['finance', 'customers'],
            InventoryItem::class => ['inventory'],
            InventoryMovement::class => ['inventory', 'finance'],
            ServiceOrderSparePart::class => ['inventory', 'finance', 'orders'],
            CustomerAsset::class => ['customers', 'warranties'],
            Warranty::class => ['warranties', 'customers'],
            WarrantyClaim::class => ['warranties', 'customers'],
            User::class => ['customers', 'workforce'],
        ];

        foreach ($domainMap as $modelClass => $domains) {
            $invalidate = static function () use ($domains): void {
                app(AnalyticsCacheManager::class)->invalidateDomains($domains);
            };

            $modelClass::saved($invalidate);
            $modelClass::deleted($invalidate);
        }

        ServiceOrder::restored(static function (): void {
            app(AnalyticsCacheManager::class)->invalidateDomains(['orders', 'workforce', 'customers', 'finance']);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('mobile-login', fn (Request $request): array => [
            Limit::perMinute(5)->by('mobile-login-account:'.hash('sha256', mb_strtolower($request->string('email')->toString()).'|'.$request->ip())),
            Limit::perMinute(30)->by('mobile-login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('mobile-two-factor', fn (Request $request): array => [
            Limit::perMinute(5)->by('mobile-2fa-challenge:'.hash('sha256', $request->string('challenge_token')->toString())),
            Limit::perMinute(30)->by('mobile-2fa-ip:'.$request->ip()),
        ]);
        RateLimiter::for('mobile-register', fn (Request $request): Limit => Limit::perHour(10)->by('mobile-register:'.$request->ip()));
        RateLimiter::for('mobile-recovery', fn (Request $request): array => [
            Limit::perHour(5)->by('mobile-recovery-email:'.hash('sha256', mb_strtolower($request->string('email')->toString()))),
            Limit::perMinute(10)->by('mobile-recovery-ip:'.$request->ip()),
        ]);
        RateLimiter::for('mobile-verification', fn (Request $request): Limit => Limit::perMinute(1)->by('mobile-verify:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('assistant', function (Request $request): array {
            if ($request->user()) {
                $key = hash('sha256', 'user:'.$request->user()->getAuthIdentifier());

                return [
                    Limit::perMinute(12)->by('assistant-minute:'.$key),
                    Limit::perDay(120)->by('assistant-day:'.$key),
                ];
            }

            $sessionKey = hash('sha256', 'guest:'.$request->session()->getId());
            $ipKey = hash('sha256', (string) $request->ip());

            return [
                Limit::perMinute(12)->by('assistant-session-minute:'.$sessionKey),
                Limit::perMinute(30)->by('assistant-ip-minute:'.$ipKey),
                Limit::perDay(240)->by('assistant-ip-day:'.$ipKey),
            ];
        });
    }
}
