import { Link } from '@inertiajs/react';
import { motion } from 'motion/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { SPRING } from '@/lib/motion';
import type { NavItem } from '@/types';

export function NavMain({
    items,
    label = 'Operasional',
}: {
    items: NavItem[];
    label?: string;
}) {
    const { isCurrentUrl } = useCurrentUrl();

    if (items.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2.5 py-1 group-data-[collapsible=icon]:items-center group-data-[collapsible=icon]:px-0">
            <SidebarGroupLabel className="text-muted-foreground px-2.5 text-[10px] font-bold tracking-[0.12em] uppercase">
                {label}
            </SidebarGroupLabel>
            <SidebarMenu className="gap-0.5">
                {items.map((item) => {
                    const active = isCurrentUrl(item.href);

                    return (
                        <SidebarMenuItem key={item.title} className="relative">
                            <SidebarMenuButton
                                asChild
                                isActive={active}
                                tooltip={{ children: item.title }}
                                className="group/side-item relative z-10 rounded-xl transition-[color,transform,background-color] duration-150 hover:bg-black/[0.04] active:scale-[0.98] data-[active=true]:bg-transparent data-[active=true]:font-semibold data-[active=true]:text-[var(--brand-text)] dark:hover:bg-white/[0.06] dark:data-[active=true]:bg-transparent"
                            >
                                <Link href={item.href} prefetch>
                                    {active && (
                                        <motion.span
                                            layoutId="sidebar-active-pill"
                                            transition={SPRING.pill}
                                            className="ring-primary/20 dark:ring-primary/35 pointer-events-none absolute inset-0 -z-10 rounded-xl bg-[var(--sidebar-accent)] ring-1"
                                        />
                                    )}
                                    {item.icon && (
                                        <item.icon className="size-4 shrink-0 transition-transform duration-150 group-hover/side-item:scale-105" />
                                    )}
                                    <span className="truncate group-data-[collapsible=icon]:hidden">
                                        {item.title}
                                    </span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
