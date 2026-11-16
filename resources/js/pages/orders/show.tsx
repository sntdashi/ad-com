import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
    BadgeCheck,
    CalendarDays,
    Camera,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Laptop,
    MapPin,
    MessageCircle,
    Pause,
    Phone,
    Play,
    Save,
    ShieldAlert,
    ShieldCheck,
    Send,
    Sparkles,
    Star,
    UserRound,
    WalletCards,
    Wrench,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { show as showDevice } from '@/routes/devices';
import { show as invoiceShow } from '@/routes/finance/invoices';
import { show as quotationShow } from '@/routes/finance/quotations';
import { link as linkOrderDevice } from '@/routes/orders/device';
import { store as storeOrderMessage } from '@/routes/orders/messages';
import { update as updateOrderChecklist } from '@/routes/orders/checklist';
import {
    confirm as confirmWorkEvidence,
    store as storeWorkEvidence,
} from '@/routes/orders/evidences';
import {
    cancel as cancelOrderSparePart,
    issue as issueOrderSparePart,
    returnMethod as returnOrderSparePart,
    store as storeOrderSparePart,
} from '@/routes/orders/spare-parts';
import { store as storeOrderWarranty } from '@/routes/orders/warranties';
import { store as storeTechnicianReview } from '@/routes/orders/technician-reviews';
import { update as updateOrderStatus } from '@/routes/orders/status';
import { update as updateOrderSla } from '@/routes/orders/sla';
import { update as updateOrderTeam } from '@/routes/orders/team';
import {
    store as startWorkSessionRoute,
    update as updateWorkSessionRoute,
} from '@/routes/orders/work-sessions';
import { voidMethod as voidOrderWarranty } from '@/routes/warranties';
import {
    resolve as resolveWarrantyClaimRoute,
    review as reviewWarrantyClaimRoute,
    store as submitWarrantyClaimRoute,
} from '@/routes/warranties/claims';

type TimelineItem = {
    title: string;
    description: string | null;
    visibility: string;
    actor: string | null;
    occurred_at: string;
};
type ChecklistItem = {
    id: number;
    title: string;
    standard_code: string | null;
    requires_evidence: boolean;
    result: 'pending' | 'passed' | 'failed';
    notes: string | null;
    completed_by: string | null;
    completed_at: string | null;
};
type Technician = {
    id: number;
    name: string;
    phone: string | null;
    avatar_url: string | null;
    specialty: string | null;
    bio: string | null;
    rating_average: number | null;
    reviews_count: number;
};
type TechnicianSummary = Pick<
    Technician,
    | 'id'
    | 'name'
    | 'avatar_url'
    | 'specialty'
    | 'rating_average'
    | 'reviews_count'
>;
type TechnicianReview = {
    rating: number;
    quality_rating: number;
    communication_rating: number;
    punctuality_rating: number;
    tags: string[];
    comment: string | null;
};
type ReviewableTechnician = TechnicianSummary & {
    review: TechnicianReview | null;
};
type OrderMessage = {
    id: number;
    body: string;
    sender_id: number | null;
    sender_name: string;
    sender_role: string;
    sender_avatar_url: string | null;
    created_at: string;
};
type FinanceEntry = {
    id: number;
    type: string;
    amount: number;
    payment_method: string;
    status: string;
    reference: string | null;
    description: string | null;
    occurred_at: string;
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
    first_responded_formatted: string | null;
    is_paused: boolean;
    paused_minutes?: number;
    pause_reason: string | null;
    compliant?: boolean;
};
type Order = {
    id: string;
    ticket_number: string;
    status: string;
    customer_name: string;
    phone: string;
    email: string | null;
    address: string;
    district: string | null;
    complaint: string;
    service: string;
    service_code: string;
    risk_level: string;
    urgency: string;
    sla?: OrderSla;
    preferred_date: string | null;
    preferred_window: string | null;
    assigned_technician_id: number | null;
    technician: Technician | null;
    technician_ids: number[];
    technician_team: TechnicianSummary[];
    reviewable_technicians: ReviewableTechnician[];
    supervisor: string | null;
    estimated_total: number | null;
    work_sessions?: WorkSessionItem[];
    evidences?: WorkEvidenceItem[];
    spare_parts?: SparePartUsageItem[];
    spare_parts_summary?: {
        internal_cost_total: number | null;
        chargeable_total: number;
        net_units: number;
    };
    timeline: TimelineItem[];
    unified_timeline?: UnifiedTimelineItem[];
    device?: OrderDeviceSummary | null;
    warranties?: OrderWarrantyItem[];
    checklist: ChecklistItem[];
    messages: OrderMessage[];
    finance: {
        estimated_total: number | null;
        verified_income: number;
        pending_income: number;
        profitability?: {
            estimated_total: number;
            invoiced_total: number;
            cash_received: number;
            outstanding_receivables: number;
            spare_part_cogs: number;
            direct_expenses: number;
            gross_profit: number;
        } | null;
        quotations?: Array<{
            id: string;
            quotation_number: string;
            version: number;
            status: string;
            total: number;
            valid_until: string | null;
            invoice_id: string | null;
            invoice_number: string | null;
        }>;
        invoices?: Array<{
            id: string;
            invoice_number: string;
            status: string;
            total_amount: number;
            paid_amount: number;
            outstanding_balance: number;
            due_date: string | null;
        }>;
        transactions: FinanceEntry[];
    };
};
type WorkSessionItem = {
    id: number;
    technician_id: number;
    technician: string | null;
    status: 'in_progress' | 'paused' | 'completed';
    started_at: string;
    paused_at: string | null;
    resumed_at: string | null;
    completed_at: string | null;
    paused_minutes: number;
    duration_minutes: number;
    notes: string | null;
};
type WorkEvidenceItem = {
    id: number;
    stage: 'before' | 'after' | 'completion';
    caption: string | null;
    has_file: boolean;
    file_url: string | null;
    uploaded_by: string | null;
    created_at: string | null;
    customer_confirmed_at: string | null;
    confirmation_note: string | null;
};
type DispatchRecommendation = {
    id: number;
    name: string;
    avatar_url: string | null;
    specialty: string | null;
    availability_status: string;
    availability_label: string;
    current_workload: number;
    active_assignments_count: number;
    pending_assignments_count: number;
    sla_risk_assignments_count: number;
    sla_compliance_rate: number;
    eligible: boolean;
    conflict_reason: string | null;
    score: number;
    recommended: boolean;
    reasons: string[];
};
type StatusOption = { value: string; label: string };
type SparePartUsageItem = {
    id: number;
    inventory_item_id: number;
    sku: string;
    name: string;
    unit: string;
    requested_quantity: number;
    issued_quantity: number;
    returned_quantity: number;
    net_issued_quantity: number;
    unit_cost: number | null;
    unit_price: number;
    line_total: number;
    status:
        | 'requested'
        | 'issued'
        | 'partially_returned'
        | 'returned'
        | 'cancelled';
    requester: string | null;
    approver: string | null;
    returner: string | null;
    request_notes: string | null;
    issue_notes: string | null;
    return_notes: string | null;
    cancellation_reason: string | null;
    requested_at: string;
    issued_at: string | null;
    returned_at: string | null;
    can_issue: boolean;
    can_return: boolean;
    can_cancel: boolean;
};
type InventoryCatalogOption = {
    id: number;
    sku: string;
    name: string;
    unit: string;
    available_stock: number;
    selling_price: number | null;
};
type UnifiedTimelineItem = {
    id: string;
    source: string;
    title: string;
    description: string | null;
    actor: string | null;
    ticket_number: string | null;
    visibility: string;
    occurred_at: string;
};
type OrderDeviceSummary = {
    id: string;
    device_code: string | null;
    name: string;
    category: string;
    brand: string | null;
    model: string | null;
    serial_masked: string | null;
    location: string | null;
    status: string;
    href: string;
};
type CustomerDeviceOption = {
    id: string;
    device_code: string | null;
    name: string;
    category: string;
    brand: string | null;
    model: string | null;
    serial_masked: string | null;
};
type OrderWarrantyClaim = {
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
    can_review: boolean;
    can_resolve: boolean;
};
type OrderWarrantyItem = {
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
    void_reason: string | null;
    can_submit_claim: boolean;
    can_void: boolean;
    claims: OrderWarrantyClaim[];
};
type OrderSparePartExtended = SparePartUsageItem & {
    warranty_days?: number | null;
    warranty_terms?: string | null;
    has_warranty?: boolean;
};
type TechnicianOption = {
    id: number;
    name: string;
    avatar_url: string | null;
    specialty: string | null;
};
type TeamForm = { technician_ids: number[]; lead_technician_id: number | '' };

const statusLabels: Record<string, string> = {
    new: 'Baru masuk',
    needs_info: 'Perlu info tambahan',
    triaged: 'Triase selesai',
    waiting_estimate: 'Menunggu estimasi',
    waiting_approval: 'Menunggu persetujuan',
    scheduled: 'Terjadwal',
    on_the_way: 'Menuju lokasi',
    in_progress: 'Sedang dikerjakan',
    verification: 'Verifikasi supervisor',
    waiting_payment: 'Menunggu pembayaran',
    completed: 'Selesai',
    warranty: 'Dalam garansi',
    closed: 'Ditutup',
    reopened: 'Dibuka kembali',
    cancelled: 'Dibatalkan',
};
const windowLabels: Record<string, string> = {
    morning: '08.00–10.00',
    midday: '10.00–12.00',
    afternoon: '13.00–15.30',
};

const money = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

const initials = (name: string) =>
    name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

export default function OrderShow({
    order,
    allowedStatuses,
    canManageTeam,
    canManageSla = false,
    canManageWork = false,
    canConfirmEvidence = false,
    canManageDevice = false,
    canManageWarranty = false,
    customerDevices = [],
    warrantyDefaults = { service_warranty_days: 0 },
    canRequestSpareParts = false,
    inventoryCatalog = [],
    dispatchRecommendations = [],
    technicians,
    currentUserId,
    currentUserRole,
    canUpdateChecklist,
    canChat,
    canReview,
}: {
    order: Order;
    allowedStatuses: StatusOption[];
    canManageTeam: boolean;
    canManageSla?: boolean;
    canManageWork?: boolean;
    canConfirmEvidence?: boolean;
    canManageDevice?: boolean;
    canManageWarranty?: boolean;
    customerDevices?: CustomerDeviceOption[];
    warrantyDefaults?: { service_warranty_days: number };
    canRequestSpareParts?: boolean;
    inventoryCatalog?: InventoryCatalogOption[];
    dispatchRecommendations?: DispatchRecommendation[];
    technicians: TechnicianOption[];
    currentUserId: number;
    currentUserRole: string;
    canUpdateChecklist: boolean;
    canChat: boolean;
    canReview: boolean;
}) {
    usePoll(5000, { only: ['order'] });
    const statusForm = useForm({
        status: allowedStatuses[0]?.value ?? '',
        note: '',
    });
    const slaForm = useForm({
        action: order.sla?.is_paused ? 'resume' : 'pause',
        reason: order.sla?.pause_reason ?? '',
        urgency: order.urgency,
        risk_level: order.risk_level,
    });
    const teamForm = useForm<TeamForm>({
        technician_ids: order.technician_ids,
        lead_technician_id:
            order.assigned_technician_id ?? order.technician_ids[0] ?? '',
    });
    const messageForm = useForm({ body: '' });

    function submitStatus(event: FormEvent) {
        event.preventDefault();
        statusForm.patch(updateOrderStatus.url(order.id), {
            preserveScroll: true,
        });
    }

    function toggleTechnician(technicianId: number) {
        const isSelected = teamForm.data.technician_ids.includes(technicianId);
        const technicianIds = isSelected
            ? teamForm.data.technician_ids.filter((id) => id !== technicianId)
            : [...teamForm.data.technician_ids, technicianId];

        teamForm.setData({
            technician_ids: technicianIds,
            lead_technician_id: technicianIds.includes(
                Number(teamForm.data.lead_technician_id),
            )
                ? teamForm.data.lead_technician_id
                : (technicianIds[0] ?? ''),
        });
    }

    function submitTeam(event: FormEvent) {
        event.preventDefault();
        teamForm.patch(updateOrderTeam.url(order.id), { preserveScroll: true });
    }

    function submitMessage(event: FormEvent) {
        event.preventDefault();
        messageForm.post(storeOrderMessage.url(order.id), {
            preserveScroll: true,
            onSuccess: () => messageForm.reset(),
        });
    }

    return (
        <>
            <Head title={`${order.ticket_number} · ${order.service}`} />
            <div className="flex flex-1 flex-col p-4 sm:p-6 lg:p-8">
                <div className="mx-auto w-full max-w-7xl">
                    <Link
                        href={dashboard()}
                        className="text-muted-foreground hover:text-foreground mb-5 inline-flex items-center gap-2 text-sm font-bold"
                    >
                        <ArrowLeft className="size-4" /> Kembali ke antrean
                    </Link>

                    <header className="liquid-panel-dark rounded-[28px] p-6 text-white sm:p-8">
                        <div className="flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
                            <div>
                                <p className="text-cyan font-mono text-xs font-bold tracking-[0.15em]">
                                    {order.ticket_number} · {order.service_code}
                                </p>
                                <h1 className="mt-3 text-3xl font-black tracking-[-0.045em] sm:text-4xl">
                                    {order.service}
                                </h1>
                                <p className="mt-3 max-w-3xl text-sm leading-6 text-white/55">
                                    {order.complaint}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <span className="bg-lime rounded-full px-3 py-1.5 text-xs font-black text-slate-950">
                                    {statusLabels[order.status] ?? order.status}
                                </span>
                                <span
                                    className={`rounded-full px-3 py-1.5 text-xs font-black ${order.risk_level === 'high' ? 'bg-red-500/20 text-red-200' : 'bg-white/10 text-white/65'}`}
                                >
                                    Risiko {order.risk_level}
                                </span>
                            </div>
                        </div>
                    </header>

                    <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(330px,.8fr)] xl:items-start">
                        <div className="space-y-5">
                            <section className="liquid-panel rounded-[26px] p-5 sm:p-7">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            Informasi lapangan
                                        </p>
                                        <h2 className="text-ink mt-1 text-xl font-black">
                                            Pelanggan dan lokasi
                                        </h2>
                                    </div>
                                    <MapPin className="text-cyan" />
                                </div>
                                <div className="mt-6 grid gap-5 text-sm sm:grid-cols-2">
                                    <Info
                                        icon={UserRound}
                                        label="Pelanggan"
                                        value={order.customer_name}
                                    />
                                    <Info
                                        icon={Phone}
                                        label="Kontak"
                                        value={order.phone}
                                    />
                                    <Info
                                        icon={MapPin}
                                        label="Alamat"
                                        value={[order.address, order.district]
                                            .filter(Boolean)
                                            .join(', ')}
                                    />
                                    <Info
                                        icon={CalendarDays}
                                        label="Waktu pilihan"
                                        value={
                                            [
                                                order.preferred_date,
                                                order.preferred_window
                                                    ? windowLabels[
                                                          order.preferred_window
                                                      ]
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || 'Fleksibel'
                                        }
                                    />
                                </div>
                            </section>

                            <section className="liquid-panel rounded-[26px] p-5 sm:p-7">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            Quality ledger
                                        </p>
                                        <h2 className="text-ink mt-1 text-xl font-black">
                                            Standar pengerjaan (
                                            {
                                                order.checklist.filter(
                                                    (item) =>
                                                        item.result ===
                                                        'passed',
                                                ).length
                                            }
                                            /{order.checklist.length})
                                        </h2>
                                    </div>
                                    <ClipboardCheck className="text-cyan" />
                                </div>
                                <div className="mt-6 grid gap-3 sm:grid-cols-2">
                                    {order.checklist.map((item) => (
                                        <article
                                            key={item.id}
                                            className="liquid-control rounded-2xl p-4"
                                        >
                                            <div className="flex items-start gap-3">
                                                <span
                                                    className={`grid size-8 shrink-0 place-items-center rounded-lg ${
                                                        item.result === 'passed'
                                                            ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                                                            : item.result ===
                                                                'failed'
                                                              ? 'bg-crimson/15 text-crimson'
                                                              : 'bg-muted text-muted-foreground'
                                                    }`}
                                                >
                                                    <CheckCircle2 className="size-4" />
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-foreground text-sm font-black">
                                                        {item.title}
                                                    </p>
                                                    <p className="text-cyan mt-1 font-mono text-[10px]">
                                                        {item.standard_code ??
                                                            'UMUM'}
                                                    </p>
                                                    {item.requires_evidence && (
                                                        <p className="text-muted-foreground mt-1 text-[10px] font-bold">
                                                            Wajib bukti
                                                        </p>
                                                    )}
                                                    {item.completed_by && (
                                                        <p className="text-muted-foreground mt-1 text-[11px]">
                                                            Oleh{' '}
                                                            {item.completed_by}
                                                            {item.completed_at
                                                                ? ` • ${item.completed_at}`
                                                                : ''}
                                                        </p>
                                                    )}
                                                    {canUpdateChecklist ? (
                                                        <div className="mt-3 flex flex-wrap gap-1.5">
                                                            {(
                                                                [
                                                                    [
                                                                        'passed',
                                                                        'Lulus',
                                                                    ],
                                                                    [
                                                                        'failed',
                                                                        'Kendala',
                                                                    ],
                                                                    [
                                                                        'pending',
                                                                        'Belum',
                                                                    ],
                                                                ] as const
                                                            ).map(
                                                                ([
                                                                    value,
                                                                    label,
                                                                ]) => (
                                                                    <button
                                                                        key={
                                                                            value
                                                                        }
                                                                        type="button"
                                                                        onClick={() =>
                                                                            router.patch(
                                                                                updateOrderChecklist.url(
                                                                                    {
                                                                                        order: order.id,
                                                                                        checklistTemplate:
                                                                                            item.id,
                                                                                    },
                                                                                ),
                                                                                {
                                                                                    result: value,
                                                                                },
                                                                                {
                                                                                    preserveScroll: true,
                                                                                },
                                                                            )
                                                                        }
                                                                        className={`rounded-lg px-2.5 py-1 text-[11px] font-bold transition ${
                                                                            item.result ===
                                                                            value
                                                                                ? value ===
                                                                                  'passed'
                                                                                    ? 'bg-emerald-600 text-white'
                                                                                    : value ===
                                                                                        'failed'
                                                                                      ? 'bg-crimson text-white'
                                                                                      : 'bg-primary text-primary-foreground'
                                                                                : 'bg-muted text-muted-foreground hover:text-foreground'
                                                                        }`}
                                                                    >
                                                                        {label}
                                                                    </button>
                                                                ),
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <p className="text-muted-foreground mt-2 text-[11px] font-bold uppercase">
                                                            Status:{' '}
                                                            {item.result ===
                                                            'passed'
                                                                ? 'Lulus'
                                                                : item.result ===
                                                                    'failed'
                                                                  ? 'Kendala'
                                                                  : 'Belum dicek'}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </article>
                                    ))}
                                </div>
                                <p className="text-muted-foreground mt-4 text-xs leading-5">
                                    Checklist ini menjaga setiap pekerjaan
                                    mengikuti standar keselamatan, dokumentasi,
                                    dan pengujian hasil AD Com.
                                </p>
                            </section>

                            <section className="liquid-panel rounded-[26px] p-5 sm:p-7">
                                <p className="section-kicker text-cyan">
                                    Audit trail
                                </p>
                                <h2 className="text-ink mt-1 text-xl font-black">
                                    Linimasa terpadu layanan
                                </h2>
                                <ol className="mt-6 space-y-5">
                                    {(order.unified_timeline &&
                                    order.unified_timeline.length > 0
                                        ? order.unified_timeline
                                        : order.timeline.map((event, idx) => ({
                                              id: `legacy-${idx}`,
                                              source: 'status',
                                              title: event.title,
                                              description: event.description,
                                              actor: event.actor,
                                              ticket_number:
                                                  order.ticket_number,
                                              visibility: event.visibility,
                                              occurred_at: event.occurred_at,
                                          }))
                                    ).map((event, index, list) => (
                                        <li
                                            key={event.id}
                                            className="grid grid-cols-[28px_1fr] gap-3"
                                        >
                                            <span
                                                className={`mt-0.5 grid size-7 place-items-center rounded-full ${index === list.length - 1 ? 'bg-cyan text-white' : 'bg-lime text-slate-950'}`}
                                            >
                                                <CheckCircle2 className="size-3.5" />
                                            </span>
                                            <div>
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 text-[10px] font-bold uppercase">
                                                            {event.source}
                                                        </span>
                                                        <p className="text-foreground text-sm font-black">
                                                            {event.title}
                                                        </p>
                                                    </div>
                                                    <p className="text-muted-foreground text-[10px]">
                                                        {event.occurred_at}
                                                    </p>
                                                </div>
                                                {event.description && (
                                                    <p className="text-muted-foreground mt-1 text-xs leading-5">
                                                        {event.description}
                                                    </p>
                                                )}
                                                <p className="text-cyan mt-1 text-[10px]">
                                                    {event.actor ?? 'Sistem'} ·{' '}
                                                    {event.visibility ===
                                                    'customer'
                                                        ? 'Terlihat pelanggan'
                                                        : 'Internal'}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            </section>

                            <section className="liquid-panel overflow-hidden rounded-[26px]">
                                <div className="border-border flex items-center justify-between gap-4 border-b p-5 sm:p-6">
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            Ticket-linked channel
                                        </p>
                                        <h2 className="text-foreground mt-1 text-xl font-black">
                                            Chat pelanggan & PIC
                                        </h2>
                                    </div>
                                    <span className="status-pill-success flex items-center gap-1.5 rounded-full px-3 py-1 text-[10px] font-black">
                                        <span className="size-1.5 rounded-full bg-emerald-500" />{' '}
                                        LIVE · 5 DTK
                                    </span>
                                </div>
                                <div className="bg-muted/25 max-h-[430px] min-h-64 space-y-4 overflow-y-auto p-5 sm:p-6">
                                    {order.messages.map((message) => {
                                        const mine =
                                            message.sender_id === currentUserId;

                                        return (
                                            <div
                                                key={message.id}
                                                className={`flex items-end gap-2 ${mine ? 'justify-end' : 'justify-start'}`}
                                            >
                                                {!mine && (
                                                    <Avatar className="border-border size-8 border">
                                                        <AvatarImage
                                                            src={
                                                                message.sender_avatar_url ??
                                                                undefined
                                                            }
                                                            alt={
                                                                message.sender_name
                                                            }
                                                        />
                                                        <AvatarFallback className="bg-cyan/10 text-cyan text-[10px] font-black">
                                                            {initials(
                                                                message.sender_name,
                                                            )}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                )}
                                                <div
                                                    className={`max-w-[82%] rounded-[20px] px-4 py-3 shadow-sm ${mine ? 'bg-gradient-to-br from-[#ff3856] to-[#c8092e] text-white' : 'border-border bg-card text-foreground border'}`}
                                                >
                                                    <p
                                                        className={`text-[10px] font-black ${mine ? 'text-white/75' : 'text-cyan'}`}
                                                    >
                                                        {mine
                                                            ? 'Anda'
                                                            : `${message.sender_name} · ${roleLabelsForChat(message.sender_role)}`}
                                                    </p>
                                                    <p className="mt-1 text-sm leading-6 whitespace-pre-wrap">
                                                        {message.body}
                                                    </p>
                                                    <p
                                                        className={`mt-1.5 text-[9px] ${mine ? 'text-white/60' : 'text-muted-foreground'}`}
                                                    >
                                                        {message.created_at}
                                                    </p>
                                                </div>
                                            </div>
                                        );
                                    })}
                                    {order.messages.length === 0 && (
                                        <div className="grid min-h-52 place-items-center text-center">
                                            <div>
                                                <MessageCircle className="text-cyan mx-auto size-9" />
                                                <p className="text-foreground mt-3 font-black">
                                                    Belum ada pesan
                                                </p>
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    Percakapan pertama akan
                                                    tersimpan di tiket ini.
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                                {canChat ? (
                                    <form
                                        onSubmit={submitMessage}
                                        className="border-border bg-muted/35 border-t p-4 sm:p-5"
                                    >
                                        <div className="flex items-end gap-2">
                                            <label className="flex-1">
                                                <span className="sr-only">
                                                    Pesan tiket
                                                </span>
                                                <textarea
                                                    className="liquid-input min-h-12 w-full resize-none rounded-[18px] px-4 py-3 text-sm outline-none"
                                                    rows={2}
                                                    maxLength={2000}
                                                    placeholder="Tulis pesan terkait pekerjaan…"
                                                    value={
                                                        messageForm.data.body
                                                    }
                                                    onChange={(event) =>
                                                        messageForm.setData(
                                                            'body',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                            <Button
                                                type="submit"
                                                className="size-12 shrink-0 rounded-2xl p-0"
                                                disabled={
                                                    messageForm.processing ||
                                                    !messageForm.data.body.trim()
                                                }
                                                aria-label="Kirim pesan"
                                            >
                                                <Send className="size-5" />
                                            </Button>
                                        </div>
                                        <InputError
                                            message={messageForm.errors.body}
                                            className="mt-2"
                                        />
                                        <p className="text-muted-foreground/80 mt-2 text-[10px]">
                                            Pesan menjadi bagian dari jejak
                                            layanan. Jangan kirim password atau
                                            OTP.
                                        </p>
                                    </form>
                                ) : (
                                    <div className="border-border bg-muted/35 text-muted-foreground border-t p-4 text-xs">
                                        Chat aktif otomatis setelah PIC teknisi
                                        dan akun pelanggan terhubung.
                                    </div>
                                )}
                            </section>

                            {['completed', 'warranty', 'closed'].includes(
                                order.status,
                            ) &&
                                order.reviewable_technicians.length > 0 && (
                                    <section className="liquid-panel rounded-[26px] p-5 sm:p-7">
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <p className="section-kicker text-cyan">
                                                    Evaluasi terverifikasi
                                                </p>
                                                <h2 className="text-foreground mt-1 text-xl font-black">
                                                    Nilai tim yang mengerjakan
                                                </h2>
                                                <p className="text-muted-foreground mt-2 max-w-2xl text-xs leading-5">
                                                    Satu pesanan hanya dapat
                                                    memberi satu penilaian per
                                                    teknisi. Nilai ini masuk ke
                                                    dashboard evaluasi AD Com.
                                                </p>
                                            </div>
                                            <Star className="text-cyan size-7 shrink-0 fill-current" />
                                        </div>
                                        <div className="mt-6 space-y-4">
                                            {order.reviewable_technicians.map(
                                                (technician) => (
                                                    <TechnicianReviewCard
                                                        key={technician.id}
                                                        orderId={order.id}
                                                        technician={technician}
                                                        canEdit={canReview}
                                                        currentUserRole={
                                                            currentUserRole
                                                        }
                                                    />
                                                ),
                                            )}
                                        </div>
                                    </section>
                                )}

                            {currentUserRole !== 'customer' && (
                                <WorkSessionsPanel
                                    orderId={order.id}
                                    orderStatus={order.status}
                                    sessions={order.work_sessions ?? []}
                                    canManage={canManageWork}
                                    assignedTechnicianId={
                                        order.assigned_technician_id
                                    }
                                    technicianTeam={order.technician_team}
                                    currentUserId={currentUserId}
                                    currentUserRole={currentUserRole}
                                />
                            )}

                            {((order.evidences ?? []).length > 0 ||
                                canManageWork) && (
                                <WorkEvidencesPanel
                                    orderId={order.id}
                                    evidences={order.evidences ?? []}
                                    canUpload={canManageWork}
                                    canConfirm={canConfirmEvidence}
                                />
                            )}

                            {((order.spare_parts ?? []).length > 0 ||
                                canRequestSpareParts) && (
                                <SparePartsPanel
                                    orderId={order.id}
                                    spareParts={order.spare_parts ?? []}
                                    summary={order.spare_parts_summary}
                                    canRequest={canRequestSpareParts}
                                    catalog={inventoryCatalog}
                                />
                            )}

                            {(order.device || canManageDevice) && (
                                <OrderDevicePanel
                                    orderId={order.id}
                                    device={order.device ?? null}
                                    customerDevices={customerDevices}
                                    canManage={canManageDevice}
                                    isInternal={currentUserRole !== 'customer'}
                                />
                            )}

                            {((order.warranties ?? []).length > 0 ||
                                canManageWarranty) && (
                                <OrderWarrantiesPanel
                                    orderId={order.id}
                                    deviceId={order.device?.id ?? null}
                                    warranties={order.warranties ?? []}
                                    spareParts={
                                        (order.spare_parts ??
                                            []) as OrderSparePartExtended[]
                                    }
                                    serviceWarrantyDays={
                                        warrantyDefaults.service_warranty_days
                                    }
                                    canManage={canManageWarranty}
                                    isInternal={currentUserRole !== 'customer'}
                                />
                            )}
                        </div>

                        <aside className="space-y-5 xl:sticky xl:top-5">
                            {order.sla && (
                                <section className="liquid-panel rounded-[26px] p-5">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="section-kicker text-cyan">
                                                Service Level Agreement
                                            </p>
                                            <h2 className="text-ink mt-1 font-black">
                                                Target & Kepatuhan SLA
                                            </h2>
                                        </div>
                                        <span
                                            className={`rounded-full border px-3 py-1 text-xs font-black ${
                                                order.sla.status === 'breached'
                                                    ? 'status-pill-danger'
                                                    : order.sla.status ===
                                                        'approaching_deadline'
                                                      ? 'status-pill-warning'
                                                      : order.sla.status ===
                                                          'paused'
                                                        ? 'border-border bg-muted text-muted-foreground'
                                                        : 'status-pill-success'
                                            }`}
                                        >
                                            {order.sla.label}
                                        </span>
                                    </div>

                                    <div className="mt-4 space-y-2.5 text-xs">
                                        <div className="liquid-control flex items-center justify-between rounded-xl p-3">
                                            <span className="text-muted-foreground font-bold">
                                                Batas respon awal
                                            </span>
                                            <span className="text-foreground font-black">
                                                {order.sla
                                                    .response_due_formatted ??
                                                    '—'}
                                            </span>
                                        </div>
                                        <div className="liquid-control flex items-center justify-between rounded-xl p-3">
                                            <span className="text-muted-foreground font-bold">
                                                Respon pertama tim
                                            </span>
                                            <span className="text-foreground font-black">
                                                {order.sla
                                                    .first_responded_formatted ??
                                                    'Menunggu respon'}
                                            </span>
                                        </div>
                                        <div className="liquid-control flex items-center justify-between rounded-xl p-3">
                                            <span className="text-muted-foreground font-bold">
                                                Batas penyelesaian
                                            </span>
                                            <span className="text-foreground font-black">
                                                {order.sla
                                                    .resolution_due_formatted ??
                                                    '—'}
                                            </span>
                                        </div>
                                        {order.sla.is_paused && (
                                            <div className="status-pill-warning rounded-xl p-3">
                                                <p className="font-black">
                                                    SLA sedang dijeda
                                                </p>
                                                <p className="mt-1 text-[11px]">
                                                    {order.sla.pause_reason ??
                                                        'Menunggu konfirmasi / respons pelanggan'}
                                                </p>
                                            </div>
                                        )}
                                    </div>

                                    {canManageSla &&
                                        ![
                                            'completed',
                                            'warranty',
                                            'closed',
                                            'cancelled',
                                        ].includes(order.status) && (
                                            <div className="border-border mt-4 space-y-3 border-t pt-4">
                                                <p className="text-muted-foreground text-[11px] font-black uppercase">
                                                    Kendali SLA Operasional
                                                </p>
                                                {order.sla.is_paused ? (
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                        disabled={
                                                            slaForm.processing
                                                        }
                                                        onClick={() =>
                                                            router.patch(
                                                                updateOrderSla.url(
                                                                    order.id,
                                                                ),
                                                                {
                                                                    action: 'resume',
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                        className="h-9 w-full rounded-xl text-xs font-bold"
                                                    >
                                                        Lanjutkan Timer SLA
                                                    </Button>
                                                ) : (
                                                    <div className="space-y-2">
                                                        <input
                                                            type="text"
                                                            value={
                                                                slaForm.data
                                                                    .reason
                                                            }
                                                            onChange={(e) =>
                                                                slaForm.setData(
                                                                    'reason',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder="Alasan jeda (mis. tunggu konfirmasi pelanggan)"
                                                            className="liquid-input h-9 w-full rounded-xl border px-3 text-xs outline-none"
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="secondary"
                                                            disabled={
                                                                slaForm.processing
                                                            }
                                                            onClick={() =>
                                                                router.patch(
                                                                    updateOrderSla.url(
                                                                        order.id,
                                                                    ),
                                                                    {
                                                                        action: 'pause',
                                                                        reason:
                                                                            slaForm
                                                                                .data
                                                                                .reason ||
                                                                            'Menunggu respons pelanggan',
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                            className="h-9 w-full rounded-xl text-xs font-bold"
                                                        >
                                                            Jeda Timer SLA
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                </section>
                            )}

                            <section className="liquid-panel rounded-[26px] p-5">
                                <p className="section-kicker text-cyan">
                                    Tim penanggung jawab
                                </p>
                                {order.technician ? (
                                    <div className="mt-5">
                                        <div className="flex items-start gap-3">
                                            <Avatar className="size-14 border-2 border-white shadow-md">
                                                <AvatarImage
                                                    src={
                                                        order.technician
                                                            .avatar_url ??
                                                        undefined
                                                    }
                                                    alt={order.technician.name}
                                                />
                                                <AvatarFallback className="bg-cyan/10 text-cyan font-black">
                                                    {initials(
                                                        order.technician.name,
                                                    )}
                                                </AvatarFallback>
                                            </Avatar>
                                            <div className="min-w-0">
                                                <p className="text-ink font-black">
                                                    {order.technician.name}
                                                </p>
                                                <p className="text-cyan mt-1 text-xs font-bold">
                                                    {order.technician
                                                        .specialty ??
                                                        'PIC teknisi AD Com'}
                                                </p>
                                                {order.technician.phone && (
                                                    <a
                                                        href={`tel:${order.technician.phone}`}
                                                        className="text-muted-foreground hover:text-cyan mt-2 inline-flex items-center gap-1.5 text-xs font-bold"
                                                    >
                                                        <Phone className="size-3.5" />{' '}
                                                        {order.technician.phone}
                                                    </a>
                                                )}
                                                <RatingSummary
                                                    average={
                                                        order.technician
                                                            .rating_average
                                                    }
                                                    count={
                                                        order.technician
                                                            .reviews_count
                                                    }
                                                />
                                            </div>
                                        </div>
                                        {order.technician.bio && (
                                            <p className="text-muted-foreground mt-4 text-xs leading-5">
                                                {order.technician.bio}
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground mt-4 text-sm">
                                        PIC belum ditugaskan.
                                    </p>
                                )}

                                {order.technician_team.length > 0 && (
                                    <div className="border-border mt-5 border-t pt-4">
                                        <p className="text-muted-foreground text-[10px] font-black uppercase">
                                            Anggota tim
                                        </p>
                                        <div className="mt-3 space-y-2">
                                            {order.technician_team.map(
                                                (technician) => (
                                                    <div
                                                        key={technician.id}
                                                        className="flex items-center gap-2"
                                                    >
                                                        <Avatar className="size-7">
                                                            <AvatarImage
                                                                src={
                                                                    technician.avatar_url ??
                                                                    undefined
                                                                }
                                                                alt={
                                                                    technician.name
                                                                }
                                                            />
                                                            <AvatarFallback className="bg-muted text-[9px] font-black">
                                                                {initials(
                                                                    technician.name,
                                                                )}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <p className="text-foreground text-xs font-bold">
                                                            {technician.name}
                                                            <span className="text-muted-foreground ml-1 font-normal">
                                                                {technician.specialty
                                                                    ? `· ${technician.specialty}`
                                                                    : ''}
                                                            </span>
                                                            <RatingSummary
                                                                average={
                                                                    technician.rating_average
                                                                }
                                                                count={
                                                                    technician.reviews_count
                                                                }
                                                                compact
                                                            />
                                                        </p>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}
                                <div className="border-border mt-5 border-t pt-4">
                                    <Info
                                        icon={BadgeCheck}
                                        label="Supervisor"
                                        value={
                                            order.supervisor ??
                                            'Belum ditugaskan'
                                        }
                                    />
                                </div>
                            </section>

                            <section className="liquid-panel rounded-[26px] p-5">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="section-kicker text-cyan">
                                            Transparansi biaya
                                        </p>
                                        <h2 className="text-foreground mt-1 font-black">
                                            Pembayaran tiket
                                        </h2>
                                    </div>
                                    <WalletCards className="text-cyan size-6" />
                                </div>
                                <div className="mt-5 grid grid-cols-2 gap-3">
                                    <div className="liquid-control rounded-2xl p-3">
                                        <p className="text-muted-foreground text-[10px] font-black uppercase">
                                            Terverifikasi
                                        </p>
                                        <p className="text-foreground mt-2 font-black">
                                            {money.format(
                                                order.finance.verified_income,
                                            )}
                                        </p>
                                    </div>
                                    <div className="surface-tile rounded-2xl p-3">
                                        <p className="text-muted-foreground text-[10px] font-black uppercase">
                                            Pending
                                        </p>
                                        <p className="text-foreground mt-2 font-black">
                                            {money.format(
                                                order.finance.pending_income,
                                            )}
                                        </p>
                                    </div>
                                </div>
                                <div className="mt-4 space-y-3">
                                    {order.finance.transactions
                                        .slice(0, 4)
                                        .map((entry) => (
                                            <div
                                                key={entry.id}
                                                className="border-border border-t pt-3"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p className="text-foreground text-xs font-black">
                                                            {entry.type ===
                                                            'income'
                                                                ? 'Pembayaran'
                                                                : 'Pengeluaran'}{' '}
                                                            · {entry.status}
                                                        </p>
                                                        <p className="text-muted-foreground mt-1 text-[10px]">
                                                            {entry.occurred_at}
                                                            {entry.reference
                                                                ? ` · ${entry.reference}`
                                                                : ''}
                                                        </p>
                                                    </div>
                                                    <p className="text-xs font-black">
                                                        {money.format(
                                                            entry.amount,
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    {order.finance.transactions.length ===
                                        0 && (
                                        <p className="text-muted-foreground text-xs">
                                            Belum ada transaksi pada tiket ini.
                                        </p>
                                    )}
                                </div>
                                <div className="border-border mt-4 flex items-center justify-between border-t pt-4 text-xs">
                                    <span className="text-muted-foreground">
                                        Estimasi pekerjaan
                                    </span>
                                    <span className="text-foreground font-black">
                                        {order.finance.estimated_total === null
                                            ? 'Belum tersedia'
                                            : money.format(
                                                  order.finance.estimated_total,
                                              )}
                                    </span>
                                </div>

                                {(order.finance.quotations?.length ?? 0) >
                                    0 && (
                                    <div className="border-border mt-4 space-y-2 border-t pt-3">
                                        <p className="text-muted-foreground text-[10px] font-black uppercase">
                                            Penawaran Biaya
                                        </p>
                                        {order.finance.quotations?.map((q) => (
                                            <Link
                                                key={q.id}
                                                href={quotationShow.url(q.id)}
                                                className="surface-tile hover:bg-muted/70 flex items-center justify-between rounded-xl px-3 py-2 text-xs transition"
                                            >
                                                <span className="text-foreground font-black">
                                                    {q.quotation_number} ·{' '}
                                                    <span className="text-cyan uppercase">
                                                        {q.status}
                                                    </span>
                                                </span>
                                                <span className="text-foreground font-bold">
                                                    {money.format(q.total)}
                                                </span>
                                            </Link>
                                        ))}
                                    </div>
                                )}

                                {(order.finance.invoices?.length ?? 0) > 0 && (
                                    <div className="border-border mt-4 space-y-2 border-t pt-3">
                                        <p className="text-muted-foreground text-[10px] font-black uppercase">
                                            Tagihan / Invoice
                                        </p>
                                        {order.finance.invoices?.map((inv) => (
                                            <Link
                                                key={inv.id}
                                                href={invoiceShow.url(inv.id)}
                                                className="surface-tile hover:bg-muted/70 flex items-center justify-between rounded-xl px-3 py-2 text-xs transition"
                                            >
                                                <div>
                                                    <p className="text-foreground font-black">
                                                        {inv.invoice_number} ·{' '}
                                                        <span className="text-cyan uppercase">
                                                            {inv.status}
                                                        </span>
                                                    </p>
                                                    {inv.outstanding_balance >
                                                        0 && (
                                                        <p className="text-[10px] font-bold text-amber-700 dark:text-amber-300">
                                                            Sisa:{' '}
                                                            {money.format(
                                                                inv.outstanding_balance,
                                                            )}
                                                        </p>
                                                    )}
                                                </div>
                                                <span className="text-foreground font-bold">
                                                    {money.format(
                                                        inv.total_amount,
                                                    )}
                                                </span>
                                            </Link>
                                        ))}
                                    </div>
                                )}

                                {order.finance.profitability && (
                                    <div className="border-border bg-muted/50 mt-4 space-y-1.5 rounded-2xl border p-3 text-[11px]">
                                        <p className="text-muted-foreground font-black uppercase">
                                            Profitabilitas Internal
                                        </p>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">
                                                Ditagihkan
                                            </span>
                                            <span className="text-foreground font-bold">
                                                {money.format(
                                                    order.finance.profitability
                                                        .invoiced_total,
                                                )}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">
                                                COGS Spare-part + Biaya
                                            </span>
                                            <span className="font-bold text-rose-600 dark:text-rose-400">
                                                {money.format(
                                                    order.finance.profitability
                                                        .spare_part_cogs +
                                                        order.finance
                                                            .profitability
                                                            .direct_expenses,
                                                )}
                                            </span>
                                        </div>
                                        <div className="border-border flex justify-between border-t pt-1">
                                            <span className="text-foreground font-black">
                                                Laba Kotor Order
                                            </span>
                                            <span
                                                className={`font-black ${
                                                    order.finance.profitability
                                                        .gross_profit >= 0
                                                        ? 'text-emerald-700'
                                                        : 'text-rose-700'
                                                }`}
                                            >
                                                {money.format(
                                                    order.finance.profitability
                                                        .gross_profit,
                                                )}
                                            </span>
                                        </div>
                                    </div>
                                )}
                            </section>

                            {canManageTeam && (
                                <section className="liquid-panel rounded-[26px] p-5">
                                    <p className="section-kicker text-cyan">
                                        Assignment desk
                                    </p>
                                    <h2 className="text-ink mt-1 font-black">
                                        Atur tim teknisi
                                    </h2>
                                    {dispatchRecommendations.length > 0 && (
                                        <div className="surface-tile mt-4 rounded-2xl p-3.5">
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="text-foreground flex items-center gap-1.5 text-xs font-black">
                                                    <Sparkles className="text-cyan size-3.5" />
                                                    Rekomendasi dispatch
                                                </p>
                                                <span className="text-muted-foreground text-[10px] font-semibold">
                                                    Pilih manual, tidak otomatis
                                                </span>
                                            </div>
                                            <div className="mt-2.5 space-y-2">
                                                {dispatchRecommendations
                                                    .slice(0, 4)
                                                    .map((rec) => {
                                                        const isSelected =
                                                            teamForm.data.technician_ids.includes(
                                                                rec.id,
                                                            );
                                                        return (
                                                            <div
                                                                key={rec.id}
                                                                className={`rounded-xl border px-3 py-2 text-xs ${
                                                                    rec.eligible
                                                                        ? isSelected
                                                                            ? 'border-cyan/60 bg-cyan/10'
                                                                            : 'border-border bg-card'
                                                                        : 'border-rose-500/30 bg-rose-500/10'
                                                                }`}
                                                            >
                                                                <div className="flex items-center justify-between gap-2">
                                                                    <div className="min-w-0">
                                                                        <p className="text-foreground truncate font-bold">
                                                                            {
                                                                                rec.name
                                                                            }{' '}
                                                                            <span className="text-muted-foreground font-mono text-[10px]">
                                                                                ·
                                                                                Skor{' '}
                                                                                {
                                                                                    rec.score
                                                                                }
                                                                            </span>
                                                                        </p>
                                                                        <p className="text-muted-foreground text-[11px]">
                                                                            {
                                                                                rec.availability_label
                                                                            }{' '}
                                                                            ·
                                                                            Beban{' '}
                                                                            {
                                                                                rec.current_workload
                                                                            }{' '}
                                                                            ·
                                                                            SLA{' '}
                                                                            {
                                                                                rec.sla_compliance_rate
                                                                            }
                                                                            %
                                                                        </p>
                                                                    </div>
                                                                    <button
                                                                        type="button"
                                                                        disabled={
                                                                            !rec.eligible
                                                                        }
                                                                        onClick={() => {
                                                                            const nextIds =
                                                                                isSelected
                                                                                    ? teamForm
                                                                                          .data
                                                                                          .technician_ids
                                                                                    : [
                                                                                          ...teamForm
                                                                                              .data
                                                                                              .technician_ids,
                                                                                          rec.id,
                                                                                      ];
                                                                            teamForm.setData(
                                                                                {
                                                                                    technician_ids:
                                                                                        nextIds,
                                                                                    lead_technician_id:
                                                                                        rec.id,
                                                                                },
                                                                            );
                                                                        }}
                                                                        className="border-border bg-background text-foreground hover:bg-muted shrink-0 rounded-lg border px-2.5 py-1 text-[11px] font-bold disabled:cursor-not-allowed disabled:opacity-50"
                                                                    >
                                                                        {rec.eligible
                                                                            ? isSelected
                                                                                ? 'Jadikan PIC'
                                                                                : 'Pilih'
                                                                            : 'Bentrok'}
                                                                    </button>
                                                                </div>
                                                                {rec.reasons
                                                                    .length >
                                                                    0 && (
                                                                    <p className="text-muted-foreground mt-1 text-[11px]">
                                                                        {rec.reasons.join(
                                                                            ' · ',
                                                                        )}
                                                                    </p>
                                                                )}
                                                            </div>
                                                        );
                                                    })}
                                            </div>
                                        </div>
                                    )}
                                    <form
                                        onSubmit={submitTeam}
                                        className="mt-5 space-y-4"
                                    >
                                        <fieldset>
                                            <legend className="text-muted-foreground text-xs font-black">
                                                Anggota aktif
                                            </legend>
                                            <div className="mt-2 space-y-2">
                                                {technicians.map(
                                                    (technician) => (
                                                        <label
                                                            key={technician.id}
                                                            className="surface-tile hover:bg-muted/70 flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold transition"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={teamForm.data.technician_ids.includes(
                                                                    technician.id,
                                                                )}
                                                                onChange={() =>
                                                                    toggleTechnician(
                                                                        technician.id,
                                                                    )
                                                                }
                                                                className="accent-cyan size-4"
                                                            />
                                                            {technician.name}
                                                        </label>
                                                    ),
                                                )}
                                            </div>
                                            <InputError
                                                message={
                                                    teamForm.errors
                                                        .technician_ids
                                                }
                                                className="mt-2"
                                            />
                                        </fieldset>
                                        <label className="text-ink/55 block text-xs font-black">
                                            PIC teknisi
                                            <select
                                                value={
                                                    teamForm.data
                                                        .lead_technician_id
                                                }
                                                onChange={(event) =>
                                                    teamForm.setData(
                                                        'lead_technician_id',
                                                        event.target.value
                                                            ? Number(
                                                                  event.target
                                                                      .value,
                                                              )
                                                            : '',
                                                    )
                                                }
                                                className="liquid-input border-border focus:ring-cyan/20 mt-2 h-11 w-full rounded-xl border px-3 text-sm font-bold outline-none focus:ring-3"
                                            >
                                                <option value="">
                                                    Pilih PIC
                                                </option>
                                                {technicians
                                                    .filter((technician) =>
                                                        teamForm.data.technician_ids.includes(
                                                            technician.id,
                                                        ),
                                                    )
                                                    .map((technician) => (
                                                        <option
                                                            key={technician.id}
                                                            value={
                                                                technician.id
                                                            }
                                                        >
                                                            {technician.name}
                                                        </option>
                                                    ))}
                                            </select>
                                            <InputError
                                                message={
                                                    teamForm.errors
                                                        .lead_technician_id
                                                }
                                                className="mt-2"
                                            />
                                        </label>
                                        <Button
                                            type="submit"
                                            disabled={
                                                teamForm.processing ||
                                                teamForm.data.technician_ids
                                                    .length === 0 ||
                                                !teamForm.data
                                                    .lead_technician_id
                                            }
                                            className="h-11 w-full rounded-xl"
                                        >
                                            {teamForm.processing
                                                ? 'Menyimpan…'
                                                : teamForm.recentlySuccessful
                                                  ? 'Tim tersimpan'
                                                  : 'Simpan tim'}{' '}
                                            <Save />
                                        </Button>
                                    </form>
                                </section>
                            )}

                            {allowedStatuses.length > 0 ? (
                                <section className="liquid-panel border-primary/25 bg-primary/6 rounded-[24px] border p-5">
                                    <div className="flex items-center gap-3">
                                        <span className="bg-primary text-primary-foreground grid size-10 place-items-center rounded-xl shadow-xs">
                                            <Save className="size-5" />
                                        </span>
                                        <div>
                                            <p className="section-kicker">
                                                Aksi tiket
                                            </p>
                                            <h2 className="font-black">
                                                Perbarui progres
                                            </h2>
                                        </div>
                                    </div>
                                    <form
                                        onSubmit={submitStatus}
                                        className="mt-5 space-y-4"
                                    >
                                        <label className="block text-sm font-black">
                                            Status berikutnya
                                            <select
                                                value={statusForm.data.status}
                                                onChange={(e) =>
                                                    statusForm.setData(
                                                        'status',
                                                        e.target.value,
                                                    )
                                                }
                                                className="liquid-input border-border focus:ring-cyan/20 mt-2 h-11 w-full rounded-xl border px-3 text-sm outline-none focus:ring-3"
                                            >
                                                {allowedStatuses.map(
                                                    (option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <InputError
                                                message={
                                                    statusForm.errors.status
                                                }
                                                className="mt-1"
                                            />
                                        </label>
                                        <label className="block text-sm font-black">
                                            Catatan untuk pelanggan{' '}
                                            <span className="text-muted-foreground font-medium">
                                                (opsional)
                                            </span>
                                            <textarea
                                                value={statusForm.data.note}
                                                onChange={(e) =>
                                                    statusForm.setData(
                                                        'note',
                                                        e.target.value,
                                                    )
                                                }
                                                className="liquid-input border-border focus:ring-cyan/20 mt-2 min-h-24 w-full rounded-xl border p-3 text-sm leading-5 outline-none focus:ring-3"
                                                placeholder="Apa yang berubah atau perlu diketahui?"
                                            />
                                            <InputError
                                                message={statusForm.errors.note}
                                                className="mt-1"
                                            />
                                        </label>
                                        <Button
                                            type="submit"
                                            disabled={
                                                statusForm.processing ||
                                                !statusForm.data.status
                                            }
                                            className="h-11 w-full rounded-xl"
                                        >
                                            {statusForm.processing
                                                ? 'Menyimpan…'
                                                : statusForm.recentlySuccessful
                                                  ? 'Tersimpan'
                                                  : 'Simpan status'}{' '}
                                            <Save />
                                        </Button>
                                    </form>
                                </section>
                            ) : (
                                <section className="liquid-panel rounded-[26px] p-5">
                                    <p className="text-ink flex items-center gap-2 text-sm font-black">
                                        <ShieldAlert className="text-cyan size-5" />{' '}
                                        Mode lihat saja
                                    </p>
                                    <p className="text-ink/45 mt-2 text-xs leading-5">
                                        Tidak ada transisi status yang tersedia
                                        untuk peran atau tahap tiket ini.
                                    </p>
                                </section>
                            )}
                        </aside>
                    </div>
                </div>
            </div>
        </>
    );
}

const reviewTagLabels: Record<string, string> = {
    rapi: 'Pengerjaan rapi',
    komunikatif: 'Komunikatif',
    tepat_waktu: 'Tepat waktu',
    solutif: 'Solutif',
    ramah: 'Ramah',
};

function TechnicianReviewCard({
    orderId,
    technician,
    canEdit,
    currentUserRole,
}: {
    orderId: string;
    technician: ReviewableTechnician;
    canEdit: boolean;
    currentUserRole: string;
}) {
    const form = useForm({
        technician_id: technician.id,
        rating: technician.review?.rating ?? 5,
        quality_rating: technician.review?.quality_rating ?? 5,
        communication_rating: technician.review?.communication_rating ?? 5,
        punctuality_rating: technician.review?.punctuality_rating ?? 5,
        tags: technician.review?.tags ?? [],
        comment: technician.review?.comment ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(storeTechnicianReview.url(orderId), {
            preserveScroll: true,
        });
    }

    function toggleTag(tag: string) {
        form.setData(
            'tags',
            form.data.tags.includes(tag)
                ? form.data.tags.filter((value) => value !== tag)
                : [...form.data.tags, tag],
        );
    }

    return (
        <article className="surface-tile overflow-hidden rounded-[22px]">
            <div className="flex items-center gap-3 p-4 sm:p-5">
                <Avatar className="border-background size-12 border-2 shadow-sm">
                    <AvatarImage
                        src={technician.avatar_url ?? undefined}
                        alt={technician.name}
                    />
                    <AvatarFallback className="bg-cyan/10 text-cyan font-black">
                        {initials(technician.name)}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <p className="text-foreground truncate font-black">
                        {technician.name}
                    </p>
                    <p className="text-muted-foreground mt-0.5 truncate text-xs">
                        {technician.specialty ?? 'Teknisi AD Com'}
                    </p>
                    <RatingSummary
                        average={technician.rating_average}
                        count={technician.reviews_count}
                        compact
                    />
                </div>
                {technician.review && (
                    <span className="status-pill-success rounded-full px-2.5 py-1 text-[10px] font-black">
                        TERVERIFIKASI
                    </span>
                )}
            </div>

            {canEdit ? (
                <form
                    onSubmit={submit}
                    className="border-border space-y-5 border-t p-4 sm:p-5"
                >
                    <div>
                        <p className="text-foreground text-sm font-black">
                            Seberapa puas kamu?
                        </p>
                        <StarRatingInput
                            value={form.data.rating}
                            onChange={(value) => form.setData('rating', value)}
                            label="Kepuasan keseluruhan"
                            large
                        />
                        <InputError
                            message={form.errors.rating}
                            className="mt-1"
                        />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-3">
                        <AspectRating
                            label="Kualitas hasil"
                            value={form.data.quality_rating}
                            onChange={(value) =>
                                form.setData('quality_rating', value)
                            }
                        />
                        <AspectRating
                            label="Komunikasi"
                            value={form.data.communication_rating}
                            onChange={(value) =>
                                form.setData('communication_rating', value)
                            }
                        />
                        <AspectRating
                            label="Ketepatan waktu"
                            value={form.data.punctuality_rating}
                            onChange={(value) =>
                                form.setData('punctuality_rating', value)
                            }
                        />
                    </div>

                    <fieldset>
                        <legend className="text-muted-foreground text-xs font-black">
                            Yang paling menonjol (opsional)
                        </legend>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {Object.entries(reviewTagLabels).map(
                                ([value, label]) => (
                                    <label
                                        key={value}
                                        className={`cursor-pointer rounded-full border px-3 py-1.5 text-[11px] font-bold transition ${form.data.tags.includes(value) ? 'border-cyan bg-cyan text-white' : 'border-border bg-background text-muted-foreground hover:border-cyan/45'}`}
                                    >
                                        <input
                                            type="checkbox"
                                            className="sr-only"
                                            checked={form.data.tags.includes(
                                                value,
                                            )}
                                            onChange={() => toggleTag(value)}
                                        />
                                        {label}
                                    </label>
                                ),
                            )}
                        </div>
                    </fieldset>

                    <label className="block">
                        <span className="text-muted-foreground text-xs font-black">
                            Catatan evaluasi (opsional)
                        </span>
                        <textarea
                            value={form.data.comment}
                            onChange={(event) =>
                                form.setData('comment', event.target.value)
                            }
                            maxLength={1000}
                            className="liquid-input border-border focus:ring-cyan/20 mt-2 min-h-24 w-full resize-y rounded-xl border p-3 text-sm leading-6 outline-none focus:ring-3"
                            placeholder="Apa yang bagus atau perlu ditingkatkan?"
                        />
                        <InputError
                            message={form.errors.comment}
                            className="mt-1"
                        />
                    </label>

                    <Button
                        type="submit"
                        disabled={form.processing}
                        className="h-11 rounded-full px-5"
                    >
                        <Star className="fill-current" />
                        {form.processing
                            ? 'Menyimpan…'
                            : technician.review
                              ? 'Perbarui penilaian'
                              : 'Kirim penilaian'}
                    </Button>
                </form>
            ) : technician.review ? (
                <div className="border-border border-t p-4 sm:p-5">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <ReviewMetric
                            label="Kepuasan"
                            value={technician.review.rating}
                        />
                        <ReviewMetric
                            label="Kualitas"
                            value={technician.review.quality_rating}
                        />
                        <ReviewMetric
                            label="Komunikasi"
                            value={technician.review.communication_rating}
                        />
                        <ReviewMetric
                            label="Ketepatan"
                            value={technician.review.punctuality_rating}
                        />
                    </div>
                    {technician.review.comment && (
                        <p className="text-muted-foreground mt-4 text-sm leading-6">
                            “{technician.review.comment}”
                        </p>
                    )}
                </div>
            ) : (
                <p className="border-border text-muted-foreground border-t p-4 text-xs">
                    {currentUserRole === 'customer'
                        ? 'Penilaian belum tersedia.'
                        : 'Customer belum memberikan penilaian.'}
                </p>
            )}
        </article>
    );
}

function StarRatingInput({
    value,
    onChange,
    label,
    large = false,
}: {
    value: number;
    onChange: (value: number) => void;
    label: string;
    large?: boolean;
}) {
    return (
        <div className="mt-2 flex items-center gap-1" aria-label={label}>
            {[1, 2, 3, 4, 5].map((star) => (
                <button
                    key={star}
                    type="button"
                    onClick={() => onChange(star)}
                    className="focus-visible:ring-cyan rounded-md p-0.5 focus-visible:ring-2 focus-visible:outline-none"
                    aria-label={`${star} dari 5`}
                    aria-pressed={star <= value}
                >
                    <Star
                        className={`${large ? 'size-8' : 'size-5'} ${star <= value ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/30'}`}
                    />
                </button>
            ))}
            <span className="text-foreground ml-2 text-sm font-black">
                {value}.0
            </span>
        </div>
    );
}

function AspectRating({
    label,
    value,
    onChange,
}: {
    label: string;
    value: number;
    onChange: (value: number) => void;
}) {
    return (
        <div className="surface-tile rounded-xl p-3">
            <p className="text-muted-foreground text-[11px] font-bold">
                {label}
            </p>
            <StarRatingInput value={value} onChange={onChange} label={label} />
        </div>
    );
}

function ReviewMetric({ label, value }: { label: string; value: number }) {
    return (
        <div className="surface-tile rounded-xl p-3">
            <p className="text-muted-foreground text-[10px] font-black uppercase">
                {label}
            </p>
            <p className="text-foreground mt-1 flex items-center gap-1 font-black">
                <Star className="size-4 fill-amber-400 text-amber-400" />
                {value}.0
            </p>
        </div>
    );
}

function RatingSummary({
    average,
    count,
    compact = false,
}: {
    average: number | null;
    count: number;
    compact?: boolean;
}) {
    return (
        <span
            className={`mt-1 flex items-center gap-1 text-amber-600 ${compact ? 'text-[10px]' : 'text-xs'}`}
        >
            <Star className="size-3.5 shrink-0 fill-current" />
            <span className="font-black">
                {average === null ? 'Baru' : average.toFixed(1)}
            </span>
            <span className="text-ink/35">({count})</span>
        </span>
    );
}

function Info({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof UserRound;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-start gap-3">
            <span className="bg-cyan/10 text-cyan grid size-9 shrink-0 place-items-center rounded-xl">
                <Icon className="size-4" />
            </span>
            <div>
                <p className="text-ink/38 text-xs">{label}</p>
                <p className="text-ink mt-0.5 leading-5 font-bold">{value}</p>
            </div>
        </div>
    );
}

function roleLabelsForChat(role: string): string {
    return (
        {
            operator: 'Superadmin',
            admin: 'Admin',
            supervisor: 'Supervisor',
            technician: 'Teknisi',
            customer: 'Pelanggan',
        }[role] ?? 'Tim AD Com'
    );
}

const SESSION_STATUS_LABELS: Record<
    WorkSessionItem['status'],
    { label: string; className: string }
> = {
    in_progress: {
        label: 'Sedang berjalan',
        className: 'status-pill-success',
    },
    paused: {
        label: 'Dijeda',
        className: 'status-pill-warning',
    },
    completed: {
        label: 'Selesai',
        className: 'border-border bg-muted text-muted-foreground',
    },
};

function WorkSessionsPanel({
    orderId,
    orderStatus,
    sessions,
    canManage,
    assignedTechnicianId,
    technicianTeam,
    currentUserId,
    currentUserRole,
}: {
    orderId: string;
    orderStatus: string;
    sessions: WorkSessionItem[];
    canManage: boolean;
    assignedTechnicianId: number | null;
    technicianTeam: TechnicianSummary[];
    currentUserId: number;
    currentUserRole: string;
}) {
    const startForm = useForm<{ technician_id: number | ''; notes: string }>({
        technician_id:
            currentUserRole === 'technician'
                ? currentUserId
                : (assignedTechnicianId ?? technicianTeam[0]?.id ?? ''),
        notes: '',
    });
    const [actionNotes, setActionNotes] = useState('');
    const [actionProcessing, setActionProcessing] = useState(false);

    const openSession = sessions.find(
        (session) =>
            session.status === 'in_progress' || session.status === 'paused',
    );
    const isTerminal = [
        'completed',
        'warranty',
        'closed',
        'cancelled',
    ].includes(orderStatus);

    const handleStart = (event: FormEvent) => {
        event.preventDefault();
        startForm.post(startWorkSessionRoute.url(orderId), {
            preserveScroll: true,
            onSuccess: () => startForm.setData('notes', ''),
        });
    };

    const handleAction = (
        sessionId: number,
        action: 'pause' | 'resume' | 'complete',
    ) => {
        setActionProcessing(true);
        router.patch(
            updateWorkSessionRoute.url({
                order: orderId,
                workSession: sessionId,
            }),
            {
                action,
                notes: actionNotes.trim() !== '' ? actionNotes.trim() : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => setActionNotes(''),
                onFinish: () => setActionProcessing(false),
            },
        );
    };

    return (
        <section className="liquid-panel rounded-[26px] p-5 sm:p-7">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="section-kicker text-cyan">
                        Work session tracking
                    </p>
                    <h2 className="text-ink mt-1 text-xl font-black">
                        Sesi pengerjaan lapangan
                    </h2>
                    <p className="text-ink/52 mt-1 text-xs">
                        Mencatat durasi kerja aktual teknisi secara terpisah
                        dari penghitungan SLA tiket.
                    </p>
                </div>
                <Clock className="text-cyan size-6 shrink-0" />
            </div>

            {canManage && !isTerminal && (
                <div className="surface-tile mt-5 rounded-2xl p-4">
                    {openSession ? (
                        <div className="space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p className="text-foreground text-sm font-black">
                                        Sesi aktif:{' '}
                                        {openSession.technician ?? 'Teknisi'}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        Mulai {openSession.started_at} · Jeda{' '}
                                        {openSession.paused_minutes} menit
                                    </p>
                                </div>
                                <span
                                    className={`rounded-full border px-2.5 py-1 text-xs font-bold ${SESSION_STATUS_LABELS[openSession.status].className}`}
                                >
                                    {
                                        SESSION_STATUS_LABELS[
                                            openSession.status
                                        ].label
                                    }
                                </span>
                            </div>

                            <input
                                type="text"
                                value={actionNotes}
                                onChange={(e) => setActionNotes(e.target.value)}
                                placeholder="Catatan jeda / lanjutan / penyelesaian sesi (opsional)..."
                                className="liquid-input w-full rounded-xl px-3 py-2 text-xs"
                            />

                            <div className="flex flex-wrap gap-2">
                                {openSession.status === 'in_progress' ? (
                                    <button
                                        type="button"
                                        disabled={actionProcessing}
                                        onClick={() =>
                                            handleAction(
                                                openSession.id,
                                                'pause',
                                            )
                                        }
                                        className="status-pill-warning inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-bold transition hover:opacity-90 disabled:opacity-60"
                                    >
                                        <Pause className="size-3.5" />
                                        Jeda Sesi
                                    </button>
                                ) : (
                                    <button
                                        type="button"
                                        disabled={actionProcessing}
                                        onClick={() =>
                                            handleAction(
                                                openSession.id,
                                                'resume',
                                            )
                                        }
                                        className="status-pill-success inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-bold transition hover:opacity-90 disabled:opacity-60"
                                    >
                                        <Play className="size-3.5" />
                                        Lanjutkan Sesi
                                    </button>
                                )}

                                <button
                                    type="button"
                                    disabled={actionProcessing}
                                    onClick={() =>
                                        handleAction(openSession.id, 'complete')
                                    }
                                    className="bg-primary text-primary-foreground inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold hover:opacity-90 disabled:opacity-60"
                                >
                                    <CheckCircle2 className="size-3.5" />
                                    Selesaikan Sesi
                                </button>
                            </div>
                        </div>
                    ) : (
                        <form onSubmit={handleStart} className="space-y-3">
                            <div className="grid gap-2.5 sm:grid-cols-2">
                                {currentUserRole !== 'technician' &&
                                    technicianTeam.length > 0 && (
                                        <select
                                            value={startForm.data.technician_id}
                                            onChange={(e) =>
                                                startForm.setData(
                                                    'technician_id',
                                                    e.target.value
                                                        ? Number(e.target.value)
                                                        : '',
                                                )
                                            }
                                            className="liquid-input rounded-xl px-3 py-2 text-xs font-semibold"
                                        >
                                            <option value="">
                                                Gunakan PIC (
                                                {assignedTechnicianId ?? '-'})
                                            </option>
                                            {technicianTeam.map((member) => (
                                                <option
                                                    key={member.id}
                                                    value={member.id}
                                                >
                                                    {member.name}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                <input
                                    type="text"
                                    value={startForm.data.notes}
                                    onChange={(e) =>
                                        startForm.setData(
                                            'notes',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Catatan awal pengerjaan (opsional)..."
                                    className="liquid-input w-full rounded-xl px-3 py-2 text-xs sm:col-span-1"
                                />
                            </div>
                            <button
                                type="submit"
                                disabled={startForm.processing}
                                className="bg-primary text-primary-foreground inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold hover:opacity-90 disabled:opacity-60"
                            >
                                <Play className="size-3.5" />
                                Mulai Sesi Pengerjaan
                            </button>
                            <InputError
                                message={
                                    (startForm.errors as Record<string, string>)
                                        .session
                                }
                            />
                        </form>
                    )}
                </div>
            )}

            <div className="mt-4 space-y-2.5">
                {sessions.length === 0 ? (
                    <p className="text-muted-foreground text-xs">
                        Belum ada sesi pengerjaan yang dicatat untuk tiket ini.
                    </p>
                ) : (
                    sessions.map((session) => {
                        const badge = SESSION_STATUS_LABELS[session.status];
                        return (
                            <div
                                key={session.id}
                                className="surface-tile rounded-2xl p-3.5 text-xs"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-foreground font-black">
                                        {session.technician ?? 'Teknisi'}
                                    </p>
                                    <span
                                        className={`rounded-full border px-2 py-0.5 text-[11px] font-bold ${badge.className}`}
                                    >
                                        {badge.label}
                                    </span>
                                </div>
                                <p className="text-muted-foreground mt-1">
                                    Mulai: {session.started_at}
                                    {session.completed_at
                                        ? ` · Selesai: ${session.completed_at}`
                                        : ''}
                                </p>
                                <p className="text-foreground mt-0.5 font-semibold">
                                    Durasi kerja bersih:{' '}
                                    {session.duration_minutes} menit · Jeda:{' '}
                                    {session.paused_minutes} menit
                                </p>
                                {session.notes && (
                                    <p className="text-muted-foreground mt-1 whitespace-pre-line">
                                        {session.notes}
                                    </p>
                                )}
                            </div>
                        );
                    })
                )}
            </div>
        </section>
    );
}

const EVIDENCE_STAGE_LABELS: Record<WorkEvidenceItem['stage'], string> = {
    before: 'Foto Sebelum Kerja',
    after: 'Foto Setelah Kerja',
    completion: 'Catatan Penyelesaian',
};

function WorkEvidencesPanel({
    orderId,
    evidences,
    canUpload,
    canConfirm,
}: {
    orderId: string;
    evidences: WorkEvidenceItem[];
    canUpload: boolean;
    canConfirm: boolean;
}) {
    const form = useForm<{
        stage: 'before' | 'after' | 'completion';
        photo: File | null;
        caption: string;
    }>({
        stage: 'before',
        photo: null,
        caption: '',
    });

    const [confirmingId, setConfirmingId] = useState<number | null>(null);

    const handleUpload = (event: FormEvent) => {
        event.preventDefault();
        form.post(storeWorkEvidence.url(orderId), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset('photo', 'caption');
            },
        });
    };

    const handleConfirm = (evidenceId: number) => {
        setConfirmingId(evidenceId);
        router.patch(
            confirmWorkEvidence.url({
                order: orderId,
                workEvidence: evidenceId,
            }),
            {},
            {
                preserveScroll: true,
                onFinish: () => setConfirmingId(null),
            },
        );
    };

    return (
        <section className="liquid-panel rounded-[26px] p-5 sm:p-7">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="section-kicker text-cyan">
                        Dokumentasi &amp; bukti kerja
                    </p>
                    <h2 className="text-ink mt-1 text-xl font-black">
                        Bukti lapangan (sebelum / sesudah / catatan)
                    </h2>
                </div>
                <Camera className="text-cyan size-6 shrink-0" />
            </div>

            {canUpload && (
                <form
                    onSubmit={handleUpload}
                    className="surface-tile mt-5 space-y-3 rounded-2xl p-4"
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="text-foreground block text-xs font-bold">
                            Tahap dokumentasi
                            <select
                                value={form.data.stage}
                                onChange={(e) =>
                                    form.setData(
                                        'stage',
                                        e.target.value as
                                            | 'before'
                                            | 'after'
                                            | 'completion',
                                    )
                                }
                                className="liquid-input mt-1 w-full rounded-xl px-3 py-2 text-xs"
                            >
                                <option value="before">
                                    Foto Sebelum Kerja
                                </option>
                                <option value="after">
                                    Foto Setelah Kerja
                                </option>
                                <option value="completion">
                                    Catatan Penyelesaian
                                </option>
                            </select>
                        </label>

                        <label className="text-foreground block text-xs font-bold">
                            Foto (JPG/PNG/WEBP, maks 5 MB)
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={(e) =>
                                    form.setData(
                                        'photo',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                                className="text-muted-foreground file:bg-primary file:text-primary-foreground mt-1 block w-full text-xs file:mr-2 file:rounded-lg file:border-0 file:px-3 file:py-1.5 file:text-xs file:font-bold"
                            />
                        </label>
                    </div>

                    <textarea
                        rows={2}
                        value={form.data.caption}
                        onChange={(e) =>
                            form.setData('caption', e.target.value)
                        }
                        placeholder={
                            form.data.stage === 'completion'
                                ? 'Tuliskan ringkasan hasil pengerjaan...'
                                : 'Keterangan foto (opsional)...'
                        }
                        className="liquid-input w-full rounded-xl px-3 py-2 text-xs"
                    />

                    <InputError message={form.errors.photo} />
                    <InputError message={form.errors.caption} />

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="bg-primary text-primary-foreground inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold hover:opacity-90 disabled:opacity-60"
                    >
                        <Camera className="size-3.5" />
                        {form.processing
                            ? 'Mengunggah...'
                            : 'Simpan Bukti Kerja'}
                    </button>
                </form>
            )}

            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                {evidences.length === 0 ? (
                    <p className="text-muted-foreground col-span-full text-xs">
                        Belum ada foto atau catatan penyelesaian yang diunggah.
                    </p>
                ) : (
                    evidences.map((evidence) => (
                        <article
                            key={evidence.id}
                            className="surface-tile flex flex-col justify-between rounded-2xl p-3.5 text-xs"
                        >
                            <div>
                                <div className="flex items-center justify-between gap-2">
                                    <span className="bg-primary text-primary-foreground rounded-full px-2.5 py-0.5 text-[10px] font-bold">
                                        {EVIDENCE_STAGE_LABELS[evidence.stage]}
                                    </span>
                                    <span className="text-muted-foreground text-[11px]">
                                        {evidence.created_at ?? ''}
                                    </span>
                                </div>

                                {evidence.has_file && evidence.file_url && (
                                    <a
                                        href={evidence.file_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="border-border bg-muted mt-2.5 block overflow-hidden rounded-xl border"
                                    >
                                        <img
                                            src={evidence.file_url}
                                            alt={
                                                evidence.caption ??
                                                EVIDENCE_STAGE_LABELS[
                                                    evidence.stage
                                                ]
                                            }
                                            className="h-40 w-full object-cover"
                                        />
                                    </a>
                                )}

                                {evidence.caption && (
                                    <p className="text-foreground mt-2.5">
                                        {evidence.caption}
                                    </p>
                                )}

                                <p className="text-muted-foreground mt-1.5 text-[11px]">
                                    Diunggah oleh{' '}
                                    {evidence.uploaded_by ?? 'Tim'}
                                </p>
                            </div>

                            <div className="border-border mt-3 border-t pt-2.5">
                                {evidence.customer_confirmed_at ? (
                                    <p className="inline-flex items-center gap-1 font-bold text-emerald-700 dark:text-emerald-300">
                                        <CheckCircle2 className="size-3.5" />
                                        Dikonfirmasi pelanggan (
                                        {evidence.customer_confirmed_at})
                                    </p>
                                ) : canConfirm ? (
                                    <button
                                        type="button"
                                        disabled={confirmingId === evidence.id}
                                        onClick={() =>
                                            handleConfirm(evidence.id)
                                        }
                                        className="status-pill-success inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-bold transition hover:opacity-90 disabled:opacity-60"
                                    >
                                        <CheckCircle2 className="size-3.5" />
                                        Konfirmasi Hasil Kerja
                                    </button>
                                ) : (
                                    <p className="text-muted-foreground text-[11px]">
                                        Menunggu konfirmasi pelanggan (opsional)
                                    </p>
                                )}
                            </div>
                        </article>
                    ))
                )}
            </div>
        </section>
    );
}

const SPARE_PART_ORDER_BADGES: Record<
    SparePartUsageItem['status'],
    { label: string; className: string }
> = {
    requested: {
        label: 'Menunggu Gudang',
        className: 'status-pill-warning',
    },
    issued: {
        label: 'Dikeluarkan',
        className: 'status-pill-success',
    },
    partially_returned: {
        label: 'Retur Sebagian',
        className: 'status-pill-info',
    },
    returned: {
        label: 'Diretur Penuh',
        className: 'border-border bg-muted text-muted-foreground',
    },
    cancelled: {
        label: 'Dibatalkan',
        className: 'status-pill-danger',
    },
};

function SparePartsPanel({
    orderId,
    spareParts,
    summary,
    canRequest,
    catalog,
}: {
    orderId: string;
    spareParts: SparePartUsageItem[];
    summary?: {
        internal_cost_total: number | null;
        chargeable_total: number;
        net_units: number;
    };
    canRequest: boolean;
    catalog: InventoryCatalogOption[];
}) {
    const requestForm = useForm({
        inventory_item_id: catalog[0]?.id.toString() ?? '',
        requested_quantity: '1',
        request_notes: '',
    });

    const handleRequest = (event: FormEvent) => {
        event.preventDefault();
        requestForm.post(storeOrderSparePart.url(orderId), {
            preserveScroll: true,
            onSuccess: () =>
                requestForm.reset('requested_quantity', 'request_notes'),
        });
    };

    return (
        <section className="liquid-panel rounded-[26px] p-5 sm:p-7">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="section-kicker text-cyan">
                        Spare-part &amp; komponen
                    </p>
                    <h2 className="text-ink mt-1 text-xl font-black">
                        Permintaan &amp; Pemakaian Spare-Part
                    </h2>
                    <p className="text-ink/52 mt-1 text-xs">
                        Permintaan oleh teknisi tidak langsung memotong stok
                        gudang sebelum disetujui dan dikeluarkan oleh bagian
                        gudang.
                    </p>
                </div>
                <Wrench className="text-cyan size-6 shrink-0" />
            </div>

            {summary && (
                <div className="mt-4 grid gap-2.5 sm:grid-cols-3">
                    <div className="surface-tile rounded-2xl p-3 text-xs">
                        <p className="text-muted-foreground font-bold">
                            Total unit bersih
                        </p>
                        <p className="text-foreground mt-1 text-base font-black">
                            {summary.net_units} unit
                        </p>
                    </div>
                    <div className="surface-tile rounded-2xl p-3 text-xs">
                        <p className="text-muted-foreground font-bold">
                            Total nilai spare-part
                        </p>
                        <p className="text-foreground mt-1 text-base font-black">
                            {money.format(summary.chargeable_total)}
                        </p>
                    </div>
                    {summary.internal_cost_total !== null && (
                        <div className="surface-tile rounded-2xl p-3 text-xs">
                            <p className="text-muted-foreground font-bold">
                                Modal HPP internal
                            </p>
                            <p className="text-foreground mt-1 text-base font-black">
                                {money.format(summary.internal_cost_total)}
                            </p>
                        </div>
                    )}
                </div>
            )}

            {canRequest && catalog.length > 0 && (
                <form
                    onSubmit={handleRequest}
                    className="surface-tile mt-5 space-y-3 rounded-2xl p-4"
                >
                    <p className="text-foreground text-xs font-black">
                        Ajukan Permintaan Spare-Part
                    </p>
                    <div className="grid gap-2.5 sm:grid-cols-[1fr_110px]">
                        <select
                            value={requestForm.data.inventory_item_id}
                            onChange={(e) =>
                                requestForm.setData(
                                    'inventory_item_id',
                                    e.target.value,
                                )
                            }
                            className="liquid-input h-10 rounded-xl px-3 text-xs font-semibold"
                        >
                            {catalog.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.sku} · {item.name} (Stok{' '}
                                    {item.available_stock} {item.unit})
                                </option>
                            ))}
                        </select>
                        <input
                            type="number"
                            min={1}
                            value={requestForm.data.requested_quantity}
                            onChange={(e) =>
                                requestForm.setData(
                                    'requested_quantity',
                                    e.target.value,
                                )
                            }
                            className="liquid-input h-10 rounded-xl px-3 text-xs font-bold"
                            aria-label="Jumlah spare-part"
                        />
                    </div>
                    <input
                        type="text"
                        value={requestForm.data.request_notes}
                        onChange={(e) =>
                            requestForm.setData('request_notes', e.target.value)
                        }
                        placeholder="Keperluan / alasan penggunaan spare-part (opsional)..."
                        className="liquid-input w-full rounded-xl px-3 py-2 text-xs"
                    />
                    <InputError
                        message={requestForm.errors.inventory_item_id}
                    />
                    <InputError
                        message={
                            (requestForm.errors as Record<string, string>)
                                .requested_quantity ??
                            (requestForm.errors as Record<string, string>)
                                .spare_part
                        }
                    />
                    <button
                        type="submit"
                        disabled={requestForm.processing}
                        className="bg-primary text-primary-foreground inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold hover:opacity-90 disabled:opacity-60"
                    >
                        <Wrench className="size-3.5" />
                        Ajukan Permintaan
                    </button>
                </form>
            )}

            <div className="mt-4 space-y-2.5">
                {spareParts.length === 0 ? (
                    <p className="text-muted-foreground text-xs">
                        Belum ada spare-part yang diminta atau dipakai pada
                        tiket ini.
                    </p>
                ) : (
                    spareParts.map((sp) => (
                        <SparePartOrderRow
                            key={sp.id}
                            orderId={orderId}
                            item={sp}
                        />
                    ))
                )}
            </div>
        </section>
    );
}

function SparePartOrderRow({
    orderId,
    item,
}: {
    orderId: string;
    item: SparePartUsageItem;
}) {
    const [qty, setQty] = useState(
        String(
            item.can_issue ? item.requested_quantity : item.net_issued_quantity,
        ),
    );
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const badge = SPARE_PART_ORDER_BADGES[item.status];

    const submitAction = (mode: 'issue' | 'return' | 'cancel') => {
        setBusy(true);
        if (mode === 'issue') {
            router.patch(
                issueOrderSparePart.url({ order: orderId, sparePart: item.id }),
                {
                    issued_quantity: Number(qty),
                    issue_notes: note.trim() !== '' ? note.trim() : null,
                },
                {
                    preserveScroll: true,
                    onSuccess: () => setNote(''),
                    onFinish: () => setBusy(false),
                },
            );
            return;
        }
        if (mode === 'return') {
            router.patch(
                returnOrderSparePart.url({
                    order: orderId,
                    sparePart: item.id,
                }),
                {
                    return_quantity: Number(qty),
                    return_notes: note.trim() !== '' ? note.trim() : null,
                },
                {
                    preserveScroll: true,
                    onSuccess: () => setNote(''),
                    onFinish: () => setBusy(false),
                },
            );
            return;
        }
        router.patch(
            cancelOrderSparePart.url({ order: orderId, sparePart: item.id }),
            {
                cancellation_reason: note.trim() !== '' ? note.trim() : null,
            },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <div className="surface-tile rounded-2xl p-3.5 text-xs">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-foreground font-black">
                    <span className="text-cyan mr-1.5 font-mono text-[10px]">
                        {item.sku}
                    </span>
                    {item.name}
                </p>
                <span
                    className={`rounded-full border px-2 py-0.5 text-[11px] font-bold ${badge.className}`}
                >
                    {badge.label}
                </span>
            </div>
            <p className="text-muted-foreground mt-1">
                Diminta: {item.requested_quantity} {item.unit} · Dikeluarkan:{' '}
                {item.issued_quantity} · Diretur: {item.returned_quantity} ·
                Bersih: {item.net_issued_quantity} {item.unit}
            </p>
            <p className="text-foreground mt-0.5 font-semibold">
                Harga/unit: {money.format(item.unit_price)} · Subtotal:{' '}
                {money.format(item.line_total)}
                {item.unit_cost !== null
                    ? ` (Modal: ${money.format(item.unit_cost)})`
                    : ''}
            </p>
            {item.requester && (
                <p className="text-muted-foreground mt-0.5 text-[11px]">
                    Pemohon: {item.requester} ({item.requested_at})
                    {item.approver ? ` · Disetujui: ${item.approver}` : ''}
                </p>
            )}
            {item.request_notes && (
                <p className="text-muted-foreground mt-1">
                    {item.request_notes}
                </p>
            )}

            {(item.can_issue || item.can_return || item.can_cancel) && (
                <div className="border-border mt-2.5 flex flex-wrap items-center gap-2 border-t pt-2.5">
                    {(item.can_issue || item.can_return) && (
                        <>
                            <input
                                type="number"
                                min={1}
                                max={
                                    item.can_issue
                                        ? item.requested_quantity
                                        : item.net_issued_quantity
                                }
                                value={qty}
                                onChange={(e) => setQty(e.target.value)}
                                className="liquid-input h-8 w-20 rounded-lg px-2 text-xs font-bold"
                            />
                            <input
                                type="text"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder="Catatan gudang (opsional)"
                                className="liquid-input h-8 min-w-36 flex-1 rounded-lg px-2.5 text-xs"
                            />
                        </>
                    )}
                    {item.can_issue && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => submitAction('issue')}
                            className="bg-primary text-primary-foreground rounded-lg px-3 py-1.5 font-bold hover:opacity-90 disabled:opacity-50"
                        >
                            Setujui &amp; Keluarkan
                        </button>
                    )}
                    {item.can_return && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => submitAction('return')}
                            className="status-pill-info rounded-lg px-3 py-1.5 font-bold transition hover:opacity-90 disabled:opacity-50"
                        >
                            Catat Retur
                        </button>
                    )}
                    {item.can_cancel && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => submitAction('cancel')}
                            className="status-pill-danger rounded-lg px-3 py-1.5 font-bold transition hover:opacity-90 disabled:opacity-50"
                        >
                            Batalkan
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

OrderShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Detail tiket', href: '#' },
    ],
};

function OrderDevicePanel({
    orderId,
    device,
    customerDevices,
    canManage,
    isInternal,
}: {
    orderId: string;
    device: OrderDeviceSummary | null;
    customerDevices: CustomerDeviceOption[];
    canManage: boolean;
    isInternal: boolean;
}) {
    const [showLinkForm, setShowLinkForm] = useState(false);
    const linkForm = useForm({
        mode: (customerDevices.length > 0 ? 'existing' : 'create') as
            | 'existing'
            | 'create',
        customer_asset_id: device?.id ?? customerDevices[0]?.id ?? '',
        name: '',
        category: 'Laptop / PC',
        brand: '',
        model: '',
        serial_number: '',
        location: '',
        diagnosis: '',
        action_taken: '',
        test_result: '',
        recommendation: '',
    });

    function submitLink(event: FormEvent) {
        event.preventDefault();
        linkForm.post(linkOrderDevice.url(orderId), {
            preserveScroll: true,
            onSuccess: () => setShowLinkForm(false),
        });
    }

    return (
        <section className="liquid-panel space-y-4 rounded-[26px] p-5 sm:p-7">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="section-kicker text-crimson">
                        Perangkat Terkait
                    </p>
                    <h2 className="text-ink mt-1 text-xl font-black">
                        {device
                            ? `${device.name} (${device.device_code ?? 'DEV'})`
                            : 'Belum tertaut ke perangkat'}
                    </h2>
                </div>
                <div className="flex flex-wrap gap-2">
                    {device && (
                        <Link
                            href={showDevice.url(device.id)}
                            className="liquid-control text-crimson inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-xs font-black hover:bg-white"
                        >
                            <Laptop className="size-3.5" />
                            Buka Riwayat Perangkat
                        </Link>
                    )}
                    {canManage && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setShowLinkForm((v) => !v)}
                        >
                            {showLinkForm
                                ? 'Tutup'
                                : device
                                  ? 'Perbarui tautan / catatan servis'
                                  : 'Tautkan perangkat'}
                        </Button>
                    )}
                </div>
            </div>

            {device && (
                <div className="surface-tile rounded-2xl p-4 text-xs">
                    <p className="text-foreground font-bold">
                        {device.category}
                        {device.brand ? ` · ${device.brand}` : ''}
                        {device.model ? ` ${device.model}` : ''}
                        {device.serial_masked
                            ? ` · SN ${device.serial_masked}`
                            : ''}
                    </p>
                    {device.location && (
                        <p className="text-muted-foreground mt-1">
                            Lokasi: {device.location}
                        </p>
                    )}
                </div>
            )}

            {showLinkForm && canManage && (
                <form
                    onSubmit={submitLink}
                    className="surface-tile space-y-3 rounded-2xl p-4 text-xs"
                >
                    <div className="flex flex-wrap gap-2">
                        {customerDevices.length > 0 && (
                            <button
                                type="button"
                                onClick={() =>
                                    linkForm.setData('mode', 'existing')
                                }
                                className={`rounded-lg px-3 py-1 font-bold ${linkForm.data.mode === 'existing' ? 'bg-primary text-primary-foreground' : 'border-border bg-background text-muted-foreground border'}`}
                            >
                                Pilih perangkat terdaftar
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => linkForm.setData('mode', 'create')}
                            className={`rounded-lg px-3 py-1 font-bold ${linkForm.data.mode === 'create' ? 'bg-primary text-primary-foreground' : 'border-border bg-background text-muted-foreground border'}`}
                        >
                            Daftarkan perangkat baru
                        </button>
                    </div>

                    {linkForm.data.mode === 'existing' &&
                    customerDevices.length > 0 ? (
                        <label className="block space-y-1 font-bold">
                            <span>Perangkat pelanggan</span>
                            <select
                                className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                value={linkForm.data.customer_asset_id}
                                onChange={(e) =>
                                    linkForm.setData(
                                        'customer_asset_id',
                                        e.target.value,
                                    )
                                }
                            >
                                {customerDevices.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name} ({d.device_code ?? d.category}
                                        {d.serial_masked
                                            ? ` · ${d.serial_masked}`
                                            : ''}
                                        )
                                    </option>
                                ))}
                            </select>
                            <InputError
                                message={linkForm.errors.customer_asset_id}
                            />
                        </label>
                    ) : (
                        <div className="grid gap-2.5 sm:grid-cols-2">
                            <input
                                type="text"
                                className="liquid-input h-9 rounded-xl px-3 text-xs"
                                placeholder="Nama perangkat *"
                                value={linkForm.data.name}
                                onChange={(e) =>
                                    linkForm.setData('name', e.target.value)
                                }
                                required
                            />
                            <input
                                type="text"
                                className="liquid-input h-9 rounded-xl px-3 text-xs"
                                placeholder="Kategori *"
                                value={linkForm.data.category}
                                onChange={(e) =>
                                    linkForm.setData('category', e.target.value)
                                }
                                required
                            />
                            <input
                                type="text"
                                className="liquid-input h-9 rounded-xl px-3 text-xs"
                                placeholder="Merek"
                                value={linkForm.data.brand}
                                onChange={(e) =>
                                    linkForm.setData('brand', e.target.value)
                                }
                            />
                            <input
                                type="text"
                                className="liquid-input h-9 rounded-xl px-3 text-xs"
                                placeholder="Nomor seri / ID unit"
                                value={linkForm.data.serial_number}
                                onChange={(e) =>
                                    linkForm.setData(
                                        'serial_number',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                    )}

                    {isInternal && (
                        <div className="grid gap-2 sm:grid-cols-2">
                            <input
                                type="text"
                                className="liquid-input h-9 rounded-xl px-3 text-xs"
                                placeholder="Ringkasan diagnosis teknis (opsional)"
                                value={linkForm.data.diagnosis}
                                onChange={(e) =>
                                    linkForm.setData(
                                        'diagnosis',
                                        e.target.value,
                                    )
                                }
                            />
                            <input
                                type="text"
                                className="liquid-input h-9 rounded-xl px-3 text-xs"
                                placeholder="Tindakan perbaikan / rekomendasi"
                                value={linkForm.data.action_taken}
                                onChange={(e) =>
                                    linkForm.setData(
                                        'action_taken',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                    )}

                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            size="sm"
                            disabled={linkForm.processing}
                        >
                            Simpan tautan perangkat
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}

function OrderWarrantiesPanel({
    orderId,
    deviceId,
    warranties,
    spareParts,
    serviceWarrantyDays,
    canManage,
    isInternal,
}: {
    orderId: string;
    deviceId: string | null;
    warranties: OrderWarrantyItem[];
    spareParts: OrderSparePartExtended[];
    serviceWarrantyDays: number;
    canManage: boolean;
    isInternal: boolean;
}) {
    const [showCreateForm, setShowCreateForm] = useState(false);
    const [claimWarrantyId, setClaimWarrantyId] = useState<number | null>(null);
    const [voidWarrantyId, setVoidWarrantyId] = useState<number | null>(null);
    const [reviewClaimId, setReviewClaimId] = useState<number | null>(null);

    const eligibleParts = spareParts.filter(
        (sp) => sp.net_issued_quantity > 0 && !sp.has_warranty,
    );

    const warrantyForm = useForm({
        scope_type: 'service' as 'service' | 'spare_part',
        service_order_spare_part_id: eligibleParts[0]?.id
            ? String(eligibleParts[0].id)
            : '',
        customer_asset_id: deviceId ?? '',
        duration_days:
            serviceWarrantyDays > 0 ? String(serviceWarrantyDays) : '14',
        coverage_description: '',
        reference_number: '',
    });

    const claimForm = useForm({ issue_description: '' });
    const voidForm = useForm({ void_reason: '' });
    const reviewForm = useForm({
        status: 'approved' as 'under_review' | 'approved' | 'rejected',
        review_notes: '',
        create_service_order: true,
    });

    function submitWarranty(event: FormEvent) {
        event.preventDefault();
        warrantyForm.post(storeOrderWarranty.url(orderId), {
            preserveScroll: true,
            onSuccess: () => setShowCreateForm(false),
        });
    }

    function submitClaim(event: FormEvent, warrantyId: number) {
        event.preventDefault();
        claimForm.post(submitWarrantyClaimRoute.url(warrantyId), {
            preserveScroll: true,
            onSuccess: () => {
                claimForm.reset();
                setClaimWarrantyId(null);
            },
        });
    }

    function submitVoid(event: FormEvent, warrantyId: number) {
        event.preventDefault();
        voidForm.patch(voidOrderWarranty.url(warrantyId), {
            preserveScroll: true,
            onSuccess: () => {
                voidForm.reset();
                setVoidWarrantyId(null);
            },
        });
    }

    function submitReview(event: FormEvent, claimId: number) {
        event.preventDefault();
        reviewForm.patch(reviewWarrantyClaimRoute.url(claimId), {
            preserveScroll: true,
            onSuccess: () => {
                reviewForm.reset();
                setReviewClaimId(null);
            },
        });
    }

    return (
        <section className="liquid-panel space-y-4 rounded-[26px] p-5 sm:p-7">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="section-kicker text-emerald-700">
                        Garansi &amp; Klaim
                    </p>
                    <h2 className="text-ink mt-1 text-xl font-black">
                        Cakupan Garansi Tiket
                    </h2>
                </div>
                <div className="flex items-center gap-2">
                    <ShieldCheck className="size-5 text-emerald-700" />
                    {canManage && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setShowCreateForm((v) => !v)}
                        >
                            {showCreateForm ? 'Tutup form' : 'Aktifkan garansi'}
                        </Button>
                    )}
                </div>
            </div>

            {showCreateForm && canManage && (
                <form
                    onSubmit={submitWarranty}
                    className="surface-tile space-y-3 rounded-2xl p-4 text-xs"
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="space-y-1 font-bold">
                            <span>Cakupan garansi</span>
                            <select
                                className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                value={warrantyForm.data.scope_type}
                                onChange={(e) => {
                                    const nextScope = e.target.value as
                                        | 'service'
                                        | 'spare_part';
                                    warrantyForm.setData(
                                        'scope_type',
                                        nextScope,
                                    );
                                }}
                            >
                                <option value="service">
                                    Garansi Pekerjaan Layanan
                                </option>
                                {eligibleParts.length > 0 && (
                                    <option value="spare_part">
                                        Garansi Spare-Part Terpasang
                                    </option>
                                )}
                            </select>
                        </label>

                        {warrantyForm.data.scope_type === 'spare_part' &&
                        eligibleParts.length > 0 ? (
                            <label className="space-y-1 font-bold">
                                <span>Pilih spare-part terpasang</span>
                                <select
                                    className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                    value={
                                        warrantyForm.data
                                            .service_order_spare_part_id
                                    }
                                    onChange={(e) => {
                                        warrantyForm.setData(
                                            'service_order_spare_part_id',
                                            e.target.value,
                                        );
                                        const chosen = eligibleParts.find(
                                            (p) =>
                                                String(p.id) === e.target.value,
                                        );
                                        if (chosen?.warranty_days) {
                                            warrantyForm.setData(
                                                'duration_days',
                                                String(chosen.warranty_days),
                                            );
                                        }
                                    }}
                                >
                                    {eligibleParts.map((sp) => (
                                        <option key={sp.id} value={sp.id}>
                                            {sp.name} ({sp.net_issued_quantity}{' '}
                                            {sp.unit})
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ) : (
                            <label className="space-y-1 font-bold">
                                <span>Nomor referensi garansi (opsional)</span>
                                <input
                                    type="text"
                                    className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                    placeholder="Kartu garansi / faktur"
                                    value={warrantyForm.data.reference_number}
                                    onChange={(e) =>
                                        warrantyForm.setData(
                                            'reference_number',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                        )}

                        <label className="space-y-1 font-bold">
                            <span>Durasi (hari)</span>
                            <input
                                type="number"
                                min="1"
                                max="1825"
                                className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                value={warrantyForm.data.duration_days}
                                onChange={(e) =>
                                    warrantyForm.setData(
                                        'duration_days',
                                        e.target.value,
                                    )
                                }
                                required
                            />
                            <InputError
                                message={warrantyForm.errors.duration_days}
                            />
                        </label>

                        <label className="space-y-1 font-bold">
                            <span>Deskripsi cakupan</span>
                            <input
                                type="text"
                                className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                placeholder="Syarat & cakupan garansi"
                                value={warrantyForm.data.coverage_description}
                                onChange={(e) =>
                                    warrantyForm.setData(
                                        'coverage_description',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                    </div>
                    <InputError message={warrantyForm.errors.scope_type} />
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            size="sm"
                            disabled={warrantyForm.processing}
                        >
                            Simpan &amp; Aktifkan
                        </Button>
                    </div>
                </form>
            )}

            {warranties.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    Belum ada garansi layanan atau spare-part yang diaktifkan
                    untuk tiket ini.
                </p>
            ) : (
                <div className="space-y-3">
                    {warranties.map((warranty) => (
                        <article
                            key={warranty.id}
                            className="surface-tile space-y-2.5 rounded-2xl p-4 text-xs"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-mono font-black">
                                            {warranty.warranty_number}
                                        </span>
                                        <span className="bg-muted text-muted-foreground rounded px-2 py-0.5 text-[10px] font-bold uppercase">
                                            {warranty.scope_type ===
                                            'spare_part'
                                                ? 'Spare-Part'
                                                : 'Layanan'}
                                        </span>
                                        <span className="status-pill-success rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase">
                                            {warranty.status}
                                        </span>
                                    </div>
                                    <p className="text-foreground mt-1 font-bold">
                                        {warranty.item_label}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {warranty.duration_days} hari · Mulai{' '}
                                        {warranty.starts_at ?? '—'} · Berakhir{' '}
                                        {warranty.expires_at ?? '—'}
                                    </p>
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    {warranty.can_submit_claim && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() =>
                                                setClaimWarrantyId(
                                                    claimWarrantyId ===
                                                        warranty.id
                                                        ? null
                                                        : warranty.id,
                                                )
                                            }
                                        >
                                            Ajukan Klaim
                                        </Button>
                                    )}
                                    {warranty.can_void && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setVoidWarrantyId(
                                                    voidWarrantyId ===
                                                        warranty.id
                                                        ? null
                                                        : warranty.id,
                                                )
                                            }
                                        >
                                            Void
                                        </Button>
                                    )}
                                </div>
                            </div>

                            {warranty.coverage_description && (
                                <p className="text-muted-foreground">
                                    {warranty.coverage_description}
                                </p>
                            )}

                            {claimWarrantyId === warranty.id && (
                                <form
                                    onSubmit={(e) =>
                                        submitClaim(e, warranty.id)
                                    }
                                    className="border-border space-y-2 border-t pt-2.5"
                                >
                                    <textarea
                                        rows={2}
                                        className="liquid-input w-full rounded-xl p-2.5 text-xs"
                                        placeholder="Jelaskan kendala garansi yang ingin diklaim…"
                                        value={claimForm.data.issue_description}
                                        onChange={(e) =>
                                            claimForm.setData(
                                                'issue_description',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <InputError
                                        message={
                                            claimForm.errors.issue_description
                                        }
                                    />
                                    <div className="flex justify-end">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={claimForm.processing}
                                        >
                                            Kirim Pengajuan Klaim
                                        </Button>
                                    </div>
                                </form>
                            )}

                            {voidWarrantyId === warranty.id && (
                                <form
                                    onSubmit={(e) => submitVoid(e, warranty.id)}
                                    className="border-border space-y-2 border-t pt-2.5"
                                >
                                    <input
                                        type="text"
                                        className="liquid-input h-9 w-full rounded-xl px-3 text-xs"
                                        placeholder="Alasan pembatalan garansi…"
                                        value={voidForm.data.void_reason}
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
                                        disabled={voidForm.processing}
                                    >
                                        Konfirmasi Void
                                    </Button>
                                </form>
                            )}

                            {warranty.claims.length > 0 && (
                                <div className="border-border space-y-2 border-t pt-2.5">
                                    {warranty.claims.map((claim) => (
                                        <div
                                            key={claim.id}
                                            className="surface-tile rounded-xl p-3"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="font-bold">
                                                    {claim.claim_number}
                                                </span>
                                                <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-[10px] font-black uppercase">
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
                                                        className="text-crimson mt-1 inline-block font-bold hover:underline"
                                                    >
                                                        Tiket tindak lanjut:{' '}
                                                        {claim.claim_ticket}
                                                    </Link>
                                                )}
                                            {isInternal && claim.can_review && (
                                                <div className="mt-2">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setReviewClaimId(
                                                                reviewClaimId ===
                                                                    claim.id
                                                                    ? null
                                                                    : claim.id,
                                                            )
                                                        }
                                                    >
                                                        Tinjau klaim
                                                    </Button>
                                                </div>
                                            )}
                                            {isInternal &&
                                                claim.can_resolve && (
                                                    <div className="mt-2">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.patch(
                                                                    resolveWarrantyClaimRoute.url(
                                                                        claim.id,
                                                                    ),
                                                                    {
                                                                        resolution_notes:
                                                                            'Penanganan klaim garansi selesai diverifikasi.',
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Selesaikan Klaim
                                                        </Button>
                                                    </div>
                                                )}
                                            {reviewClaimId === claim.id && (
                                                <form
                                                    onSubmit={(e) =>
                                                        submitReview(
                                                            e,
                                                            claim.id,
                                                        )
                                                    }
                                                    className="mt-2 space-y-2 border-t pt-2"
                                                >
                                                    <select
                                                        className="liquid-input h-8 w-full rounded-lg px-2.5 text-xs font-bold"
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
                                                            Setujui Klaim
                                                        </option>
                                                        <option value="rejected">
                                                            Tolak Klaim
                                                        </option>
                                                    </select>
                                                    <input
                                                        type="text"
                                                        className="liquid-input h-8 w-full rounded-lg px-2.5 text-xs"
                                                        placeholder="Catatan keputusan tinjauan…"
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
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={
                                                            reviewForm.processing
                                                        }
                                                    >
                                                        Simpan Keputusan
                                                    </Button>
                                                </form>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
}
