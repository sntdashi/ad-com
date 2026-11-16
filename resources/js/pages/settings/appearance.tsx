import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Tampilan aplikasi" />

            <h1 className="sr-only">Tampilan aplikasi</h1>

            <div className="liquid-panel space-y-6 rounded-[28px] p-5 sm:p-7">
                <Heading
                    variant="small"
                    title="Mode tampilan"
                    description="Pilih tampilan yang nyaman untuk akun dan perangkatmu"
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Tampilan aplikasi',
            href: editAppearance(),
        },
    ],
};
