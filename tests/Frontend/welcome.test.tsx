import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, expect, test, vi } from 'vite-plus/test';
import HeroNetworkEmblem3D from '@/components/motion/HeroNetworkEmblem3D';
import Welcome from '@/pages/welcome';

const session = vi.hoisted(() => ({ user: null as { id: string } | null }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string | { url: string };
        children?: ReactNode;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { auth: { user: session.user } } }),
}));
vi.mock('@/components/adcom-assistant', () => ({ default: () => null }));

beforeEach(() => {
    session.user = null;
});

test('each service opens booking with the selected service and an accessible label', () => {
    const html = renderToStaticMarkup(<Welcome />);

    for (const [slug, name] of [
        ['jaringan-wifi', 'Jaringan &amp; Wi-Fi'],
        ['instalasi-cctv', 'Instalasi CCTV'],
        ['servis-komputer', 'Servis Komputer'],
        ['konsultasi-it', 'Konsultasi IT'],
    ]) {
        expect(html).toContain(`href="/pesan?service=${slug}"`);
        expect(html).toContain(`aria-label="Pesan ${name}"`);
    }
});

test('tracking uses a real destination without a disconnected ticket field', () => {
    const html = renderToStaticMarkup(<Welcome />);

    expect(html).toContain('href="/lacak"');
    expect(html).not.toContain('<input');
    expect(html).toContain('kode akses');
});

test('guest sign-in and authenticated dashboard links remain available', () => {
    const guest = renderToStaticMarkup(<Welcome />);
    session.user = { id: 'customer-test' };
    const authenticated = renderToStaticMarkup(<Welcome />);

    expect(guest).toContain('href="/login"');
    expect(authenticated).toContain('href="/dashboard"');
    expect(authenticated).not.toContain('href="/login"');
});

test('section navigation has destinations and the page retains its privacy links', () => {
    const html = renderToStaticMarkup(<Welcome />);

    for (const id of ['main-content', 'layanan', 'cara-kerja', 'tentang']) {
        expect(html).toContain(`href="#${id}"`);
        expect(html).toContain(`id="${id}"`);
    }
    expect(html.match(/<h1\b/g)).toHaveLength(1);
    expect(html).toContain('href="/privasi"');
    expect(html).toContain('href="/syarat-layanan"');
});

test('hero 3d emblem renders spatial layers and telemetry without duplicating desktop nav labels', () => {
    const welcomeHtml = renderToStaticMarkup(<Welcome />);
    const emblemHtml = renderToStaticMarkup(<HeroNetworkEmblem3D />);

    expect(welcomeHtml).toContain('TEFA · TKJ');
    expect(emblemHtml).toContain('TEFA · TKJ');
    expect(emblemHtml).toContain('Jaringan · CCTV · Komputer');
    expect(welcomeHtml.match(/>Pesan Layanan</g)).toHaveLength(1);
    expect(welcomeHtml.match(/>Lacak Tiket</g)).toHaveLength(1);
    expect(welcomeHtml).toContain('aria-label="Navigasi utama AD Com"');
    expect(welcomeHtml).not.toContain('transition-[height]');
    expect(welcomeHtml).not.toContain('transition-[padding');
});
