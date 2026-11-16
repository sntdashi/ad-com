import { Link, usePage } from '@inertiajs/react';
import { LayoutGroup } from 'motion/react';
import {
    Activity,
    Banknote,
    BarChart3,
    Bell,
    Bot,
    Boxes,
    Cpu,
    FileSpreadsheet,
    Globe2,
    Laptop,
    LayoutGrid,
    Plus,
    ReceiptText,
    Settings2,
    ShieldCheck,
    SlidersHorizontal,
    Star,
    Ticket,
    UsersRound,
    Webhook,
    Wrench,
} from 'lucide-react';
import TechnicianPerformanceController from '@/actions/App/Http/Controllers/TechnicianPerformanceController';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, home } from '@/routes';
import { controlCenter as adminControlCenter } from '@/routes/admin';
import { index as analyticsIndex } from '@/routes/analytics';
import { index as aiProvidersIndex } from '@/routes/ai-providers';
import { edit as editAssistantSettings } from '@/routes/assistant-settings';
import { create as createBooking } from '@/routes/booking';
import { index as devicesIndex } from '@/routes/devices';
import { index as financeIndex } from '@/routes/finance';
import { index as invoicesIndex } from '@/routes/finance/invoices';
import { index as quotationsIndex } from '@/routes/finance/quotations';
import { edit as integrationsSettingsEdit } from '@/routes/integrations-settings';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as notificationsIndex } from '@/routes/notifications';
import { index as ordersIndex } from '@/routes/orders';
import { index as servicesSettingsIndex } from '@/routes/services-settings';
import { edit as systemSettingsEdit } from '@/routes/system-settings';
import { index as teamIndex } from '@/routes/team';
import { index as warrantiesIndex } from '@/routes/warranties';
import { index as workforceIndex } from '@/routes/workforce';
import type { NavItem } from '@/types';

const footerNavItems: NavItem[] = [
    {
        title: 'Portal publik',
        href: home(),
        icon: Globe2,
    },
    {
        title: 'Buat pesanan',
        href: createBooking(),
        icon: Plus,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const role = auth.user.role;
    const mainNavItems: NavItem[] = [
        {
            title: role === 'warehouse' ? 'Dashboard gudang' : 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        ...([
            'operator',
            'admin',
            'supervisor',
            'technician',
            'warehouse',
        ].includes(role)
            ? [
                  {
                      title: 'Analitik & BI',
                      href: analyticsIndex(),
                      icon: BarChart3,
                  },
              ]
            : []),
        {
            title: 'Notifikasi & aktivitas',
            href: notificationsIndex(),
            icon: Bell,
        },
        ...(['operator', 'admin', 'supervisor'].includes(role)
            ? [
                  {
                      title: 'Semua tiket',
                      href: ordersIndex(),
                      icon: Ticket,
                  },
              ]
            : []),
        ...([
            'operator',
            'admin',
            'supervisor',
            'technician',
            'customer',
        ].includes(role)
            ? [
                  {
                      title:
                          role === 'customer'
                              ? 'Perangkat & Garansi'
                              : 'Perangkat & Riwayat',
                      href: devicesIndex(),
                      icon: Laptop,
                  },
              ]
            : []),
        ...(['operator', 'admin', 'supervisor'].includes(role)
            ? [
                  {
                      title: 'Garansi & Klaim',
                      href: warrantiesIndex(),
                      icon: ShieldCheck,
                  },
              ]
            : []),
        ...([
            'operator',
            'admin',
            'supervisor',
            'technician',
            'warehouse',
        ].includes(role)
            ? [
                  {
                      title: 'Inventaris',
                      href: inventoryIndex(),
                      icon: Boxes,
                  },
              ]
            : []),
        ...(['operator', 'admin'].includes(role)
            ? [
                  {
                      title: 'Keuangan',
                      href: financeIndex(),
                      icon: Banknote,
                  },
              ]
            : []),
        ...(['operator', 'admin', 'supervisor', 'customer'].includes(role)
            ? [
                  {
                      title:
                          role === 'customer' ? 'Penawaran Biaya' : 'Penawaran',
                      href: quotationsIndex(),
                      icon: FileSpreadsheet,
                  },
                  {
                      title:
                          role === 'customer'
                              ? 'Tagihan Saya'
                              : 'Tagihan & Invoice',
                      href: invoicesIndex(),
                      icon: ReceiptText,
                  },
              ]
            : []),
        ...(['operator', 'admin'].includes(role)
            ? [
                  {
                      title: 'Tim & akses',
                      href: teamIndex(),
                      icon: UsersRound,
                  },
              ]
            : []),
        ...(['operator', 'admin', 'supervisor', 'technician'].includes(role)
            ? [
                  {
                      title:
                          role === 'technician'
                              ? 'Status & beban saya'
                              : 'Workforce & dispatch',
                      href: workforceIndex(),
                      icon: Activity,
                  },
                  {
                      title:
                          role === 'technician'
                              ? 'Performa saya'
                              : 'Evaluasi teknisi',
                      href: TechnicianPerformanceController(),
                      icon: Star,
                  },
              ]
            : []),
    ];

    const platformNavItems: NavItem[] = [
        ...(['operator', 'admin'].includes(role)
            ? [
                  {
                      title:
                          role === 'operator'
                              ? 'Pusat Kendali Superadmin'
                              : 'Pusat kendali admin',
                      href: adminControlCenter(),
                      icon: SlidersHorizontal,
                  },
              ]
            : []),
        ...(role === 'operator'
            ? [
                  {
                      title: 'Layanan & katalog',
                      href: servicesSettingsIndex(),
                      icon: Wrench,
                  },
                  {
                      title: 'Konfigurasi sistem',
                      href: systemSettingsEdit(),
                      icon: Settings2,
                  },
                  {
                      title: 'AI Provider Manager',
                      href: aiProvidersIndex(),
                      icon: Cpu,
                  },
                  {
                      title: 'Integrasi & Notifikasi',
                      href: integrationsSettingsEdit(),
                      icon: Webhook,
                  },
                  {
                      title: 'Pengaturan ADI',
                      href: editAssistantSettings(),
                      icon: Bot,
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="px-2.5 pt-3 pb-2 group-data-[collapsible=icon]:items-center group-data-[collapsible=icon]:px-0">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            tooltip={{ children: 'Dashboard AD Com' }}
                            className="rounded-xl hover:bg-black/[0.04] dark:hover:bg-white/[0.06]"
                        >
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-1">
                <LayoutGroup id="app-sidebar-nav">
                    <NavMain items={mainNavItems} label="Operasional" />
                    <NavMain
                        items={platformNavItems}
                        label="Kontrol Platform"
                    />
                </LayoutGroup>
            </SidebarContent>

            <SidebarFooter className="border-t border-[var(--sidebar-border)] px-2.5 py-2.5 group-data-[collapsible=icon]:px-0">
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
