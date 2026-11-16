import type { AnchorHTMLAttributes } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test, vi } from 'vite-plus/test';
import Booking from '@/pages/booking';
import Track from '@/pages/track';
import TrackShow from '@/pages/track-show';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url} {...props} />,
    usePage: () => ({ props: { auth: { user: null } } }),
    useForm: (data: unknown) => ({
        data,
        setData: vi.fn(),
        post: vi.fn(),
        errors: {},
        processing: false,
    }),
}));
vi.mock('@/components/adcom-assistant', () => ({ default: () => null }));

test('booking keeps the selected service visible and exposes the current step', () => {
    const html = renderToStaticMarkup(
        <Booking
            selectedServiceSlug="instalasi-cctv"
            services={[
                {
                    id: 1,
                    code: 'SEC.02',
                    slug: 'instalasi-cctv',
                    name: 'Instalasi CCTV',
                    summary: 'Survei dan pemasangan kamera.',
                    pricing_label: 'Biaya setelah survei',
                    service_mode: 'onsite',
                    risk_level: 'high',
                    icon: 'camera',
                },
                {
                    id: 2,
                    code: 'NET.01',
                    slug: 'jaringan-wifi',
                    name: 'Jaringan & Wi-Fi',
                    summary: 'Pemasangan dan perbaikan jaringan.',
                    pricing_label: 'Mulai Rp75.000',
                    service_mode: 'onsite',
                    risk_level: 'low',
                    icon: 'router',
                },
            ]}
        />,
    );

    expect(html).toContain('Ajukan layanan');
    expect(html).toContain('aria-label="Langkah 1 dari 3"');
    expect(html).toContain('aria-pressed="true"');
    expect(html).toContain('aria-pressed="false"');
    expect(html).toContain('Instalasi CCTV');
    expect(html).toContain('Jaringan &amp; Wi-Fi');
    expect(html).toContain('Lanjut');
});

test('tracking retains both labelled credential fields and a submit button', () => {
    const html = renderToStaticMarkup(<Track />);

    expect(html).toContain('Lacak tiket layanan');
    expect(html).toContain('id="ticket_number"');
    expect(html).toContain('for="ticket_number"');
    expect(html).toContain('id="access_code"');
    expect(html).toContain('for="access_code"');
    expect(html).toContain('type="submit"');
});

test('ticket details display the provided status and safely handle an unassigned technician', () => {
    const html = renderToStaticMarkup(
        <TrackShow
            created={false}
            order={{
                ticket_number: 'ADC-TEST-0001',
                status: 'new',
                status_label: 'Permintaan diterima',
                customer_name: 'Pelanggan demo',
                service: 'Instalasi CCTV',
                service_code: 'SEC.02',
                complaint: 'Butuh kamera untuk toko.',
                preferred_date: null,
                preferred_window: null,
                scheduled_at: null,
                technician: null,
                technician_team: [],
                supervisor: null,
                risk_level: 'unassessed',
                estimated_total: null,
                created_at: '10 September 2026',
                timeline: [],
                estimate: null,
                payment: {
                    verified: 0,
                    pending: 0,
                    last_method: null,
                    last_reference: null,
                },
            }}
        />,
    );

    expect(html).toContain('ADC-TEST-0001');
    expect(html).toContain('Permintaan diterima');
    expect(html).toContain('href="/lacak"');
    expect(html).not.toContain('href="tel:');
});
