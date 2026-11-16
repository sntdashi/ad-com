import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    Cpu,
    Laptop,
    Plus,
    Search,
    ShieldAlert,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import {
    index as devicesIndex,
    show as devicesShow,
    store as storeDevice,
} from '@/routes/devices';

type DeviceRow = {
    id: string;
    device_code: string | null;
    name: string;
    category: string;
    brand: string | null;
    model: string | null;
    serial_masked: string | null;
    location: string | null;
    status: string;
    notes: string | null;
    customer_name: string | null;
    orders_count: number;
    warranties_count: number;
    active_warranties_count: number;
    expiring_warranties_count: number;
    claims_count: number;
    last_serviced_at: string | null;
    registered_at: string | null;
    href: string;
};

type Pagination<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

type CustomerOption = {
    id: number;
    name: string;
    email: string;
};

type PortalOrder = {
    id: string;
    ticket_number: string;
    service: string;
    status: string;
    device_name: string | null;
    created_at?: string;
    completed_at?: string;
    href: string;
};

type PortalWarranty = {
    id: number;
    warranty_number: string;
    scope_type: 'service' | 'spare_part';
    item_label: string;
    device_name: string | null;
    device_href: string | null;
    ticket_number: string;
    order_href: string;
    status: 'pending' | 'active' | 'expired' | 'void';
    is_expiring: boolean;
    remaining_days: number | null;
    duration_days: number;
    starts_at: string | null;
    expires_at: string | null;
    coverage_description: string | null;
};

type PortalClaim = {
    id: number;
    claim_number: string;
    warranty_number: string;
    item_label: string;
    status: 'submitted' | 'under_review' | 'approved' | 'rejected' | 'resolved';
    issue_description: string;
    review_notes: string | null;
    resolution_notes: string | null;
    original_ticket: string;
    claim_ticket: string | null;
    claim_order_href: string | null;
    submitted_at: string;
};

const deviceStatusLabel: Record<string, string> = {
    active: 'Aktif',
    in_service: 'Sedang diservis',
    retired: 'Nonaktif',
    transferred: 'Dialihkan',
};

const warrantyStatusBadge: Record<string, string> = {
    active: 'status-pill-success',
    pending: 'status-pill-info',
    expired: 'border border-border bg-muted text-muted-foreground',
    void: 'status-pill-danger',
};

const claimStatusBadge: Record<string, string> = {
    submitted: 'status-pill-warning',
    under_review: 'status-pill-info',
    approved: 'status-pill-success',
    rejected: 'status-pill-danger',
    resolved: 'bg-primary text-primary-foreground',
};

export default function DevicesIndex({
    isCustomer,
    canCreate,
    canSelectCustomer,
    devices,
    filters,
    stats,
    customers,
    customerPortal,
}: {
    isCustomer: boolean;
    canCreate: boolean;
    canSelectCustomer: boolean;
    devices: Pagination<DeviceRow>;
    filters: { search: string; status: string; warranty: string };
    stats: {
        total_devices: number;
        active_warranties: number;
        expiring_warranties: number;
        active_claims: number;
    };
    customers: CustomerOption[];
    customerPortal: {
        active_orders: PortalOrder[];
        completed_orders: PortalOrder[];
        warranties: PortalWarranty[];
        claims: PortalClaim[];
    } | null;
}) {
    const [showCreateForm, setShowCreateForm] = useState(false);
    const [searchText, setSearchText] = useState(filters.search);

    const createForm = useForm({
        customer_id: customers[0]?.id ? String(customers[0].id) : '',
        name: '',
        category: 'Laptop / PC',
        brand: '',
        model: '',
        serial_number: '',
        location: '',
        status: 'active',
        notes: '',
    });

    function applyFilter(next: Partial<typeof filters>) {
        const merged = { ...filters, ...next };
        router.get(
            devicesIndex.url({
                query: Object.fromEntries(
                    Object.entries(merged).filter(
                        ([, v]) => v !== '' && v !== 'all',
                    ),
                ),
            }),
            {},
            { preserveState: true, replace: true },
        );
    }

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        applyFilter({ search: searchText.trim() });
    }

    function submitCreate(event: FormEvent) {
        event.preventDefault();
        createForm.post(storeDevice.url(), {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset(
                    'name',
                    'brand',
                    'model',
                    'serial_number',
                    'location',
                    'notes',
                );
                setShowCreateForm(false);
            },
        });
    }

    return (
        <>
            <Head
                title={
                    isCustomer
                        ? 'Perangkat & Garansi Saya'
                        : 'Registri Perangkat & Riwayat Layanan'
                }
            />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl space-y-6">
                    <header className="liquid-panel flex flex-wrap items-center justify-between gap-4 rounded-[28px] p-6">
                        <div>
                            <p className="section-kicker text-crimson">
                                {isCustomer
                                    ? 'Portal Perangkat Pelanggan'
                                    : 'Customer Service History'}
                            </p>
                            <h1 className="text-ink mt-1 text-2xl font-black tracking-tight sm:text-3xl">
                                {isCustomer
                                    ? 'Perangkat, Riwayat Servis & Garansi'
                                    : 'Registri Perangkat & Riwayat Pelanggan'}
                            </h1>
                            <p className="text-ink/55 mt-1 text-sm">
                                {isCustomer
                                    ? 'Pantau seluruh perangkat terdaftar, linimasa perbaikan, status garansi, dan klaim layanan.'
                                    : 'Rekam jejak aset pelanggan, keterkaitan tiket servis, serta status cakupan garansi aktif.'}
                            </p>
                        </div>
                        {canCreate && (
                            <Button
                                type="button"
                                onClick={() =>
                                    setShowCreateForm((prev) => !prev)
                                }
                                className="rounded-2xl"
                            >
                                <Plus className="mr-2 size-4" />
                                {showCreateForm
                                    ? 'Tutup formulir'
                                    : 'Daftarkan perangkat'}
                            </Button>
                        )}
                    </header>

                    <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-ink/50 text-xs font-bold uppercase">
                                {isCustomer
                                    ? 'Perangkat saya'
                                    : 'Total perangkat'}
                            </p>
                            <p className="text-ink mt-2 text-3xl font-black">
                                {stats.total_devices}
                            </p>
                        </div>
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-ink/50 text-xs font-bold uppercase">
                                Garansi aktif
                            </p>
                            <p className="mt-2 text-3xl font-black text-emerald-700">
                                {stats.active_warranties}
                            </p>
                        </div>
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-ink/50 text-xs font-bold uppercase">
                                Segera berakhir (&le;7 hari)
                            </p>
                            <p className="mt-2 text-3xl font-black text-amber-700">
                                {stats.expiring_warranties}
                            </p>
                        </div>
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-ink/50 text-xs font-bold uppercase">
                                Klaim aktif
                            </p>
                            <p className="text-crimson mt-2 text-3xl font-black">
                                {stats.active_claims}
                            </p>
                        </div>
                    </section>

                    {showCreateForm && canCreate && (
                        <form
                            onSubmit={submitCreate}
                            className="liquid-panel space-y-4 rounded-[26px] p-6"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <h2 className="text-ink text-lg font-black">
                                    Daftarkan perangkat baru
                                </h2>
                                <span className="text-ink/45 text-xs">
                                    Nomor seri identik pada akun yang sama akan
                                    ditolak otomatis.
                                </span>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {canSelectCustomer && (
                                    <label className="space-y-1.5 text-xs font-bold">
                                        <span className="text-ink/70">
                                            Pemilik pelanggan
                                        </span>
                                        <select
                                            className="liquid-input h-10 w-full rounded-xl px-3 text-sm font-medium"
                                            value={createForm.data.customer_id}
                                            onChange={(e) =>
                                                createForm.setData(
                                                    'customer_id',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        >
                                            <option value="">
                                                Pilih pelanggan…
                                            </option>
                                            {customers.map((customer) => (
                                                <option
                                                    key={customer.id}
                                                    value={customer.id}
                                                >
                                                    {customer.name} (
                                                    {customer.email})
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={
                                                createForm.errors.customer_id
                                            }
                                        />
                                    </label>
                                )}

                                <label className="space-y-1.5 text-xs font-bold">
                                    <span className="text-ink/70">
                                        Nama perangkat
                                    </span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm font-medium"
                                        placeholder="Laptop Lab TKJ 01 / Router Utama"
                                        value={createForm.data.name}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <InputError
                                        message={createForm.errors.name}
                                    />
                                </label>

                                <label className="space-y-1.5 text-xs font-bold">
                                    <span className="text-ink/70">
                                        Kategori
                                    </span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm font-medium"
                                        placeholder="Laptop / PC / Jaringan / CCTV"
                                        value={createForm.data.category}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'category',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <InputError
                                        message={createForm.errors.category}
                                    />
                                </label>

                                <label className="space-y-1.5 text-xs font-bold">
                                    <span className="text-ink/70">
                                        Merek / pabrikan
                                    </span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm font-medium"
                                        placeholder="ASUS / MikroTik / Hikvision"
                                        value={createForm.data.brand}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'brand',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={createForm.errors.brand}
                                    />
                                </label>

                                <label className="space-y-1.5 text-xs font-bold">
                                    <span className="text-ink/70">Model</span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm font-medium"
                                        placeholder="ROG Strix G16 / RB750Gr3"
                                        value={createForm.data.model}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'model',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={createForm.errors.model}
                                    />
                                </label>

                                <label className="space-y-1.5 text-xs font-bold">
                                    <span className="text-ink/70">
                                        Nomor seri / ID unit
                                    </span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm font-medium"
                                        placeholder="SN-2026-00918"
                                        value={createForm.data.serial_number}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'serial_number',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            createForm.errors.serial_number
                                        }
                                    />
                                </label>

                                <label className="space-y-1.5 text-xs font-bold">
                                    <span className="text-ink/70">
                                        Lokasi pemasangan / unit
                                    </span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm font-medium"
                                        placeholder="Ruang Server Lantai 2"
                                        value={createForm.data.location}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'location',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={createForm.errors.location}
                                    />
                                </label>

                                <label className="space-y-1.5 text-xs font-bold sm:col-span-2">
                                    <span className="text-ink/70">Catatan</span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm font-medium"
                                        placeholder="Spesifikasi tambahan atau catatan unit"
                                        value={createForm.data.notes}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={createForm.errors.notes}
                                    />
                                </label>
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setShowCreateForm(false)}
                                >
                                    Batal
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={createForm.processing}
                                >
                                    Simpan perangkat
                                </Button>
                            </div>
                        </form>
                    )}

                    <section className="liquid-panel space-y-5 rounded-[26px] p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <form
                                onSubmit={submitSearch}
                                className="flex min-w-64 flex-1 items-center gap-2"
                            >
                                <div className="relative flex-1">
                                    <Search className="text-ink/40 pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                    <input
                                        type="search"
                                        value={searchText}
                                        onChange={(e) =>
                                            setSearchText(e.target.value)
                                        }
                                        placeholder="Cari nama perangkat, kode unit, kategori, merek, serial…"
                                        className="liquid-input h-10 w-full rounded-xl pr-3 pl-9 text-sm"
                                    />
                                </div>
                                <Button type="submit" variant="outline">
                                    Cari
                                </Button>
                            </form>

                            <div className="flex flex-wrap items-center gap-2">
                                <select
                                    aria-label="Filter status perangkat"
                                    className="liquid-input h-10 rounded-xl px-3 text-xs font-bold"
                                    value={filters.status}
                                    onChange={(e) =>
                                        applyFilter({ status: e.target.value })
                                    }
                                >
                                    <option value="all">Semua status</option>
                                    <option value="active">Aktif</option>
                                    <option value="in_service">
                                        Sedang diservis
                                    </option>
                                    <option value="retired">Nonaktif</option>
                                    <option value="transferred">
                                        Dialihkan
                                    </option>
                                </select>

                                <select
                                    aria-label="Filter status garansi"
                                    className="liquid-input h-10 rounded-xl px-3 text-xs font-bold"
                                    value={filters.warranty}
                                    onChange={(e) =>
                                        applyFilter({
                                            warranty: e.target.value,
                                        })
                                    }
                                >
                                    <option value="all">Semua garansi</option>
                                    <option value="active">
                                        Garansi aktif
                                    </option>
                                    <option value="expiring">
                                        Segera berakhir
                                    </option>
                                    <option value="expired">Kedaluwarsa</option>
                                </select>
                            </div>
                        </div>

                        {devices.data.length === 0 ? (
                            <div className="liquid-control rounded-2xl p-8 text-center">
                                <Laptop className="text-muted-foreground/40 mx-auto size-10" />
                                <p className="text-foreground mt-3 font-bold">
                                    Belum ada perangkat terdaftar
                                </p>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Daftarkan perangkat untuk melacak riwayat
                                    perbaikan dan masa garansi.
                                </p>
                            </div>
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2">
                                {devices.data.map((device) => (
                                    <article
                                        key={device.id}
                                        className="surface-tile flex flex-col justify-between gap-4 rounded-2xl p-5"
                                    >
                                        <div className="space-y-2">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="bg-muted text-muted-foreground rounded-lg px-2 py-0.5 font-mono text-[11px] font-bold">
                                                            {device.device_code ??
                                                                'DEV'}
                                                        </span>
                                                        <span className="border-border bg-muted text-muted-foreground rounded-full border px-2.5 py-0.5 text-[11px] font-bold">
                                                            {deviceStatusLabel[
                                                                device.status
                                                            ] ?? device.status}
                                                        </span>
                                                        {device.expiring_warranties_count >
                                                            0 && (
                                                            <span className="status-pill-warning inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-bold">
                                                                <AlertTriangle className="size-3" />
                                                                Garansi segera
                                                                berakhir
                                                            </span>
                                                        )}
                                                    </div>
                                                    <h3 className="text-foreground mt-2 text-lg font-black">
                                                        {device.name}
                                                    </h3>
                                                    <p className="text-muted-foreground text-xs">
                                                        {device.category}
                                                        {device.brand
                                                            ? ` · ${device.brand}`
                                                            : ''}
                                                        {device.model
                                                            ? ` ${device.model}`
                                                            : ''}
                                                        {device.serial_masked
                                                            ? ` · SN ${device.serial_masked}`
                                                            : ''}
                                                    </p>
                                                </div>
                                                <Cpu className="text-crimson/75 size-5 shrink-0" />
                                            </div>

                                            {!isCustomer &&
                                                device.customer_name && (
                                                    <p className="text-muted-foreground text-xs font-semibold">
                                                        Pemilik:{' '}
                                                        {device.customer_name}
                                                    </p>
                                                )}

                                            {device.location && (
                                                <p className="text-muted-foreground text-xs">
                                                    Lokasi: {device.location}
                                                </p>
                                            )}
                                        </div>

                                        <div className="border-border flex flex-wrap items-center justify-between gap-3 border-t pt-3 text-xs">
                                            <div className="flex flex-wrap gap-3 text-xs font-bold">
                                                <span className="text-muted-foreground">
                                                    {device.orders_count} servis
                                                </span>
                                                <span className="text-emerald-700 dark:text-emerald-400">
                                                    {
                                                        device.active_warranties_count
                                                    }{' '}
                                                    garansi aktif
                                                </span>
                                                {device.claims_count > 0 && (
                                                    <span className="text-crimson">
                                                        {device.claims_count}{' '}
                                                        klaim
                                                    </span>
                                                )}
                                            </div>
                                            <Link
                                                href={devicesShow.url(
                                                    device.id,
                                                )}
                                                className="text-crimson inline-flex items-center gap-1 font-black hover:underline"
                                            >
                                                Detail &amp; Riwayat
                                                <ArrowUpRight className="size-3.5" />
                                            </Link>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}

                        {devices.links.length > 3 && (
                            <div className="flex flex-wrap items-center justify-end gap-1.5 pt-2">
                                {devices.links.map((link, idx) => (
                                    <Button
                                        key={`${link.label}-${idx}`}
                                        type="button"
                                        size="sm"
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url &&
                                            router.visit(link.url, {
                                                preserveState: true,
                                            })
                                        }
                                    >
                                        {link.label
                                            .replace('&laquo;', '«')
                                            .replace('&raquo;', '»')}
                                    </Button>
                                ))}
                            </div>
                        )}
                    </section>

                    {isCustomer && customerPortal && (
                        <div className="grid gap-6 lg:grid-cols-2">
                            <section className="liquid-panel space-y-4 rounded-[26px] p-6">
                                <div className="flex items-center justify-between gap-2">
                                    <div>
                                        <p className="section-kicker text-crimson">
                                            Tiket Servis
                                        </p>
                                        <h2 className="text-ink text-lg font-black">
                                            Pesanan Aktif &amp; Riwayat Selesai
                                        </h2>
                                    </div>
                                    <Wrench className="text-crimson/70 size-5" />
                                </div>

                                {customerPortal.active_orders.length > 0 && (
                                    <div className="space-y-2">
                                        <p className="text-ink/55 text-xs font-bold uppercase">
                                            Pesanan aktif (
                                            {
                                                customerPortal.active_orders
                                                    .length
                                            }
                                            )
                                        </p>
                                        {customerPortal.active_orders.map(
                                            (order) => (
                                                <Link
                                                    key={order.id}
                                                    href={order.href}
                                                    className="surface-tile hover:bg-muted/70 flex items-center justify-between rounded-xl p-3 text-xs transition"
                                                >
                                                    <div>
                                                        <p className="text-foreground font-black">
                                                            {
                                                                order.ticket_number
                                                            }{' '}
                                                            · {order.service}
                                                        </p>
                                                        <p className="text-muted-foreground">
                                                            {order.device_name ??
                                                                'Belum tertaut perangkat'}{' '}
                                                            · {order.created_at}
                                                        </p>
                                                    </div>
                                                    <span className="bg-crimson/10 text-crimson rounded-full px-2.5 py-0.5 font-bold uppercase">
                                                        {order.status}
                                                    </span>
                                                </Link>
                                            ),
                                        )}
                                    </div>
                                )}

                                <div className="space-y-2">
                                    <p className="text-muted-foreground text-xs font-bold uppercase">
                                        Riwayat servis selesai (
                                        {customerPortal.completed_orders.length}
                                        )
                                    </p>
                                    {customerPortal.completed_orders.length ===
                                    0 ? (
                                        <p className="text-muted-foreground text-xs">
                                            Belum ada pesanan selesai.
                                        </p>
                                    ) : (
                                        customerPortal.completed_orders
                                            .slice(0, 6)
                                            .map((order) => (
                                                <Link
                                                    key={order.id}
                                                    href={order.href}
                                                    className="surface-tile hover:bg-muted/70 flex items-center justify-between rounded-xl p-3 text-xs transition"
                                                >
                                                    <div>
                                                        <p className="text-foreground font-bold">
                                                            {
                                                                order.ticket_number
                                                            }{' '}
                                                            · {order.service}
                                                        </p>
                                                        <p className="text-muted-foreground">
                                                            {order.device_name ??
                                                                'Perangkat umum'}{' '}
                                                            ·{' '}
                                                            {order.completed_at}
                                                        </p>
                                                    </div>
                                                    <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" />
                                                </Link>
                                            ))
                                    )}
                                </div>
                            </section>

                            <section className="liquid-panel space-y-4 rounded-[26px] p-6">
                                <div className="flex items-center justify-between gap-2">
                                    <div>
                                        <p className="section-kicker text-emerald-700 dark:text-emerald-400">
                                            Garansi &amp; Klaim Saya
                                        </p>
                                        <h2 className="text-foreground text-lg font-black">
                                            Cakupan Garansi &amp; Riwayat Klaim
                                        </h2>
                                    </div>
                                    <ShieldCheck className="size-5 text-emerald-700 dark:text-emerald-400" />
                                </div>

                                <div className="space-y-2">
                                    {customerPortal.warranties.length === 0 ? (
                                        <p className="text-muted-foreground text-xs">
                                            Belum ada catatan garansi aktif pada
                                            akun Anda.
                                        </p>
                                    ) : (
                                        customerPortal.warranties
                                            .slice(0, 6)
                                            .map((warranty) => (
                                                <div
                                                    key={warranty.id}
                                                    className="surface-tile rounded-xl p-3 text-xs"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                        <span className="text-foreground font-black">
                                                            {
                                                                warranty.warranty_number
                                                            }{' '}
                                                            ·{' '}
                                                            {
                                                                warranty.item_label
                                                            }
                                                        </span>
                                                        <span
                                                            className={`rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase ${warrantyStatusBadge[warranty.status] ?? 'border-border bg-muted text-muted-foreground border'}`}
                                                        >
                                                            {warranty.status}
                                                        </span>
                                                    </div>
                                                    <p className="text-muted-foreground mt-1">
                                                        Tiket:{' '}
                                                        <Link
                                                            href={
                                                                warranty.order_href
                                                            }
                                                            className="text-crimson font-bold hover:underline"
                                                        >
                                                            {
                                                                warranty.ticket_number
                                                            }
                                                        </Link>
                                                        {warranty.expires_at
                                                            ? ` · Berlaku s.d. ${warranty.expires_at}`
                                                            : ''}
                                                    </p>
                                                </div>
                                            ))
                                    )}
                                </div>

                                {customerPortal.claims.length > 0 && (
                                    <div className="border-border space-y-2 border-t pt-3">
                                        <p className="text-muted-foreground flex items-center gap-1.5 text-xs font-bold uppercase">
                                            <ShieldAlert className="text-crimson size-3.5" />
                                            Riwayat pengajuan klaim (
                                            {customerPortal.claims.length})
                                        </p>
                                        {customerPortal.claims.map((claim) => (
                                            <div
                                                key={claim.id}
                                                className="surface-tile rounded-xl p-3 text-xs"
                                            >
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-foreground font-black">
                                                        {claim.claim_number} ·{' '}
                                                        {claim.warranty_number}
                                                    </span>
                                                    <span
                                                        className={`rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase ${claimStatusBadge[claim.status] ?? 'border-border bg-muted text-muted-foreground border'}`}
                                                    >
                                                        {claim.status}
                                                    </span>
                                                </div>
                                                <p className="text-muted-foreground mt-1">
                                                    {claim.issue_description}
                                                </p>
                                                {claim.claim_order_href &&
                                                    claim.claim_ticket && (
                                                        <Link
                                                            href={
                                                                claim.claim_order_href
                                                            }
                                                            className="text-crimson mt-1 inline-flex items-center gap-1 font-bold hover:underline"
                                                        >
                                                            Tiket tindak lanjut{' '}
                                                            {claim.claim_ticket}
                                                            <ArrowUpRight className="size-3" />
                                                        </Link>
                                                    )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

DevicesIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Perangkat & Riwayat', href: '#' },
    ],
};
