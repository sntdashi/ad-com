import { AlertCircle, CheckCircle2, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type GlassNotificationProps = {
    tone?: 'info' | 'success' | 'warning' | 'danger';
    title?: string;
    children: ReactNode;
    action?: ReactNode;
    className?: string;
};

export function GlassNotification({
    tone = 'info',
    title,
    children,
    action,
    className,
}: GlassNotificationProps) {
    const Icon =
        tone === 'success'
            ? CheckCircle2
            : tone === 'danger' || tone === 'warning'
              ? AlertCircle
              : Info;

    return (
        <div
            role="status"
            className={cn(
                'glass-surface flex flex-wrap items-start justify-between gap-3 rounded-2xl px-4 py-3 text-xs',
                tone === 'success' &&
                    'border-emerald-500/25 bg-emerald-500/10 text-emerald-900 dark:text-emerald-200',
                tone === 'warning' &&
                    'border-amber-500/25 bg-amber-500/10 text-amber-900 dark:text-amber-200',
                tone === 'danger' &&
                    'border-rose-500/30 bg-rose-500/10 text-rose-900 dark:text-rose-200',
                tone === 'info' && 'text-foreground',
                className,
            )}
        >
            <div className="flex min-w-0 flex-1 items-start gap-2.5">
                <Icon className="mt-0.5 size-4 shrink-0" />
                <div className="min-w-0">
                    {title && <p className="font-bold">{title}</p>}
                    <div className="leading-5 opacity-90">{children}</div>
                </div>
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}
