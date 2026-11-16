import { Head, useForm } from '@inertiajs/react';
import { BadgeCheck, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update as updateSystemSettings } from '@/routes/system-settings';

type Props = {
    settings: {
        business_name: string;
        business_tagline: string;
        business_phone: string;
        business_address: string;
        operating_hours: string;
        warranty_default_days: string;
        registration_otp_enabled: string;
        otp_length: string;
        otp_expiration_minutes: string;
        otp_resend_cooldown_seconds: string;
        otp_max_attempts: string;
        otp_max_resends: string;
        otp_lockout_minutes: string;
        otp_rate_limit_max: string;
        otp_purpose_registration_phone_enabled: string;
        otp_purpose_password_reset_enabled: string;
        otp_purpose_password_change_enabled: string;
        otp_purpose_sensitive_action_enabled: string;
        otp_purpose_phone_change_enabled: string;
        otp_message_template: string;
        sla_normal_response_minutes?: string;
        sla_normal_resolution_minutes?: string;
        sla_soon_response_minutes?: string;
        sla_soon_resolution_minutes?: string;
        sla_high_risk_response_minutes?: string;
        sla_high_risk_resolution_minutes?: string;
        sla_approaching_threshold_percent?: string;
        sla_business_hours_enabled?: string;
        sla_work_start_time?: string;
        sla_work_end_time?: string;
        sla_working_days?: string;
        sla_holidays?: string;
    };
    integrations: {
        whatsapp_configured: boolean;
        google_oauth_configured: boolean;
        app_env: string;
    };
};

export default function SystemSettingsPage({ settings, integrations }: Props) {
    const form = useForm({
        business_name: settings.business_name,
        business_tagline: settings.business_tagline,
        business_phone: settings.business_phone,
        business_address: settings.business_address,
        operating_hours: settings.operating_hours,
        warranty_default_days: settings.warranty_default_days,
        registration_otp_enabled: settings.registration_otp_enabled === '1',
        otp_length: settings.otp_length,
        otp_expiration_minutes: settings.otp_expiration_minutes,
        otp_resend_cooldown_seconds: settings.otp_resend_cooldown_seconds,
        otp_max_attempts: settings.otp_max_attempts,
        otp_max_resends: settings.otp_max_resends,
        otp_lockout_minutes: settings.otp_lockout_minutes,
        otp_rate_limit_max: settings.otp_rate_limit_max,
        otp_purpose_registration_phone_enabled:
            settings.otp_purpose_registration_phone_enabled === '1',
        otp_purpose_password_reset_enabled:
            settings.otp_purpose_password_reset_enabled === '1',
        otp_purpose_password_change_enabled:
            settings.otp_purpose_password_change_enabled === '1',
        otp_purpose_sensitive_action_enabled:
            settings.otp_purpose_sensitive_action_enabled === '1',
        otp_purpose_phone_change_enabled:
            settings.otp_purpose_phone_change_enabled === '1',
        otp_message_template: settings.otp_message_template,
        sla_normal_response_minutes:
            settings.sla_normal_response_minutes ?? '120',
        sla_normal_resolution_minutes:
            settings.sla_normal_resolution_minutes ?? '1440',
        sla_soon_response_minutes: settings.sla_soon_response_minutes ?? '60',
        sla_soon_resolution_minutes:
            settings.sla_soon_resolution_minutes ?? '480',
        sla_high_risk_response_minutes:
            settings.sla_high_risk_response_minutes ?? '30',
        sla_high_risk_resolution_minutes:
            settings.sla_high_risk_resolution_minutes ?? '240',
        sla_approaching_threshold_percent:
            settings.sla_approaching_threshold_percent ?? '80',
        sla_business_hours_enabled: settings.sla_business_hours_enabled === '1',
        sla_work_start_time: settings.sla_work_start_time ?? '08:00',
        sla_work_end_time: settings.sla_work_end_time ?? '17:00',
        sla_working_days: settings.sla_working_days ?? '1,2,3,4,5,6',
        sla_holidays: settings.sla_holidays ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(updateSystemSettings.url(), { preserveScroll: true });
    }

    return (
        <>
            <Head title="Konfigurasi Sistem" />
            <div className="space-y-6">
                <header>
                    <p className="section-kicker text-cyan">
                        Kontrol Superadmin
                    </p>
                    <h1 className="text-foreground mt-2 text-2xl font-black">
                        Konfigurasi platform AD Com
                    </h1>
                    <p className="text-muted-foreground mt-2 max-w-2xl text-sm leading-6">
                        Atur identitas bisnis, jam operasional, garansi default,
                        dan kebijakan platform secara terpusat. Pengaturan ini
                        hanya dapat diubah oleh Superadmin.
                    </p>
                </header>

                <section className="grid gap-3 sm:grid-cols-3">
                    <article className="liquid-panel rounded-2xl p-4">
                        <p className="text-muted-foreground text-xs">
                            Lingkungan aplikasi
                        </p>
                        <p className="text-foreground mt-1.5 text-lg font-black uppercase">
                            {integrations.app_env}
                        </p>
                    </article>
                    <article className="liquid-panel rounded-2xl p-4">
                        <p className="text-muted-foreground text-xs">
                            WhatsApp gateway (Fonnte)
                        </p>
                        <p className="text-foreground mt-1.5 text-lg font-black">
                            {integrations.whatsapp_configured
                                ? 'Terkonfigurasi'
                                : 'Belum diatur'}
                        </p>
                    </article>
                    <article className="liquid-panel rounded-2xl p-4">
                        <p className="text-muted-foreground text-xs">
                            Google OAuth
                        </p>
                        <p className="text-foreground mt-1.5 text-lg font-black">
                            {integrations.google_oauth_configured
                                ? 'Aktif'
                                : 'Belum diatur'}
                        </p>
                    </article>
                </section>

                <section className="liquid-panel rounded-[24px] p-5 sm:p-7">
                    <form onSubmit={submit} className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="business_name">
                                    Nama platform / unit TEFA
                                </Label>
                                <Input
                                    id="business_name"
                                    value={form.data.business_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'business_name',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError
                                    message={form.errors.business_name}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="business_phone">
                                    Nomor WhatsApp resmi
                                </Label>
                                <Input
                                    id="business_phone"
                                    value={form.data.business_phone}
                                    onChange={(e) =>
                                        form.setData(
                                            'business_phone',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="628123450000"
                                />
                                <InputError
                                    message={form.errors.business_phone}
                                />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="business_tagline">
                                Tagline platform
                            </Label>
                            <Input
                                id="business_tagline"
                                value={form.data.business_tagline}
                                onChange={(e) =>
                                    form.setData(
                                        'business_tagline',
                                        e.target.value,
                                    )
                                }
                                required
                            />
                            <InputError
                                message={form.errors.business_tagline}
                            />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="operating_hours">
                                    Jam operasional
                                </Label>
                                <Input
                                    id="operating_hours"
                                    value={form.data.operating_hours}
                                    onChange={(e) =>
                                        form.setData(
                                            'operating_hours',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.operating_hours}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="warranty_default_days">
                                    Garansi standar (hari)
                                </Label>
                                <Input
                                    id="warranty_default_days"
                                    type="number"
                                    min={0}
                                    max={365}
                                    value={form.data.warranty_default_days}
                                    onChange={(e) =>
                                        form.setData(
                                            'warranty_default_days',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError
                                    message={form.errors.warranty_default_days}
                                />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="business_address">
                                Alamat workshop / laboratorium
                            </Label>
                            <Input
                                id="business_address"
                                value={form.data.business_address}
                                onChange={(e) =>
                                    form.setData(
                                        'business_address',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.business_address}
                            />
                        </div>

                        <label className="liquid-control flex items-center gap-3 rounded-2xl p-3.5 text-sm font-bold">
                            <input
                                type="checkbox"
                                checked={form.data.registration_otp_enabled}
                                onChange={(e) =>
                                    form.setData(
                                        'registration_otp_enabled',
                                        e.target.checked,
                                    )
                                }
                                className="accent-cyan size-4"
                            />
                            <span>
                                Aktifkan OTP WhatsApp saat pendaftaran akun baru
                                (TOTP internal tetap opsional dan tidak dipaksa)
                            </span>
                        </label>

                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="otp_length">
                                    Panjang OTP (digit)
                                </Label>
                                <Input
                                    id="otp_length"
                                    type="number"
                                    min={4}
                                    max={8}
                                    value={form.data.otp_length}
                                    onChange={(e) =>
                                        form.setData(
                                            'otp_length',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-muted-foreground text-[11px]">
                                    Jumlah digit kode (default 6).
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="otp_expiration_minutes">
                                    Masa berlaku (menit)
                                </Label>
                                <Input
                                    id="otp_expiration_minutes"
                                    type="number"
                                    min={1}
                                    max={30}
                                    value={form.data.otp_expiration_minutes}
                                    onChange={(e) =>
                                        form.setData(
                                            'otp_expiration_minutes',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-muted-foreground text-[11px]">
                                    Batas waktu validasi kode.
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="otp_resend_cooldown_seconds">
                                    Cooldown resend (detik)
                                </Label>
                                <Input
                                    id="otp_resend_cooldown_seconds"
                                    type="number"
                                    min={10}
                                    max={300}
                                    value={
                                        form.data.otp_resend_cooldown_seconds
                                    }
                                    onChange={(e) =>
                                        form.setData(
                                            'otp_resend_cooldown_seconds',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-muted-foreground text-[11px]">
                                    Jeda minimal kirim ulang.
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="otp_max_attempts">
                                    Maks. percobaan salah
                                </Label>
                                <Input
                                    id="otp_max_attempts"
                                    type="number"
                                    min={3}
                                    max={10}
                                    value={form.data.otp_max_attempts}
                                    onChange={(e) =>
                                        form.setData(
                                            'otp_max_attempts',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-muted-foreground text-[11px]">
                                    Terkunci setelah batas ini.
                                </p>
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="otp_max_resends">
                                    Maks. kirim ulang per sesi
                                </Label>
                                <Input
                                    id="otp_max_resends"
                                    type="number"
                                    min={1}
                                    max={10}
                                    value={form.data.otp_max_resends}
                                    onChange={(e) =>
                                        form.setData(
                                            'otp_max_resends',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-muted-foreground text-[11px]">
                                    Default 3 kali per challenge.
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="otp_lockout_minutes">
                                    Durasi lockout (menit)
                                </Label>
                                <Input
                                    id="otp_lockout_minutes"
                                    type="number"
                                    min={1}
                                    max={120}
                                    value={form.data.otp_lockout_minutes}
                                    onChange={(e) =>
                                        form.setData(
                                            'otp_lockout_minutes',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-muted-foreground text-[11px]">
                                    Lama blokir sementara setelah salah terus.
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="otp_rate_limit_max">
                                    Rate limit (req / 15 menit)
                                </Label>
                                <Input
                                    id="otp_rate_limit_max"
                                    type="number"
                                    min={1}
                                    max={30}
                                    value={form.data.otp_rate_limit_max}
                                    onChange={(e) =>
                                        form.setData(
                                            'otp_rate_limit_max',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-muted-foreground text-[11px]">
                                    Batas request per nomor & IP.
                                </p>
                            </div>
                        </div>

                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {(
                                [
                                    [
                                        'otp_purpose_registration_phone_enabled',
                                        'OTP Registrasi Nomor',
                                    ],
                                    [
                                        'otp_purpose_password_reset_enabled',
                                        'OTP Reset Password',
                                    ],
                                    [
                                        'otp_purpose_password_change_enabled',
                                        'OTP Ubah Password',
                                    ],
                                    [
                                        'otp_purpose_sensitive_action_enabled',
                                        'OTP Aksi Sensitif',
                                    ],
                                    [
                                        'otp_purpose_phone_change_enabled',
                                        'OTP Ubah Nomor WA',
                                    ],
                                ] as const
                            ).map(([field, label]) => (
                                <label
                                    key={field}
                                    className="liquid-control flex items-center gap-2.5 rounded-2xl p-3 text-xs font-bold"
                                >
                                    <input
                                        type="checkbox"
                                        checked={form.data[field]}
                                        onChange={(e) => {
                                            form.setData(
                                                field,
                                                e.target.checked,
                                            );
                                            if (
                                                field ===
                                                'otp_purpose_registration_phone_enabled'
                                            ) {
                                                form.setData(
                                                    'registration_otp_enabled',
                                                    e.target.checked,
                                                );
                                            }
                                        }}
                                        className="accent-cyan size-4"
                                    />
                                    <span>{label}</span>
                                </label>
                            ))}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="otp_message_template">
                                Template pesan OTP WhatsApp (gunakan {'{code}'}{' '}
                                dan {'{minutes}'})
                            </Label>
                            <Input
                                id="otp_message_template"
                                value={form.data.otp_message_template}
                                onChange={(e) =>
                                    form.setData(
                                        'otp_message_template',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.otp_message_template}
                            />
                        </div>

                        <div className="border-border space-y-5 border-t pt-6">
                            <div>
                                <p className="section-kicker text-cyan">
                                    Service Level Agreement (SLA)
                                </p>
                                <h2 className="text-foreground mt-1 text-lg font-black">
                                    Target waktu respon & penyelesaian
                                </h2>
                                <p className="text-muted-foreground mt-1 text-xs leading-5">
                                    Konfigurasi batas waktu respon awal dan
                                    penyelesaian berdasarkan prioritas tiket
                                    serta jadwal hari kerja.
                                </p>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="liquid-control space-y-3 rounded-2xl p-4">
                                    <p className="text-foreground text-xs font-black uppercase">
                                        Prioritas Normal
                                    </p>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="sla_normal_response_minutes">
                                            Respon awal (menit)
                                        </Label>
                                        <Input
                                            id="sla_normal_response_minutes"
                                            type="number"
                                            min={5}
                                            max={10080}
                                            value={
                                                form.data
                                                    .sla_normal_response_minutes
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'sla_normal_response_minutes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="sla_normal_resolution_minutes">
                                            Penyelesaian (menit)
                                        </Label>
                                        <Input
                                            id="sla_normal_resolution_minutes"
                                            type="number"
                                            min={15}
                                            max={43200}
                                            value={
                                                form.data
                                                    .sla_normal_resolution_minutes
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'sla_normal_resolution_minutes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="liquid-control space-y-3 rounded-2xl p-4">
                                    <p className="text-foreground text-xs font-black uppercase">
                                        Prioritas Segera (Soon)
                                    </p>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="sla_soon_response_minutes">
                                            Respon awal (menit)
                                        </Label>
                                        <Input
                                            id="sla_soon_response_minutes"
                                            type="number"
                                            min={5}
                                            max={10080}
                                            value={
                                                form.data
                                                    .sla_soon_response_minutes
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'sla_soon_response_minutes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="sla_soon_resolution_minutes">
                                            Penyelesaian (menit)
                                        </Label>
                                        <Input
                                            id="sla_soon_resolution_minutes"
                                            type="number"
                                            min={15}
                                            max={43200}
                                            value={
                                                form.data
                                                    .sla_soon_resolution_minutes
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'sla_soon_resolution_minutes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="liquid-control space-y-3 rounded-2xl p-4">
                                    <p className="text-foreground text-xs font-black uppercase">
                                        Risiko Tinggi / Darurat
                                    </p>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="sla_high_risk_response_minutes">
                                            Respon awal (menit)
                                        </Label>
                                        <Input
                                            id="sla_high_risk_response_minutes"
                                            type="number"
                                            min={5}
                                            max={10080}
                                            value={
                                                form.data
                                                    .sla_high_risk_response_minutes
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'sla_high_risk_response_minutes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="sla_high_risk_resolution_minutes">
                                            Penyelesaian (menit)
                                        </Label>
                                        <Input
                                            id="sla_high_risk_resolution_minutes"
                                            type="number"
                                            min={15}
                                            max={43200}
                                            value={
                                                form.data
                                                    .sla_high_risk_resolution_minutes
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'sla_high_risk_resolution_minutes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                            </div>

                            <label className="liquid-control flex items-center gap-3 rounded-2xl p-3.5 text-sm font-bold">
                                <input
                                    type="checkbox"
                                    checked={
                                        form.data.sla_business_hours_enabled
                                    }
                                    onChange={(e) =>
                                        form.setData(
                                            'sla_business_hours_enabled',
                                            e.target.checked,
                                        )
                                    }
                                    className="accent-cyan size-4"
                                />
                                <span>
                                    Hitung SLA hanya pada jam kerja operasional
                                    dan kecualikan hari libur
                                </span>
                            </label>

                            <div className="grid gap-4 sm:grid-cols-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="sla_work_start_time">
                                        Jam mulai kerja
                                    </Label>
                                    <Input
                                        id="sla_work_start_time"
                                        value={form.data.sla_work_start_time}
                                        onChange={(e) =>
                                            form.setData(
                                                'sla_work_start_time',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="08:00"
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="sla_work_end_time">
                                        Jam selesai kerja
                                    </Label>
                                    <Input
                                        id="sla_work_end_time"
                                        value={form.data.sla_work_end_time}
                                        onChange={(e) =>
                                            form.setData(
                                                'sla_work_end_time',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="17:00"
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="sla_working_days">
                                        Hari kerja (ISO 1=Sen..7=Min)
                                    </Label>
                                    <Input
                                        id="sla_working_days"
                                        value={form.data.sla_working_days}
                                        onChange={(e) =>
                                            form.setData(
                                                'sla_working_days',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="1,2,3,4,5,6"
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="sla_approaching_threshold_percent">
                                        Batas peringatan SLA (%)
                                    </Label>
                                    <Input
                                        id="sla_approaching_threshold_percent"
                                        type="number"
                                        min={50}
                                        max={95}
                                        value={
                                            form.data
                                                .sla_approaching_threshold_percent
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                'sla_approaching_threshold_percent',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="sla_holidays">
                                    Daftar tanggal libur non-operasional (format
                                    YYYY-MM-DD, pisahkan koma)
                                </Label>
                                <Input
                                    id="sla_holidays"
                                    value={form.data.sla_holidays}
                                    onChange={(e) =>
                                        form.setData(
                                            'sla_holidays',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="2026-08-17, 2026-12-25"
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button disabled={form.processing}>
                                <BadgeCheck />{' '}
                                {form.processing
                                    ? 'Menyimpan…'
                                    : 'Simpan konfigurasi'}
                            </Button>
                            {form.recentlySuccessful && (
                                <span
                                    role="status"
                                    className="flex items-center gap-1.5 text-xs font-bold text-emerald-700"
                                >
                                    <ShieldCheck className="size-4" />{' '}
                                    Konfigurasi tersimpan dan dicatat di audit
                                    log.
                                </span>
                            )}
                        </div>
                    </form>
                </section>
            </div>
        </>
    );
}
