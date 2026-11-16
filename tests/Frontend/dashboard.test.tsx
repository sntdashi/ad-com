import { Globe2, Plus } from 'lucide-react';
import type { AnchorHTMLAttributes } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test, vi } from 'vite-plus/test';
import { NavFooter } from '@/components/nav-footer';
import { SidebarProvider } from '@/components/ui/sidebar';
import Dashboard from '@/pages/dashboard';
import { home } from '@/routes';
import { create as createBooking } from '@/routes/booking';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url} {...props} />,
}));

test.each([
    ['customer', 'Pesanan saya', '/pesan'],
    ['technician', 'Tugas saya', '/inventory'],
    ['warehouse', 'Ringkasan gudang', '/inventory'],
    ['admin', 'Ringkasan operasional', '/team'],
    ['operator', 'Ringkasan operasional', '/team'],
    ['supervisor', 'Ringkasan operasional', '/inventory'],
])(
    'the %s workspace retains its heading, primary action, and empty state',
    (role, title, destination) => {
        const html = renderToStaticMarkup(
            <Dashboard
                role={role}
                stats={{ active: 0, waiting: 0, scheduled: 0, completed: 0 }}
                orders={[]}
                signals={[]}
            />,
        );

        expect(html).toContain(title);
        expect(html).toContain(`href="${destination}"`);
        expect(html).toContain('Belum ada tiket');
        expect(html).toContain('aria-label="Ringkasan pekerjaan"');
    },
);

test('sidebar Portal publik and Buat pesanan links navigate in the current browser tab without target=_blank', () => {
    const html = renderToStaticMarkup(
        <SidebarProvider>
            <NavFooter
                items={[
                    { title: 'Portal publik', href: home(), icon: Globe2 },
                    {
                        title: 'Buat pesanan',
                        href: createBooking(),
                        icon: Plus,
                    },
                ]}
            />
        </SidebarProvider>,
    );

    expect(html).toContain('Portal publik');
    expect(html).toContain('href="/"');
    expect(html).toContain('href="/pesan"');
    expect(html).not.toContain('target="_blank"');
});
