import { router } from '@inertiajs/react';
import { Command, CornerDownLeft, Search } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export type CommandMenuItem = {
    id: string;
    title: string;
    group: string;
    href: string;
    keywords?: string;
    icon?: ReactNode;
};

type GlassCommandMenuProps = {
    items: CommandMenuItem[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function GlassCommandMenu({
    items,
    open,
    onOpenChange,
}: GlassCommandMenuProps) {
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                onOpenChange(!open);
            }
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, onOpenChange]);

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (!needle) {
            return items;
        }

        return items.filter(
            (item) =>
                item.title.toLowerCase().includes(needle) ||
                item.group.toLowerCase().includes(needle) ||
                (item.keywords?.toLowerCase().includes(needle) ?? false),
        );
    }, [items, query]);

    function navigateTo(item: CommandMenuItem) {
        onOpenChange(false);
        setQuery('');
        router.visit(item.href);
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                if (!next) {
                    setQuery('');
                    setActiveIndex(0);
                }
            }}
        >
            <DialogContent className="max-w-xl gap-0 overflow-hidden p-0">
                <DialogTitle className="sr-only">
                    Navigasi Cepat AD Com
                </DialogTitle>
                <div className="flex items-center gap-2.5 border-b border-black/[0.06] px-4 py-3 dark:border-white/[0.08]">
                    <Search className="text-muted-foreground size-4 shrink-0" />
                    <input
                        type="text"
                        autoFocus
                        value={query}
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setActiveIndex(0);
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'ArrowDown') {
                                e.preventDefault();
                                setActiveIndex((prev) =>
                                    filtered.length === 0
                                        ? 0
                                        : (prev + 1) % filtered.length,
                                );
                            } else if (e.key === 'ArrowUp') {
                                e.preventDefault();
                                setActiveIndex((prev) =>
                                    filtered.length === 0
                                        ? 0
                                        : (prev - 1 + filtered.length) %
                                          filtered.length,
                                );
                            } else if (
                                e.key === 'Enter' &&
                                filtered[activeIndex]
                            ) {
                                e.preventDefault();
                                navigateTo(filtered[activeIndex]);
                            }
                        }}
                        placeholder="Cari halaman, pengaturan, atau modul AD Com..."
                        className="text-foreground placeholder:text-muted-foreground w-full bg-transparent text-sm focus:outline-none"
                    />
                    <kbd className="text-muted-foreground hidden items-center gap-0.5 rounded-lg border border-black/10 px-1.5 py-0.5 font-mono text-[10px] sm:inline-flex dark:border-white/15">
                        ESC
                    </kbd>
                </div>

                <div className="max-h-[340px] overflow-y-auto p-2">
                    {filtered.length === 0 ? (
                        <div className="text-muted-foreground py-10 text-center text-xs">
                            Tidak ada menu yang cocok dengan &ldquo;{query}
                            &rdquo;.
                        </div>
                    ) : (
                        filtered.map((item, idx) => {
                            const active = idx === activeIndex;

                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    onMouseEnter={() => setActiveIndex(idx)}
                                    onClick={() => navigateTo(item)}
                                    className={cn(
                                        'flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left transition-colors',
                                        active
                                            ? 'bg-primary/12 text-primary dark:bg-primary/20'
                                            : 'text-foreground hover:bg-black/[0.04] dark:hover:bg-white/[0.06]',
                                    )}
                                >
                                    <span className="flex min-w-0 items-center gap-2.5">
                                        <span className="text-muted-foreground shrink-0">
                                            {item.icon ?? (
                                                <Command className="size-4" />
                                            )}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm font-semibold">
                                                {item.title}
                                            </span>
                                            <span className="text-muted-foreground block text-[11px]">
                                                {item.group}
                                            </span>
                                        </span>
                                    </span>
                                    {active && (
                                        <CornerDownLeft className="size-3.5 shrink-0 opacity-70" />
                                    )}
                                </button>
                            );
                        })
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
