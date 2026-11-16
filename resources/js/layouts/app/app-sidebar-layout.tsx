import { usePage } from '@inertiajs/react';
import {
    Banknote,
    Bot,
    Boxes,
    Cpu,
    LayoutGrid,
    Shield,
    SlidersHorizontal,
    Ticket,
    UserRound,
    UsersRound,
    Webhook,
    Wrench,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AdComAssistant from '@/components/adcom-assistant';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { GlassCommandMenu, type CommandMenuItem } from '@/components/glass';
import { MotionPage } from '@/components/motion/MotionPage';
import { dashboard } from '@/routes';
import { controlCenter as adminControlCenter } from '@/routes/admin';
import { index as aiProvidersIndex } from '@/routes/ai-providers';
import { edit as editAssistantSettings } from '@/routes/assistant-settings';
import { index as financeIndex } from '@/routes/finance';
import { edit as integrationsSettingsEdit } from '@/routes/integrations-settings';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as ordersIndex } from '@/routes/orders';
import { edit as profileEdit } from '@/routes/profile';
import { edit as securityEdit } from '@/routes/security';
import { index as servicesSettingsIndex } from '@/routes/services-settings';
import { edit as systemSettingsEdit } from '@/routes/system-settings';
import { index as teamIndex } from '@/routes/team';
import type { AppLayoutProps, Auth } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const [commandOpen, setCommandOpen] = useState(false);
    const { props, url } = usePage<{ auth: Auth }>();
    const { auth } = props;
    const role = auth?.user?.role ?? 'customer';

    const commandItems = useMemo<CommandMenuItem[]>(() => {
        const items: CommandMenuItem[] = [
            {
                id: 'dashboard',
                title: 'Dashboard',
                group: 'Operasional',
                href: dashboard().url,
                icon: <LayoutGrid className="size-4" />,
            },
            {
                id: 'profile',
                title: 'Profil Akun',
                group: 'Pengaturan',
                href: profileEdit().url,
                icon: <UserRound className="size-4" />,
            },
            {
                id: 'security',
                title: 'Keamanan & 2FA',
                group: 'Pengaturan',
                href: securityEdit().url,
                icon: <Shield className="size-4" />,
            },
        ];

        if (['operator', 'admin', 'supervisor'].includes(role)) {
            items.push({
                id: 'orders',
                title: 'Semua Tiket',
                group: 'Operasional',
                href: ordersIndex().url,
                icon: <Ticket className="size-4" />,
            });
        }
        if (
            [
                'operator',
                'admin',
                'supervisor',
                'technician',
                'warehouse',
            ].includes(role)
        ) {
            items.push({
                id: 'inventory',
                title: 'Inventaris & Stok',
                group: 'Operasional',
                href: inventoryIndex().url,
                icon: <Boxes className="size-4" />,
            });
        }
        if (['operator', 'admin'].includes(role)) {
            items.push(
                {
                    id: 'finance',
                    title: 'Keuangan',
                    group: 'Operasional',
                    href: financeIndex().url,
                    icon: <Banknote className="size-4" />,
                },
                {
                    id: 'team',
                    title: 'Tim & Akses',
                    group: 'Operasional',
                    href: teamIndex().url,
                    icon: <UsersRound className="size-4" />,
                },
                {
                    id: 'control-center',
                    title:
                        role === 'operator'
                            ? 'Pusat Kendali Superadmin'
                            : 'Pusat Kendali Admin',
                    group: 'Kontrol Platform',
                    href: adminControlCenter().url,
                    icon: <SlidersHorizontal className="size-4" />,
                },
            );
        }
        if (role === 'operator') {
            items.push(
                {
                    id: 'services',
                    title: 'Layanan & Katalog',
                    group: 'Kontrol Platform',
                    href: servicesSettingsIndex().url,
                    icon: <Wrench className="size-4" />,
                },
                {
                    id: 'system',
                    title: 'Konfigurasi Sistem & OTP',
                    group: 'Kontrol Platform',
                    href: systemSettingsEdit().url,
                    icon: <SlidersHorizontal className="size-4" />,
                },
                {
                    id: 'ai-providers',
                    title: 'AI Provider & Model Manager',
                    group: 'Kontrol Platform',
                    href: aiProvidersIndex().url,
                    icon: <Cpu className="size-4" />,
                },
                {
                    id: 'integrations',
                    title: 'Integrasi & Notifikasi',
                    group: 'Kontrol Platform',
                    href: integrationsSettingsEdit().url,
                    icon: <Webhook className="size-4" />,
                },
                {
                    id: 'assistant',
                    title: 'Pengaturan ADI',
                    group: 'Kontrol Platform',
                    href: editAssistantSettings().url,
                    icon: <Bot className="size-4" />,
                },
            );
        }

        return items;
    }, [role]);

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    onOpenCommandMenu={() => setCommandOpen(true)}
                />
                <MotionPage pageKey={url.split('?')[0]} className="flex-1">
                    {children}
                </MotionPage>
                <AdComAssistant />
            </AppContent>
            <GlassCommandMenu
                items={commandItems}
                open={commandOpen}
                onOpenChange={setCommandOpen}
            />
        </AppShell>
    );
}
