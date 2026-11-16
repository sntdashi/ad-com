import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <div className="flex min-w-0 items-center gap-2.5 group-data-[collapsible=icon]:gap-0">
            <div className="border-border/70 flex size-9 shrink-0 items-center justify-center rounded-xl border bg-white p-1.5 shadow-xs dark:border-white/15 dark:bg-white/95">
                <AppLogoIcon
                    tone="brand"
                    className="size-full object-contain"
                />
            </div>
            <div className="grid min-w-0 flex-1 text-left group-data-[collapsible=icon]:hidden">
                <span className="text-sidebar-foreground truncate text-sm leading-tight font-semibold tracking-tight">
                    {name}
                </span>
                <span className="text-muted-foreground truncate text-[10px] font-semibold tracking-[0.08em] uppercase">
                    Operasional TKJ
                </span>
            </div>
        </div>
    );
}
