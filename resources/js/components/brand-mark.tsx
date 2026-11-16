import type { ComponentProps } from 'react';

import { cn } from '@/lib/utils';

type BrandMarkProps = Omit<ComponentProps<'img'>, 'src'> & {
    tone?: 'brand' | 'light';
};

export default function BrandMark({
    tone = 'brand',
    className,
    alt = 'AD Com',
    ...props
}: BrandMarkProps) {
    return (
        <img
            src="/images/adcom-logo-mark.png"
            alt={alt}
            className={cn(
                'block object-contain select-none',
                tone === 'light' && 'brightness-0 invert',
                className,
            )}
            {...props}
        />
    );
}
