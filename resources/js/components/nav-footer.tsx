import { Link } from '@inertiajs/react';
import type { ComponentPropsWithoutRef } from 'react';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

export function NavFooter({
    items,
    className,
    ...props
}: ComponentPropsWithoutRef<typeof SidebarGroup> & {
    items: NavItem[];
}) {
    return (
        <SidebarGroup
            {...props}
            className={`px-0 py-1 group-data-[collapsible=icon]:items-center ${className || ''}`}
        >
            <SidebarGroupContent>
                <SidebarMenu className="gap-0.5">
                    {items.map((item) => {
                        const url = toUrl(item.href);
                        const isExternal = /^https?:\/\//i.test(url);

                        return (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    asChild
                                    tooltip={{ children: item.title }}
                                    className="text-muted-foreground hover:text-sidebar-foreground rounded-xl"
                                >
                                    {isExternal ? (
                                        <a
                                            href={url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {item.icon && (
                                                <item.icon className="size-4 shrink-0" />
                                            )}
                                            <span className="truncate group-data-[collapsible=icon]:hidden">
                                                {item.title}
                                            </span>
                                        </a>
                                    ) : (
                                        <Link href={item.href}>
                                            {item.icon && (
                                                <item.icon className="size-4 shrink-0" />
                                            )}
                                            <span className="truncate group-data-[collapsible=icon]:hidden">
                                                {item.title}
                                            </span>
                                        </Link>
                                    )}
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        );
                    })}
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
