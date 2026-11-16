import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test, vi } from 'vite-plus/test';
import {
    AssistantScreen,
    MobileActionProposalCard,
    type MobileActionProposal,
} from './AssistantScreen';

vi.mock('./api', () => ({
    ApiError: class ApiError extends Error {
        constructor(
            message: string,
            public status: number,
        ) {
            super(message);
        }
    },
    mediaUrl: (url: string | null) => url,
    request: vi.fn(),
    errorMessage: (error: unknown) =>
        error instanceof Error ? error.message : 'Permintaan gagal.',
}));

vi.mock('react-native', () => ({
    Platform: { OS: 'ios' },
    StyleSheet: { create: (styles: unknown) => styles },
    View: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    Text: ({
        children,
        accessibilityRole,
    }: {
        children: ReactNode;
        accessibilityRole?: string;
    }) =>
        accessibilityRole === 'header' ? (
            <h1>{children}</h1>
        ) : (
            <span>{children}</span>
        ),
    TextInput: ({
        accessibilityLabel,
        value,
    }: {
        accessibilityLabel: string;
        value?: string;
    }) => <input aria-label={accessibilityLabel} defaultValue={value} />,
    Image: () => null,
    ActivityIndicator: () => <span role="progressbar" />,
    Pressable: ({
        children,
        accessibilityLabel,
        accessibilityState,
        disabled,
    }: {
        children: ReactNode;
        accessibilityLabel: string;
        accessibilityState: { busy: boolean };
        disabled: boolean;
    }) => (
        <button
            aria-label={accessibilityLabel}
            aria-busy={accessibilityState.busy}
            disabled={disabled}
        >
            {children}
        </button>
    ),
}));

const baseProposal: MobileActionProposal = {
    action_id: 'prop-123',
    action_name: 'create_service_order',
    action_label: 'Buat Pesanan Layanan',
    status: 'awaiting_confirmation',
    payload_hash: 'hash-abc',
    expires_at: '2026-11-16T04:00:00Z',
    preview: {
        title: 'Pratinjau Pembuatan Pesanan Layanan',
        target: 'Budi Santoso — Servis Laptop',
        proposed_changes: [
            { label: 'Layanan', before: null, after: 'Servis Laptop' },
            { label: 'Keluhan', before: null, after: 'Laptop tidak menyala' },
        ],
        financial_impact: {
            label: 'Estimasi Rentang Tarif Layanan',
            amount: 150000,
            formatted: 'Mulai Rp 150.000',
        },
        warnings: ['Pastikan nomor telepon pelanggan aktif untuk koordinasi.'],
    },
};

test('renders pending mobile action proposal with preview, countdown, and confirmation controls', () => {
    const nowMs = Date.parse('2026-11-16T03:55:00Z');
    const html = renderToStaticMarkup(
        <MobileActionProposalCard
            proposal={baseProposal}
            nowMs={nowMs}
            acting={false}
            disabled={false}
            onConfirm={() => {}}
            onCancel={() => {}}
        />,
    );

    expect(html).toContain('Pratinjau Pembuatan Pesanan Layanan');
    expect(html).toContain('Menunggu Konfirmasi');
    expect(html).toContain('Budi Santoso — Servis Laptop');
    expect(html).toContain('Mulai Rp 150.000');
    expect(html).toContain('Konfirmasi berlaku 300 detik lagi.');
    expect(html).toContain('aria-label="Konfirmasi &amp; Jalankan"');
    expect(html).toContain('aria-label="Batalkan"');
});

test('disables action buttons cleanly while confirmation request is in flight', () => {
    const nowMs = Date.parse('2026-11-16T03:58:00Z');
    const html = renderToStaticMarkup(
        <MobileActionProposalCard
            proposal={baseProposal}
            nowMs={nowMs}
            acting
            disabled
            onConfirm={() => {}}
            onCancel={() => {}}
        />,
    );

    expect(html).toContain('aria-busy="true"');
    expect(html).toContain('disabled=""');
});

test('locks controls and shows expiry warning when proposal ttl elapses', () => {
    const expiredNow = Date.parse('2026-11-16T04:01:00Z');
    const html = renderToStaticMarkup(
        <MobileActionProposalCard
            proposal={baseProposal}
            nowMs={expiredNow}
            acting={false}
            disabled={false}
            onConfirm={() => {}}
            onCancel={() => {}}
        />,
    );

    expect(html).toContain('Kedaluwarsa');
    expect(html).toContain(
        'Batas waktu konfirmasi proposal ini sudah berakhir.',
    );
    expect(html).not.toContain('Konfirmasi &amp; Jalankan');
    expect(html).not.toContain('aria-label="Batalkan"');
});

test('shows executed result reference and hides confirmation buttons', () => {
    const executedProposal: MobileActionProposal = {
        ...baseProposal,
        status: 'executed',
        result_reference: 'ADC-20261116-0999',
    };

    const html = renderToStaticMarkup(
        <MobileActionProposalCard
            proposal={executedProposal}
            nowMs={Date.parse('2026-11-16T03:56:00Z')}
            acting={false}
            disabled={false}
            feedback="Pesanan layanan ADC-20261116-0999 berhasil dibuat."
            onConfirm={() => {}}
            onCancel={() => {}}
        />,
    );

    expect(html).toContain('Berhasil Dieksekusi');
    expect(html).toContain('Referensi Hasil: ADC-20261116-0999');
    expect(html).toContain(
        'Pesanan layanan ADC-20261116-0999 berhasil dibuat.',
    );
    expect(html).not.toContain('Konfirmasi &amp; Jalankan');
});

test('shows cancelled state and feedback cleanly', () => {
    const cancelledProposal: MobileActionProposal = {
        ...baseProposal,
        status: 'cancelled',
    };

    const html = renderToStaticMarkup(
        <MobileActionProposalCard
            proposal={cancelledProposal}
            nowMs={Date.parse('2026-11-16T03:56:00Z')}
            acting={false}
            disabled={false}
            feedback="Aksi dibatalkan oleh pengguna."
            onConfirm={() => {}}
            onCancel={() => {}}
        />,
    );

    expect(html).toContain('Dibatalkan');
    expect(html).toContain('Aksi dibatalkan oleh pengguna.');
    expect(html).not.toContain('Konfirmasi &amp; Jalankan');
});

test('renders AssistantScreen initial layout cleanly', () => {
    const html = renderToStaticMarkup(<AssistantScreen />);

    expect(html).toContain('<h1>Tanya ADI</h1>');
    expect(html).toContain('Apa yang ingin kamu tanyakan?');
    expect(html).toContain('Wi-Fi sering putus');
});
