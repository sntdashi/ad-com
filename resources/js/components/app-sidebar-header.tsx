import { Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, Bell, CheckCheck, Search } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';
import { ThemeToggle } from '@/components/appearance-tabs';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { usePointerLight } from '@/hooks/use-pointer-light';
import { SPRING, VARIANTS } from '@/lib/motion';
import {
    index as notificationsIndex,
    read as markNotificationRead,
    readAll as markAllNotificationsRead,
} from '@/routes/notifications';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type NotificationItem = {
    id: string;
    category?: string;
    category_label?: string;
    priority?: string;
    title: string;
    body: string;
    href: string | null;
    read_at: string | null;
    created_at_relative?: string;
};

type NotificationSharedProps = {
    notifications?: {
        unread_count: number;
        recent: NotificationItem[];
    };
};

export function AppSidebarHeader({
    breadcrumbs = [],
    onOpenCommandMenu,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    onOpenCommandMenu?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const popoverRef = useRef<HTMLDivElement>(null);
    const { ref: searchLightRef, pointerHandlers: searchPointerHandlers } =
        usePointerLight<HTMLButtonElement>();
    const { notifications } = usePage<NotificationSharedProps>().props;
    const unreadCount = notifications?.unread_count ?? 0;
    const recent = notifications?.recent ?? [];

    useEffect(() => {
        if (!open) {
            return;
        }
        const handleClickOutside = (event: MouseEvent) => {
            if (!popoverRef.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const handleEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        document.addEventListener('keydown', handleEscape);

        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('keydown', handleEscape);
        };
    }, [open]);

    return (
        <header className="glass-toolbar sticky top-2.5 z-30 mx-3 mt-2.5 flex h-13 shrink-0 items-center justify-between gap-3 rounded-2xl px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 sm:mx-4">
            <div className="flex min-w-0 items-center gap-2.5">
                <SidebarTrigger className="-ml-1 rounded-xl" />
                <div className="min-w-0 truncate">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>

            <div className="flex items-center gap-2">
                {onOpenCommandMenu && (
                    <button
                        ref={searchLightRef}
                        {...searchPointerHandlers}
                        type="button"
                        onClick={onOpenCommandMenu}
                        className="glass-pill glass-pointer-lit text-muted-foreground hover:text-foreground hidden items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium transition-all duration-150 hover:-translate-y-[0.5px] active:scale-[0.97] sm:inline-flex"
                    >
                        <Search className="size-3.5" />
                        <span>Cari cepat</span>
                        <kbd className="rounded-md bg-black/[0.06] px-1.5 py-0.5 font-mono text-[10px] dark:bg-white/[0.1]">
                            ⌘K
                        </kbd>
                    </button>
                )}

                <ThemeToggle />

                <div ref={popoverRef} className="relative">
                    <motion.button
                        type="button"
                        aria-label={
                            unreadCount > 0
                                ? `Notifikasi (${unreadCount} belum dibaca)`
                                : 'Notifikasi'
                        }
                        aria-expanded={open}
                        whileTap={{ scale: 0.94 }}
                        transition={SPRING.snappy}
                        onClick={() => setOpen((prev) => !prev)}
                        className="glass-pill text-foreground/80 hover:text-foreground relative grid size-9 place-items-center rounded-full transition"
                    >
                        <Bell className="size-4" />
                        <AnimatePresence>
                            {unreadCount > 0 && (
                                <motion.span
                                    key={unreadCount}
                                    initial={{ scale: 0.6, opacity: 0 }}
                                    animate={{ scale: 1, opacity: 1 }}
                                    exit={{ scale: 0.6, opacity: 0 }}
                                    transition={SPRING.snappy}
                                    className="bg-primary absolute -top-0.5 -right-0.5 grid min-w-4 place-items-center rounded-full px-1 text-[10px] font-black text-white shadow-sm"
                                >
                                    {unreadCount > 9 ? '9+' : unreadCount}
                                </motion.span>
                            )}
                        </AnimatePresence>
                    </motion.button>

                    <AnimatePresence>
                        {open && (
                            <motion.div
                                variants={VARIANTS.popover}
                                initial="initial"
                                animate="animate"
                                exit="exit"
                                style={{ transformOrigin: 'top right' }}
                                className="glass-popover absolute right-0 mt-2 w-80 rounded-2xl p-3 sm:w-96"
                            >
                                <div className="flex items-center justify-between border-b border-black/[0.07] pb-2.5 dark:border-white/[0.08]">
                                    <p className="text-foreground text-xs font-black tracking-wide uppercase">
                                        Pusat Notifikasi
                                    </p>
                                    {unreadCount > 0 && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.patch(
                                                    markAllNotificationsRead.url(),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="inline-flex items-center gap-1 text-[11px] font-bold text-[var(--brand-text)] hover:underline"
                                        >
                                            <CheckCheck className="size-3.5" />
                                            Tandai dibaca
                                        </button>
                                    )}
                                </div>
                                <div className="mt-2 max-h-80 space-y-1.5 overflow-y-auto">
                                    {recent.length === 0 ? (
                                        <p className="text-muted-foreground py-8 text-center text-xs">
                                            Belum ada notifikasi aktif.
                                        </p>
                                    ) : (
                                        recent.map((item) => (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onClick={() => {
                                                    setOpen(false);
                                                    const destination =
                                                        item.href ||
                                                        notificationsIndex.url();
                                                    if (!item.read_at) {
                                                        router.patch(
                                                            markNotificationRead.url(
                                                                {
                                                                    notificationId:
                                                                        item.id,
                                                                },
                                                            ),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                                onSuccess:
                                                                    () => {
                                                                        router.visit(
                                                                            destination,
                                                                        );
                                                                    },
                                                            },
                                                        );
                                                    } else {
                                                        router.visit(
                                                            destination,
                                                        );
                                                    }
                                                }}
                                                className={`w-full rounded-xl p-2.5 text-left transition ${
                                                    item.read_at
                                                        ? 'hover:bg-black/[0.04] dark:hover:bg-white/[0.06]'
                                                        : 'bg-primary/10 hover:bg-primary/15 dark:bg-primary/20'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-[10px] font-bold text-[var(--brand-text)] uppercase">
                                                        {item.category_label ??
                                                            'Operasional'}
                                                    </span>
                                                    {item.created_at_relative && (
                                                        <span className="text-muted-foreground text-[10px]">
                                                            {
                                                                item.created_at_relative
                                                            }
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-foreground mt-0.5 text-xs font-bold">
                                                    {item.title}
                                                </p>
                                                <p className="text-muted-foreground mt-0.5 line-clamp-2 text-[11px]">
                                                    {item.body}
                                                </p>
                                            </button>
                                        ))
                                    )}
                                </div>
                                <div className="mt-2.5 border-t border-black/[0.07] pt-2 dark:border-white/[0.08]">
                                    <Link
                                        href={notificationsIndex()}
                                        onClick={() => setOpen(false)}
                                        className="hover:bg-primary/10 flex w-full items-center justify-center gap-1.5 rounded-xl py-1.5 text-xs font-bold text-[var(--brand-text)] transition"
                                    >
                                        <span>Buka Pusat Notifikasi</span>
                                        <ArrowUpRight className="size-3.5" />
                                    </Link>
                                </div>
                            </motion.div>
                        )}
                    </AnimatePresence>
                </div>
            </div>
        </header>
    );
}
