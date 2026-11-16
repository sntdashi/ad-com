import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, expect, test, vi } from 'vite-plus/test';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import AppearanceToggleTab, { ThemeToggle } from '@/components/appearance-tabs';
import { AppSidebar } from '@/components/app-sidebar';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AuthSplitLayout from '@/layouts/auth/auth-split-layout';
import Login from '@/pages/auth/login';

const originalWindow = globalThis.window;
const originalDocument = globalThis.document;
const originalLocalStorage = globalThis.localStorage;

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        ...props
    }: {
        children:
            | ReactNode
            | ((state: {
                  processing: boolean;
                  errors: Record<string, string>;
              }) => ReactNode);
    }) => (
        <form {...props}>
            {typeof children === 'function'
                ? children({ processing: false, errors: {} })
                : children}
        </form>
    ),
    Head: () => null,
    Link: ({
        href,
        prefetch: _prefetch,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string | { url: string };
        prefetch?: boolean | string | string[];
    }) => <a href={typeof href === 'string' ? href : href.url} {...props} />,
    router: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
    },
    usePage: () => ({
        url: '/dashboard',
        props: {
            name: 'AD Com',
            auth: {
                user: {
                    id: 1,
                    name: 'Operator AD Com',
                    email: 'operator@adcom.test',
                    role: 'operator',
                },
            },
            sidebarOpen: true,
            notifications: {
                unread_count: 2,
                recent: [],
            },
        },
    }),
}));

afterEach(() => {
    vi.restoreAllMocks();
    Object.defineProperty(globalThis, 'window', {
        value: originalWindow,
        configurable: true,
    });
    Object.defineProperty(globalThis, 'document', {
        value: originalDocument,
        configurable: true,
    });
    Object.defineProperty(globalThis, 'localStorage', {
        value: originalLocalStorage,
        configurable: true,
    });
});

function listTsxFiles(dir: string): string[] {
    const entries = readdirSync(dir);
    const files: string[] = [];

    for (const entry of entries) {
        const fullPath = join(dir, entry);
        if (statSync(fullPath).isDirectory()) {
            files.push(...listTsxFiles(fullPath));
        } else if (entry.endsWith('.tsx')) {
            files.push(fullPath);
        }
    }

    return files;
}

test('initializeTheme toggles dark class and colorScheme from stored preference', () => {
    const store = new Map<string, string>([['appearance', 'dark']]);
    const classSet = new Set<string>();
    let lastCookie = '';

    const fakeStorage = {
        getItem: (key: string) => store.get(key) ?? null,
        setItem: (key: string, value: string) => {
            store.set(key, value);
        },
    };

    const fakeDoc = {
        documentElement: {
            classList: {
                toggle: (name: string, force?: boolean) => {
                    if (force) {
                        classSet.add(name);
                    } else {
                        classSet.delete(name);
                    }
                    return classSet.has(name);
                },
            },
            style: { colorScheme: '' },
        },
        set cookie(value: string) {
            lastCookie = value;
        },
        get cookie() {
            return lastCookie;
        },
    };

    Object.defineProperty(globalThis, 'localStorage', {
        value: fakeStorage,
        configurable: true,
    });
    Object.defineProperty(globalThis, 'document', {
        value: fakeDoc,
        configurable: true,
    });
    Object.defineProperty(globalThis, 'window', {
        value: {
            localStorage: fakeStorage,
            matchMedia: () => ({
                matches: false,
                addEventListener: vi.fn(),
            }),
        },
        configurable: true,
    });

    initializeTheme();

    expect(classSet.has('dark')).toBe(true);
    expect(fakeDoc.documentElement.style.colorScheme).toBe('dark');

    store.set('appearance', 'light');
    initializeTheme();

    expect(classSet.has('dark')).toBe(false);
    expect(fakeDoc.documentElement.style.colorScheme).toBe('light');
});

test('AppearanceToggleTab and ThemeToggle expose accessible labels and controls', () => {
    const html = renderToStaticMarkup(
        <div>
            <AppearanceToggleTab />
            <ThemeToggle />
        </div>,
    );

    expect(html).toContain('role="radiogroup"');
    expect(html).toContain('aria-label="Mode tampilan"');
    expect(html).toContain('Terang');
    expect(html).toContain('Gelap');
    expect(html).toContain('Sistem');
    expect(html).toContain('Beralih ke mode gelap');
});

test('AppSidebar collapsed rail hides branding subtext, keeps brand-tone emblem, and exposes header theme toggle', () => {
    const html = renderToStaticMarkup(
        <TooltipProvider delayDuration={0}>
            <SidebarProvider defaultOpen={false}>
                <AppSidebar />
                <AppSidebarHeader
                    breadcrumbs={[{ title: 'Dashboard', href: '/dashboard' }]}
                />
            </SidebarProvider>
        </TooltipProvider>,
    );

    expect(html).toContain('data-state="collapsed"');
    expect(html).toContain('group-data-[collapsible=icon]:hidden');
    expect(html).toContain('data-test="sidebar-menu-button"');
    expect(html).toContain('Beralih ke mode gelap');
});

test('AuthSplitLayout and Login render enterprise split screen with Google and credential inputs', () => {
    const html = renderToStaticMarkup(
        <AuthSplitLayout
            title="Masuk ke AD Com"
            description="Akses dashboard operasional atau pantau tiket servis Anda."
        >
            <Login canResetPassword />
        </AuthSplitLayout>,
    );

    expect(html).toContain('TEFA TKJ');
    expect(html).toContain('Masuk ke dashboard');
    expect(html).toContain('data-test="google-login-button"');
    expect(html).toContain('name="email"');
    expect(html).toContain('name="password"');
    expect(html).toContain('data-test="login-button"');
    expect(html).toContain('Beralih ke mode gelap');
});

test('no TSX page or component retains inverted bg-ink+text-white buttons or hardcoded pink page wrappers', () => {
    const roots = [
        join(process.cwd(), 'resources/js/pages'),
        join(process.cwd(), 'resources/js/components'),
    ];
    const offenders: string[] = [];

    for (const root of roots) {
        for (const file of listTsxFiles(root)) {
            const rel = relative(process.cwd(), file).replaceAll('\\', '/');
            const source = readFileSync(file, 'utf8');

            if (
                /\bbg-ink\b[^"\n]*\btext-white\b|\bdark:bg-ink\b/.test(source)
            ) {
                offenders.push(`${rel}: inverted bg-ink+text-white`);
            }
            if (source.includes('255,245,244')) {
                offenders.push(`${rel}: hardcoded light pink page background`);
            }
        }
    }

    expect(offenders).toEqual([]);
});
