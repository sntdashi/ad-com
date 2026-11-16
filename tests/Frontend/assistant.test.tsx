import type { AnchorHTMLAttributes } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test, vi } from 'vite-plus/test';
import AdComAssistant, {
    ActionProposalCard,
    AssistantResult,
    type AssistantActionProposal,
    type AssistantResponse,
    roleStarterSuggestions,
} from '@/components/adcom-assistant';

let currentRole: string | null = 'operator';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url} {...props} />,
    useHttp: (initial: unknown) => ({
        data: initial,
        transform: vi.fn(),
        post: vi.fn(() => Promise.resolve()),
        cancel: vi.fn(),
        clearErrors: vi.fn(),
        processing: false,
        errors: {},
    }),
    usePage: () => ({
        props: {
            auth: {
                user: currentRole
                    ? {
                          id: 1,
                          name: 'Test User',
                          role: currentRole,
                      }
                    : null,
            },
        },
    }),
}));

test('roleStarterSuggestions tailors initial prompts cleanly for each role', () => {
    expect(roleStarterSuggestions('operator')[0].label).toBe(
        'Performa 90 hari',
    );
    expect(roleStarterSuggestions('admin')[0].label).toBe('Piutang & overdue');
    expect(roleStarterSuggestions('technician')[0].label).toBe(
        'Beban tugas saya',
    );
    expect(roleStarterSuggestions('warehouse')[0].label).toBe('Perlu restock');
    expect(roleStarterSuggestions('customer')[0].label).toBe(
        'Pesanan & garansi',
    );
    expect(roleStarterSuggestions(null)[0].label).toBe('Wi-Fi sering putus');
});

test('AssistantResult renders verified attribution, structured KPI facts, insight severity cards, and empty-data disclaimer', () => {
    const verifiedResult: AssistantResponse = {
        reply: 'Ringkasan terverifikasi 90 hari.',
        mode: 'ai',
        suggestions: ['Cek SLA'],
        services: [],
        actions: [
            {
                label: 'Buka Analytics & BI',
                href: '/analytics?period=quarterly',
                kind: 'primary',
            },
        ],
        context_meta: {
            role: 'operator',
            period_label: '19 Agu 2026 – 16 Nov 2026',
            generated_at: '2026-11-16T09:00:00+07:00',
            analysis_source: 'verified_tool_plus_ai',
        },
        evidence: {
            verified: true,
            source: 'ExecutiveAnalyticsService',
            period_label: '19 Agu 2026 – 16 Nov 2026',
            data_insufficient: true,
            facts: [
                { label: 'Laba Bersih Kas', value: 'Rp 100.000' },
                { label: 'Kepatuhan SLA', value: '75.0%' },
            ],
            insights: [
                {
                    id: 'ins-1',
                    title: 'Tiket Melewati SLA',
                    category: 'sla',
                    severity: 'critical',
                    description: 'Ada 1 tiket melewati deadline SLA.',
                    recommended_action: 'Eskalasi ke supervisor teknis.',
                },
            ],
        },
    };

    const html = renderToStaticMarkup(
        <AssistantResult result={verifiedResult} />,
    );

    expect(html).toContain('Data Terverifikasi + Analisis AI');
    expect(html).toContain('19 Agu 2026 – 16 Nov 2026');
    expect(html).toContain('Laba Bersih Kas');
    expect(html).toContain('Rp 100.000');
    expect(html).toContain('[CRITICAL] Tiket Melewati SLA');
    expect(html).toContain('Saran: Eskalasi ke supervisor teknis.');
    expect(html).toContain(
        'Data transaksi pada rentang periode ini belum mencukupi',
    );
    expect(html).toContain('Buka Analytics &amp; BI');
});

test('AdComAssistant renders accessible launcher button cleanly', () => {
    currentRole = 'warehouse';
    const html = renderToStaticMarkup(<AdComAssistant />);

    expect(html).toContain('Tanya ADI');
    expect(html).toContain('Buka chat ADI');
});

test('AssistantResult renders confirmation action preview with financial impact, warnings, and real Confirm/Cancel buttons', () => {
    const proposal: AssistantActionProposal = {
        action_id: '01JPROPOSAL000000000000001',
        action_name: 'create_quotation',
        action_label: 'Buat Penawaran / Estimasi',
        status: 'awaiting_confirmation',
        payload_hash: 'a'.repeat(64),
        expires_at: '2026-11-16T10:30:00+07:00',
        ttl_seconds: 900,
        confirm_url: '/assistant/actions/01JPROPOSAL000000000000001/confirm',
        cancel_url: '/assistant/actions/01JPROPOSAL000000000000001/cancel',
        preview: {
            title: 'Pratinjau Pembuatan Penawaran Biaya',
            target: 'ADC-20261116-0101 — Pelanggan Sari',
            proposed_changes: [
                {
                    label: 'Versi Penawaran',
                    before: 'v1',
                    after: 'v2',
                },
                {
                    label: '[LABOR] Jasa Diagnosa',
                    after: '1x — Rp 200.000',
                },
            ],
            financial_impact: {
                label: 'Total Penawaran (Setelah Diskon & Pajak)',
                amount: 200000,
                formatted: 'Rp 200.000',
            },
            warnings: ['Pesanan sudah memiliki 1 versi penawaran sebelumnya.'],
        },
    };

    const html = renderToStaticMarkup(
        <AssistantResult
            result={{
                reply: 'Pratinjau penawaran siap dikonfirmasi.',
                mode: 'guide',
                suggestions: [],
                services: [],
                actions: [],
                action_proposal: proposal,
            }}
        />,
    );

    expect(html).toContain('Buat Penawaran / Estimasi');
    expect(html).toContain('Menunggu Konfirmasi');
    expect(html).toContain('ADC-20261116-0101 — Pelanggan Sari');
    expect(html).toContain('v1 → v2');
    expect(html).toContain('Rp 200.000');
    expect(html).toContain(
        'Pesanan sudah memiliki 1 versi penawaran sebelumnya.',
    );
    expect(html).toContain('Konfirmasi &amp; Jalankan');
    expect(html).toContain('Batalkan');
});

test('ActionProposalCard hides confirmation controls and shows reference badge once executed or cancelled', () => {
    const executedHtml = renderToStaticMarkup(
        <ActionProposalCard
            initialProposal={{
                action_id: '01JPROPOSAL000000000000002',
                action_name: 'create_service_order',
                action_label: 'Buat Pesanan Layanan',
                status: 'executed',
                payload_hash: 'b'.repeat(64),
                expires_at: '2026-11-16T10:30:00+07:00',
                ttl_seconds: 0,
                result_reference: 'ADC-20261116-0999',
                confirm_url:
                    '/assistant/actions/01JPROPOSAL000000000000002/confirm',
                cancel_url:
                    '/assistant/actions/01JPROPOSAL000000000000002/cancel',
                preview: {
                    title: 'Pratinjau',
                    target: 'Servis Komputer — Sari',
                    proposed_changes: [],
                },
            }}
        />,
    );

    expect(executedHtml).toContain('Berhasil Dieksekusi');
    expect(executedHtml).toContain('Ref: ADC-20261116-0999');
    expect(executedHtml).not.toContain('Konfirmasi &amp; Jalankan');

    const cancelledHtml = renderToStaticMarkup(
        <ActionProposalCard
            initialProposal={{
                action_id: '01JPROPOSAL000000000000003',
                action_name: 'update_order_status',
                action_label: 'Perbarui Status Pesanan',
                status: 'cancelled',
                payload_hash: 'c'.repeat(64),
                expires_at: '2026-11-16T10:30:00+07:00',
                ttl_seconds: 0,
                confirm_url:
                    '/assistant/actions/01JPROPOSAL000000000000003/confirm',
                cancel_url:
                    '/assistant/actions/01JPROPOSAL000000000000003/cancel',
            }}
        />,
    );

    expect(cancelledHtml).toContain('Dibatalkan');
    expect(cancelledHtml).not.toContain('Konfirmasi &amp; Jalankan');
});
