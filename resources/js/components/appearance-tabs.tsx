import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();

    const tabs: { value: Appearance; icon: LucideIcon; label: string }[] = [
        { value: 'light', icon: Sun, label: 'Terang' },
        { value: 'dark', icon: Moon, label: 'Gelap' },
        { value: 'system', icon: Monitor, label: 'Sistem' },
    ];

    return (
        <div
            role="radiogroup"
            aria-label="Mode tampilan"
            className={cn(
                'border-border bg-muted/80 inline-flex flex-wrap gap-1 rounded-xl border p-1',
                className,
            )}
            {...props}
        >
            {tabs.map(({ value, icon: Icon, label }) => {
                const active = appearance === value;

                return (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => updateAppearance(value)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-medium transition-all duration-150',
                            active
                                ? 'bg-card text-foreground border-border/80 border shadow-xs'
                                : 'text-muted-foreground hover:bg-background/60 hover:text-foreground',
                        )}
                    >
                        <Icon className="size-4" />
                        <span>{label}</span>
                    </button>
                );
            })}
        </div>
    );
}

export function ThemeToggle({ className }: { className?: string }) {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isDark = resolvedAppearance === 'dark';

    return (
        <button
            type="button"
            onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
            aria-label={
                isDark ? 'Beralih ke mode terang' : 'Beralih ke mode gelap'
            }
            title={isDark ? 'Mode terang' : 'Mode gelap'}
            className={cn(
                'glass-pill text-muted-foreground hover:text-foreground inline-grid size-9 place-items-center rounded-full transition-colors',
                className,
            )}
        >
            {isDark ? <Sun className="size-4" /> : <Moon className="size-4" />}
        </button>
    );
}
