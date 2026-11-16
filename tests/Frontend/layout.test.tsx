import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, expect, test, vi } from 'vite-plus/test';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import AnalyticsIndex from '@/pages/analytics/index';
import Dashboard from '@/pages/dashboard';
import Orders from '@/pages/operations/orders';
import WorkforcePage from '@/pages/operations/workforce';
import AiProvidersPage from '@/pages/settings/ai-providers';

const setLayoutPropsMock = vi.fn();
let currentUrl = '/workforce';

vi.mock('@inertiajs/react', () => ({
    Form: ({ children, ...props }: { children: ReactNode }) => (
        <form {...props}>{children}</form>
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
    setLayoutProps: (props: unknown) => setLayoutPropsMock(props),
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
        clearErrors: vi.fn(),
        recentlySuccessful: false,
    }),
    useHttp: (initial: unknown) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
    }),
    usePage: () => ({
        url: currentUrl,
        props: {
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
                unread_count: 0,
                recent: [],
            },
        },
    }),
}));

beforeEach(() => {
    setLayoutPropsMock.mockClear();
    currentUrl = '/workforce';
});

function listPageFiles(dir: string): string[] {
    const entries = readdirSync(dir);
    const files: string[] = [];

    for (const entry of entries) {
        const fullPath = join(dir, entry);
        if (statSync(fullPath).isDirectory()) {
            files.push(...listPageFiles(fullPath));
        } else if (entry.endsWith('.tsx')) {
            files.push(fullPath);
        }
    }

    return files;
}

function assertSingleApplicationShell(html: string) {
    const wrapperMatches = html.match(/data-slot="sidebar-wrapper"/g) ?? [];
    const sidebarMatches = html.match(/data-slot="sidebar"/g) ?? [];
    const insetMatches = html.match(/data-slot="sidebar-inset"/g) ?? [];
    const triggerMatches = html.match(/data-slot="sidebar-trigger"/g) ?? [];
    const sidebarDomMatches = html.match(/data-sidebar="sidebar"/g) ?? [];
    const assistantLauncherMatches =
        html.match(/aria-label="Buka chat ADI"/g) ?? [];

    expect(wrapperMatches).toHaveLength(1);
    expect(sidebarMatches).toHaveLength(1);
    expect(insetMatches).toHaveLength(1);
    expect(triggerMatches).toHaveLength(1);
    expect(sidebarDomMatches).toHaveLength(1);
    expect(assistantLauncherMatches).toHaveLength(1);
}

test('no authenticated Inertia page imports or renders a duplicate global AppLayout or shell', () => {
    const pagesRoot = join(process.cwd(), 'resources/js/pages');
    const pageFiles = listPageFiles(pagesRoot);

    expect(pageFiles.length).toBeGreaterThanOrEqual(30);

    const excludedPublicPages = new Set([
        'welcome.tsx',
        'booking.tsx',
        'track.tsx',
        'track-show.tsx',
        'legal.tsx',
    ]);

    const offenders: string[] = [];

    for (const file of pageFiles) {
        const rel = relative(pagesRoot, file).replaceAll('\\', '/');
        if (excludedPublicPages.has(rel)) {
            continue;
        }

        const source = readFileSync(file, 'utf8');
        if (
            /@\/layouts\/app-layout|@\/layouts\/app\/|@\/components\/app-shell|@\/components\/app-sidebar\b|@\/layouts\/settings\/layout|<AppLayout\b/.test(
                source,
            )
        ) {
            offenders.push(rel);
        }
    }

    expect(offenders).toEqual([]);
});

test('workforce page renders a single sidebar shell and registers breadcrumbs via setLayoutProps', () => {
    currentUrl = '/workforce';

    const html = renderToStaticMarkup(
        <TooltipProvider delayDuration={0}>
            <AppLayout>
                <WorkforcePage
                    role="operator"
                    currentUserId={1}
                    canManageAll
                    summary={{
                        total: 2,
                        available: 1,
                        busy: 1,
                        unavailable: 0,
                        sla_risk: 0,
                    }}
                    technicians={[
                        {
                            id: 3,
                            name: 'Raka Wijaya',
                            username: 'raka',
                            avatar_url: null,
                            specialty: 'Jaringan & Laptop',
                            is_active: true,
                            availability_status: 'available',
                            availability_label: 'Available',
                            availability_label_id: 'Tersedia',
                            availability_note: null,
                            active_assignments_count: 1,
                            pending_assignments_count: 0,
                            completed_jobs_count: 8,
                            current_workload: 1,
                            workload_level: 'light',
                            sla_risk_assignments_count: 0,
                            avg_resolution_minutes: 90,
                            avg_work_session_minutes: 45,
                            sla_compliance_rate: 100,
                            reopened_jobs_count: 0,
                        },
                    ]}
                    availabilityOptions={[
                        {
                            value: 'available',
                            label: 'Available',
                            label_id: 'Tersedia',
                        },
                    ]}
                    filters={{
                        availability: '',
                        workload: '',
                        specialty: '',
                        assignment: '',
                    }}
                    recentLogs={[]}
                />
            </AppLayout>
        </TooltipProvider>,
    );

    assertSingleApplicationShell(html);
    expect(setLayoutPropsMock).toHaveBeenCalledTimes(1);
    expect(setLayoutPropsMock).toHaveBeenCalledWith({
        breadcrumbs: [{ title: 'Workforce & dispatch', href: '/workforce' }],
    });
    expect(html).toContain('Kapasitas, Ketersediaan &amp; Beban Kerja Teknisi');
    expect(html).toContain('Raka Wijaya');
});

test('analytics page preserves single sidebar shell and dynamic breadcrumb registration', () => {
    currentUrl = '/analytics';

    const html = renderToStaticMarkup(
        <TooltipProvider delayDuration={0}>
            <AppLayout>
                <AnalyticsIndex
                    period={{
                        preset: 'monthly',
                        start_date: '2026-10-18',
                        end_date: '2026-11-16',
                        previous_start_date: '2026-09-18',
                        previous_end_date: '2026-10-17',
                        label: '18 Okt 2026 – 16 Nov 2026',
                        comparison_label: '18 Sep 2026 – 17 Okt 2026',
                        bucket_granularity: 'day',
                        timezone: 'Asia/Jakarta',
                    }}
                    activeSection="executive"
                    capabilities={{
                        role: 'operator',
                        allowed_sections: ['executive', 'workforce'],
                        default_section: 'executive',
                        allowed_exports: ['executive'],
                        can_view_financial_summary: true,
                        can_view_raw_finance_ledger: true,
                        can_view_customer_pii: true,
                        scoped_technician_id: null,
                    }}
                    activityContext={{
                        latest_order_date: '16 Nov 2026',
                        latest_transaction_date: '16 Nov 2026',
                        latest_movement_date: '16 Nov 2026',
                        has_outside_period_data: false,
                    }}
                    kpiDefinitions={{}}
                    insights={[]}
                    executive={{
                        kpis: {
                            total_orders: {
                                value: 4,
                                previous: 2,
                                delta: 100,
                                delta_unit: 'pct',
                            },
                            new_orders: {
                                value: 1,
                                previous: 1,
                                delta: 0,
                                delta_unit: 'pct',
                            },
                            active_orders: {
                                value: 2,
                                previous: 1,
                                delta: 100,
                                delta_unit: 'pct',
                            },
                            completed_orders: {
                                value: 1,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                            },
                            cancelled_orders: {
                                value: 0,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                            },
                            completion_rate: {
                                value: 25,
                                previous: 0,
                                delta: 25,
                                delta_unit: 'pp',
                            },
                            sla_compliance_rate: {
                                value: 100,
                                previous: 100,
                                delta: 0,
                                delta_unit: 'pp',
                            },
                            avg_resolution_minutes: {
                                value: 60,
                                previous: 90,
                                delta: -33.3,
                                delta_unit: 'pct',
                            },
                            active_customers: {
                                value: 2,
                                previous: 1,
                                delta: 100,
                                delta_unit: 'pct',
                            },
                            repeat_customer_rate: {
                                value: 50,
                                previous: 0,
                                delta: 50,
                                delta_unit: 'pp',
                            },
                            gross_revenue: {
                                value: 1000000,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                            },
                            net_cash_flow: {
                                value: 1000000,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                            },
                            outstanding_ar: {
                                value: 0,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                            },
                            spare_part_expense: {
                                value: 0,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                            },
                            inventory_value: {
                                value: 500000,
                                previous: 0,
                                delta: null,
                                delta_unit: 'snapshot',
                                units: 10,
                                low_stock: 1,
                            },
                        },
                        sla_snapshot: {
                            on_track: 1,
                            approaching_deadline: 0,
                            breached: 0,
                            paused: 0,
                            completed: 1,
                            avg_first_response_minutes: 10,
                        },
                        status_distribution: [],
                        trends: [],
                        drill_downs: [],
                    }}
                    financial={null}
                    services={null}
                    workforce={null}
                    inventory={null}
                    customers={null}
                />
            </AppLayout>
        </TooltipProvider>,
    );

    assertSingleApplicationShell(html);
    expect(setLayoutPropsMock).toHaveBeenCalledTimes(1);
    expect(html).toContain('Analitik &amp; Business Intelligence');
});

test('dashboard, orders, and nested settings pages render inside a single global shell', () => {
    currentUrl = '/dashboard';
    const dashboardHtml = renderToStaticMarkup(
        <TooltipProvider delayDuration={0}>
            <AppLayout breadcrumbs={Dashboard.layout?.breadcrumbs ?? []}>
                <Dashboard
                    role="operator"
                    stats={{
                        active: 2,
                        waiting: 1,
                        scheduled: 1,
                        completed: 3,
                    }}
                    orders={[]}
                    signals={[]}
                />
            </AppLayout>
        </TooltipProvider>,
    );
    assertSingleApplicationShell(dashboardHtml);
    expect(Dashboard.layout?.breadcrumbs?.[0]?.title).toBe('Dashboard');

    currentUrl = '/orders';
    const ordersHtml = renderToStaticMarkup(
        <TooltipProvider delayDuration={0}>
            <AppLayout>
                <Orders
                    orders={{
                        data: [],
                        links: [],
                        from: null,
                        to: null,
                        total: 0,
                    }}
                    filters={{
                        search: '',
                        status: '',
                        group: '',
                        group_label: null,
                    }}
                    statuses={[]}
                />
            </AppLayout>
        </TooltipProvider>,
    );
    assertSingleApplicationShell(ordersHtml);

    currentUrl = '/settings/ai-providers';
    const aiProvidersHtml = renderToStaticMarkup(
        <TooltipProvider delayDuration={0}>
            <AppLayout>
                <SettingsLayout>
                    <AiProvidersPage
                        providers={[]}
                        activeDefault={null}
                        drivers={[{ value: 'openai', label: 'OpenAI' }]}
                    />
                </SettingsLayout>
            </AppLayout>
        </TooltipProvider>,
    );
    assertSingleApplicationShell(aiProvidersHtml);
    expect(aiProvidersHtml).toContain(
        'Kelola Provider &amp; Model AI untuk ADI',
    );
    expect(aiProvidersHtml).toContain('Kontrol akun dan platform');
});

test('ai provider manager isolates long openrouter model names inside truncated grid tracks', () => {
    currentUrl = '/settings/ai-providers';
    const openRouterProvider = {
        id: 7,
        driver: 'openrouter',
        name: 'OpenRouter Production',
        base_url: 'https://openrouter.ai/api/v1',
        token_configured: true,
        masked_token: 'sk-or-••••1234',
        model_id: 'google/gemini-2.5-pro-exp-03-25:free',
        model_display_name:
            'Google: Gemini 2.5 Pro Experimental Long OpenRouter Identifier Name',
        discovered_models: [
            {
                id: 'google/gemini-2.5-pro-exp-03-25:free',
                name: 'Google: Gemini 2.5 Pro Experimental Long OpenRouter Identifier Name',
            },
            {
                id: 'anthropic/claude-3.7-sonnet:thinking',
                name: 'Anthropic: Claude 3.7 Sonnet (Thinking)',
            },
        ],
        is_active: true,
        is_default: true,
        connection_status: 'connected',
        last_error: null,
        last_validated_at: '16 Nov 2026, 05.30',
    };

    const html = renderToStaticMarkup(
        <TooltipProvider delayDuration={0}>
            <AppLayout>
                <SettingsLayout>
                    <AiProvidersPage
                        providers={[openRouterProvider]}
                        activeDefault={openRouterProvider}
                        drivers={[{ value: 'openrouter', label: 'OpenRouter' }]}
                    />
                </SettingsLayout>
            </AppLayout>
        </TooltipProvider>,
    );

    assertSingleApplicationShell(html);
    expect(html).toContain('lg:grid-cols-3');
    expect(html).toContain('grid min-w-0 gap-1.5');
    expect(html).toContain('max-w-full min-w-0');
    expect(html).toContain('overflow-hidden');
    expect(html).toContain('block truncate');
    expect(html).toContain('Atau Validasi Model Manual');
    expect(html).toContain('sk-or-••••1234');
});
