import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type GlassCardProps = HTMLAttributes<HTMLDivElement> & {
    variant?: 'surface' | 'floating' | 'subtle' | 'dark';
    interactive?: boolean;
};

export function GlassCard({
    className,
    variant = 'surface',
    interactive = false,
    ...props
}: GlassCardProps) {
    return (
        <div
            data-slot="glass-card"
            className={cn(
                'rounded-[24px] p-5 sm:p-6',
                variant === 'surface' && 'liquid-panel',
                variant === 'floating' && 'glass-floating',
                variant === 'subtle' &&
                    'glass-surface bg-[var(--glass-bg-subtle)]',
                variant === 'dark' && 'liquid-panel-dark text-white',
                interactive &&
                    'cursor-pointer transition-transform duration-200 ease-out hover:-translate-y-0.5',
                className,
            )}
            {...props}
        />
    );
}

export function GlassCardHeader({
    className,
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-3',
                className,
            )}
            {...props}
        />
    );
}

export function GlassCardTitle({
    className,
    ...props
}: HTMLAttributes<HTMLHeadingElement>) {
    return (
        <h3
            className={cn(
                'text-foreground text-base font-black tracking-tight sm:text-lg',
                className,
            )}
            {...props}
        />
    );
}

export function GlassCardDescription({
    className,
    ...props
}: HTMLAttributes<HTMLParagraphElement>) {
    return (
        <p
            className={cn('text-muted-foreground text-xs leading-5', className)}
            {...props}
        />
    );
}

export function GlassCardContent({
    className,
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('mt-4', className)} {...props} />;
}
