import { Link, useHttp, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    BarChart3,
    Bot,
    Camera,
    CheckCircle2,
    Clock3,
    Computer,
    Database,
    LoaderCircle,
    MessageCircleQuestion,
    Network,
    Package,
    RefreshCcw,
    Send,
    ShieldCheck,
    Sparkles,
    TrendingUp,
    Wallet,
    Wrench,
    X,
    XCircle,
} from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import {
    type FormEvent,
    type KeyboardEvent,
    useEffect,
    useRef,
    useState,
} from 'react';
import { SPRING, VARIANTS } from '@/lib/motion';
import { usePointerLight } from '@/hooks/use-pointer-light';
import { store as sendAssistantMessage } from '@/routes/assistant/messages';

type AssistantMode = 'ai' | 'guide';

type AssistantService = {
    code: string;
    name: string;
    summary: string;
    pricing_label: string;
    booking_url: string;
};

type AssistantAction = {
    label: string;
    href: string;
    kind: 'primary' | 'secondary';
};

export type AssistantEvidenceFact = {
    label: string;
    value: string;
};

export type AssistantEvidenceInsight = {
    id?: string;
    title: string;
    category: string;
    severity: 'critical' | 'warning' | 'info';
    description: string;
    recommended_action: string;
};

export type AssistantEvidence = {
    verified?: boolean;
    source?: string;
    period_label?: string | null;
    generated_at?: string;
    data_insufficient?: boolean;
    facts?: AssistantEvidenceFact[];
    insights?: AssistantEvidenceInsight[];
    recommendations?: string[];
};

export type AssistantContextMeta = {
    role: string;
    period_label: string;
    generated_at: string;
    analysis_source: string;
};

type AssistantPageContext = {
    route?: string | null;
    order_id?: string | null;
    period?: string | null;
    section?: string | null;
    start_date?: string | null;
    end_date?: string | null;
};

export type AssistantActionPreviewChange = {
    label: string;
    before?: string | null;
    after: string;
};

export type AssistantActionFinancialImpact = {
    label: string;
    amount: number | null;
    formatted: string | null;
    breakdown?: { label: string; value: string }[];
};

export type AssistantActionProposal = {
    action_id: string;
    action_name: string;
    action_label: string;
    status:
        | 'requested'
        | 'validated'
        | 'previewed'
        | 'awaiting_confirmation'
        | 'executed'
        | 'cancelled'
        | 'expired'
        | 'denied'
        | 'failed';
    payload_hash: string;
    expires_at: string;
    ttl_seconds: number;
    preview?: {
        title: string;
        target: string;
        proposed_changes: AssistantActionPreviewChange[];
        financial_impact?: AssistantActionFinancialImpact | null;
        warnings?: string[];
        summary_text?: string;
    } | null;
    result_reference?: string | null;
    result_summary?: Record<string, unknown> | null;
    failure_reason?: string | null;
    confirm_url: string;
    cancel_url: string;
};

export type AssistantResponse = {
    reply: string;
    mode: AssistantMode;
    suggestions: string[];
    services: AssistantService[];
    actions: AssistantAction[];
    evidence?: AssistantEvidence | null;
    action_proposal?: AssistantActionProposal | null;
    context_meta?: AssistantContextMeta | null;
};

type AssistantRequest = {
    message: string;
    messages: Array<{
        role: 'user' | 'assistant';
        content: string;
    }>;
    page_context?: AssistantPageContext;
};

type ChatMessage = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    result?: AssistantResponse;
};

const VALID_ANALYTICS_PERIODS = new Set([
    'daily',
    'weekly',
    'monthly',
    'quarterly',
    'yearly',
    'custom',
]);

const VALID_ANALYTICS_SECTIONS = new Set([
    'overview',
    'financial',
    'services',
    'workforce',
    'inventory',
    'customers',
    'insights',
]);

export function extractPageContext(): AssistantPageContext | undefined {
    if (typeof window === 'undefined') {
        return undefined;
    }

    const pathname = window.location.pathname || '/';
    const params = new URLSearchParams(window.location.search);
    const context: AssistantPageContext = {
        route: pathname.slice(0, 120),
    };

    const period = params.get('period');
    if (period && VALID_ANALYTICS_PERIODS.has(period)) {
        context.period = period;
    }

    const section = params.get('section');
    if (section && VALID_ANALYTICS_SECTIONS.has(section)) {
        context.section = section;
    }

    const startDate = params.get('start_date');
    if (startDate && /^\d{4}-\d{2}-\d{2}$/.test(startDate)) {
        context.start_date = startDate;
    }

    const endDate = params.get('end_date');
    if (endDate && /^\d{4}-\d{2}-\d{2}$/.test(endDate)) {
        context.end_date = endDate;
    }

    const orderMatch = pathname.match(/\/orders\/([0-9a-zA-Z-]{1,36})/);
    if (orderMatch?.[1]) {
        context.order_id = orderMatch[1];
    }

    return context;
}

const guestStarterSuggestions = [
    {
        label: 'Wi-Fi sering putus',
        prompt: 'Wi-Fi di rumah sering putus, apa yang perlu dicek?',
        icon: Network,
    },
    {
        label: 'Mau pasang CCTV',
        prompt: 'Saya ingin pasang CCTV di rumah. Apa yang perlu disiapkan?',
        icon: Camera,
    },
    {
        label: 'Komputer lemot',
        prompt: 'Komputer saya lemot dan sering hang. Bisa bantu diagnosis awal?',
        icon: Computer,
    },
];

export function roleStarterSuggestions(role: string | null | undefined) {
    switch (role) {
        case 'operator':
        case 'supervisor':
            return [
                {
                    label: 'Performa 90 hari',
                    prompt: 'Bagaimana performa bisnis dalam 90 hari terakhir?',
                    icon: BarChart3,
                },
                {
                    label: 'Layanan paling profit',
                    prompt: 'Layanan mana yang paling menguntungkan?',
                    icon: TrendingUp,
                },
                {
                    label: 'Alert stok & SLA',
                    prompt: 'Apa insight prioritas dan order yang berisiko melanggar SLA?',
                    icon: AlertTriangle,
                },
            ];
        case 'admin':
            return [
                {
                    label: 'Piutang & overdue',
                    prompt: 'Berapa piutang yang belum dibayar dan invoice overdue?',
                    icon: Wallet,
                },
                {
                    label: 'Profitabilitas layanan',
                    prompt: 'Layanan mana yang paling menguntungkan?',
                    icon: TrendingUp,
                },
                {
                    label: 'Order risiko SLA',
                    prompt: 'Order mana yang berisiko melanggar SLA?',
                    icon: AlertTriangle,
                },
            ];
        case 'technician':
            return [
                {
                    label: 'Beban tugas saya',
                    prompt: 'Ringkas beban tugas dan order aktif saya hari ini',
                    icon: Wrench,
                },
                {
                    label: 'Risiko SLA order',
                    prompt: 'Order mana yang berisiko melanggar SLA?',
                    icon: AlertTriangle,
                },
                {
                    label: 'Kepatuhan SLA',
                    prompt: 'Bagaimana status SLA pekerjaan saat ini?',
                    icon: Activity,
                },
            ];
        case 'warehouse':
            return [
                {
                    label: 'Perlu restock',
                    prompt: 'Barang inventaris apa yang perlu segera restock?',
                    icon: Package,
                },
                {
                    label: 'Cek dead stock',
                    prompt: 'Stok mana yang dead stock dan tidak bergerak?',
                    icon: AlertTriangle,
                },
                {
                    label: 'Turnover stok',
                    prompt: 'Ringkas pergerakan stok 30 hari terakhir',
                    icon: Activity,
                },
            ];
        case 'customer':
            return [
                {
                    label: 'Pesanan & garansi',
                    prompt: 'Bagaimana status pesanan dan garansi saya?',
                    icon: ShieldCheck,
                },
                {
                    label: 'Wi-Fi sering putus',
                    prompt: 'Wi-Fi di rumah sering putus, apa yang perlu dicek?',
                    icon: Network,
                },
                {
                    label: 'Servis komputer',
                    prompt: 'Komputer saya lemot dan sering hang. Bisa bantu diagnosis awal?',
                    icon: Computer,
                },
            ];
        default:
            return guestStarterSuggestions;
    }
}

const welcomeMessage: ChatMessage = {
    id: 'welcome',
    role: 'assistant',
    content:
        'Halo, aku ADI 👋 Ajukan pertanyaan seputar layanan, kendala perangkat, atau insight operasional sesuai peran akunmu.',
};

function createMessage(
    role: ChatMessage['role'],
    content: string,
    result?: AssistantResponse,
): ChatMessage {
    return {
        id: `${role}-${Date.now()}-${Math.random().toString(36).slice(2)}`,
        role,
        content,
        result,
    };
}

function sourceBadgeLabel(result: AssistantResponse): {
    label: string;
    tone: 'verified' | 'ai' | 'guide';
} {
    const analysisSource = result.context_meta?.analysis_source;

    if (analysisSource === 'verified_tool_plus_ai') {
        return {
            label: 'Data Terverifikasi + Analisis AI',
            tone: 'verified',
        };
    }

    if (analysisSource === 'verified_tool' || result.evidence?.verified) {
        return {
            label: 'Data Aplikasi Terverifikasi',
            tone: 'verified',
        };
    }

    if (result.mode === 'ai') {
        return {
            label: 'Penjelasan AI (Tergrounded)',
            tone: 'ai',
        };
    }

    return {
        label: 'Panduan Layanan Terstruktur',
        tone: 'guide',
    };
}

function insightToneClasses(severity: string): string {
    switch (severity) {
        case 'critical':
            return 'border-red-500/25 bg-red-500/8 text-red-900 dark:text-red-200';
        case 'warning':
            return 'border-amber-500/25 bg-amber-500/8 text-amber-900 dark:text-amber-200';
        default:
            return 'border-sky-500/20 bg-sky-500/8 text-sky-900 dark:text-sky-200';
    }
}

function readCsrfHeaders(): Record<string, string> {
    if (typeof document === 'undefined') {
        return {};
    }

    const xsrfCookie = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    if (xsrfCookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie) };
    }

    const metaToken = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.getAttribute('content');

    return metaToken ? { 'X-CSRF-TOKEN': metaToken } : {};
}

function formatExpiryLabel(iso: string): string {
    const parsed = new Date(iso);
    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }

    return parsed.toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function proposalStatusBadge(status: AssistantActionProposal['status']): {
    label: string;
    classes: string;
} {
    switch (status) {
        case 'executed':
            return {
                label: 'Berhasil Dieksekusi',
                classes:
                    'border-emerald-500/30 bg-emerald-500/12 text-emerald-700 dark:text-emerald-300',
            };
        case 'cancelled':
            return {
                label: 'Dibatalkan',
                classes:
                    'border-slate-500/25 bg-slate-500/10 text-slate-700 dark:text-slate-300',
            };
        case 'expired':
            return {
                label: 'Kedaluwarsa',
                classes:
                    'border-amber-500/30 bg-amber-500/12 text-amber-800 dark:text-amber-200',
            };
        case 'denied':
        case 'failed':
            return {
                label: status === 'denied' ? 'Ditolak' : 'Gagal',
                classes:
                    'border-red-500/30 bg-red-500/12 text-red-700 dark:text-red-300',
            };
        default:
            return {
                label: 'Menunggu Konfirmasi',
                classes:
                    'border-cyan/30 bg-cyan/10 text-cyan-800 dark:text-cyan-200',
            };
    }
}

export function ActionProposalCard({
    initialProposal,
}: {
    initialProposal: AssistantActionProposal;
}) {
    const [proposal, setProposal] =
        useState<AssistantActionProposal>(initialProposal);
    const [busy, setBusy] = useState<'confirm' | 'cancel' | null>(null);
    const [feedback, setFeedback] = useState<string | null>(null);
    const [resultActions, setResultActions] = useState<
        { label: string; href: string }[]
    >([]);

    const isPending = proposal.status === 'awaiting_confirmation';
    const badge = proposalStatusBadge(proposal.status);

    async function handleDecision(modeDecision: 'confirm' | 'cancel') {
        if (busy !== null || !isPending) {
            return;
        }

        setBusy(modeDecision);
        setFeedback(null);

        try {
            const endpoint =
                modeDecision === 'confirm'
                    ? proposal.confirm_url
                    : proposal.cancel_url;

            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...readCsrfHeaders(),
                },
                body: JSON.stringify(
                    modeDecision === 'confirm'
                        ? { payload_hash: proposal.payload_hash }
                        : {},
                ),
            });

            const data = (await response.json()) as {
                message?: string;
                action_proposal?: AssistantActionProposal | null;
                actions?: { label: string; href: string }[];
            };

            if (data.action_proposal) {
                setProposal(data.action_proposal);
            }
            if (Array.isArray(data.actions)) {
                setResultActions(data.actions);
            }
            if (typeof data.message === 'string' && data.message !== '') {
                setFeedback(data.message);
            } else if (!response.ok) {
                setFeedback('Permintaan konfirmasi tidak dapat diproses.');
            }
        } catch {
            setFeedback(
                'Koneksi terganggu saat mengirim keputusan aksi. Silakan coba lagi.',
            );
        } finally {
            setBusy(null);
        }
    }

    return (
        <div
            data-testid={`action-proposal-${proposal.action_id}`}
            className="border-ink/12 rounded-2xl border bg-black/[0.02] p-3 text-[11px] dark:border-white/12 dark:bg-white/[0.04]"
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-muted-foreground text-[9px] font-black tracking-wider uppercase">
                        Pratinjau Aksi Terkendali
                    </p>
                    <p className="text-foreground mt-0.5 text-xs font-black">
                        {proposal.action_label}
                    </p>
                </div>
                <span
                    className={`shrink-0 rounded-full border px-2 py-0.5 text-[9px] font-black ${badge.classes}`}
                >
                    {badge.label}
                </span>
            </div>

            {proposal.preview?.target && (
                <div className="border-border mt-2 rounded-xl border bg-[var(--surface-2)] px-2.5 py-1.5">
                    <p className="text-muted-foreground text-[9px] font-bold uppercase">
                        Target
                    </p>
                    <p className="text-foreground font-bold">
                        {proposal.preview.target}
                    </p>
                </div>
            )}

            {proposal.preview?.proposed_changes &&
                proposal.preview.proposed_changes.length > 0 && (
                    <div className="mt-2 space-y-1">
                        {proposal.preview.proposed_changes.map((change) => (
                            <div
                                key={`${change.label}-${change.after}`}
                                className="border-border flex items-start justify-between gap-2 rounded-lg border bg-[var(--surface-2)]/75 px-2 py-1"
                            >
                                <span className="text-muted-foreground shrink-0 font-semibold">
                                    {change.label}
                                </span>
                                <span className="text-foreground text-right font-bold">
                                    {change.before
                                        ? `${change.before} → ${change.after}`
                                        : change.after}
                                </span>
                            </div>
                        ))}
                    </div>
                )}

            {proposal.preview?.financial_impact?.formatted && (
                <div className="mt-2 rounded-xl border border-emerald-500/20 bg-emerald-500/8 px-2.5 py-1.5">
                    <p className="text-[9px] font-bold tracking-wide text-emerald-800 uppercase dark:text-emerald-200">
                        {proposal.preview.financial_impact.label}
                    </p>
                    <p className="mt-0.5 text-xs font-black text-emerald-900 tabular-nums dark:text-emerald-100">
                        {proposal.preview.financial_impact.formatted}
                    </p>
                </div>
            )}

            {proposal.preview?.warnings &&
                proposal.preview.warnings.length > 0 && (
                    <div className="mt-2 space-y-1">
                        {proposal.preview.warnings.map((warning) => (
                            <p
                                key={warning}
                                className="flex items-start gap-1.5 rounded-lg border border-amber-500/20 bg-amber-500/8 px-2 py-1 text-[10px] font-medium text-amber-800 dark:text-amber-200"
                            >
                                <AlertTriangle
                                    className="mt-0.5 size-3 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>{warning}</span>
                            </p>
                        ))}
                    </div>
                )}

            <div className="text-muted-foreground mt-2 flex items-center justify-between gap-2 text-[10px] font-semibold">
                <span className="inline-flex items-center gap-1">
                    <Clock3 className="size-3" aria-hidden="true" />
                    Berlaku hingga {formatExpiryLabel(proposal.expires_at)}
                </span>
                {proposal.result_reference && (
                    <span className="text-foreground font-black">
                        Ref: {proposal.result_reference}
                    </span>
                )}
            </div>

            {feedback && (
                <p
                    className={`mt-2 rounded-xl px-2.5 py-1.5 text-[11px] font-semibold ${
                        proposal.status === 'executed'
                            ? 'border border-emerald-500/25 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200'
                            : proposal.status === 'cancelled'
                              ? 'border border-slate-500/25 bg-slate-500/10 text-slate-700 dark:text-slate-300'
                              : 'border border-red-500/25 bg-red-500/10 text-red-800 dark:text-red-200'
                    }`}
                >
                    {feedback}
                </p>
            )}

            {isPending && (
                <div className="mt-2.5 flex items-center gap-2">
                    <button
                        type="button"
                        disabled={busy !== null}
                        onClick={() => void handleDecision('confirm')}
                        className="inline-flex h-8 flex-1 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-3 text-[11px] font-black text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {busy === 'confirm' ? (
                            <LoaderCircle
                                className="size-3.5 animate-spin"
                                aria-hidden="true"
                            />
                        ) : (
                            <CheckCircle2
                                className="size-3.5"
                                aria-hidden="true"
                            />
                        )}
                        Konfirmasi &amp; Jalankan
                    </button>
                    <button
                        type="button"
                        disabled={busy !== null}
                        onClick={() => void handleDecision('cancel')}
                        className="border-border text-foreground inline-flex h-8 items-center justify-center gap-1 rounded-xl border bg-[var(--surface-2)] px-3 text-[11px] font-bold transition hover:bg-[var(--surface-3)] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <XCircle className="size-3.5" aria-hidden="true" />
                        Batalkan
                    </button>
                </div>
            )}

            {resultActions.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {resultActions.map((action) => (
                        <Link
                            key={action.href}
                            href={action.href}
                            className="inline-flex h-7 items-center gap-1 rounded-full bg-cyan-600 px-2.5 text-[10px] font-black text-white hover:bg-cyan-700"
                        >
                            {action.label}
                            <ArrowRight className="size-3" aria-hidden="true" />
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

export function AssistantResult({ result }: { result: AssistantResponse }) {
    const evidence = result.evidence;
    const facts = evidence?.facts ?? [];
    const insights = evidence?.insights ?? [];
    const recommendations = evidence?.recommendations ?? [];
    const hasStructuredEvidence =
        Boolean(evidence?.verified) ||
        facts.length > 0 ||
        insights.length > 0 ||
        recommendations.length > 0;
    const sourceBadge = sourceBadgeLabel(result);

    return (
        <div className="mt-3 space-y-2.5">
            {(hasStructuredEvidence || result.mode === 'ai') && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span
                        className={
                            sourceBadge.tone === 'verified'
                                ? 'inline-flex items-center gap-1 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300'
                                : sourceBadge.tone === 'ai'
                                  ? 'bg-primary/10 text-primary inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold'
                                  : 'bg-muted text-muted-foreground inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold'
                        }
                    >
                        {sourceBadge.tone === 'verified' ? (
                            <Database className="size-3" aria-hidden="true" />
                        ) : (
                            <Sparkles className="size-3" aria-hidden="true" />
                        )}
                        {sourceBadge.label}
                    </span>
                    {evidence?.period_label && (
                        <span className="text-muted-foreground text-[10px] font-semibold">
                            • {evidence.period_label}
                        </span>
                    )}
                </div>
            )}

            {evidence?.data_insufficient && (
                <div className="rounded-xl border border-amber-500/25 bg-amber-500/10 px-2.5 py-2 text-[11px] font-medium text-amber-800 dark:text-amber-200">
                    Data transaksi pada rentang periode ini belum mencukupi; ADI
                    tidak mengasumsikan angka.
                </div>
            )}

            {facts.length > 0 && (
                <div className="grid grid-cols-2 gap-1.5">
                    {facts.slice(0, 4).map((fact) => (
                        <div
                            key={`${fact.label}-${fact.value}`}
                            className="border-ink/8 rounded-xl border bg-black/[0.02] px-2.5 py-1.5 dark:border-white/10 dark:bg-white/[0.04]"
                        >
                            <p className="text-muted-foreground truncate text-[9px] font-bold tracking-wide uppercase">
                                {fact.label}
                            </p>
                            <p className="text-foreground mt-0.5 truncate text-[11px] font-black tabular-nums">
                                {fact.value}
                            </p>
                        </div>
                    ))}
                </div>
            )}

            {insights.length > 0 && (
                <div className="space-y-1.5">
                    {insights.slice(0, 3).map((insight) => (
                        <div
                            key={insight.id ?? insight.title}
                            className={`rounded-xl border px-2.5 py-2 text-[11px] ${insightToneClasses(insight.severity)}`}
                        >
                            <p className="font-black">
                                [{insight.severity.toUpperCase()}]{' '}
                                {insight.title}
                            </p>
                            <p className="mt-0.5 leading-4 opacity-90">
                                {insight.description}
                            </p>
                            {insight.recommended_action && (
                                <p className="mt-1 font-semibold opacity-95">
                                    Saran: {insight.recommended_action}
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {result.action_proposal && (
                <ActionProposalCard initialProposal={result.action_proposal} />
            )}

            {recommendations.length > 0 && insights.length === 0 && (
                <div className="border-ink/8 rounded-xl border bg-black/[0.02] px-2.5 py-2 text-[11px] dark:border-white/10 dark:bg-white/[0.03]">
                    <p className="text-foreground font-black">
                        Rekomendasi Tindakan (Analisis)
                    </p>
                    <ul className="text-muted-foreground mt-1 list-disc space-y-0.5 pl-4">
                        {recommendations.slice(0, 3).map((item) => (
                            <li key={item}>{item}</li>
                        ))}
                    </ul>
                </div>
            )}

            {result.services.map((service) => (
                <Link
                    key={service.code}
                    href={service.booking_url}
                    className="liquid-control group text-foreground hover:border-primary/50 block rounded-xl p-3 transition hover:-translate-y-0.5"
                >
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-cyan text-[10px] font-black tracking-[0.12em] uppercase">
                                {service.code}
                            </p>
                            <p className="mt-0.5 text-sm font-black">
                                {service.name}
                            </p>
                        </div>
                        <ArrowRight
                            className="mt-1 size-4 shrink-0 transition-transform group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </div>
                    <p className="text-ink/55 mt-1.5 line-clamp-2 text-[11px] leading-4 dark:text-white/55">
                        {service.summary}
                    </p>
                    <p className="text-ink/75 dark:text-lime mt-2 text-[11px] font-bold">
                        {service.pricing_label}
                    </p>
                </Link>
            ))}

            <div className="flex flex-wrap gap-2 pt-0.5">
                {result.actions.map((action) => (
                    <Link
                        key={action.label}
                        href={action.href}
                        className={
                            action.kind === 'primary'
                                ? 'liquid-control bg-primary inline-flex h-8 items-center gap-1.5 rounded-full border-transparent px-3 text-[11px] font-black text-white transition hover:brightness-105'
                                : 'liquid-control text-foreground hover:border-primary/40 hover:bg-primary/10 inline-flex h-8 items-center rounded-full px-3 text-[11px] font-black transition'
                        }
                    >
                        {action.label}
                        {action.kind === 'primary' && (
                            <ArrowRight
                                className="size-3.5"
                                aria-hidden="true"
                            />
                        )}
                    </Link>
                ))}
            </div>
        </div>
    );
}

export default function AdComAssistant() {
    const page = usePage<{ auth?: { user?: { role?: string } | null } }>();
    const userRole = page?.props?.auth?.user?.role ?? null;
    const starterSuggestions = roleStarterSuggestions(userRole);

    const [isOpen, setIsOpen] = useState(false);
    const [draft, setDraft] = useState('');
    const [messages, setMessages] = useState<ChatMessage[]>([welcomeMessage]);
    const [mode, setMode] = useState<AssistantMode>('guide');
    const [requestError, setRequestError] = useState<string | null>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const { ref: launcherLightRef, pointerHandlers: launcherPointerHandlers } =
        usePointerLight<HTMLButtonElement>();
    const request = useHttp<AssistantRequest, AssistantResponse>({
        message: '',
        messages: [],
    });

    useEffect(() => {
        if (isOpen) {
            window.setTimeout(() => inputRef.current?.focus(), 150);
        }
    }, [isOpen]);

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
        });
    }, [messages, request.processing]);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const closeOnEscape = (event: globalThis.KeyboardEvent) => {
            if (event.key === 'Escape') {
                setIsOpen(false);
            }
        };

        window.addEventListener('keydown', closeOnEscape);

        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [isOpen]);

    function resetConversation() {
        request.cancel();
        request.clearErrors();
        setMessages([welcomeMessage]);
        setDraft('');
        setMode('guide');
        setRequestError(null);
        inputRef.current?.focus();
    }

    function sendMessage(content: string) {
        const message = content.trim();

        if (request.processing || message.length < 2 || message.length > 1200) {
            return;
        }

        const history = messages
            .filter((item) => item.id !== 'welcome')
            .slice(-8)
            .map(({ role, content: historicalContent }) => ({
                role,
                content: historicalContent,
            }));

        setMessages((current) => [...current, createMessage('user', message)]);
        setDraft('');
        setRequestError(null);
        request.clearErrors();
        const pageContext = extractPageContext();
        request.transform(() => ({
            message,
            messages: history,
            ...(pageContext ? { page_context: pageContext } : {}),
        }));

        void request
            .post(sendAssistantMessage.url(), {
                onSuccess: (result) => {
                    setMessages((current) => [
                        ...current,
                        createMessage('assistant', result.reply, result),
                    ]);
                    setMode(result.mode);
                },
                onHttpException: (response) => {
                    setRequestError(
                        response.status === 429
                            ? 'ADI lagi ramai. Tunggu sebentar lalu coba lagi, ya.'
                            : 'ADI belum bisa menjawab sekarang. Coba lagi sebentar.',
                    );
                },
                onNetworkError: () => {
                    setRequestError(
                        'Koneksi ke ADI terputus. Periksa internet lalu coba lagi.',
                    );
                },
            })
            .catch(() => undefined);
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        sendMessage(draft);
    }

    function handleInputKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendMessage(draft);
        }
    }

    const messageError =
        typeof request.errors.message === 'string'
            ? request.errors.message
            : null;
    const lastResult = [...messages]
        .reverse()
        .find((message) => message.result)?.result;

    return (
        <div className="fixed right-3 bottom-3 z-[80] sm:right-6 sm:bottom-6">
            <AnimatePresence>
                {isOpen && (
                    <motion.section
                        id="adi-chat-panel"
                        role="dialog"
                        aria-label="Chat dengan ADI"
                        variants={VARIANTS.assistantPanel}
                        initial="initial"
                        animate="animate"
                        exit="exit"
                        style={{ transformOrigin: 'bottom right' }}
                        className="glass-floating text-foreground absolute right-0 bottom-[4.75rem] flex h-[min(680px,calc(100dvh-7rem))] w-[min(430px,calc(100vw-1.5rem))] flex-col overflow-hidden rounded-[28px]"
                    >
                        <header className="border-b border-[var(--border)] bg-[var(--surface-1)]/92 px-4 py-3.5 backdrop-blur-xl">
                            <div className="relative flex items-center gap-3">
                                <span className="bg-primary grid size-10 shrink-0 place-items-center rounded-2xl text-white shadow-[0_6px_16px_rgba(169,37,59,0.28)]">
                                    <Bot
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <h2 className="text-foreground font-black tracking-[-0.025em]">
                                            ADI
                                        </h2>
                                        <span className="bg-primary/12 rounded-full px-2 py-0.5 text-[9px] font-black tracking-widest text-[var(--brand-text)] uppercase">
                                            {mode === 'ai'
                                                ? 'AI Aktif'
                                                : 'Panduan'}
                                        </span>
                                    </div>
                                    <p className="text-muted-foreground mt-0.5 truncate text-[11px]">
                                        Asisten cerdas layanan AD Com
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={resetConversation}
                                    className="glass-pill text-muted-foreground hover:text-foreground grid size-8 shrink-0 place-items-center rounded-full transition"
                                    aria-label="Mulai ulang percakapan"
                                >
                                    <RefreshCcw
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setIsOpen(false)}
                                    className="glass-pill text-muted-foreground hover:text-foreground grid size-8 shrink-0 place-items-center rounded-full transition"
                                    aria-label="Tutup chat"
                                >
                                    <X className="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        </header>

                        <div className="flex items-center justify-between gap-3 border-b border-black/[0.05] bg-black/[0.02] px-4 py-2 text-[10px] dark:border-white/[0.06] dark:bg-white/[0.02]">
                            <span className="text-muted-foreground flex items-center gap-1.5 truncate font-semibold">
                                <span className="size-1.5 shrink-0 rounded-full bg-emerald-500" />
                                {lastResult?.evidence?.verified
                                    ? `Data terverifikasi • ${lastResult.evidence.source ?? 'AD Com BI'}`
                                    : mode === 'ai'
                                      ? 'Terhubung ke model AI & konteks AD Com'
                                      : 'Mode panduan & intelijen lokal AD Com aktif'}
                            </span>
                            {lastResult?.context_meta?.period_label && (
                                <span className="text-muted-foreground shrink-0 font-bold">
                                    {lastResult.context_meta.period_label}
                                </span>
                            )}
                        </div>

                        <div
                            className="min-h-0 flex-1 overflow-y-auto px-3 py-4 sm:px-4"
                            aria-live="polite"
                            aria-busy={request.processing}
                        >
                            <div className="space-y-4">
                                {messages.map((message) => (
                                    <motion.div
                                        key={message.id}
                                        variants={VARIANTS.messageBubble}
                                        initial={
                                            message.id === 'welcome'
                                                ? false
                                                : 'initial'
                                        }
                                        animate="animate"
                                        className={
                                            message.role === 'user'
                                                ? 'flex justify-end'
                                                : 'flex justify-start'
                                        }
                                    >
                                        <div
                                            className={
                                                message.role === 'user'
                                                    ? 'bg-primary max-w-[84%] rounded-2xl rounded-br-md px-3.5 py-3 text-sm leading-5 text-white shadow-sm'
                                                    : 'border-border text-foreground max-w-[92%] rounded-2xl rounded-bl-md border bg-[var(--surface-1)] px-3.5 py-3 text-sm leading-5 shadow-xs'
                                            }
                                        >
                                            <p className="whitespace-pre-wrap">
                                                {message.content}
                                            </p>
                                            {message.result && (
                                                <AssistantResult
                                                    result={message.result}
                                                />
                                            )}
                                        </div>
                                    </motion.div>
                                ))}

                                {messages.length === 1 && (
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                        {starterSuggestions.map(
                                            ({ label, prompt, icon: Icon }) => (
                                                <button
                                                    key={label}
                                                    type="button"
                                                    onClick={() =>
                                                        sendMessage(prompt)
                                                    }
                                                    className="liquid-control text-foreground flex items-center gap-2 rounded-xl p-2.5 text-left text-[11px] font-black transition hover:-translate-y-0.5"
                                                >
                                                    <Icon
                                                        className="text-cyan size-4 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    {label}
                                                </button>
                                            ),
                                        )}
                                    </div>
                                )}

                                {request.processing && (
                                    <div className="flex justify-start">
                                        <div className="glass-surface text-muted-foreground flex items-center gap-2.5 rounded-2xl rounded-bl-md px-3.5 py-2.5 text-xs font-semibold">
                                            <span className="flex items-center gap-1">
                                                <span className="bg-primary size-1.5 animate-bounce rounded-full [animation-delay:0ms]" />
                                                <span className="bg-primary size-1.5 animate-bounce rounded-full [animation-delay:150ms]" />
                                                <span className="bg-primary size-1.5 animate-bounce rounded-full [animation-delay:300ms]" />
                                            </span>
                                            ADI sedang menyusun jawaban…
                                        </div>
                                    </div>
                                )}

                                {requestError && (
                                    <div
                                        role="alert"
                                        className="rounded-xl border border-red-500/20 bg-red-500/8 px-3 py-2 text-xs font-semibold text-red-700 dark:text-red-300"
                                    >
                                        {requestError}
                                    </div>
                                )}

                                {lastResult &&
                                    messages.at(-1)?.role === 'assistant' && (
                                        <div className="flex flex-wrap gap-1.5">
                                            {lastResult.suggestions
                                                .slice(0, 3)
                                                .map((suggestion) => (
                                                    <button
                                                        key={suggestion}
                                                        type="button"
                                                        onClick={() =>
                                                            sendMessage(
                                                                suggestion,
                                                            )
                                                        }
                                                        className="liquid-control text-muted-foreground hover:text-foreground rounded-full px-3 py-1.5 text-[10px] font-bold transition"
                                                    >
                                                        {suggestion}
                                                    </button>
                                                ))}
                                        </div>
                                    )}
                            </div>
                            <div ref={messagesEndRef} />
                        </div>

                        <form
                            onSubmit={submit}
                            className="border-t border-[var(--border)] bg-[var(--surface-1)]/95 p-3 sm:p-4"
                        >
                            {(messageError || draft.length > 1200) && (
                                <p className="mb-2 text-xs font-semibold text-red-600 dark:text-red-300">
                                    {messageError ??
                                        'Pesan maksimal 1.200 karakter.'}
                                </p>
                            )}
                            <div className="liquid-input focus-within:border-primary focus-within:ring-ring/25 flex items-end gap-2 rounded-2xl border border-[var(--input)] bg-[var(--surface-2)] p-1.5 focus-within:ring-3">
                                <textarea
                                    ref={inputRef}
                                    value={draft}
                                    onChange={(event) =>
                                        setDraft(event.target.value)
                                    }
                                    onKeyDown={handleInputKeyDown}
                                    rows={1}
                                    maxLength={1250}
                                    placeholder="Ceritakan masalah teknologimu…"
                                    aria-label="Pesan untuk ADI"
                                    className="placeholder:text-ink/35 max-h-28 min-h-10 flex-1 resize-none bg-transparent px-2 py-2 text-sm leading-5 outline-none dark:placeholder:text-white/30"
                                />
                                <motion.button
                                    type="submit"
                                    whileTap={{ scale: 0.92 }}
                                    transition={SPRING.snappy}
                                    disabled={
                                        request.processing ||
                                        draft.trim().length < 2 ||
                                        draft.length > 1200
                                    }
                                    className="liquid-control grid size-10 shrink-0 place-items-center rounded-xl border-white/20 bg-[linear-gradient(145deg,#ff4962,#b40e2a)] text-white transition hover:brightness-105 disabled:cursor-not-allowed disabled:opacity-35"
                                    aria-label="Kirim pesan"
                                >
                                    {request.processing ? (
                                        <LoaderCircle
                                            className="size-4 animate-spin"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <Send
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    )}
                                </motion.button>
                            </div>
                            <div className="text-ink/40 mt-2 flex items-center justify-between gap-3 px-1 text-[9px] font-semibold dark:text-white/40">
                                <span className="flex items-center gap-1">
                                    <ShieldCheck
                                        className="text-cyan size-3"
                                        aria-hidden="true"
                                    />{' '}
                                    Jangan kirim sandi, OTP, atau kode tiket
                                </span>
                                {draft.length > 900 && (
                                    <span>{draft.length}/1200</span>
                                )}
                            </div>
                        </form>
                    </motion.section>
                )}
            </AnimatePresence>

            <motion.button
                ref={launcherLightRef}
                {...launcherPointerHandlers}
                type="button"
                initial={false}
                whileHover={{ scale: 1.03 }}
                whileTap={{ scale: 0.96 }}
                transition={SPRING.snappy}
                onClick={() => setIsOpen((open) => !open)}
                aria-expanded={isOpen}
                aria-controls="adi-chat-panel"
                aria-label={isOpen ? 'Tutup chat ADI' : 'Buka chat ADI'}
                className="liquid-control glass-pointer-lit focus-visible:ring-ring border-brand-deep/20 bg-brand-deep/90 hover:bg-brand ml-auto flex size-13 items-center justify-center rounded-full p-2 text-white shadow-[0_14px_32px_rgba(169,37,59,0.32)] transition-colors focus-visible:ring-4 focus-visible:outline-none xl:h-12 xl:w-auto xl:gap-2 xl:px-4"
            >
                <span className="grid size-7 shrink-0 place-items-center">
                    {isOpen ? (
                        <X className="size-5" aria-hidden="true" />
                    ) : (
                        <MessageCircleQuestion
                            className="size-5"
                            aria-hidden="true"
                        />
                    )}
                </span>
                <span className="hidden text-sm font-semibold xl:block">
                    Tanya ADI
                </span>
            </motion.button>
        </div>
    );
}
