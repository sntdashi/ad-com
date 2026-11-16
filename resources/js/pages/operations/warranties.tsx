import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    Search,
    ShieldAlert,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import {
    index as warrantiesIndex,
    voidMethod as voidWarrantyRoute,
} from '@/routes/warranties';
import {
    resolve as resolveClaimRoute,
    review as reviewClaimRoute,
} from '@/routes/warranties/claims';

type WarrantyRow = {
    id: number;
    warranty_number: string;
    scope_type: 'service' | 'spare_part';
    item_label: string;
    reference_number: string | null;
    customer_name: string;
    device_name: string | null;
    device_code: string | null;
    device_href: string | null;
    ticket_number: string;
    order_href: string;
    duration_days: number;
    coverage_description: string | null;
    status: 'pending' | 'active' | 'expired' | 'void';
    is_expiring: boolean;
    remaining_days: number | null;
    starts_at: string | null;
    expires_at: string | null;
    void_reason: string | null;
    active_claims_count: number;
    can_void: boolean;
};

type ClaimRow = {
    id: number;
    claim_number: string;
    warranty_number: string;
    warranty_status: string;
    item_label: string;
    customer_name: string;
    device_name: string | null;
    device_href: string | null;
    original_ticket: string;
    original_order_href: string;
    claim_ticket: string | null;
    claim_order_href: string | null;
    status: 'submitted' | 'under_review' | 'approved' | 'rejected' | 'resolved';
    issue_description: string;
    eligibility_checked: boolean;
    eligibility_notes: string | null;
    review_notes: string | null;
    resolution_notes: string | null;
    submitter_name: string | null;
    reviewer_name: string | null;
    resolver_name: string | null;
    submitted_at: string;
    reviewed_at: string | null;
    resolved_at: string | null;
    can_review: boolean;
    can_resolve: boolean;
};

type ExpiringAlert = {
    id: number;
    warranty_number: string;
    item_label: string;
    customer_name: string;
    device_name: string | null;
    remaining_days: number | null;
    expires_at: string | null;
    order_href: string;
};

type Pagination<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
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

export default function WarrantiesIndex({
    warranties,
    claims,
    expiringAlerts,
    filters,
    stats,
}: {
    warranties: Pagination<WarrantyRow>;
    claims: Pagination<ClaimRow>;
    expiringAlerts: ExpiringAlert[];
    filters: {
        search: string;
        status: string;
        scope: string;
        claim_status: string;
    };
    stats: {
        active: number;
        expiring: number;
        expired: number;
        active_claims: number;
    };
}) {
    const [searchText, setSearchText] = useState(filters.search);
    const [voidingId, setVoidingId] = useState<number | null>(null);
    const [reviewingClaimId, setReviewingClaimId] = useState<number | null>(
        null,
    );
    const [resolvingClaimId, setResolvingClaimId] = useState<number | null>(
        null,
    );

    const voidForm = useForm({ void_reason: '' });
    const reviewForm = useForm({
        status: 'approved' as 'under_review' | 'approved' | 'rejected',
        eligibility_checked: true,
        eligibility_notes: '',
        review_notes: '',
        create_service_order: true,
    });
    const resolveForm = useForm({ resolution_notes: '' });

    function applyFilter(next: Partial<typeof filters>) {
        const merged = { ...filters, ...next };
        router.get(
            warrantiesIndex.url({
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

    function submitVoid(event: FormEvent, warrantyId: number) {
        event.preventDefault();
        voidForm.patch(voidWarrantyRoute.url(warrantyId), {
            preserveScroll: true,
            onSuccess: () => {
                voidForm.reset();
                setVoidingId(null);
            },
        });
    }

    function submitReview(event: FormEvent, claimId: number) {
        event.preventDefault();
        reviewForm.patch(reviewClaimRoute.url(claimId), {
            preserveScroll: true,
            onSuccess: () => {
                reviewForm.reset();
                setReviewingClaimId(null);
            },
        });
    }

    function submitResolve(event: FormEvent, claimId: number) {
        event.preventDefault();
        resolveForm.patch(resolveClaimRoute.url(claimId), {
            preserveScroll: true,
            onSuccess: () => {
                resolveForm.reset();
                setResolvingClaimId(null);
            },
        });
    }

    return (
        <>
            <Head title="Garansi & Klaim Layanan" />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl space-y-6">
                    <header className="liquid-panel rounded-[28px] p-6">
                        <p className="section-kicker text-crimson">
                            Warranty &amp; Claim Operations
                        </p>
                        <h1 className="text-foreground mt-1 text-2xl font-black tracking-tight sm:text-3xl">
                            Manajemen Garansi &amp; Tinjauan Klaim
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Pantau cakupan garansi layanan dan spare-part,
                            peringatan jatuh tempo, serta verifikasi kelayakan
                            klaim pelanggan.
                        </p>
                    </header>

                    <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-muted-foreground text-xs font-bold uppercase">
                                Garansi aktif
                            </p>
                            <p className="mt-2 text-3xl font-black text-emerald-700 dark:text-emerald-400">
                                {stats.active}
                            </p>
                        </div>
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-muted-foreground text-xs font-bold uppercase">
                                Segera berakhir (&le;7 hari)
                            </p>
                            <p className="mt-2 text-3xl font-black text-amber-700 dark:text-amber-300">
                                {stats.expiring}
                            </p>
                        </div>
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-muted-foreground text-xs font-bold uppercase">
                                Garansi kedaluwarsa
                            </p>
                            <p className="text-foreground mt-2 text-3xl font-black">
                                {stats.expired}
                            </p>
                        </div>
                        <div className="liquid-panel rounded-[22px] p-5">
                            <p className="text-muted-foreground text-xs font-bold uppercase">
                                Klaim aktif
                            </p>
                            <p className="text-crimson mt-2 text-3xl font-black">
                                {stats.active_claims}
                            </p>
                        </div>
                    </section>

                    {expiringAlerts.length > 0 && (
                        <section className="rounded-[24px] border border-amber-300/70 bg-amber-50/90 p-5 dark:border-amber-400/25 dark:bg-amber-500/10">
                            <div className="flex items-center gap-2 text-amber-900 dark:text-amber-200">
                                <AlertTriangle className="size-5" />
                                <h2 className="font-black">
                                    Peringatan Garansi Mendekati Berakhir
                                </h2>
                            </div>
                            <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {expiringAlerts.map((alert) => (
                                    <Link
                                        key={alert.id}
                                        href={alert.order_href}
                                        className="liquid-control flex items-center justify-between rounded-xl px-3 py-2 text-xs transition hover:border-amber-400/50"
                                    >
                                        <div>
                                            <p className="text-foreground font-black">
                                                {alert.warranty_number} ·{' '}
                                                {alert.item_label}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {alert.customer_name}
                                                {alert.device_name
                                                    ? ` · ${alert.device_name}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <span className="shrink-0 font-bold text-amber-800 dark:text-amber-300">
                                            {alert.remaining_days} hr
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        </section>
                    )}

                    <section className="liquid-panel space-y-4 rounded-[26px] p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <form
                                onSubmit={submitSearch}
                                className="flex min-w-64 flex-1 items-center gap-2"
                            >
                                <div className="relative flex-1">
                                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                    <input
                                        type="search"
                                        value={searchText}
                                        onChange={(e) =>
                                            setSearchText(e.target.value)
                                        }
                                        placeholder="Cari nomor garansi, klaim, tiket, perangkat, pelanggan…"
                                        className="liquid-input h-10 w-full rounded-xl pr-3 pl-9 text-sm"
                                    />
                                </div>
                                <Button type="submit" variant="outline">
                                    Cari
                                </Button>
                            </form>

                            <div className="flex flex-wrap gap-2">
                                <select
                                    className="liquid-input h-10 rounded-xl px-3 text-xs font-bold"
                                    value={filters.status}
                                    onChange={(e) =>
                                        applyFilter({ status: e.target.value })
                                    }
                                >
                                    <option value="all">
                                        Status garansi: Semua
                                    </option>
                                    <option value="active">Aktif</option>
                                    <option value="expiring">
                                        Segera berakhir
                                    </option>
                                    <option value="expired">Kedaluwarsa</option>
                                    <option value="pending">Pending</option>
                                    <option value="void">Void</option>
                                </select>
                                <select
                                    className="liquid-input h-10 rounded-xl px-3 text-xs font-bold"
                                    value={filters.scope}
                                    onChange={(e) =>
                                        applyFilter({ scope: e.target.value })
                                    }
                                >
                                    <option value="all">Cakupan: Semua</option>
                                    <option value="service">Layanan</option>
                                    <option value="spare_part">
                                        Spare-Part
                                    </option>
                                </select>
                                <select
                                    className="liquid-input h-10 rounded-xl px-3 text-xs font-bold"
                                    value={filters.claim_status}
                                    onChange={(e) =>
                                        applyFilter({
                                            claim_status: e.target.value,
                                        })
                                    }
                                >
                                    <option value="all">
                                        Status klaim: Semua
                                    </option>
                                    <option value="submitted">Diajukan</option>
                                    <option value="under_review">
                                        Ditinjau
                                    </option>
                                    <option value="approved">Disetujui</option>
                                    <option value="rejected">Ditolak</option>
                                    <option value="resolved">Selesai</option>
                                </select>
                            </div>
                        </div>
                    </section>

                    <div className="grid gap-6 xl:grid-cols-2">
                        <section className="liquid-panel space-y-4 rounded-[26px] p-6">
                            <div className="flex items-center justify-between gap-2">
                                <div>
                                    <p className="section-kicker text-crimson">
                                        Antrean Klaim Garansi
                                    </p>
                                    <h2 className="text-ink text-lg font-black">
                                        Tinjauan &amp; Resolusi Klaim
                                    </h2>
                                </div>
                                <ShieldAlert className="text-crimson size-5" />
                            </div>

                            {claims.data.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Tidak ada pengajuan klaim garansi yang cocok
                                    dengan filter.
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {claims.data.map((claim) => (
                                        <article
                                            key={claim.id}
                                            className="liquid-control space-y-2.5 rounded-2xl p-4 text-xs"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <span className="text-foreground font-mono font-black">
                                                        {claim.claim_number}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        ·{' '}
                                                        {
                                                            claim.warranty_number
                                                        }{' '}
                                                        ({claim.item_label})
                                                    </span>
                                                </div>
                                                <span
                                                    className={`rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase ${claimStatusBadge[claim.status] ?? 'bg-muted text-muted-foreground'}`}
                                                >
                                                    {claim.status}
                                                </span>
                                            </div>

                                            <p className="text-foreground/85">
                                                {claim.issue_description}
                                            </p>

                                            <div className="text-muted-foreground flex flex-wrap gap-3">
                                                <span>
                                                    Pelanggan:{' '}
                                                    {claim.customer_name}
                                                </span>
                                                <Link
                                                    href={
                                                        claim.original_order_href
                                                    }
                                                    className="text-crimson font-bold hover:underline"
                                                >
                                                    Tiket asal:{' '}
                                                    {claim.original_ticket}
                                                </Link>
                                                {claim.claim_order_href &&
                                                    claim.claim_ticket && (
                                                        <Link
                                                            href={
                                                                claim.claim_order_href
                                                            }
                                                            className="inline-flex items-center gap-0.5 font-bold text-emerald-700 hover:underline dark:text-emerald-400"
                                                        >
                                                            Tiket lanjutan:{' '}
                                                            {claim.claim_ticket}
                                                            <ArrowUpRight className="size-3" />
                                                        </Link>
                                                    )}
                                            </div>

                                            {claim.review_notes && (
                                                <p className="text-muted-foreground">
                                                    <strong>
                                                        Catatan tinjauan:
                                                    </strong>{' '}
                                                    {claim.review_notes}
                                                </p>
                                            )}
                                            {claim.resolution_notes && (
                                                <p className="text-emerald-700">
                                                    <strong>Resolusi:</strong>{' '}
                                                    {claim.resolution_notes}
                                                </p>
                                            )}

                                            <div className="flex flex-wrap gap-2 pt-1">
                                                {claim.can_review && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        onClick={() =>
                                                            setReviewingClaimId(
                                                                reviewingClaimId ===
                                                                    claim.id
                                                                    ? null
                                                                    : claim.id,
                                                            )
                                                        }
                                                    >
                                                        Tinjau &amp; Verifikasi
                                                    </Button>
                                                )}
                                                {claim.can_resolve && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setResolvingClaimId(
                                                                resolvingClaimId ===
                                                                    claim.id
                                                                    ? null
                                                                    : claim.id,
                                                            )
                                                        }
                                                    >
                                                        <CheckCircle2 className="mr-1 size-3.5" />
                                                        Selesaikan klaim
                                                    </Button>
                                                )}
                                            </div>

                                            {reviewingClaimId === claim.id && (
                                                <form
                                                    onSubmit={(e) =>
                                                        submitReview(
                                                            e,
                                                            claim.id,
                                                        )
                                                    }
                                                    className="border-border space-y-2.5 border-t pt-3"
                                                >
                                                    <div className="grid gap-2 sm:grid-cols-2">
                                                        <select
                                                            className="liquid-input h-9 rounded-xl px-2.5 text-xs font-bold"
                                                            value={
                                                                reviewForm.data
                                                                    .status
                                                            }
                                                            onChange={(e) =>
                                                                reviewForm.setData(
                                                                    'status',
                                                                    e.target
                                                                        .value as
                                                                        | 'under_review'
                                                                        | 'approved'
                                                                        | 'rejected',
                                                                )
                                                            }
                                                        >
                                                            <option value="approved">
                                                                Setujui klaim
                                                            </option>
                                                            <option value="under_review">
                                                                Sedang ditinjau
                                                            </option>
                                                            <option value="rejected">
                                                                Tolak klaim
                                                            </option>
                                                        </select>
                                                        <label className="flex items-center gap-2 font-bold">
                                                            <input
                                                                type="checkbox"
                                                                checked={
                                                                    reviewForm
                                                                        .data
                                                                        .create_service_order
                                                                }
                                                                onChange={(e) =>
                                                                    reviewForm.setData(
                                                                        'create_service_order',
                                                                        e.target
                                                                            .checked,
                                                                    )
                                                                }
                                                            />
                                                            Buat tiket servis
                                                            lanjutan (jika
                                                            disetujui)
                                                        </label>
                                                    </div>
                                                    <input
                                                        type="text"
                                                        className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                                        placeholder="Catatan verifikasi kelayakan…"
                                                        value={
                                                            reviewForm.data
                                                                .eligibility_notes
                                                        }
                                                        onChange={(e) =>
                                                            reviewForm.setData(
                                                                'eligibility_notes',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    <input
                                                        type="text"
                                                        className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                                        placeholder="Catatan keputusan (wajib bila ditolak)…"
                                                        value={
                                                            reviewForm.data
                                                                .review_notes
                                                        }
                                                        onChange={(e) =>
                                                            reviewForm.setData(
                                                                'review_notes',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            reviewForm.errors
                                                                .review_notes
                                                        }
                                                    />
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={
                                                                reviewForm.processing
                                                            }
                                                        >
                                                            Simpan keputusan
                                                        </Button>
                                                    </div>
                                                </form>
                                            )}

                                            {resolvingClaimId === claim.id && (
                                                <form
                                                    onSubmit={(e) =>
                                                        submitResolve(
                                                            e,
                                                            claim.id,
                                                        )
                                                    }
                                                    className="border-border space-y-2 border-t pt-3"
                                                >
                                                    <input
                                                        type="text"
                                                        className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                                        placeholder="Catatan hasil penyelesaian klaim…"
                                                        value={
                                                            resolveForm.data
                                                                .resolution_notes
                                                        }
                                                        onChange={(e) =>
                                                            resolveForm.setData(
                                                                'resolution_notes',
                                                                e.target.value,
                                                            )
                                                        }
                                                        required
                                                    />
                                                    <div className="flex justify-end">
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={
                                                                resolveForm.processing
                                                            }
                                                        >
                                                            Tandai Selesai
                                                        </Button>
                                                    </div>
                                                </form>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            )}
                            {claims.links.length > 3 && (
                                <div className="flex flex-wrap justify-end gap-1.5 pt-2">
                                    {claims.links.map((link, idx) => (
                                        <Button
                                            key={`c-${idx}`}
                                            type="button"
                                            size="sm"
                                            variant={
                                                link.active
                                                    ? 'default'
                                                    : 'outline'
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

                        <section className="liquid-panel space-y-4 rounded-[26px] p-6">
                            <div className="flex items-center justify-between gap-2">
                                <div>
                                    <p className="section-kicker text-emerald-700">
                                        Daftar Cakupan Garansi
                                    </p>
                                    <h2 className="text-ink text-lg font-black">
                                        Seluruh Catatan Garansi
                                    </h2>
                                </div>
                                <ShieldCheck className="size-5 text-emerald-700" />
                            </div>

                            {warranties.data.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Belum ada catatan garansi sesuai filter.
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {warranties.data.map((warranty) => (
                                        <article
                                            key={warranty.id}
                                            className="liquid-control space-y-2 rounded-2xl p-4 text-xs"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <span className="text-foreground font-mono font-black">
                                                        {
                                                            warranty.warranty_number
                                                        }
                                                    </span>
                                                    <span className="border-border bg-muted text-muted-foreground ml-2 rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase">
                                                        {warranty.scope_type ===
                                                        'spare_part'
                                                            ? 'Spare-Part'
                                                            : 'Layanan'}
                                                    </span>
                                                </div>
                                                <span
                                                    className={`rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase ${warrantyStatusBadge[warranty.status] ?? 'bg-muted text-muted-foreground'}`}
                                                >
                                                    {warranty.status}
                                                </span>
                                            </div>
                                            <p className="text-foreground font-bold">
                                                {warranty.item_label}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {warranty.customer_name} ·{' '}
                                                <Link
                                                    href={warranty.order_href}
                                                    className="text-crimson font-bold hover:underline"
                                                >
                                                    {warranty.ticket_number}
                                                </Link>
                                                {warranty.device_href &&
                                                warranty.device_name
                                                    ? ' · '
                                                    : ''}
                                                {warranty.device_href &&
                                                    warranty.device_name && (
                                                        <Link
                                                            href={
                                                                warranty.device_href
                                                            }
                                                            className="font-bold hover:underline"
                                                        >
                                                            {
                                                                warranty.device_name
                                                            }
                                                        </Link>
                                                    )}
                                            </p>
                                            <p className="text-muted-foreground">
                                                Durasi {warranty.duration_days}{' '}
                                                hari · Mulai{' '}
                                                {warranty.starts_at ?? '—'} ·
                                                Berakhir{' '}
                                                {warranty.expires_at ?? '—'}
                                            </p>
                                            {warranty.can_void && (
                                                <div className="pt-1">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setVoidingId(
                                                                voidingId ===
                                                                    warranty.id
                                                                    ? null
                                                                    : warranty.id,
                                                            )
                                                        }
                                                        className="inline-flex items-center gap-1 font-bold text-rose-700 hover:underline"
                                                    >
                                                        <XCircle className="size-3.5" />
                                                        Nonaktifkan (Void)
                                                    </button>
                                                </div>
                                            )}
                                            {voidingId === warranty.id && (
                                                <form
                                                    onSubmit={(e) =>
                                                        submitVoid(
                                                            e,
                                                            warranty.id,
                                                        )
                                                    }
                                                    className="border-border space-y-2 border-t pt-2"
                                                >
                                                    <input
                                                        type="text"
                                                        className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                                        placeholder="Alasan pembatalan garansi…"
                                                        value={
                                                            voidForm.data
                                                                .void_reason
                                                        }
                                                        onChange={(e) =>
                                                            voidForm.setData(
                                                                'void_reason',
                                                                e.target.value,
                                                            )
                                                        }
                                                        required
                                                    />
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
                                                </form>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            )}
                            {warranties.links.length > 3 && (
                                <div className="flex flex-wrap justify-end gap-1.5 pt-2">
                                    {warranties.links.map((link, idx) => (
                                        <Button
                                            key={`w-${idx}`}
                                            type="button"
                                            size="sm"
                                            variant={
                                                link.active
                                                    ? 'default'
                                                    : 'outline'
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
                    </div>
                </div>
            </div>
        </>
    );
}

WarrantiesIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Garansi & Klaim', href: '#' },
    ],
};
