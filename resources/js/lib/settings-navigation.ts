import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Banknote,
    BellRing,
    Boxes,
    ClipboardList,
    LayoutDashboard,
    Palette,
    PlusCircle,
    Settings2,
    ShieldCheck,
    Star,
    UserRound,
    UsersRound,
    Wrench,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { create as createBooking } from '@/routes/booking';
import { index as financeIndex } from '@/routes/finance';
import { edit as integrationsSettingsEdit } from '@/routes/integrations-settings';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as notificationsIndex } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as servicesSettingsIndex } from '@/routes/services-settings';
import { index as settingsIndex } from '@/routes/settings';
import { edit as systemSettingsEdit } from '@/routes/system-settings';
import { index as teamIndex } from '@/routes/team';
import { performance } from '@/routes/technicians';
import { edit as editAssistantSettings } from '@/routes/assistant-settings';
import { controlCenter as adminControlCenter } from '@/routes/admin';
import { index as aiProvidersIndex } from '@/routes/ai-providers';
import type { User } from '@/types';

export type SettingsNavigationItem = {
    title: string;
    description: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
};

type Role = User['role'];

export const roleSettingsCopy: Record<
    Role,
    { label: string; eyebrow: string; description: string }
> = {
    customer: {
        label: 'Customer',
        eyebrow: 'Kontrol akun pribadi',
        description:
            'Atur identitas, alamat layanan, privasi, keamanan, dan akses pesananmu.',
    },
    technician: {
        label: 'Teknisi',
        eyebrow: 'Kontrol akun lapangan',
        description:
            'Kelola profil publik teknisi, keamanan wajib, stok pendukung, dan performa pribadi.',
    },
    supervisor: {
        label: 'Supervisor',
        eyebrow: 'Kontrol pengawasan',
        description:
            'Kelola akunmu serta akses evaluasi teknisi dan kesiapan inventaris lapangan.',
    },
    warehouse: {
        label: 'Tim gudang',
        eyebrow: 'Kontrol inventaris',
        description:
            'Kelola keamanan akun dan pusat kendali persediaan barang AD Com.',
    },
    admin: {
        label: 'Admin operasional',
        eyebrow: 'Kontrol operasional',
        description:
            'Kelola akun, tim non-operator, keuangan, inventaris, dan evaluasi layanan.',
    },
    operator: {
        label: 'Superadmin',
        eyebrow: 'Kontrol tingkat tertinggi',
        description:
            'Akses penuh untuk identitas tim, role, layanan, konfigurasi platform, keuangan, inventaris, dan mutu teknisi.',
    },
};

export function accountSettingsForRole(role: Role): SettingsNavigationItem[] {
    const profileTitle =
        role === 'customer'
            ? 'Profil & alamat'
            : role === 'technician'
              ? 'Profil teknisi'
              : 'Profil akun';

    return [
        {
            title: 'Pusat pengaturan',
            description: 'Ringkasan kontrol yang tersedia untuk role ini.',
            href: settingsIndex(),
            icon: Settings2,
        },
        {
            title: profileTitle,
            description:
                role === 'technician'
                    ? 'Foto, bio, spesialisasi, kontak, dan privasi nomor HP.'
                    : 'Nama, username, foto, bio, kontak, dan alamat.',
            href: editProfile(),
            icon: UserRound,
        },
        {
            title:
                role === 'customer' ? 'Password & keamanan' : 'Keamanan & OTP',
            description:
                role === 'customer'
                    ? 'Ganti password, aktifkan OTP opsional, dan kelola passkey.'
                    : 'Ganti password via OTP WhatsApp, serta atur TOTP opsional dan passkey.',
            href: editSecurity(),
            icon: ShieldCheck,
        },
        {
            title: 'Tampilan aplikasi',
            description: 'Pilih mode terang, gelap, atau mengikuti perangkat.',
            href: editAppearance(),
            icon: Palette,
        },
        {
            title: 'Preferensi notifikasi',
            description:
                'Atur preferensi notifikasi in-app dan WhatsApp sesuai kategori.',
            href: notificationsIndex({ query: { tab: 'preferences' } }),
            icon: BellRing,
        },
    ];
}

export function roleToolsForRole(role: Role): SettingsNavigationItem[] {
    if (role === 'customer') {
        return [
            {
                title: 'Pesanan & aktivitas',
                description: 'Pantau tiket, progres, chat, dan rating layanan.',
                href: dashboard(),
                icon: ClipboardList,
            },
            {
                title: 'Pesan layanan baru',
                description: 'Buat permintaan jasa menggunakan profilmu.',
                href: createBooking(),
                icon: PlusCircle,
            },
        ];
    }

    if (role === 'warehouse') {
        return [
            {
                title: 'Kontrol inventaris',
                description:
                    'Kelola stok, mutasi, ambang minimum, dan lokasi barang.',
                href: inventoryIndex(),
                icon: Boxes,
            },
        ];
    }

    if (role === 'technician') {
        return [
            {
                title: 'Performa saya',
                description:
                    'Lihat rating terverifikasi dan area evaluasi pribadi.',
                href: performance(),
                icon: Star,
            },
            {
                title: 'Ketersediaan alat',
                description:
                    'Periksa stok pendukung sebelum mengerjakan tiket.',
                href: inventoryIndex(),
                icon: Boxes,
            },
        ];
    }

    if (role === 'supervisor') {
        return [
            {
                title: 'Evaluasi teknisi',
                description:
                    'Pantau mutu, rating rendah, dan kebutuhan pendampingan.',
                href: performance(),
                icon: Star,
            },
            {
                title: 'Inventaris lapangan',
                description:
                    'Periksa kesiapan barang untuk pekerjaan yang diawasi.',
                href: inventoryIndex(),
                icon: Boxes,
            },
        ];
    }

    const items: SettingsNavigationItem[] = [
        {
            title: role === 'operator' ? 'Hak akses & role' : 'Tim & akses',
            description:
                role === 'operator'
                    ? 'Kelola seluruh role termasuk Superadmin, status akun, dan profil tim.'
                    : 'Kelola anggota operasional tanpa mengubah akun Superadmin.',
            href: teamIndex(),
            icon: UsersRound,
        },
        {
            title:
                role === 'operator'
                    ? 'Keuangan & verifikasi'
                    : 'Keuangan layanan',
            description:
                role === 'operator'
                    ? 'Catat transaksi dan verifikasi bukti secara terpisah.'
                    : 'Kelola pencatatan pemasukan dan pengeluaran layanan.',
            href: financeIndex(),
            icon: Banknote,
        },
        {
            title: 'Inventaris',
            description: 'Kelola stok, mutasi, dan kebutuhan pengadaan.',
            href: inventoryIndex(),
            icon: Boxes,
        },
        {
            title: 'Evaluasi teknisi',
            description: 'Pantau performa berdasarkan pekerjaan terverifikasi.',
            href: performance(),
            icon: Star,
        },
        {
            title: 'Dashboard role',
            description: 'Kembali ke ringkasan tugas dan sinyal prioritas.',
            href: dashboard(),
            icon: LayoutDashboard,
        },
    ];

    if (role === 'operator') {
        items.unshift(
            {
                title: 'Provider & Model AI',
                description:
                    'Kelola multi-provider AI, token terenkripsi, dan discovery model dinamis.',
                href: aiProvidersIndex(),
                icon: ShieldCheck,
            },
            {
                title: 'Integrasi & notifikasi',
                description:
                    'Token Fonnte, API key OpenAI, uji koneksi, dan template notifikasi.',
                href: integrationsSettingsEdit(),
                icon: ShieldCheck,
            },
            {
                title: 'Konfigurasi sistem',
                description:
                    'Identitas bisnis, jam operasional, garansi default, dan opsi OTP.',
                href: systemSettingsEdit(),
                icon: Settings2,
            },
            {
                title: 'Layanan & katalog',
                description:
                    'Kelola daftar layanan AD Com, tarif awal, mode servis, dan status aktif.',
                href: servicesSettingsIndex(),
                icon: Wrench,
            },
        );
    }

    if (role === 'admin' || role === 'operator') {
        items.unshift({
            title:
                role === 'operator'
                    ? 'Pusat Kendali Superadmin'
                    : 'Pusat kendali admin',
            description:
                'Pantau operasional dan buka semua area pengelolaan AD Com.',
            href: adminControlCenter(),
            icon: LayoutDashboard,
        });
        items.unshift({
            title: 'Pengaturan ADI',
            description: 'Atur model AI dan API key untuk asisten layanan.',
            href: editAssistantSettings(),
            icon: Settings2,
        });
    }

    return items;
}
