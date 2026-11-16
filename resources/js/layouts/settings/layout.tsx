import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { GlassNavigation, type GlassNavItem } from '@/components/glass';
import { useCurrentUrl } from '@/hooks/use-current-url';
import {
    accountSettingsForRole,
    roleSettingsCopy,
    roleToolsForRole,
} from '@/lib/settings-navigation';
import type { Auth } from '@/types';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { isCurrentUrl } = useCurrentUrl();
    const roleCopy = roleSettingsCopy[auth.user.role];
    const accountItems = accountSettingsForRole(auth.user.role);
    const roleTools = roleToolsForRole(auth.user.role);

    const accountNavItems: GlassNavItem[] = accountItems.map((item) => {
        const Icon = item.icon;

        return {
            title: item.title,
            href: item.href,
            active: isCurrentUrl(item.href),
            icon: Icon ? <Icon className="size-3.5" /> : undefined,
        };
    });

    const roleNavItems: GlassNavItem[] = roleTools.map((item) => {
        const Icon = item.icon;

        return {
            title: item.title,
            href: item.href,
            active: isCurrentUrl(item.href),
            icon: Icon ? <Icon className="size-3.5" /> : undefined,
        };
    });

    return (
        <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
            <header className="mb-6">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="section-kicker text-[var(--brand-text)]">
                        Pengaturan · {roleCopy.label}
                    </p>
                    <span className="bg-primary/12 rounded-full px-2.5 py-0.5 text-[10px] font-black text-[var(--brand-text)] uppercase">
                        {roleCopy.eyebrow}
                    </span>
                </div>
                <h1 className="text-foreground mt-1.5 text-2xl font-black tracking-tight">
                    Kontrol akun dan platform
                </h1>
                <p className="text-muted-foreground mt-1 max-w-2xl text-sm leading-6">
                    {roleCopy.description}
                </p>
            </header>

            <div className="mb-7 space-y-2.5">
                <GlassNavigation
                    items={accountNavItems}
                    ariaLabel="Pengaturan akun"
                />
                {roleNavItems.length > 0 && (
                    <div>
                        <GlassNavigation
                            items={roleNavItems}
                            ariaLabel={`Kontrol ${roleCopy.label}`}
                        />
                    </div>
                )}
            </div>

            <main className="min-w-0">{children}</main>
        </div>
    );
}
