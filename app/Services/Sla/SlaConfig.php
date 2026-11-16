<?php

namespace App\Services\Sla;

use App\Models\SystemSetting;

class SlaConfig
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        'sla_normal_response_minutes' => '120',
        'sla_normal_resolution_minutes' => '1440',
        'sla_soon_response_minutes' => '60',
        'sla_soon_resolution_minutes' => '480',
        'sla_high_risk_response_minutes' => '30',
        'sla_high_risk_resolution_minutes' => '240',
        'sla_approaching_threshold_percent' => '80',
        'sla_business_hours_enabled' => '0',
        'sla_work_start_time' => '08:00',
        'sla_work_end_time' => '17:00',
        'sla_working_days' => '1,2,3,4,5,6',
        'sla_holidays' => '',
    ];

    /**
     * @return array{
     *     normal_response_minutes: int,
     *     normal_resolution_minutes: int,
     *     soon_response_minutes: int,
     *     soon_resolution_minutes: int,
     *     high_risk_response_minutes: int,
     *     high_risk_resolution_minutes: int,
     *     approaching_threshold_percent: int,
     *     business_hours_enabled: bool,
     *     work_start_time: string,
     *     work_end_time: string,
     *     working_days: list<int>,
     *     holidays: list<string>
     * }
     */
    public static function get(): array
    {
        $raw = SystemSetting::getMany(self::DEFAULTS);

        $workingDays = collect(explode(',', $raw['sla_working_days']))
            ->map(fn (string $val): int => (int) trim($val))
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->values()
            ->all();

        if ($workingDays === []) {
            $workingDays = [1, 2, 3, 4, 5, 6];
        }

        $holidays = collect(preg_split('/[\s,;]+/', $raw['sla_holidays']) ?: [])
            ->map(fn (string $date): string => trim($date))
            ->filter(fn (string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1)
            ->unique()
            ->values()
            ->all();

        $startTime = preg_match('/^\d{2}:\d{2}$/', $raw['sla_work_start_time']) === 1
            ? $raw['sla_work_start_time']
            : '08:00';
        $endTime = preg_match('/^\d{2}:\d{2}$/', $raw['sla_work_end_time']) === 1
            ? $raw['sla_work_end_time']
            : '17:00';

        return [
            'normal_response_minutes' => max(5, min(10080, (int) $raw['sla_normal_response_minutes'])),
            'normal_resolution_minutes' => max(15, min(43200, (int) $raw['sla_normal_resolution_minutes'])),
            'soon_response_minutes' => max(5, min(10080, (int) $raw['sla_soon_response_minutes'])),
            'soon_resolution_minutes' => max(15, min(43200, (int) $raw['sla_soon_resolution_minutes'])),
            'high_risk_response_minutes' => max(5, min(10080, (int) $raw['sla_high_risk_response_minutes'])),
            'high_risk_resolution_minutes' => max(15, min(43200, (int) $raw['sla_high_risk_resolution_minutes'])),
            'approaching_threshold_percent' => max(50, min(95, (int) $raw['sla_approaching_threshold_percent'])),
            'business_hours_enabled' => $raw['sla_business_hours_enabled'] === '1',
            'work_start_time' => $startTime,
            'work_end_time' => $endTime,
            'working_days' => array_values($workingDays),
            'holidays' => array_values($holidays),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array{response_minutes: int, resolution_minutes: int}
     */
    public static function targetsFor(string $urgency, string $riskLevel = 'unassessed', ?array $config = null): array
    {
        $resolved = $config ?? self::get();

        if ($riskLevel === 'high' || $urgency === 'urgent') {
            return [
                'response_minutes' => (int) $resolved['high_risk_response_minutes'],
                'resolution_minutes' => (int) $resolved['high_risk_resolution_minutes'],
            ];
        }

        if (in_array($urgency, ['soon', 'high'], true)) {
            return [
                'response_minutes' => (int) $resolved['soon_response_minutes'],
                'resolution_minutes' => (int) $resolved['soon_resolution_minutes'],
            ];
        }

        return [
            'response_minutes' => (int) $resolved['normal_response_minutes'],
            'resolution_minutes' => (int) $resolved['normal_resolution_minutes'],
        ];
    }
}
