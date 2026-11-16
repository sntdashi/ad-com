import { Form, Head, Link } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as ordersIndex } from '@/routes/orders';

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
    status: string;
    preferred_date: string | null;
    scheduled_at: string | null;
    estimated_total: number | null;
    technicians: string[];
    href: string;
    sla?: OrderSla;
};

type SlaMetrics = {
    on_track: number;
    approaching_deadline: number;
    breached: number;
    paused: number;
    completed: number;
    compliance_rate: number;
};

type Props = {
    orders: {
        data: Order[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    sla_metrics?: SlaMetrics;
    filters: {
        search: string;
        status: string;
        group: string;
        group_label: string | null;
    };
    statuses: Array<{ value: string; label: string }>;
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

export default function Orders({
    orders,
    sla_metrics,
    filters,
    statuses,
}: Props) {
    return (
        <>
            <Head title="Semua tiket layanan" />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl">
                    <header className="mb-6">
                        <p className="section-kicker text-cyan">
                            Meja operasional
                        </p>
                        <h1 className="text-foreground mt-2 text-2xl font-semibold">
                            Semua tiket layanan
                        </h1>
                        <p className="text-muted-foreground mt-2 text-sm">
                            Cari tiket, pelanggan, layanan, atau nama teknisi.
                            Buka tiket untuk melihat detail pengerjaan dan
                            transaksi.
                        </p>
                    </header>

                    {sla_metrics && (
                        <div className="mb-5 flex flex-wrap items-center gap-2">
                            <Link
                                href={ordersIndex()}
                                className={`rounded-full px-3.5 py-1.5 text-xs font-bold transition ${!filters.group ? 'bg-primary text-primary-foreground shadow-sm' : 'liquid-control text-muted-foreground hover:text-foreground'}`}
                            >
                                Semua tiket
                            </Link>
                            <Link
                                href={ordersIndex({
                                    query: { group: 'sla_breached' },
                                })}
                                className={`rounded-full px-3.5 py-1.5 text-xs font-bold transition ${filters.group === 'sla_breached' ? 'bg-red-600 text-white shadow-sm' : 'status-pill-danger'}`}
                            >
                                SLA Breached ({sla_metrics.breached})
                            </Link>
                            <Link
                                href={ordersIndex({
                                    query: { group: 'sla_approaching' },
                                })}
                                className={`rounded-full px-3.5 py-1.5 text-xs font-bold transition ${filters.group === 'sla_approaching' ? 'bg-amber-600 text-white shadow-sm' : 'status-pill-warning'}`}
                            >
                                Approaching Deadline (
                                {sla_metrics.approaching_deadline})
                            </Link>
                            <Link
                                href={ordersIndex({
                                    query: { group: 'sla_paused' },
                                })}
                                className={`rounded-full px-3.5 py-1.5 text-xs font-bold transition ${filters.group === 'sla_paused' ? 'bg-foreground text-background shadow-sm' : 'border-border bg-muted text-muted-foreground hover:text-foreground border'}`}
                            >
                                SLA Paused ({sla_metrics.paused})
                            </Link>
                            <span className="status-pill-success ml-auto rounded-full px-3 py-1 text-xs font-bold">
                                Kepatuhan SLA: {sla_metrics.compliance_rate}%
                            </span>
                        </div>
                    )}

                    <Form
                        {...ordersIndex.form()}
                        className="liquid-panel mb-5 grid gap-3 rounded-2xl p-4 sm:grid-cols-[minmax(0,1fr)_220px_auto] sm:items-end"
                    >
                        <label className="grid gap-1.5 text-xs font-semibold">
                            Cari tiket
                            <Input
                                name="search"
                                defaultValue={filters.search}
                                placeholder="Nomor tiket, pelanggan, layanan, teknisi"
                            />
                        </label>
                        <label className="grid gap-1.5 text-xs font-semibold">
                            Status
                            <select
                                name="status"
                                defaultValue={filters.status}
                                className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                            >
                                <option value="">Semua status</option>
                                {statuses.map((status) => (
                                    <option
                                        key={status.value}
                                        value={status.value}
                                    >
                                        {status.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {filters.group && (
                            <input
                                type="hidden"
                                name="group"
                                value={filters.group}
                            />
                        )}
                        <Button type="submit" className="gap-2">
                            <Search className="size-4" />
                            Cari
                        </Button>
                    </Form>

                    {filters.group_label && (
                        <p className="mb-4 flex items-center gap-3 text-sm">
                            <span className="bg-cyan/15 text-cyan rounded-full px-3 py-1 text-xs font-semibold">
                                Filter: {filters.group_label}
                            </span>
                            <Link
                                href={ordersIndex()}
                                className="text-muted-foreground text-xs hover:underline"
                            >
                                Hapus filter
                            </Link>
                        </p>
                    )}

                    <section className="liquid-panel overflow-hidden rounded-2xl">
                        <div className="border-border flex flex-wrap items-center justify-between gap-2 border-b px-5 py-4">
                            <h2 className="font-semibold">Antrean tiket</h2>
                            <p className="text-muted-foreground text-xs">
                                {orders.from ?? 0}–{orders.to ?? 0} dari{' '}
                                {orders.total} tiket
                            </p>
                        </div>
                        {orders.data.length ? (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[900px] text-left text-sm">
                                    <thead className="bg-muted/60 text-muted-foreground text-[10px] font-semibold tracking-wide uppercase">
                                        <tr>
                                            <th className="px-5 py-3">
                                                Tiket / pelanggan
                                            </th>
                                            <th className="px-5 py-3">
                                                Layanan
                                            </th>
                                            <th className="px-5 py-3">
                                                Teknisi
                                            </th>
                                            <th className="px-5 py-3">
                                                Tanggal
                                            </th>
                                            <th className="px-5 py-3">
                                                Estimasi
                                            </th>
                                            <th className="px-5 py-3">SLA</th>
                                            <th className="px-5 py-3">
                                                Status
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-ink/[0.07] divide-y">
                                        {orders.data.map((order) => (
                                            <tr
                                                key={order.id}
                                                className="hover:bg-warm/40"
                                            >
                                                <td className="px-5 py-4">
                                                    <Link
                                                        href={order.href}
                                                        className="text-cyan font-mono text-xs font-bold hover:underline"
                                                    >
                                                        {order.ticket_number}
                                                    </Link>
                                                    <p className="mt-1 font-semibold">
                                                        {order.customer_name}
                                                    </p>
                                                </td>
                                                <td className="px-5 py-4">
                                                    {order.service}
                                                </td>
                                                <td className="px-5 py-4">
                                                    {order.technicians.length
                                                        ? order.technicians.join(
                                                              ', ',
                                                          )
                                                        : 'Belum ditugaskan'}
                                                </td>
                                                <td className="px-5 py-4">
                                                    {order.scheduled_at ??
                                                        order.preferred_date ??
                                                        'Belum dijadwalkan'}
                                                </td>
                                                <td className="px-5 py-4">
                                                    {formatPrice(
                                                        order.estimated_total,
                                                    )}
                                                </td>
                                                <td className="px-5 py-4">
                                                    {order.sla ? (
                                                        <div>
                                                            <span
                                                                className={`inline-flex rounded-full px-2.5 py-0.5 text-[10px] font-bold ${
                                                                    order.sla
                                                                        .status ===
                                                                    'breached'
                                                                        ? 'status-pill-danger'
                                                                        : order
                                                                                .sla
                                                                                .status ===
                                                                            'approaching_deadline'
                                                                          ? 'status-pill-warning'
                                                                          : order
                                                                                  .sla
                                                                                  .status ===
                                                                              'paused'
                                                                            ? 'border-border bg-muted text-muted-foreground border'
                                                                            : 'status-pill-success'
                                                                }`}
                                                            >
                                                                {
                                                                    order.sla
                                                                        .label
                                                                }
                                                            </span>
                                                            {order.sla
                                                                .resolution_due_formatted && (
                                                                <p className="text-muted-foreground mt-1 text-[10px]">
                                                                    Target:{' '}
                                                                    {
                                                                        order
                                                                            .sla
                                                                            .resolution_due_formatted
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="px-5 py-4">
                                                    {statusLabels[
                                                        order.status
                                                    ] ?? order.status}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <p className="text-muted-foreground px-5 py-12 text-center text-sm">
                                Tidak ada tiket yang cocok dengan filter ini.
                            </p>
                        )}
                        {orders.links.length > 3 && (
                            <nav
                                aria-label="Halaman tiket"
                                className="border-border flex flex-wrap gap-2 border-t p-4"
                            >
                                {orders.links.slice(1, -1).map((link, index) =>
                                    link.url ? (
                                        <Link
                                            key={`${link.label}-${index}`}
                                            href={link.url}
                                            className={`rounded-lg px-3 py-2 text-xs font-semibold transition ${link.active ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground hover:bg-muted/80'}`}
                                        >
                                            {link.label}
                                        </Link>
                                    ) : null,
                                )}
                            </nav>
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
