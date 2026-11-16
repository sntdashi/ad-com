<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class AnalyticsPeriod
{
    /** @var list<string> */
    public const PRESETS = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'custom'];

    public function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly CarbonImmutable $previousStart,
        public readonly CarbonImmutable $previousEnd,
        public readonly string $bucketGranularity,
        public readonly string $timezone,
    ) {}

    public static function resolve(
        ?string $preset = 'monthly',
        ?string $startDate = null,
        ?string $endDate = null,
        ?CarbonImmutable $referenceNow = null,
    ): self {
        $timezone = (string) config('app.timezone', 'Asia/Jakarta');
        $now = ($referenceNow ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $normalized = in_array($preset, self::PRESETS, true) ? (string) $preset : 'monthly';

        if ($normalized === 'custom' && $startDate !== null && $startDate !== '' && $endDate !== null && $endDate !== '') {
            try {
                $start = CarbonImmutable::parse($startDate, $timezone)->startOfDay();
                $end = CarbonImmutable::parse($endDate, $timezone)->endOfDay();
            } catch (InvalidArgumentException) {
                $start = $now->subDays(29)->startOfDay();
                $end = $now->endOfDay();
                $normalized = 'monthly';
            }

            if ($start->gt($end)) {
                [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
            }

            $daySpan = max(1, (int) $start->startOfDay()->diffInDays($end->startOfDay()) + 1);
            $prevEnd = $start->subSecond();
            $prevStart = $prevEnd->subDays($daySpan - 1)->startOfDay();

            $granularity = match (true) {
                $daySpan <= 31 => 'day',
                $daySpan <= 180 => 'week',
                default => 'month',
            };

            return new self($normalized, $start, $end, $prevStart, $prevEnd, $granularity, $timezone);
        }

        if ($normalized === 'custom') {
            $normalized = 'monthly';
        }

        return match ($normalized) {
            'daily' => new self(
                preset: 'daily',
                start: $now->startOfDay(),
                end: $now->endOfDay(),
                previousStart: $now->subDay()->startOfDay(),
                previousEnd: $now->subDay()->endOfDay(),
                bucketGranularity: 'day',
                timezone: $timezone,
            ),
            'weekly' => new self(
                preset: 'weekly',
                start: $now->subDays(6)->startOfDay(),
                end: $now->endOfDay(),
                previousStart: $now->subDays(13)->startOfDay(),
                previousEnd: $now->subDays(7)->endOfDay(),
                bucketGranularity: 'day',
                timezone: $timezone,
            ),
            'quarterly' => new self(
                preset: 'quarterly',
                start: $now->subDays(89)->startOfDay(),
                end: $now->endOfDay(),
                previousStart: $now->subDays(179)->startOfDay(),
                previousEnd: $now->subDays(90)->endOfDay(),
                bucketGranularity: 'week',
                timezone: $timezone,
            ),
            'yearly' => new self(
                preset: 'yearly',
                start: $now->subMonths(11)->startOfMonth(),
                end: $now->endOfDay(),
                previousStart: $now->subMonths(23)->startOfMonth(),
                previousEnd: $now->subMonths(12)->endOfMonth(),
                bucketGranularity: 'month',
                timezone: $timezone,
            ),
            default => new self(
                preset: 'monthly',
                start: $now->subDays(29)->startOfDay(),
                end: $now->endOfDay(),
                previousStart: $now->subDays(59)->startOfDay(),
                previousEnd: $now->subDays(30)->endOfDay(),
                bucketGranularity: 'day',
                timezone: $timezone,
            ),
        };
    }

    /**
     * Build ordered time-series buckets covering [start, end] in the period timezone.
     *
     * @return array<string, array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function buckets(): array
    {
        $buckets = [];
        $cursor = match ($this->bucketGranularity) {
            'month' => $this->start->startOfMonth(),
            'week' => $this->start->startOfWeek(),
            default => $this->start->startOfDay(),
        };

        $endBoundary = $this->end;
        $guard = 0;

        while ($cursor->lte($endBoundary) && $guard < 400) {
            $guard++;

            [$bucketEnd, $nextCursor, $key, $label] = match ($this->bucketGranularity) {
                'month' => [
                    $cursor->endOfMonth(),
                    $cursor->addMonth()->startOfMonth(),
                    $cursor->format('Y-m'),
                    $cursor->translatedFormat('M Y'),
                ],
                'week' => [
                    $cursor->endOfWeek(),
                    $cursor->addWeek()->startOfWeek(),
                    $cursor->format('o-\WW'),
                    $cursor->translatedFormat('d M'),
                ],
                default => [
                    $cursor->endOfDay(),
                    $cursor->addDay()->startOfDay(),
                    $cursor->format('Y-m-d'),
                    $cursor->translatedFormat('d M'),
                ],
            };

            $buckets[$key] = [
                'key' => $key,
                'label' => $label,
                'start' => $cursor,
                'end' => $bucketEnd,
            ];

            $cursor = $nextCursor;
        }

        return $buckets;
    }

    public function bucketKeyFor(CarbonImmutable $timestamp): string
    {
        $localized = $timestamp->setTimezone($this->timezone);

        return match ($this->bucketGranularity) {
            'month' => $localized->format('Y-m'),
            'week' => $localized->format('o-\WW'),
            default => $localized->format('Y-m-d'),
        };
    }

    public function cacheKeySuffix(): string
    {
        return sprintf(
            '%s:%s:%s:%s',
            $this->preset,
            $this->start->toIso8601String(),
            $this->end->toIso8601String(),
            $this->bucketGranularity,
        );
    }

    public function label(): string
    {
        return $this->start->translatedFormat('d M Y').' – '.$this->end->translatedFormat('d M Y');
    }

    public function comparisonLabel(): string
    {
        return $this->previousStart->translatedFormat('d M Y').' – '.$this->previousEnd->translatedFormat('d M Y');
    }

    public static function percentageDelta(int|float|null $current, int|float|null $previous): ?float
    {
        if ($current === null || $previous === null || (float) $previous === 0.0) {
            return null;
        }

        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    public static function pointDelta(int|float|null $current, int|float|null $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return round((float) $current - (float) $previous, 1);
    }

    /**
     * @return array{
     *     preset: string,
     *     start_date: string,
     *     end_date: string,
     *     previous_start_date: string,
     *     previous_end_date: string,
     *     label: string,
     *     comparison_label: string,
     *     bucket_granularity: string,
     *     timezone: string
     * }
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'start_date' => $this->start->toDateString(),
            'end_date' => $this->end->toDateString(),
            'previous_start_date' => $this->previousStart->toDateString(),
            'previous_end_date' => $this->previousEnd->toDateString(),
            'label' => $this->label(),
            'comparison_label' => $this->comparisonLabel(),
            'bucket_granularity' => $this->bucketGranularity,
            'timezone' => $this->timezone,
        ];
    }
}
