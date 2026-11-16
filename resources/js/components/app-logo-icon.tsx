import type { ComponentProps } from 'react';

import BrandMark from '@/components/brand-mark';

export default function AppLogoIcon(props: ComponentProps<typeof BrandMark>) {
    return <BrandMark {...props} />;
}
