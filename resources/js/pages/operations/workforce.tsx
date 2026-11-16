import { Head, router, setLayoutProps, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Clock,
    Filter,
    PauseCircle,
    RotateCcw,
    UserCheck,
    Wrench,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { update as updateAvailability } from '@/routes/technicians/availability';
import { index as workforceIndex } from '@/routes/workforce';
import type { BreadcrumbItem } from '@/types';

type AvailabilityStatus = 'available' | 'busy' | 'unavailable' | 'on_leave';
type WorkloadLevel = 'idle' | 'light' | 'moderate' | 'high';

type TechnicianWorkload = {
    id: number;
    name: string;
    username: string | null;
    avatar_url: string | null;
    specialty: string | null;
    is_active: boolean;
    availability_status: AvailabilityStatus;
    availability_label: string;
    availability_label_id: string;
    availability_note: string | null;
    active_assignments_count: number;
    pending_assignments_count: number;
    completed_jobs_count: number;
    current_workload: number;
    workload_level: WorkloadLevel;
    sla_risk_assignments_count: number;
    avg_resolution_minutes: number | null;
    avg_work_session_minutes: number | null;
    sla_compliance_rate: number;
    reopened_jobs_count: number;
};

type AvailabilityOption = {
    value: AvailabilityStatus;
    label: string;
    label_id: string;
};

type AvailabilityLog = {
    id: number;
    technician: string | null;
    actor: string;
    previous_status: string | null;
    new_status: string;
    reason: string | null;
    created_at: string | null;
};

type Filters = {
    availability: string;
    workload: string;
    specialty: string;
    assignment: string;
};

const AVAILABILITY_BADGES: Record<AvailabilityStatus, string> = {
    available: 'status-pill-success',
    busy: 'status-pill-warning',
    unavailable: 'border border-border bg-muted text-muted-foreground',
    on_leave: 'status-pill-danger',
};

const WORKLOAD_BADGES: Record<
    WorkloadLevel,
    { label: string; className: string }
> = {
    idle: { label: 'Longgar', className: 'status-pill-success' },
    light: { label: 'Ringan', className: 'status-pill-info' },
    moderate: { label: 'Sedang', className: 'status-pill-warning' },
    high: { label: 'Padat', className: 'status-pill-danger' },
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Workforce & dispatch', href: workforceIndex().url },
];

export default function WorkforcePage({
    role,
    currentUserId,
    canManageAll = false,
    technicians = [],
    summary = { total: 0, available: 0, busy: 0, unavailable: 0, sla_risk: 0 },
    filters = { availability: '', workload: '', specialty: '', assignment: '' },
    availabilityOptions = [],
    recentLogs = [],
}: {
    role: string;
    currentUserId: number;
    canManageAll?: boolean;
    technicians?: TechnicianWorkload[];
    summary?: {
        total: number;
        available: number;
        busy: number;
        unavailable: number;
        sla_risk: number;
    };
    filters?: Filters;
    availabilityOptions?: AvailabilityOption[];
    recentLogs?: AvailabilityLog[];
}) {
    setLayoutProps({ breadcrumbs });

    const [filterState, setFilterState] = useState<Filters>(filters);

    const applyFilters = (next: Filters) => {
        setFilterState(next);
        const query = Object.fromEntries(
            Object.entries(next).filter(([, value]) => value.trim() !== ''),
        );
        router.get(
            workforceIndex.url({ query }),
            {},
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        const empty: Filters = {
            availability: '',
            workload: '',
            specialty: '',
            assignment: '',
        };
        applyFilters(empty);
    };

    return (
        <>
            <Head title="Workforce & Dispatch" />

            <div className="space-y-6 p-4 sm:p-6">
                <section className="liquid-panel rounded-[30px] p-6">
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p className="section-kicker text-cyan">
                                Workforce &amp; dispatch management
                            </p>
                            <h1 className="text-ink mt-1 text-2xl font-black tracking-tight">
                                {role === 'technician'
                                    ? 'Status Ketersediaan & Beban Kerja Saya'
                                    : 'Kapasitas, Ketersediaan & Beban Kerja Teknisi'}
                            </h1>
                            <p className="text-muted-foreground mt-1 text-sm">
                                Pantau status ketersediaan real-time, beban
                                tugas aktif, risiko SLA, dan riwayat perubahan
                                status teknisi.
                            </p>
                        </div>
                    </div>

                    <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <SummaryCard
                            label="Total Teknisi"
                            value={summary.total}
                            icon={Wrench}
                            tone="neutral"
                        />
                        <SummaryCard
                            label="Tersedia"
                            value={summary.available}
                            icon={UserCheck}
                            tone="emerald"
                        />
                        <SummaryCard
                            label="Sedang Sibuk"
                            value={summary.busy}
                            icon={Activity}
                            tone="amber"
                        />
                        <SummaryCard
                            label="Tidak Tersedia / Cuti"
                            value={summary.unavailable}
                            icon={PauseCircle}
                            tone="slate"
                        />
                        <SummaryCard
                            label="Tiket Risiko SLA"
                            value={summary.sla_risk}
                            icon={AlertTriangle}
                            tone={summary.sla_risk > 0 ? 'rose' : 'emerald'}
                        />
                    </div>
                </section>

                <section className="liquid-panel rounded-[26px] p-5">
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <Filter className="text-muted-foreground h-4 w-4" />
                            <h2 className="text-foreground text-sm font-bold">
                                Filter kapasitas &amp; penugasan
                            </h2>
                        </div>
                        {(filterState.availability ||
                            filterState.workload ||
                            filterState.specialty ||
                            filterState.assignment) && (
                            <button
                                type="button"
                                onClick={resetFilters}
                                className="border-border text-foreground hover:bg-muted inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition"
                            >
                                <RotateCcw className="h-3.5 w-3.5" />
                                Reset filter
                            </button>
                        )}
                    </div>

                    <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label className="block">
                            <span className="text-muted-foreground text-xs font-semibold">
                                Status Ketersediaan
                            </span>
                            <select
                                value={filterState.availability}
                                onChange={(e) =>
                                    applyFilters({
                                        ...filterState,
                                        availability: e.target.value,
                                    })
                                }
                                className="liquid-input mt-1 w-full rounded-xl px-3 py-2 text-sm"
                            >
                                <option value="">Semua status</option>
                                {availabilityOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label_id} ({option.label})
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="block">
                            <span className="text-muted-foreground text-xs font-semibold">
                                Tingkat Beban Kerja
                            </span>
                            <select
                                value={filterState.workload}
                                onChange={(e) =>
                                    applyFilters({
                                        ...filterState,
                                        workload: e.target.value,
                                    })
                                }
                                className="liquid-input mt-1 w-full rounded-xl px-3 py-2 text-sm"
                            >
                                <option value="">Semua beban</option>
                                <option value="idle">Longgar (0 tugas)</option>
                                <option value="light">Ringan (1 tugas)</option>
                                <option value="moderate">
                                    Sedang (2–3 tugas)
                                </option>
                                <option value="high">Padat (4+ tugas)</option>
                            </select>
                        </label>

                        <label className="block">
                            <span className="text-muted-foreground text-xs font-semibold">
                                Kondisi Penugasan
                            </span>
                            <select
                                value={filterState.assignment}
                                onChange={(e) =>
                                    applyFilters({
                                        ...filterState,
                                        assignment: e.target.value,
                                    })
                                }
                                className="liquid-input mt-1 w-full rounded-xl px-3 py-2 text-sm"
                            >
                                <option value="">Semua kondisi</option>
                                <option value="active">Sedang bertugas</option>
                                <option value="pending">
                                    Ada antrean terjadwal
                                </option>
                                <option value="sla_risk">Ada risiko SLA</option>
                                <option value="none">Tanpa beban aktif</option>
                            </select>
                        </label>

                        <label className="block">
                            <span className="text-muted-foreground text-xs font-semibold">
                                Spesialisasi
                            </span>
                            <input
                                type="text"
                                value={filterState.specialty}
                                onChange={(e) =>
                                    applyFilters({
                                        ...filterState,
                                        specialty: e.target.value,
                                    })
                                }
                                placeholder="Cari jaringan, CCTV, laptop..."
                                className="liquid-input mt-1 w-full rounded-xl px-3 py-2 text-sm"
                            />
                        </label>
                    </div>
                </section>

                <section className="grid gap-4 lg:grid-cols-2">
                    {technicians.length === 0 ? (
                        <div className="liquid-panel text-muted-foreground col-span-full rounded-[26px] p-8 text-center text-sm">
                            Tidak ada teknisi yang sesuai dengan filter saat
                            ini.
                        </div>
                    ) : (
                        technicians.map((technician) => (
                            <TechnicianWorkloadCard
                                key={technician.id}
                                technician={technician}
                                canUpdate={
                                    canManageAll ||
                                    technician.id === currentUserId
                                }
                                availabilityOptions={availabilityOptions}
                            />
                        ))
                    )}
                </section>

                <section className="liquid-panel rounded-[26px] p-5">
                    <div className="flex items-center gap-2">
                        <Clock className="text-cyan h-4 w-4" />
                        <h2 className="text-ink font-black">
                            Riwayat Perubahan Ketersediaan
                        </h2>
                    </div>
                    {recentLogs.length === 0 ? (
                        <p className="text-muted-foreground mt-3 text-sm">
                            Belum ada perubahan status ketersediaan yang
                            tercatat.
                        </p>
                    ) : (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-border text-muted-foreground border-b">
                                        <th className="py-2 pr-3 font-semibold">
                                            Teknisi
                                        </th>
                                        <th className="py-2 pr-3 font-semibold">
                                            Transisi
                                        </th>
                                        <th className="py-2 pr-3 font-semibold">
                                            Catatan
                                        </th>
                                        <th className="py-2 pr-3 font-semibold">
                                            Oleh
                                        </th>
                                        <th className="py-2 font-semibold">
                                            Waktu
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {recentLogs.map((log) => (
                                        <tr
                                            key={log.id}
                                            className="text-foreground/85"
                                        >
                                            <td className="text-foreground py-2.5 pr-3 font-semibold">
                                                {log.technician ?? '-'}
                                            </td>
                                            <td className="py-2.5 pr-3">
                                                <span className="text-muted-foreground font-mono text-[11px]">
                                                    {log.previous_status ?? '-'}
                                                </span>{' '}
                                                &rarr;{' '}
                                                <span className="text-foreground font-mono text-[11px] font-semibold">
                                                    {log.new_status}
                                                </span>
                                            </td>
                                            <td className="text-muted-foreground py-2.5 pr-3">
                                                {log.reason ?? '-'}
                                            </td>
                                            <td className="py-2.5 pr-3">
                                                {log.actor}
                                            </td>
                                            <td className="text-muted-foreground py-2.5 whitespace-nowrap">
                                                {log.created_at ?? '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function SummaryCard({
    label,
    value,
    icon: Icon,
    tone,
}: {
    label: string;
    value: number;
    icon: typeof Wrench;
    tone: 'neutral' | 'emerald' | 'amber' | 'slate' | 'rose';
}) {
    const toneStyles = {
        neutral: 'bg-muted text-foreground',
        emerald:
            'bg-emerald-500/12 text-emerald-700 dark:bg-emerald-400/14 dark:text-emerald-300',
        amber: 'bg-amber-500/12 text-amber-800 dark:bg-amber-400/14 dark:text-amber-300',
        slate: 'bg-muted text-muted-foreground',
        rose: 'bg-rose-500/12 text-rose-700 dark:bg-rose-400/14 dark:text-rose-300',
    };

    return (
        <div className="liquid-control rounded-2xl p-4">
            <div className="flex items-center justify-between gap-2">
                <span className="text-muted-foreground text-xs font-semibold">
                    {label}
                </span>
                <span className={`rounded-xl p-1.5 ${toneStyles[tone]}`}>
                    <Icon className="h-4 w-4" />
                </span>
            </div>
            <p className="text-foreground mt-2 text-2xl font-black">{value}</p>
        </div>
    );
}

function TechnicianWorkloadCard({
    technician,
    canUpdate,
    availabilityOptions,
}: {
    technician: TechnicianWorkload;
    canUpdate: boolean;
    availabilityOptions: AvailabilityOption[];
}) {
    const form = useForm({
        availability_status: technician.availability_status,
        availability_note: technician.availability_note ?? '',
    });

    const workloadBadge =
        WORKLOAD_BADGES[technician.workload_level] ?? WORKLOAD_BADGES.idle;
    const availabilityClass =
        AVAILABILITY_BADGES[technician.availability_status] ??
        AVAILABILITY_BADGES.available;

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(updateAvailability.url(technician.id), {
            preserveScroll: true,
        });
    };

    return (
        <article className="liquid-panel rounded-[26px] p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    {technician.avatar_url ? (
                        <img
                            src={technician.avatar_url}
                            alt={technician.name}
                            className="h-11 w-11 rounded-2xl object-cover"
                        />
                    ) : (
                        <div className="bg-primary text-primary-foreground flex h-11 w-11 items-center justify-center rounded-2xl text-sm font-black shadow-sm">
                            {technician.name.slice(0, 2).toUpperCase()}
                        </div>
                    )}
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-foreground font-black">
                                {technician.name}
                            </h3>
                            {!technician.is_active && (
                                <span className="border-border bg-muted text-muted-foreground rounded-full border px-2 py-0.5 text-[10px] font-bold">
                                    Nonaktif
                                </span>
                            )}
                        </div>
                        <p className="text-muted-foreground text-xs">
                            {technician.specialty || 'Teknisi Lapangan Umum'}
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <span
                        className={`rounded-full px-2.5 py-1 text-xs font-bold ${availabilityClass}`}
                    >
                        {technician.availability_label_id}
                    </span>
                    <span
                        className={`rounded-full px-2.5 py-1 text-xs font-bold ${workloadBadge.className}`}
                    >
                        Beban {workloadBadge.label} (
                        {technician.current_workload})
                    </span>
                </div>
            </div>

            {technician.availability_note && (
                <p className="text-muted-foreground mt-2 text-xs">
                    Catatan status:{' '}
                    <span className="font-medium">
                        {technician.availability_note}
                    </span>
                </p>
            )}

            <div className="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                <MetricBox
                    label="Tugas Aktif"
                    value={technician.active_assignments_count}
                />
                <MetricBox
                    label="Antrean Terjadwal"
                    value={technician.pending_assignments_count}
                />
                <MetricBox
                    label="Selesai"
                    value={technician.completed_jobs_count}
                />
                <MetricBox
                    label="Risiko SLA"
                    value={technician.sla_risk_assignments_count}
                    alert={technician.sla_risk_assignments_count > 0}
                />
            </div>

            <div className="mt-2.5 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                <MetricBox
                    label="Kepatuhan SLA"
                    value={`${technician.sla_compliance_rate}%`}
                />
                <MetricBox
                    label="Rata Resolusi"
                    value={
                        technician.avg_resolution_minutes !== null
                            ? `${technician.avg_resolution_minutes} mnt`
                            : '-'
                    }
                />
                <MetricBox
                    label="Rata Sesi Kerja"
                    value={
                        technician.avg_work_session_minutes !== null
                            ? `${technician.avg_work_session_minutes} mnt`
                            : '-'
                    }
                />
                <MetricBox
                    label="Reopened"
                    value={technician.reopened_jobs_count}
                />
            </div>

            {canUpdate && (
                <form
                    onSubmit={handleSubmit}
                    className="border-border mt-4 space-y-2.5 border-t pt-4"
                >
                    <div className="grid gap-2 sm:grid-cols-[180px_1fr_auto]">
                        <select
                            value={form.data.availability_status}
                            onChange={(e) =>
                                form.setData(
                                    'availability_status',
                                    e.target.value as AvailabilityStatus,
                                )
                            }
                            aria-label={`Ketersediaan ${technician.name}`}
                            className="liquid-input rounded-xl px-3 py-2 text-xs font-semibold"
                        >
                            {availabilityOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label_id} ({option.label})
                                </option>
                            ))}
                        </select>
                        <input
                            type="text"
                            value={form.data.availability_note}
                            onChange={(e) =>
                                form.setData(
                                    'availability_note',
                                    e.target.value,
                                )
                            }
                            placeholder="Alasan / catatan singkat (opsional)..."
                            className="liquid-input rounded-xl px-3 py-2 text-xs"
                        />
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="bg-primary text-primary-foreground inline-flex items-center justify-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold transition hover:opacity-90 disabled:opacity-60"
                        >
                            <CheckCircle2 className="h-3.5 w-3.5" />
                            Simpan Status
                        </button>
                    </div>
                    {form.errors.availability_status && (
                        <p className="text-destructive text-xs font-medium">
                            {form.errors.availability_status}
                        </p>
                    )}
                </form>
            )}
        </article>
    );
}

function MetricBox({
    label,
    value,
    alert = false,
}: {
    label: string;
    value: string | number;
    alert?: boolean;
}) {
    return (
        <div className="surface-tile rounded-xl px-3 py-2">
            <p className="text-muted-foreground text-[11px] font-medium">
                {label}
            </p>
            <p
                className={`mt-0.5 text-sm font-black ${
                    alert ? 'text-[var(--brand-text)]' : 'text-foreground'
                }`}
            >
                {value}
            </p>
        </div>
    );
}
