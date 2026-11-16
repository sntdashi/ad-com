import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowRight, ArrowUpRight, Minus } from 'lucide-react';
import { useId, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';

const idrFormatter = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

const numberFormatter = new Intl.NumberFormat('id-ID');

export function formatIdr(amount: number | null | undefined): string {
    if (amount === null || amount === undefined || Number.isNaN(amount)) {
        return '—';
    }

    return idrFormatter.format(amount);
}

export function formatIdrCompact(amount: number): string {
    const abs = Math.abs(amount);
    const sign = amount < 0 ? '-' : '';
    if (abs >= 1_000_000_000) {
        return `${sign}Rp ${(abs / 1_000_000_000).toFixed(1)} M`;
    }
    if (abs >= 1_000_000) {
        return `${sign}Rp ${(abs / 1_000_000).toFixed(1)} jt`;
    }
    if (abs >= 1_000) {
        return `${sign}Rp ${(abs / 1_000).toFixed(0)} rb`;
    }

    return `${sign}Rp ${abs}`;
}

export function formatNumber(
    value: number | null | undefined,
    suffix = '',
): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    return `${numberFormatter.format(value)}${suffix}`;
}

interface KpiStatCardProps {
    title: string;
    value: string;
    delta?: number | null;
    deltaUnit?: string;
    basisLabel?: string;
    subtitle?: string;
    href?: string;
    inverseTrend?: boolean;
    tone?: 'default' | 'positive' | 'warning' | 'danger';
}

export function KpiStatCard({
    title,
    value,
    delta = null,
    deltaUnit = 'pct',
    basisLabel,
    subtitle,
    href,
    inverseTrend = false,
    tone = 'default',
}: KpiStatCardProps) {
    const hasNumericDelta =
        delta !== null && delta !== undefined && deltaUnit !== 'snapshot';
    const isPositiveRaw = hasNumericDelta && delta > 0;
    const isNegativeRaw = hasNumericDelta && delta < 0;
    const isGood = inverseTrend ? isNegativeRaw : isPositiveRaw;
    const isBad = inverseTrend ? isPositiveRaw : isNegativeRaw;

    const formattedDelta = hasNumericDelta
        ? `${delta > 0 ? '+' : ''}${delta.toFixed(1)}${deltaUnit === 'pp' ? ' poin' : '%'}`
        : null;

    return (
        <Card
            className={cn(
                'border-border/70 bg-card/95 hover:border-border shadow-xs transition-colors',
                tone === 'warning' && 'border-amber-500/35 bg-amber-500/[0.03]',
                tone === 'danger' && 'border-red-500/40 bg-red-500/[0.04]',
                tone === 'positive' &&
                    'border-emerald-500/35 bg-emerald-500/[0.03]',
            )}
        >
            <CardHeader className="flex flex-row items-start justify-between gap-2 pb-2">
                <div className="space-y-1">
                    <CardTitle className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                        {title}
                    </CardTitle>
                </div>
                {basisLabel && (
                    <Badge
                        variant="outline"
                        className="shrink-0 text-[10px] font-medium"
                    >
                        {basisLabel}
                    </Badge>
                )}
            </CardHeader>
            <CardContent className="space-y-2">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <div className="text-2xl font-semibold tracking-tight tabular-nums">
                        {value}
                    </div>
                    {formattedDelta !== null && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium tabular-nums',
                                isGood &&
                                    'bg-emerald-500/12 text-emerald-700 dark:text-emerald-300',
                                isBad &&
                                    'bg-red-500/12 text-red-700 dark:text-red-300',
                                !isGood &&
                                    !isBad &&
                                    'bg-muted text-muted-foreground',
                            )}
                        >
                            {isPositiveRaw ? (
                                <ArrowUpRight className="size-3.5" />
                            ) : isNegativeRaw ? (
                                <ArrowDownRight className="size-3.5" />
                            ) : (
                                <Minus className="size-3.5" />
                            )}
                            {formattedDelta}
                        </span>
                    )}
                </div>
                {(subtitle || href) && (
                    <div className="text-muted-foreground flex items-center justify-between gap-2 text-xs">
                        <span className="line-clamp-1">{subtitle ?? ''}</span>
                        {href && (
                            <Link
                                href={href}
                                className="text-primary inline-flex shrink-0 items-center gap-1 font-medium hover:underline"
                            >
                                Detail
                                <ArrowRight className="size-3" />
                            </Link>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export interface TimeSeriesConfig {
    key: string;
    label: string;
    color: string;
}

interface InteractiveTimeSeriesChartProps {
    title: string;
    description?: string;
    data: Array<Record<string, string | number>>;
    xKey?: string;
    series: TimeSeriesConfig[];
    valueType?: 'currency' | 'count';
    emptyMessage?: string;
}

export function InteractiveTimeSeriesChart({
    title,
    description,
    data,
    xKey = 'label',
    series,
    valueType = 'count',
    emptyMessage = 'Belum ada data tren pada periode ini.',
}: InteractiveTimeSeriesChartProps) {
    const gradientPrefix = useId().replace(/:/g, '');
    const [hoveredIndex, setHoveredIndex] = useState<number | null>(null);

    const hasNonZero = data.some((point) =>
        series.some((s) => Number(point[s.key] ?? 0) !== 0),
    );

    const width = 760;
    const height = 240;
    const padLeft = 58;
    const padRight = 18;
    const padTop = 18;
    const padBottom = 34;
    const plotWidth = width - padLeft - padRight;
    const plotHeight = height - padTop - padBottom;

    const allValues: number[] = [];
    for (const point of data) {
        for (const s of series) {
            allValues.push(Number(point[s.key] ?? 0));
        }
    }

    const rawMin = allValues.length > 0 ? Math.min(0, ...allValues) : 0;
    const rawMax = allValues.length > 0 ? Math.max(1, ...allValues) : 1;
    const range = Math.max(1, rawMax - rawMin);

    const xForIndex = (index: number): number => {
        if (data.length <= 1) {
            return padLeft + plotWidth / 2;
        }

        return padLeft + (index / (data.length - 1)) * plotWidth;
    };

    const yForValue = (val: number): number =>
        padTop + plotHeight - ((val - rawMin) / range) * plotHeight;

    const formatTick = (val: number): string =>
        valueType === 'currency'
            ? formatIdrCompact(val)
            : numberFormatter.format(Math.round(val));

    const activeIndex =
        hoveredIndex !== null && hoveredIndex < data.length
            ? hoveredIndex
            : null;
    const activePoint = activeIndex !== null ? data[activeIndex] : null;
    const labelStep = data.length > 10 ? Math.ceil(data.length / 6) : 1;

    return (
        <Card className="border-border/70 bg-card/95 shadow-xs">
            <CardHeader className="space-y-2 pb-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle className="text-base">{title}</CardTitle>
                        {description && (
                            <CardDescription>{description}</CardDescription>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        {series.map((s) => (
                            <span
                                key={s.key}
                                className="text-muted-foreground inline-flex items-center gap-1.5 text-xs"
                            >
                                <span
                                    className="size-2.5 rounded-full"
                                    style={{ backgroundColor: s.color }}
                                />
                                {s.label}
                            </span>
                        ))}
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                {data.length === 0 || !hasNonZero ? (
                    <div className="border-border/70 bg-muted/20 text-muted-foreground flex h-52 items-center justify-center rounded-xl border border-dashed px-4 text-center text-sm">
                        {emptyMessage}
                    </div>
                ) : (
                    <div className="relative">
                        {activePoint && activeIndex !== null && (
                            <div className="border-border/70 bg-background/85 mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border px-3 py-1.5 text-xs shadow-xs backdrop-blur-md">
                                <span className="text-foreground font-semibold">
                                    {String(activePoint[xKey] ?? '')}
                                </span>
                                {series.map((s) => {
                                    const val = Number(activePoint[s.key] ?? 0);
                                    return (
                                        <span
                                            key={s.key}
                                            className="inline-flex items-center gap-1.5 tabular-nums"
                                        >
                                            <span
                                                className="size-2 rounded-full"
                                                style={{
                                                    backgroundColor: s.color,
                                                }}
                                            />
                                            <span className="text-muted-foreground">
                                                {s.label}:
                                            </span>
                                            <span className="text-foreground font-medium">
                                                {valueType === 'currency'
                                                    ? formatIdr(val)
                                                    : numberFormatter.format(
                                                          val,
                                                      )}
                                            </span>
                                        </span>
                                    );
                                })}
                            </div>
                        )}

                        <svg
                            viewBox={`0 0 ${width} ${height}`}
                            className="h-60 w-full overflow-visible select-none"
                            role="img"
                            aria-label={title}
                            onMouseLeave={() => setHoveredIndex(null)}
                        >
                            <defs>
                                {series.map((s) => (
                                    <linearGradient
                                        key={s.key}
                                        id={`${gradientPrefix}-${s.key}`}
                                        x1="0"
                                        x2="0"
                                        y1="0"
                                        y2="1"
                                    >
                                        <stop
                                            offset="0%"
                                            stopColor={s.color}
                                            stopOpacity="0.22"
                                        />
                                        <stop
                                            offset="100%"
                                            stopColor={s.color}
                                            stopOpacity="0.01"
                                        />
                                    </linearGradient>
                                ))}
                            </defs>

                            {[0, 0.25, 0.5, 0.75, 1].map((ratio) => {
                                const tickVal = rawMin + range * ratio;
                                const y = yForValue(tickVal);
                                return (
                                    <g key={ratio}>
                                        <line
                                            x1={padLeft}
                                            x2={width - padRight}
                                            y1={y}
                                            y2={y}
                                            stroke="currentColor"
                                            strokeOpacity={
                                                ratio === 0 ? 0.2 : 0.08
                                            }
                                            strokeDasharray={
                                                ratio === 0 ? undefined : '4 4'
                                            }
                                        />
                                        <text
                                            x={padLeft - 8}
                                            y={y + 4}
                                            textAnchor="end"
                                            className="fill-muted-foreground text-[10px] tabular-nums"
                                        >
                                            {formatTick(tickVal)}
                                        </text>
                                    </g>
                                );
                            })}

                            {series.map((s, seriesIndex) => {
                                const coords = data.map((point, idx) => ({
                                    x: xForIndex(idx),
                                    y: yForValue(Number(point[s.key] ?? 0)),
                                }));

                                const linePath = coords
                                    .map(
                                        (c, idx) =>
                                            `${idx === 0 ? 'M' : 'L'} ${c.x.toFixed(1)} ${c.y.toFixed(1)}`,
                                    )
                                    .join(' ');

                                const baselineY = yForValue(
                                    Math.max(0, rawMin),
                                );
                                const areaPath = `${linePath} L ${coords[coords.length - 1].x.toFixed(1)} ${baselineY.toFixed(1)} L ${coords[0].x.toFixed(1)} ${baselineY.toFixed(1)} Z`;

                                return (
                                    <g key={s.key}>
                                        {seriesIndex === 0 && (
                                            <path
                                                d={areaPath}
                                                fill={`url(#${gradientPrefix}-${s.key})`}
                                            />
                                        )}
                                        <path
                                            d={linePath}
                                            fill="none"
                                            stroke={s.color}
                                            strokeWidth={2.25}
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                        />
                                        {coords.map((c, idx) => (
                                            <circle
                                                key={`${s.key}-${idx}`}
                                                cx={c.x}
                                                cy={c.y}
                                                r={
                                                    activeIndex === idx
                                                        ? 4.5
                                                        : data.length <= 16
                                                          ? 2.8
                                                          : 0
                                                }
                                                fill={s.color}
                                                stroke="var(--background)"
                                                strokeWidth={1.5}
                                            />
                                        ))}
                                    </g>
                                );
                            })}

                            {activeIndex !== null && (
                                <line
                                    x1={xForIndex(activeIndex)}
                                    x2={xForIndex(activeIndex)}
                                    y1={padTop}
                                    y2={height - padBottom}
                                    stroke="currentColor"
                                    strokeOpacity={0.25}
                                    strokeDasharray="3 3"
                                />
                            )}

                            {data.map((point, idx) => {
                                const x = xForIndex(idx);
                                const showLabel =
                                    idx % labelStep === 0 ||
                                    idx === data.length - 1;
                                const colWidth =
                                    data.length > 1
                                        ? plotWidth / data.length
                                        : plotWidth;

                                return (
                                    <g key={`hover-${idx}`}>
                                        {showLabel && (
                                            <text
                                                x={x}
                                                y={height - 10}
                                                textAnchor="middle"
                                                className="fill-muted-foreground text-[10px]"
                                            >
                                                {String(point[xKey] ?? '')}
                                            </text>
                                        )}
                                        <rect
                                            x={x - colWidth / 2}
                                            y={padTop}
                                            width={Math.max(12, colWidth)}
                                            height={plotHeight}
                                            fill="transparent"
                                            className="cursor-crosshair"
                                            onMouseEnter={() =>
                                                setHoveredIndex(idx)
                                            }
                                            onFocus={() => setHoveredIndex(idx)}
                                            tabIndex={0}
                                            aria-label={`${String(point[xKey] ?? '')}`}
                                        />
                                    </g>
                                );
                            })}
                        </svg>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

interface ComparativeBarItem {
    label: string;
    value: number;
    secondaryLabel?: string;
    color?: string;
}

interface ComparativeBarChartProps {
    title: string;
    description?: string;
    items: ComparativeBarItem[];
    valueType?: 'currency' | 'count' | 'percent';
    emptyMessage?: string;
}

export function ComparativeBarChart({
    title,
    description,
    items,
    valueType = 'count',
    emptyMessage = 'Belum ada data pembanding pada periode ini.',
}: ComparativeBarChartProps) {
    const maxAbs = Math.max(1, ...items.map((item) => Math.abs(item.value)));

    const formatVal = (val: number): string => {
        if (valueType === 'currency') {
            return formatIdr(val);
        }
        if (valueType === 'percent') {
            return `${val.toFixed(1)}%`;
        }

        return numberFormatter.format(val);
    };

    return (
        <Card className="border-border/70 bg-card/95 shadow-xs">
            <CardHeader className="pb-3">
                <CardTitle className="text-base">{title}</CardTitle>
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <div className="border-border/70 bg-muted/20 text-muted-foreground flex h-40 items-center justify-center rounded-xl border border-dashed px-4 text-center text-sm">
                        {emptyMessage}
                    </div>
                ) : (
                    <div className="space-y-3">
                        {items.map((item, idx) => {
                            const widthPct = Math.min(
                                100,
                                Math.round(
                                    (Math.abs(item.value) / maxAbs) * 100,
                                ),
                            );
                            const isNegative = item.value < 0;
                            const fill =
                                item.color ??
                                (isNegative ? '#dc2626' : '#dc2626');

                            return (
                                <div
                                    key={`${item.label}-${idx}`}
                                    className="space-y-1"
                                >
                                    <div className="flex items-baseline justify-between gap-2 text-xs">
                                        <span className="text-foreground truncate font-medium">
                                            {item.label}
                                        </span>
                                        <span className="shrink-0 font-semibold tabular-nums">
                                            {formatVal(item.value)}
                                        </span>
                                    </div>
                                    <div className="bg-muted/70 h-2.5 w-full overflow-hidden rounded-full">
                                        <div
                                            className="h-full rounded-full transition-all duration-300"
                                            style={{
                                                width: `${Math.max(item.value === 0 ? 0 : 3, widthPct)}%`,
                                                backgroundColor: fill,
                                            }}
                                        />
                                    </div>
                                    {item.secondaryLabel && (
                                        <p className="text-muted-foreground text-[11px]">
                                            {item.secondaryLabel}
                                        </p>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

interface DistributionSlice {
    label: string;
    value: number;
    color: string;
}

interface DistributionDonutChartProps {
    title: string;
    description?: string;
    slices: DistributionSlice[];
    centerLabel?: string;
    emptyMessage?: string;
}

export function DistributionDonutChart({
    title,
    description,
    slices,
    centerLabel = 'Total',
    emptyMessage = 'Belum ada distribusi data.',
}: DistributionDonutChartProps) {
    const total = slices.reduce((sum, slice) => sum + slice.value, 0);
    const radius = 44;
    const circumference = 2 * Math.PI * radius;
    let cumulative = 0;

    return (
        <Card className="border-border/70 bg-card/95 shadow-xs">
            <CardHeader className="pb-3">
                <CardTitle className="text-base">{title}</CardTitle>
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
            </CardHeader>
            <CardContent>
                {total === 0 ? (
                    <div className="border-border/70 bg-muted/20 text-muted-foreground flex h-40 items-center justify-center rounded-xl border border-dashed px-4 text-center text-sm">
                        {emptyMessage}
                    </div>
                ) : (
                    <div className="flex flex-col items-center gap-5 sm:flex-row">
                        <div className="relative size-32 shrink-0">
                            <svg
                                viewBox="0 0 120 120"
                                className="size-full -rotate-90"
                                role="img"
                                aria-label={title}
                            >
                                <circle
                                    cx="60"
                                    cy="60"
                                    r={radius}
                                    fill="none"
                                    stroke="currentColor"
                                    strokeOpacity={0.1}
                                    strokeWidth="14"
                                />
                                {slices.map((slice) => {
                                    if (slice.value <= 0) {
                                        return null;
                                    }
                                    const fraction = slice.value / total;
                                    const dash = fraction * circumference;
                                    const offset = -cumulative * circumference;
                                    cumulative += fraction;

                                    return (
                                        <circle
                                            key={slice.label}
                                            cx="60"
                                            cy="60"
                                            r={radius}
                                            fill="none"
                                            stroke={slice.color}
                                            strokeWidth="14"
                                            strokeDasharray={`${dash} ${circumference}`}
                                            strokeDashoffset={offset}
                                        />
                                    );
                                })}
                            </svg>
                            <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
                                <span className="text-xl font-semibold tabular-nums">
                                    {numberFormatter.format(total)}
                                </span>
                                <span className="text-muted-foreground text-[10px] tracking-wide uppercase">
                                    {centerLabel}
                                </span>
                            </div>
                        </div>

                        <div className="grid w-full gap-2">
                            {slices.map((slice) => {
                                const pct =
                                    total > 0
                                        ? ((slice.value / total) * 100).toFixed(
                                              1,
                                          )
                                        : '0.0';
                                return (
                                    <div
                                        key={slice.label}
                                        className="flex items-center justify-between gap-2 text-xs"
                                    >
                                        <span className="inline-flex items-center gap-2 truncate">
                                            <span
                                                className="size-2.5 shrink-0 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        slice.color,
                                                }}
                                            />
                                            <span className="text-foreground truncate">
                                                {slice.label}
                                            </span>
                                        </span>
                                        <span className="text-muted-foreground shrink-0 font-medium tabular-nums">
                                            {numberFormatter.format(
                                                slice.value,
                                            )}{' '}
                                            ({pct}%)
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
