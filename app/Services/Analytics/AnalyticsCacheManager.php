<?php

namespace App\Services\Analytics;

use Closure;
use Illuminate\Support\Facades\Cache;

class AnalyticsCacheManager
{
    private const VERSION_PREFIX = 'analytics:domain_version:';

    /** @var list<string> */
    public const DOMAINS = ['orders', 'finance', 'inventory', 'workforce', 'customers', 'warranties'];

    /**
     * Remember an analytics payload keyed against current domain version stamps.
     *
     * @template TReturn
     *
     * @param  list<string>  $domains
     * @param  Closure(): TReturn  $resolver
     * @return TReturn
     */
    public function remember(string $namespace, array $domains, AnalyticsPeriod $period, Closure $resolver, string $extraKey = ''): mixed
    {
        $ttl = max(30, (int) config('analytics.cache_ttl_seconds', 300));
        $versions = [];

        foreach ($domains as $domain) {
            $versions[] = $domain.':'.$this->versionFor($domain);
        }

        $cacheKey = sprintf(
            'analytics:payload:%s:%s:%s%s',
            $namespace,
            implode('|', $versions),
            sha1($period->cacheKeySuffix()),
            $extraKey !== '' ? ':'.sha1($extraKey) : '',
        );

        return Cache::remember($cacheKey, now()->addSeconds($ttl), $resolver);
    }

    public function versionFor(string $domain): int
    {
        return (int) Cache::get(self::VERSION_PREFIX.$domain, 1);
    }

    /**
     * Bump cache version stamps for the specified analytics domains after data mutations.
     *
     * @param  list<string>  $domains
     */
    public function invalidateDomains(array $domains): void
    {
        foreach ($domains as $domain) {
            $key = self::VERSION_PREFIX.$domain;
            $next = $this->versionFor($domain) + 1;
            Cache::forever($key, $next);
        }
    }

    public function invalidateAll(): void
    {
        $this->invalidateDomains(self::DOMAINS);
    }
}
