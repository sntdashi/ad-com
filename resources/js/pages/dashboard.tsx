import { Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowUpRight,
    CalendarCheck2,
    CheckCircle2,
    Clock3,
    ClipboardList,
    Plus,
    Radio,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import { MotionNumber } from '@/components/motion/MotionNumber';
import {
    MotionStagger,
    MotionStaggerItem,
} from '@/components/motion/MotionStagger';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { create as createBooking } from '@/routes/booking';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as teamIndex } from '@/routes/team';
import { index as ordersIndex } from '@/routes/orders';

type Stats = {
    active: number;
    waiting: number;
    scheduled: number;
    completed: number;
};
type SlaMetrics = {
    on_track: number;
    approaching_deadline: number;
    breached: number;
    paused: number;
    completed: number;
    avg_first_response_minutes: number | null;
    avg_resolution_minutes: number | null;
    compliance_rate: number;
};
type OrderSla = {
    status:
        | 'on_track'
        | 'approaching_deadline'
        | 'breached'
        | 'paused'
        | 'completed';
    label: string;
    label_id: string;
    response_due_formatted: string | null;
    resolution_due_formatted: string | null;
    is_paused: boolean;
};
type Order = {
    id: string;
    ticket_number: string;
    customer_name: string;
    service: string;
    service_code: string;
    status: string;
    risk_level: string;
    preferred_date: string | null;
    scheduled_at: string | null;
    estimated_total: number | null;
    technician: string | null;
    technician_team: string[];
    created_at: string;
    href: string;
    sla?: OrderSla;
};
type Signal = {
    label: string;
    value: number | string;
    detail: string;
    href: string | null;
};

const statusLabels: Record<string, string> = {
    new: 'Baru masuk',
    needs_info: 'Perlu info',
    triaged: 'Selesai triase',
    waiting_estimate: 'Buat estimasi',
    waiting_approval: 'Tunggu persetujuan',
    scheduled: 'Terjadwal',
    on_the_way: 'Menuju lokasi',
    in_progress: 'Dikerjakan',
    verification: 'Verifikasi',
    waiting_payment: 'Tunggu bayar',
    completed: 'Selesai',
    warranty: 'Garansi',
    reopened: 'Dibuka kembali',
    closed: 'Ditutup',
    cancelled: 'Batal',
};

const roleCopy: Record<string, [string, string]> = {
    operator: [
        'Superadmin',
        'Kendali menyeluruh untuk akun, operasional, stok, keuangan, dan audit AD Com.',
    ],
    admin: [
        'Admin operasional',
        'Pantau antrean, penugasan, kapasitas tim, transaksi, dan layanan harian.',
    ],
    supervisor: [
        'Meja supervisor',
        'Triase pekerjaan, jaga keselamatan, dan verifikasi hasil.',
    ],
    technician: [
        'Teknisi',
        'Lihat tugas yang ditetapkan, hubungi pelanggan, dan catat progres lapangan.',
    ],
    warehouse: [
        'Gudang',
        'Pantau ketersediaan barang, kebutuhan restock, serta mutasi per tiket.',
    ],
    customer: ['Pesanan saya', 'Pantau semua tiket yang terhubung ke akunmu.'],
};

export default function Dashboard({
    role,
    stats,
    orders,
    signals,
    sla,
}: {
    role: string;
    stats: Stats;
    orders: Order[];
    signals: Signal[];
    sla?: SlaMetrics | null;
}) {
    const [eyebrow, description] = roleCopy[role] ?? roleCopy.customer;
    const canBrowseQueue = ['operator', 'admin', 'supervisor'].includes(role);
    const cards = [
        [
            'Aktif',
            stats.active,
            Radio,
            'bg-primary/12 text-[var(--brand-text)]',
            'active',
        ],
        [
            'Butuh aksi',
            stats.waiting,
            AlertCircle,
            'bg-amber-500/14 text-amber-700 dark:text-amber-300',
            'waiting',
        ],
        [
            'Terjadwal',
            stats.scheduled,
            CalendarCheck2,
            'bg-sky-500/14 text-sky-700 dark:text-sky-300',
            'scheduled',
        ],
        [
            'Tuntas',
            stats.completed,
            CheckCircle2,
            'bg-emerald-500/14 text-emerald-700 dark:text-emerald-300',
            'done',
        ],
    ] as const;
    const primaryAction =
        role === 'warehouse' || role === 'technician' || role === 'supervisor'
            ? { label: 'Buka inventaris', href: inventoryIndex() }
            : role === 'operator' || role === 'admin'
              ? { label: 'Kelola tim', href: teamIndex() }
              : { label: 'Buat pesanan', href: createBooking() };

    return (
        <>
            <Head title="Dashboard Operasional" />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl">
                    <header className="liquid-panel-dark flex flex-col justify-between gap-5 rounded-[28px] p-6 sm:flex-row sm:items-end sm:p-8">
                        <div>
                            <p className="section-kicker text-white/60">
                                {eyebrow}
                            </p>
                            <h1 className="mt-2 text-3xl font-semibold tracking-[-0.035em] text-white sm:text-4xl">
                                {role === 'warehouse'
                                    ? 'Ringkasan gudang'
                                    : role === 'technician'
                                      ? 'Tugas saya'
                                      : role === 'customer'
                                        ? 'Pesanan saya'
                                        : 'Ringkasan operasional'}
                            </h1>
                            <p className="mt-3 max-w-2xl text-sm leading-6 text-white/68">
                                {description}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {role === 'operator' && (
                                <Button
                                    asChild
                                    variant="secondary"
                                    className="h-11 rounded-full px-5"
                                >
                                    <Link href="/settings/assistant">
                                        Kelola ADI
                                    </Link>
                                </Button>
                            )}
                            <Button asChild className="h-11 rounded-full px-5">
                                <Link href={primaryAction.href}>
                                    <Plus /> {primaryAction.label}
                                </Link>
                            </Button>
                        </div>
                    </header>

                    <section className="mt-8" aria-label="Ringkasan pekerjaan">
                        <MotionStagger className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                            {cards.map(([label, value, Icon, tone, group]) => {
                                const Wrapper = canBrowseQueue
                                    ? Link
                                    : 'article';
                                const wrapperProps = canBrowseQueue
                                    ? {
                                          href: ordersIndex({
                                              query: { group },
                                          }),
                                      }
                                    : {};

                                return (
                                    <MotionStaggerItem key={label}>
                                        <Wrapper
                                            {...wrapperProps}
                                            className={`liquid-panel rounded-2xl p-4 sm:p-5 ${canBrowseQueue ? 'hover:border-brand/40 block transition-all duration-200 hover:-translate-y-0.5' : ''}`}
                                        >
                                            <div className="flex items-start justify-between gap-4">
                                                <div>
                                                    <p className="text-muted-foreground text-xs font-bold">
                                                        {label}
                                                    </p>
                                                    <p className="text-foreground mt-3 text-3xl font-semibold tracking-[-0.04em]">
                                                        <MotionNumber
                                                            value={value}
                                                        />
                                                    </p>
                                                </div>
                                                <span
                                                    className={`grid size-10 place-items-center rounded-xl ${tone}`}
                                                >
                                                    <Icon className="size-5" />
                                                </span>
                                            </div>
                                        </Wrapper>
                                    </MotionStaggerItem>
                                );
                            })}
                        </MotionStagger>
                    </section>

                    {sla && (
                        <section
                            className="liquid-panel mt-5 rounded-2xl p-5 sm:p-6"
                            aria-label="Indikator SLA"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="section-kicker text-cyan">
                                        Service Level Agreement
                                    </p>
                                    <h2 className="text-foreground mt-1 text-lg font-semibold tracking-[-0.02em]">
                                        Kepatuhan & target waktu layanan
                                    </h2>
                                </div>
                                <span className="status-pill-success rounded-full px-3 py-1 text-xs font-bold">
                                    Kepatuhan SLA: {sla.compliance_rate}%
                                </span>
                            </div>
                            <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                                <div className="liquid-control rounded-xl p-3.5">
                                    <p className="text-muted-foreground text-[11px] font-bold">
                                        On Track
                                    </p>
                                    <p className="mt-1.5 text-2xl font-semibold text-emerald-600 dark:text-emerald-400">
                                        {sla.on_track}
                                    </p>
                                </div>
                                <div className="liquid-control rounded-xl p-3.5">
                                    <p className="text-muted-foreground text-[11px] font-bold">
                                        Approaching Deadline
                                    </p>
                                    <p className="mt-1.5 text-2xl font-semibold text-amber-600 dark:text-amber-400">
                                        {sla.approaching_deadline}
                                    </p>
                                </div>
                                <div className="liquid-control rounded-xl p-3.5">
                                    <p className="text-muted-foreground text-[11px] font-bold">
                                        Breached
                                    </p>
                                    <p className="mt-1.5 text-2xl font-semibold text-red-600 dark:text-rose-400">
                                        {sla.breached}
                                    </p>
                                </div>
                                <div className="liquid-control rounded-xl p-3.5">
                                    <p className="text-muted-foreground text-[11px] font-bold">
                                        Paused
                                    </p>
                                    <p className="text-foreground mt-1.5 text-2xl font-semibold">
                                        {sla.paused}
                                    </p>
                                </div>
                                <div className="liquid-control rounded-xl p-3.5">
                                    <p className="text-muted-foreground text-[11px] font-bold">
                                        Rata-rata respon
                                    </p>
                                    <p className="text-foreground mt-1.5 text-lg font-semibold">
                                        {sla.avg_first_response_minutes === null
                                            ? '—'
                                            : `${sla.avg_first_response_minutes} mnt`}
                                    </p>
                                </div>
                                <div className="liquid-control rounded-xl p-3.5">
                                    <p className="text-muted-foreground text-[11px] font-bold">
                                        Rata-rata selesai
                                    </p>
                                    <p className="text-foreground mt-1.5 text-lg font-semibold">
                                        {sla.avg_resolution_minutes === null
                                            ? '—'
                                            : `${sla.avg_resolution_minutes} mnt`}
                                    </p>
                                </div>
                            </div>
                        </section>
                    )}

                    {signals.length > 0 && (
                        <section
                            className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                            aria-label="Sinyal operasional"
                        >
                            {signals.map((signal) => (
                                <Link
                                    key={signal.label}
                                    href={signal.href ?? dashboard()}
                                    className="border-border bg-card group hover:border-brand/40 rounded-xl border p-5 shadow-[0_2px_8px_rgba(41,39,43,0.04)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_10px_24px_rgba(41,39,43,0.07)]"
                                >
                                    <div className="flex items-center justify-between gap-4">
                                        <p className="text-muted-foreground text-xs font-bold">
                                            {signal.label}
                                        </p>
                                        <ArrowUpRight
                                            className="text-muted-foreground group-hover:text-brand-deep size-4 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:-translate-y-0.5"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <p className="text-foreground mt-3 text-3xl font-semibold tracking-[-0.04em]">
                                        {signal.value}
                                    </p>
                                    <p className="text-muted-foreground mt-2 text-xs">
                                        {signal.detail}
                                    </p>
                                </Link>
                            ))}
                        </section>
                    )}

                    <section className="border-border bg-card mt-5 rounded-2xl border shadow-[0_4px_18px_rgba(41,39,43,0.05)]">
                        <div className="border-border flex items-center justify-between gap-4 border-b p-5 sm:p-6">
                            <div>
                                <p className="section-kicker text-cyan">
                                    Antrean layanan
                                </p>
                                <h2 className="text-foreground mt-1 text-xl font-semibold tracking-[-0.03em]">
                                    Tiket terbaru
                                </h2>
                            </div>
                            <div className="flex items-center gap-3">
                                {(role === 'admin' || role === 'operator') && (
                                    <Link
                                        href={ordersIndex()}
                                        className="text-cyan text-xs font-semibold hover:underline"
                                    >
                                        Semua tiket
                                    </Link>
                                )}
                                <p className="text-muted-foreground hidden items-center gap-2 text-xs sm:flex">
                                    <ShieldCheck className="text-cyan size-4" />{' '}
                                    Akses sesuai peran
                                </p>
                            </div>
                        </div>

                        {orders.length === 0 ? (
                            <div className="grid min-h-72 place-items-center p-8 text-center">
                                <div>
                                    <span className="bg-cyan/10 text-cyan mx-auto grid size-14 place-items-center rounded-2xl">
                                        <ClipboardList />
                                    </span>
                                    <p className="text-foreground mt-4 font-semibold">
                                        Belum ada tiket
                                    </p>
                                    <p className="text-muted-foreground mt-2 text-sm">
                                        Pesanan yang relevan dengan akun ini
                                        akan muncul di sini.
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <>
                                <div className="hidden overflow-x-auto md:block">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-ink/[0.025] text-muted-foreground text-[10px] font-semibold tracking-[0.13em] uppercase">
                                            <tr>
                                                <th className="px-6 py-4">
                                                    Tiket & layanan
                                                </th>
                                                <th className="px-6 py-4">
                                                    Pelanggan
                                                </th>
                                                <th className="px-6 py-4">
                                                    Status
                                                </th>
                                                <th className="px-6 py-4">
                                                    Tim teknisi
                                                </th>
                                                <th className="px-6 py-4">
                                                    Jadwal & estimasi
                                                </th>
                                                <th className="px-6 py-4">
                                                    <span className="sr-only">
                                                        Aksi
                                                    </span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-ink/[0.07] divide-y">
                                            {orders.map((order) => (
                                                <tr
                                                    key={order.id}
                                                    className="group hover:bg-warm/55"
                                                >
                                                    <td className="px-6 py-4">
                                                        <p className="text-cyan font-mono text-[11px] font-bold">
                                                            {
                                                                order.ticket_number
                                                            }
                                                        </p>
                                                        <p className="text-foreground mt-1 font-semibold">
                                                            {order.service}
                                                        </p>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <p className="text-foreground font-bold">
                                                            {
                                                                order.customer_name
                                                            }
                                                        </p>
                                                        <p className="text-ink/38 mt-1 text-xs">
                                                            {order.created_at}
                                                        </p>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="flex flex-wrap items-center gap-1.5">
                                                            <StatusBadge
                                                                status={
                                                                    order.status
                                                                }
                                                            />
                                                            {order.sla && (
                                                                <SlaBadge
                                                                    sla={
                                                                        order.sla
                                                                    }
                                                                />
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <span className="text-ink/55 flex items-start gap-2 text-xs leading-5">
                                                            <UserRound className="text-cyan mt-0.5 size-4 shrink-0" />
                                                            {order.technician_team.join(
                                                                ', ',
                                                            ) ||
                                                                'Belum ditugaskan'}
                                                        </span>
                                                    </td>
                                                    <td className="text-ink/55 px-6 py-4 text-xs">
                                                        <p>
                                                            {order.scheduled_at ??
                                                                order.preferred_date ??
                                                                'Jadwal belum ditentukan'}
                                                        </p>
                                                        <p className="text-foreground mt-1 font-semibold">
                                                            {formatPrice(
                                                                order.estimated_total,
                                                            )}
                                                        </p>
                                                    </td>
                                                    <td className="px-6 py-4 text-right">
                                                        <Link
                                                            href={order.href}
                                                            className="liquid-control text-foreground group-hover:border-primary group-hover:bg-primary inline-grid size-9 place-items-center rounded-full transition group-hover:text-white"
                                                        >
                                                            <ArrowUpRight className="size-4" />
                                                        </Link>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="divide-ink/[0.07] divide-y md:hidden">
                                    {orders.map((order) => (
                                        <Link
                                            key={order.id}
                                            href={order.href}
                                            className="hover:bg-warm/55 block p-5"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-cyan font-mono text-[10px] font-bold">
                                                        {order.ticket_number}
                                                    </p>
                                                    <h3 className="text-foreground mt-1 font-semibold">
                                                        {order.service}
                                                    </h3>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {order.customer_name}
                                                    </p>
                                                </div>
                                                <ArrowUpRight className="text-ink/30 size-5" />
                                            </div>
                                            <p className="text-muted-foreground mt-3 flex items-start gap-2 text-[11px] leading-5">
                                                <UserRound className="text-cyan mt-0.5 size-3.5 shrink-0" />
                                                {order.technician_team.join(
                                                    ', ',
                                                ) || 'Tim belum ditugaskan'}
                                            </p>
                                            <div className="mt-4 flex items-center justify-between gap-3">
                                                <StatusBadge
                                                    status={order.status}
                                                />
                                                <span className="text-ink/38 flex items-center gap-1 text-[11px]">
                                                    <Clock3 className="size-3.5" />
                                                    {order.created_at}
                                                </span>
                                            </div>
                                            <p className="text-muted-foreground mt-3 text-xs">
                                                {order.scheduled_at ??
                                                    order.preferred_date ??
                                                    'Jadwal belum ditentukan'}{' '}
                                                ·{' '}
                                                {formatPrice(
                                                    order.estimated_total,
                                                )}
                                            </p>
                                        </Link>
                                    ))}
                                </div>
                            </>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

function formatPrice(amount: number | null): string {
    return amount === null
        ? 'Belum ada estimasi'
        : new Intl.NumberFormat('id-ID', {
              style: 'currency',
              currency: 'IDR',
              maximumFractionDigits: 0,
          }).format(amount);
}

function StatusBadge({ status }: { status: string }) {
    const active = ['on_the_way', 'in_progress', 'verification'].includes(
        status,
    );
    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ${active ? 'bg-primary/14 text-[var(--brand-text)]' : status === 'completed' ? 'status-pill-success' : status === 'cancelled' ? 'status-pill-danger' : 'text-muted-foreground bg-[var(--surface-2)]'}`}
        >
            {statusLabels[status] ?? status}
        </span>
    );
}

function SlaBadge({ sla }: { sla: OrderSla }) {
    const tone =
        sla.status === 'breached'
            ? 'status-pill-danger'
            : sla.status === 'approaching_deadline'
              ? 'status-pill-warning'
              : sla.status === 'paused'
                ? 'border border-[var(--border)] bg-[var(--surface-2)] text-muted-foreground'
                : 'status-pill-success';

    return (
        <span
            className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold ${tone}`}
        >
            {sla.label}
        </span>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] };
