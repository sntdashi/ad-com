import type { AnchorHTMLAttributes } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, expect, test, vi } from 'vite-plus/test';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import AnalyticsIndex from '@/pages/analytics/index';

const setLayoutPropsMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
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
    useHttp: (initial: unknown) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
    }),
    usePage: () => ({
        url: '/analytics',
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
});

const basePeriod = {
    preset: 'monthly',
    start_date: '2026-10-18',
    end_date: '2026-11-16',
    previous_start_date: '2026-09-18',
    previous_end_date: '2026-10-17',
    label: '18 Okt 2026 – 16 Nov 2026',
    comparison_label: '18 Sep 2026 – 17 Okt 2026',
    bucket_granularity: 'day',
    timezone: 'Asia/Jakarta',
};

const operatorCapabilities = {
    role: 'operator',
    allowed_sections: [
        'executive',
        'financial',
        'services',
        'workforce',
        'inventory',
        'customers',
        'insights',
    ] as const,
    default_section: 'executive' as const,
    allowed_exports: ['executive', 'financial', 'inventory'],
    can_view_financial_summary: true,
    can_view_raw_finance_ledger: true,
    can_view_customer_pii: true,
    scoped_technician_id: null,
};

test('analytics page does not render a duplicate AppLayout, sidebar, or inset wrapper inside AppLayout', () => {
    const html = renderToStaticMarkup(
        <TooltipProvider delayDuration={0}>
            <AppLayout>
                <AnalyticsIndex
                    period={basePeriod}
                    activeSection="executive"
                    capabilities={{
                        ...operatorCapabilities,
                        allowed_sections: [
                            ...operatorCapabilities.allowed_sections,
                        ],
                    }}
                    activityContext={{
                        latest_order_date: '02 Sep 2026',
                        latest_transaction_date: '05 Sep 2026',
                        latest_movement_date: '02 Sep 2026',
                        has_outside_period_data: true,
                    }}
                    kpiDefinitions={{}}
                    insights={[]}
                    executive={{
                        kpis: {
                            total_orders: {
                                value: 0,
                                previous: 4,
                                delta: -100,
                                delta_unit: 'pct',
                            },
                            new_orders: {
                                value: 0,
                                previous: 1,
                                delta: -100,
                                delta_unit: 'pct',
                            },
                            active_orders: {
                                value: 0,
                                previous: 2,
                                delta: -100,
                                delta_unit: 'pct',
                            },
                            completed_orders: {
                                value: 0,
                                previous: 1,
                                delta: -100,
                                delta_unit: 'pct',
                            },
                            cancelled_orders: {
                                value: 0,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                            },
                            completion_rate: {
                                value: null,
                                previous: 25,
                                delta: null,
                                delta_unit: 'pp',
                            },
                            sla_compliance_rate: {
                                value: null,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pp',
                            },
                            avg_resolution_minutes: {
                                value: null,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                            },
                            active_customers: {
                                value: 0,
                                previous: 3,
                                delta: -100,
                                delta_unit: 'pct',
                            },
                            returning_customers: {
                                value: 0,
                                previous: 1,
                                delta: -100,
                                delta_unit: 'pct',
                            },
                            repeat_customer_rate: {
                                value: null,
                                previous: 33.3,
                                delta: null,
                                delta_unit: 'pp',
                            },
                            total_registered_customers: {
                                value: 2,
                                previous: null,
                                delta: null,
                                delta_unit: 'snapshot',
                            },
                            invoiced_revenue: {
                                value: 0,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                                basis: 'accrual',
                            },
                            cash_received: {
                                value: 0,
                                previous: 900000,
                                delta: -100,
                                delta_unit: 'pct',
                                basis: 'cash',
                            },
                            gross_profit_accrual: {
                                value: 0,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                                basis: 'accrual',
                            },
                            operational_expenses: {
                                value: 0,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                            },
                            spare_part_cogs: {
                                value: 0,
                                previous: 0,
                                delta: null,
                                delta_unit: 'pct',
                            },
                            net_cash_flow: {
                                value: 0,
                                previous: 900000,
                                delta: -100,
                                delta_unit: 'pct',
                                basis: 'cash',
                            },
                            outstanding_receivables: {
                                value: 0,
                                previous: null,
                                delta: null,
                                delta_unit: 'snapshot',
                                overdue: 0,
                                overdue_count: 0,
                            },
                            inventory_valuation: {
                                value: 3201000,
                                previous: null,
                                delta: null,
                                delta_unit: 'snapshot',
                                units: 257,
                                low_stock: 2,
                            },
                        },
                        sla_snapshot: {
                            on_track: 0,
                            approaching_deadline: 0,
                            breached: 0,
                            paused: 0,
                            completed: 0,
                            avg_first_response_minutes: null,
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

    const wrapperMatches = html.match(/data-slot="sidebar-wrapper"/g) ?? [];
    const sidebarMatches = html.match(/data-slot="sidebar"/g) ?? [];
    const insetMatches = html.match(/data-slot="sidebar-inset"/g) ?? [];

    expect(wrapperMatches).toHaveLength(1);
    expect(sidebarMatches).toHaveLength(1);
    expect(insetMatches).toHaveLength(1);
    expect(setLayoutPropsMock).toHaveBeenCalledTimes(1);
    expect(html).toContain('Analitik &amp; Business Intelligence');
    expect(html).toContain('Tampilkan 90 Hari');
    expect(html).toContain('3.201.000');
});
