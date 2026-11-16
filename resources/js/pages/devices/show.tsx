import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowUpRight,
    CheckCircle2,
    Clock,
    Laptop,
    Pencil,
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
    update as updateDevice,
} from '@/routes/devices';
import { voidMethod as voidWarrantyRoute } from '@/routes/warranties';
import { store as submitWarrantyClaim } from '@/routes/warranties/claims';

type DeviceDetail = {
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
    customer_name: string;
    customer_email: string | null;
    registered_at: string | null;
    last_serviced_at: string | null;
};

type DeviceOrder = {
    id: string;
    ticket_number: string;
    service: string;
    status: string;
    is_completed: boolean;
    technician: string | null;
    created_at: string;
    completed_at: string | null;
    href: string;
};

type ServiceRecord = {
    id: number;
    service_order_id: string;
    ticket_number: string;
    service_name: string;
    order_status: string;
    device_snapshot: Record<string, string | null> | null;
    diagnosis: string | null;
    action_taken: string | null;
    test_result: string | null;
    recommendation: string | null;
    warranty_until: string | null;
    recorded_at: string | null;
    order_href: string;
};

type WarrantyClaimItem = {
    id: number;
    claim_number: string;
    status: string;
    issue_description: string;
    eligibility_checked: boolean;
    eligibility_notes: string | null;
    review_notes: string | null;
    resolution_notes: string | null;
    claim_ticket: string | null;
    claim_order_href: string | null;
    submitted_at: string;
    reviewed_at: string | null;
    resolved_at: string | null;
    reviewer_name: string | null;
};

type DeviceWarranty = {
    id: number;
    warranty_number: string;
    scope_type: 'service' | 'spare_part';
    item_label: string;
    reference_number: string | null;
    duration_days: number;
    coverage_description: string | null;
    status: 'pending' | 'active' | 'expired' | 'void';
    is_expiring: boolean;
    remaining_days: number | null;
    starts_at: string | null;
    expires_at: string | null;
    ticket_number: string;
    order_href: string;
    can_submit_claim: boolean;
    can_void: boolean;
    claims: WarrantyClaimItem[];
};

type TimelineEntry = {
    id: string;
    source: string;
    title: string;
    description: string | null;
    actor: string | null;
    ticket_number: string | null;
    order_href: string | null;
    occurred_at: string;
};

const statusBadge: Record<string, string> = {
    active: 'status-pill-success',
    pending: 'status-pill-info',
    expired: 'border border-border bg-muted text-muted-foreground',
    void: 'status-pill-danger',
};

const sourceLabel: Record<string, string> = {
    device: 'Perangkat',
    status: 'Status Tiket',
    assignment: 'Penugasan',
    work_session: 'Sesi Kerja',
    spare_part: 'Spare-Part',
    evidence: 'Bukti Kerja',
    warranty: 'Garansi',
    warranty_claim: 'Klaim Garansi',
};

export default function DeviceShow({
    isCustomer,
    canUpdate,
    device,
    orders,
    serviceRecords,
    warranties,
    timeline,
}: {
    isCustomer: boolean;
    canUpdate: boolean;
    canManageWarranty: boolean;
    device: DeviceDetail;
    orders: DeviceOrder[];
    serviceRecords: ServiceRecord[];
    warranties: DeviceWarranty[];
    timeline: TimelineEntry[];
}) {
    const [editing, setEditing] = useState(false);
    const [claimingWarrantyId, setClaimingWarrantyId] = useState<number | null>(
        null,
    );
    const [voidingWarrantyId, setVoidingWarrantyId] = useState<number | null>(
        null,
    );

    const editForm = useForm({
        name: device.name,
        category: device.category,
        brand: device.brand ?? '',
        model: device.model ?? '',
        serial_number: '',
        location: device.location ?? '',
        status: device.status,
        notes: device.notes ?? '',
    });

    const claimForm = useForm({
        issue_description: '',
    });

    const voidForm = useForm({
        void_reason: '',
    });

    function submitEdit(event: FormEvent) {
        event.preventDefault();
        editForm.patch(updateDevice.url(device.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    }

    function submitClaim(event: FormEvent, warrantyId: number) {
        event.preventDefault();
        claimForm.post(submitWarrantyClaim.url(warrantyId), {
            preserveScroll: true,
            onSuccess: () => {
                claimForm.reset();
                setClaimingWarrantyId(null);
            },
        });
    }

    function submitVoid(event: FormEvent, warrantyId: number) {
        event.preventDefault();
        voidForm.patch(voidWarrantyRoute.url(warrantyId), {
            preserveScroll: true,
            onSuccess: () => {
                voidForm.reset();
                setVoidingWarrantyId(null);
            },
        });
    }

    return (
        <>
            <Head title={`${device.name} · Detail Perangkat`} />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl space-y-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Link
                            href={devicesIndex.url()}
                            className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm font-bold"
                        >
                            <ArrowLeft className="size-4" />
                            Kembali ke daftar perangkat
                        </Link>
                        {canUpdate && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing((v) => !v)}
                            >
                                <Pencil className="mr-1.5 size-3.5" />
                                {editing ? 'Tutup edit' : 'Ubah info perangkat'}
                            </Button>
                        )}
                    </div>

                    <header className="liquid-panel rounded-[28px] p-6">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="space-y-1.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="bg-primary text-primary-foreground rounded-lg px-2.5 py-0.5 font-mono text-xs font-bold">
                                        {device.device_code ?? 'DEV'}
                                    </span>
                                    <span className="border-border bg-muted text-muted-foreground rounded-full border px-3 py-0.5 text-xs font-bold uppercase">
                                        {device.status}
                                    </span>
                                </div>
                                <h1 className="text-foreground text-2xl font-black tracking-tight sm:text-3xl">
                                    {device.name}
                                </h1>
                                <p className="text-muted-foreground text-sm">
                                    {device.category}
                                    {device.brand ? ` · ${device.brand}` : ''}
                                    {device.model ? ` ${device.model}` : ''}
                                    {device.serial_masked
                                        ? ` · Serial ${device.serial_masked}`
                                        : ''}
                                </p>
                            </div>
                            <div className="text-muted-foreground space-y-1 text-right text-xs">
                                {!isCustomer && (
                                    <p className="text-foreground font-bold">
                                        Pelanggan: {device.customer_name}
                                    </p>
                                )}
                                <p>Terdaftar: {device.registered_at ?? '—'}</p>
                                <p>
                                    Servis terakhir:{' '}
                                    {device.last_serviced_at ?? '—'}
                                </p>
                                {device.location && (
                                    <p>Lokasi: {device.location}</p>
                                )}
                            </div>
                        </div>

                        {device.notes && (
                            <p className="border-border text-muted-foreground mt-4 border-t pt-3 text-sm">
                                {device.notes}
                            </p>
                        )}
                    </header>

                    {editing && canUpdate && (
                        <form
                            onSubmit={submitEdit}
                            className="liquid-panel space-y-4 rounded-[26px] p-6"
                        >
                            <h2 className="text-ink text-lg font-black">
                                Perbarui metadata perangkat
                            </h2>
                            <p className="text-ink/55 text-xs">
                                Perubahan nama, lokasi, atau nomor seri tetap
                                mempertahankan snapshot historis pada catatan
                                servis sebelumnya.
                            </p>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <label className="space-y-1 text-xs font-bold">
                                    <span>Nama perangkat</span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                        value={editForm.data.name}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <InputError
                                        message={editForm.errors.name}
                                    />
                                </label>
                                <label className="space-y-1 text-xs font-bold">
                                    <span>Kategori</span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                        value={editForm.data.category}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'category',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </label>
                                <label className="space-y-1 text-xs font-bold">
                                    <span>Status unit</span>
                                    <select
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                        value={editForm.data.status}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'status',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="active">Aktif</option>
                                        <option value="in_service">
                                            Sedang diservis
                                        </option>
                                        <option value="retired">
                                            Nonaktif
                                        </option>
                                        <option value="transferred">
                                            Dialihkan
                                        </option>
                                    </select>
                                </label>
                                <label className="space-y-1 text-xs font-bold">
                                    <span>Merek</span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                        value={editForm.data.brand}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'brand',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="space-y-1 text-xs font-bold">
                                    <span>Model</span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                        value={editForm.data.model}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'model',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="space-y-1 text-xs font-bold">
                                    <span>Lokasi</span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                        value={editForm.data.location}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'location',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="space-y-1 text-xs font-bold sm:col-span-2 lg:col-span-3">
                                    <span>Catatan</span>
                                    <input
                                        type="text"
                                        className="liquid-input h-10 w-full rounded-xl px-3 text-sm"
                                        value={editForm.data.notes}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <div className="flex justify-end gap-2">
                                <Button
                                    type="submit"
                                    disabled={editForm.processing}
                                >
                                    Simpan perubahan
                                </Button>
                            </div>
                        </form>
                    )}

                    <div className="grid gap-6 lg:grid-cols-12">
                        <div className="space-y-6 lg:col-span-7">
                            <section className="liquid-panel space-y-4 rounded-[26px] p-6">
                                <div className="flex items-center justify-between gap-2">
                                    <div>
                                        <p className="section-kicker text-emerald-700">
                                            Cakupan Garansi &amp; Klaim
                                        </p>
                                        <h2 className="text-foreground text-lg font-black">
                                            Garansi Layanan &amp; Spare-Part
                                        </h2>
                                    </div>
                                    <ShieldCheck className="size-5 text-emerald-700 dark:text-emerald-400" />
                                </div>

                                {warranties.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        Belum ada garansi yang didaftarkan untuk
                                        perangkat ini.
                                    </p>
                                ) : (
                                    <div className="space-y-4">
                                        {warranties.map((warranty) => (
                                            <article
                                                key={warranty.id}
                                                className="surface-tile space-y-3 rounded-2xl p-4"
                                            >
                                                <div className="flex flex-wrap items-start justify-between gap-2">
                                                    <div>
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-mono text-xs font-black">
                                                                {
                                                                    warranty.warranty_number
                                                                }
                                                            </span>
                                                            <span className="bg-muted text-muted-foreground rounded-md px-2 py-0.5 text-[10px] font-bold uppercase">
                                                                {warranty.scope_type ===
                                                                'spare_part'
                                                                    ? 'Spare-Part'
                                                                    : 'Layanan'}
                                                            </span>
                                                            <span
                                                                className={`rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase ${statusBadge[warranty.status] ?? 'border-border bg-muted text-muted-foreground border'}`}
                                                            >
                                                                {
                                                                    warranty.status
                                                                }
                                                            </span>
                                                            {warranty.is_expiring && (
                                                                <span className="status-pill-warning inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold">
                                                                    <AlertTriangle className="size-3" />
                                                                    Sisa{' '}
                                                                    {
                                                                        warranty.remaining_days
                                                                    }{' '}
                                                                    hari
                                                                </span>
                                                            )}
                                                        </div>
                                                        <h3 className="text-foreground mt-1 font-black">
                                                            {
                                                                warranty.item_label
                                                            }
                                                        </h3>
                                                        <p className="text-muted-foreground text-xs">
                                                            Tiket asal:{' '}
                                                            <Link
                                                                href={
                                                                    warranty.order_href
                                                                }
                                                                className="text-crimson font-bold hover:underline"
                                                            >
                                                                {
                                                                    warranty.ticket_number
                                                                }
                                                            </Link>{' '}
                                                            · Durasi{' '}
                                                            {
                                                                warranty.duration_days
                                                            }{' '}
                                                            hari
                                                            {warranty.expires_at
                                                                ? ` · Berakhir ${warranty.expires_at}`
                                                                : ''}
                                                        </p>
                                                    </div>

                                                    <div className="flex flex-wrap gap-2">
                                                        {warranty.can_submit_claim && (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setClaimingWarrantyId(
                                                                        claimingWarrantyId ===
                                                                            warranty.id
                                                                            ? null
                                                                            : warranty.id,
                                                                    )
                                                                }
                                                            >
                                                                Ajukan klaim
                                                            </Button>
                                                        )}
                                                        {warranty.can_void && (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setVoidingWarrantyId(
                                                                        voidingWarrantyId ===
                                                                            warranty.id
                                                                            ? null
                                                                            : warranty.id,
                                                                    )
                                                                }
                                                            >
                                                                Nonaktifkan
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>

                                                {warranty.coverage_description && (
                                                    <p className="text-muted-foreground text-xs">
                                                        {
                                                            warranty.coverage_description
                                                        }
                                                    </p>
                                                )}

                                                {claimingWarrantyId ===
                                                    warranty.id && (
                                                    <form
                                                        onSubmit={(e) =>
                                                            submitClaim(
                                                                e,
                                                                warranty.id,
                                                            )
                                                        }
                                                        className="border-border space-y-2 border-t pt-3"
                                                    >
                                                        <label className="block space-y-1 text-xs font-bold">
                                                            <span>
                                                                Keluhan klaim
                                                                garansi
                                                            </span>
                                                            <textarea
                                                                rows={2}
                                                                className="liquid-input w-full rounded-xl p-2.5 text-sm"
                                                                placeholder="Jelaskan kendala berulang yang terjadi pada layanan atau spare-part…"
                                                                value={
                                                                    claimForm
                                                                        .data
                                                                        .issue_description
                                                                }
                                                                onChange={(e) =>
                                                                    claimForm.setData(
                                                                        'issue_description',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                required
                                                            />
                                                            <InputError
                                                                message={
                                                                    claimForm
                                                                        .errors
                                                                        .issue_description
                                                                }
                                                            />
                                                        </label>
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setClaimingWarrantyId(
                                                                        null,
                                                                    )
                                                                }
                                                            >
                                                                Batal
                                                            </Button>
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={
                                                                    claimForm.processing
                                                                }
                                                            >
                                                                Kirim klaim
                                                            </Button>
                                                        </div>
                                                    </form>
                                                )}

                                                {voidingWarrantyId ===
                                                    warranty.id && (
                                                    <form
                                                        onSubmit={(e) =>
                                                            submitVoid(
                                                                e,
                                                                warranty.id,
                                                            )
                                                        }
                                                        className="border-border space-y-2 border-t pt-3"
                                                    >
                                                        <input
                                                            type="text"
                                                            className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                                            placeholder="Alasan pembatalan garansi (wajib)"
                                                            value={
                                                                voidForm.data
                                                                    .void_reason
                                                            }
                                                            onChange={(e) =>
                                                                voidForm.setData(
                                                                    'void_reason',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            required
                                                        />
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="destructive"
                                                                disabled={
                                                                    voidForm.processing
                                                                }
                                                            >
                                                                Konfirmasi Void
                                                            </Button>
                                                        </div>
                                                    </form>
                                                )}

                                                {warranty.claims.length > 0 && (
                                                    <div className="border-border space-y-2 border-t pt-2.5 text-xs">
                                                        {warranty.claims.map(
                                                            (claim) => (
                                                                <div
                                                                    key={
                                                                        claim.id
                                                                    }
                                                                    className="surface-tile rounded-xl p-2.5"
                                                                >
                                                                    <div className="flex items-center justify-between gap-2">
                                                                        <span className="font-bold">
                                                                            <ShieldAlert className="text-crimson mr-1 inline size-3.5" />
                                                                            {
                                                                                claim.claim_number
                                                                            }
                                                                        </span>
                                                                        <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-[10px] font-black uppercase">
                                                                            {
                                                                                claim.status
                                                                            }
                                                                        </span>
                                                                    </div>
                                                                    <p className="text-muted-foreground mt-1">
                                                                        {
                                                                            claim.issue_description
                                                                        }
                                                                    </p>
                                                                    {claim.resolution_notes && (
                                                                        <p className="mt-1 text-emerald-700 dark:text-emerald-400">
                                                                            Resolusi:{' '}
                                                                            {
                                                                                claim.resolution_notes
                                                                            }
                                                                        </p>
                                                                    )}
                                                                    {claim.claim_order_href &&
                                                                        claim.claim_ticket && (
                                                                            <Link
                                                                                href={
                                                                                    claim.claim_order_href
                                                                                }
                                                                                className="text-crimson mt-1 inline-flex items-center gap-1 font-bold hover:underline"
                                                                            >
                                                                                Tiket
                                                                                tindak
                                                                                lanjut{' '}
                                                                                {
                                                                                    claim.claim_ticket
                                                                                }
                                                                                <ArrowUpRight className="size-3" />
                                                                            </Link>
                                                                        )}
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </article>
                                        ))}
                                    </div>
                                )}
                            </section>

                            <section className="liquid-panel space-y-4 rounded-[26px] p-6">
                                <div className="flex items-center justify-between gap-2">
                                    <div>
                                        <p className="section-kicker text-crimson">
                                            Arsip Historis
                                        </p>
                                        <h2 className="text-foreground text-lg font-black">
                                            Tiket &amp; Catatan Servis Perangkat
                                        </h2>
                                    </div>
                                    <Wrench className="text-crimson/70 size-5" />
                                </div>

                                {orders.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        Belum ada pesanan layanan yang ditautkan
                                        ke perangkat ini.
                                    </p>
                                ) : (
                                    <div className="space-y-3">
                                        {orders.map((order) => {
                                            const record = serviceRecords.find(
                                                (r) =>
                                                    r.service_order_id ===
                                                    order.id,
                                            );

                                            return (
                                                <div
                                                    key={order.id}
                                                    className="surface-tile space-y-2 rounded-2xl p-4 text-xs"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                        <div>
                                                            <Link
                                                                href={
                                                                    order.href
                                                                }
                                                                className="text-crimson font-black hover:underline"
                                                            >
                                                                {
                                                                    order.ticket_number
                                                                }{' '}
                                                                ·{' '}
                                                                {order.service}
                                                            </Link>
                                                            <p className="text-muted-foreground mt-0.5">
                                                                Masuk:{' '}
                                                                {
                                                                    order.created_at
                                                                }
                                                                {order.technician
                                                                    ? ` · PIC ${order.technician}`
                                                                    : ''}
                                                            </p>
                                                        </div>
                                                        <span className="bg-muted text-muted-foreground rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase">
                                                            {order.status}
                                                        </span>
                                                    </div>

                                                    {record &&
                                                        (record.diagnosis ||
                                                            record.action_taken ||
                                                            record.recommendation) && (
                                                            <div className="border-border text-muted-foreground space-y-1 border-t pt-2">
                                                                {record.diagnosis && (
                                                                    <p>
                                                                        <strong>
                                                                            Diagnosis:
                                                                        </strong>{' '}
                                                                        {
                                                                            record.diagnosis
                                                                        }
                                                                    </p>
                                                                )}
                                                                {record.action_taken && (
                                                                    <p>
                                                                        <strong>
                                                                            Tindakan:
                                                                        </strong>{' '}
                                                                        {
                                                                            record.action_taken
                                                                        }
                                                                    </p>
                                                                )}
                                                                {record.recommendation && (
                                                                    <p>
                                                                        <strong>
                                                                            Rekomendasi:
                                                                        </strong>{' '}
                                                                        {
                                                                            record.recommendation
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </section>
                        </div>

                        <section className="liquid-panel space-y-4 rounded-[26px] p-6 lg:col-span-5">
                            <div className="flex items-center justify-between gap-2">
                                <div>
                                    <p className="section-kicker text-crimson">
                                        Unified History Timeline
                                    </p>
                                    <h2 className="text-foreground text-lg font-black">
                                        Linimasa Terpadu Perangkat
                                    </h2>
                                </div>
                                <Clock className="text-muted-foreground size-5" />
                            </div>

                            {timeline.length === 0 ? (
                                <div className="surface-tile rounded-2xl p-6 text-center text-xs">
                                    <Laptop className="text-muted-foreground/40 mx-auto size-7" />
                                    <p className="text-muted-foreground mt-2">
                                        Belum ada aktivitas historis pada
                                        perangkat ini.
                                    </p>
                                </div>
                            ) : (
                                <ol className="space-y-4">
                                    {timeline.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="grid grid-cols-[24px_1fr] gap-3"
                                        >
                                            <span className="bg-crimson/15 text-crimson mt-0.5 grid size-6 place-items-center rounded-full">
                                                <CheckCircle2 className="size-3.5" />
                                            </span>
                                            <div className="border-border border-b pb-3">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <span className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 text-[10px] font-bold uppercase">
                                                        {sourceLabel[
                                                            entry.source
                                                        ] ?? entry.source}
                                                    </span>
                                                    <span className="text-muted-foreground text-[10px]">
                                                        {entry.occurred_at}
                                                    </span>
                                                </div>
                                                <p className="text-foreground mt-1 text-sm font-black">
                                                    {entry.title}
                                                </p>
                                                {entry.description && (
                                                    <p className="text-muted-foreground mt-0.5 text-xs leading-5">
                                                        {entry.description}
                                                    </p>
                                                )}
                                                <p className="text-muted-foreground mt-1 text-[10px]">
                                                    {entry.actor
                                                        ? `${entry.actor} · `
                                                        : ''}
                                                    {entry.ticket_number ?? ''}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}

DeviceShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Perangkat & Riwayat', href: devicesIndex() },
        { title: 'Detail Perangkat', href: '#' },
    ],
};
