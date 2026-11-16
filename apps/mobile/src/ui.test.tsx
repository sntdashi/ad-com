import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test, vi } from 'vite-plus/test';
import { Button, Field, Hero } from './ui';

vi.mock('./api', () => ({ mediaUrl: (url: string | null) => url }));
vi.mock('react-native', () => ({
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

test('busy controls stay labelled and prevent repeated submission', () => {
    const html = renderToStaticMarkup(
        <Button label="Simpan profil" onPress={() => {}} busy />,
    );

    expect(html).toContain('aria-label="Simpan profil"');
    expect(html).toContain('aria-busy="true"');
    expect(html).toContain('disabled');
    expect(html).toContain('role="progressbar"');
});

test('page titles are exposed as headings without losing their descriptions', () => {
    const html = renderToStaticMarkup(
        <Hero
            eyebrow="Tiket saya"
            title="Pesanan saya"
            description="Pilih tiket untuk melihat pengerjaan."
        />,
    );

    expect(html).toContain('<h1>Pesanan saya</h1>');
    expect(html).toContain('Pilih tiket untuk melihat pengerjaan.');
});

test('form fields retain their visible and accessible labels', () => {
    const html = renderToStaticMarkup(
        <Field label="Nama lengkap" value="Pelanggan demo" />,
    );

    expect(html).toContain('<span>Nama lengkap</span>');
    expect(html).toContain('aria-label="Nama lengkap"');
    expect(html).toContain('value="Pelanggan demo"');
});
