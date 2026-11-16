import { Head, useForm } from '@inertiajs/react';
import { BadgeCheck, Plus, Wrench } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    store as storeService,
    update as updateService,
} from '@/routes/services-settings';

type ServiceItem = {
    id: number;
    code: string;
    slug: string;
    name: string;
    summary: string;
    description: string | null;
    starting_price: number | null;
    pricing_label: string;
    service_mode: 'onsite' | 'dropoff' | 'remote';
    risk_level: 'low' | 'medium' | 'high';
    warranty_days: number;
    sort_order: number;
    is_active: boolean;
    orders_count: number;
};

export default function ServicesSettingsPage({
    services,
}: {
    services: ServiceItem[];
}) {
    const createForm = useForm({
        code: '',
        name: '',
        summary: '',
        description: '',
        starting_price: '50000',
        pricing_label: 'Mulai Rp50 ribu',
        service_mode: 'onsite',
        risk_level: 'medium',
        warranty_days: '14',
        sort_order: String(services.length + 1),
        is_active: true,
    });

    function submitCreate(event: FormEvent) {
        event.preventDefault();
        createForm.post(storeService.url(), {
            preserveScroll: true,
            onSuccess: () =>
                createForm.reset('code', 'name', 'summary', 'description'),
        });
    }

    return (
        <>
            <Head title="Layanan & Katalog" />
            <div className="space-y-6">
                <header>
                    <p className="section-kicker text-cyan">
                        Kontrol Superadmin
                    </p>
                    <h1 className="text-foreground mt-2 text-2xl font-black">
                        Manajemen layanan & katalog
                    </h1>
                    <p className="text-muted-foreground mt-2 max-w-2xl text-sm leading-6">
                        Tambah, ubah tarif awal, mode servis, garansi, dan
                        status aktif layanan AD Com. Perubahan otomatis
                        tersinkron ke halaman pemesanan dan konteks ADI.
                    </p>
                </header>

                <details className="liquid-panel rounded-[24px] p-5 sm:p-6">
                    <summary className="text-foreground flex cursor-pointer list-none items-center justify-between text-sm font-black">
                        <span className="flex items-center gap-2">
                            <Plus className="text-cyan size-4" /> Tambah layanan
                            baru
                        </span>
                        <span className="text-muted-foreground text-xs">
                            Buka form +
                        </span>
                    </summary>
                    <form
                        onSubmit={submitCreate}
                        className="border-border mt-4 grid gap-4 border-t pt-4 sm:grid-cols-2"
                    >
                        <div>
                            <Label htmlFor="new_code">Kode layanan</Label>
                            <Input
                                id="new_code"
                                value={createForm.data.code}
                                onChange={(e) =>
                                    createForm.setData('code', e.target.value)
                                }
                                placeholder="NET.05"
                                required
                            />
                            <InputError message={createForm.errors.code} />
                        </div>
                        <div>
                            <Label htmlFor="new_name">Nama layanan</Label>
                            <Input
                                id="new_name"
                                value={createForm.data.name}
                                onChange={(e) =>
                                    createForm.setData('name', e.target.value)
                                }
                                placeholder="Pemeliharaan Server Sekolah"
                                required
                            />
                            <InputError message={createForm.errors.name} />
                        </div>
                        <div className="sm:col-span-2">
                            <Label htmlFor="new_summary">
                                Ringkasan singkat
                            </Label>
                            <Input
                                id="new_summary"
                                value={createForm.data.summary}
                                onChange={(e) =>
                                    createForm.setData(
                                        'summary',
                                        e.target.value,
                                    )
                                }
                                required
                            />
                            <InputError message={createForm.errors.summary} />
                        </div>
                        <div>
                            <Label htmlFor="new_price">Harga awal (Rp)</Label>
                            <Input
                                id="new_price"
                                type="number"
                                min={0}
                                value={createForm.data.starting_price}
                                onChange={(e) =>
                                    createForm.setData(
                                        'starting_price',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={createForm.errors.starting_price}
                            />
                        </div>
                        <div>
                            <Label htmlFor="new_pricing_label">
                                Label harga
                            </Label>
                            <Input
                                id="new_pricing_label"
                                value={createForm.data.pricing_label}
                                onChange={(e) =>
                                    createForm.setData(
                                        'pricing_label',
                                        e.target.value,
                                    )
                                }
                                required
                            />
                            <InputError
                                message={createForm.errors.pricing_label}
                            />
                        </div>
                        <div>
                            <Label htmlFor="new_mode">Mode layanan</Label>
                            <select
                                id="new_mode"
                                className="liquid-input mt-1 h-10 w-full rounded-xl px-3 text-sm"
                                value={createForm.data.service_mode}
                                onChange={(e) =>
                                    createForm.setData(
                                        'service_mode',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="onsite">
                                    Kunjungan (Onsite)
                                </option>
                                <option value="dropoff">
                                    Antar unit (Drop-off)
                                </option>
                                <option value="remote">
                                    Jarak jauh (Remote)
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="new_risk">Tingkat risiko</Label>
                            <select
                                id="new_risk"
                                className="liquid-input mt-1 h-10 w-full rounded-xl px-3 text-sm"
                                value={createForm.data.risk_level}
                                onChange={(e) =>
                                    createForm.setData(
                                        'risk_level',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="low">Rendah</option>
                                <option value="medium">Sedang</option>
                                <option value="high">Tinggi</option>
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="new_warranty">Garansi (hari)</Label>
                            <Input
                                id="new_warranty"
                                type="number"
                                min={0}
                                max={365}
                                value={createForm.data.warranty_days}
                                onChange={(e) =>
                                    createForm.setData(
                                        'warranty_days',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div>
                            <Label htmlFor="new_sort">Urutan tampil</Label>
                            <Input
                                id="new_sort"
                                type="number"
                                min={0}
                                value={createForm.data.sort_order}
                                onChange={(e) =>
                                    createForm.setData(
                                        'sort_order',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
                            <Button disabled={createForm.processing}>
                                <Plus /> Simpan layanan baru
                            </Button>
                            {createForm.recentlySuccessful && (
                                <span
                                    role="status"
                                    className="text-xs font-bold text-emerald-700"
                                >
                                    Layanan baru tersimpan.
                                </span>
                            )}
                        </div>
                    </form>
                </details>

                <section className="space-y-4">
                    {services.map((service) => (
                        <ServiceRowCard key={service.id} service={service} />
                    ))}
                </section>
            </div>
        </>
    );
}

function ServiceRowCard({ service }: { service: ServiceItem }) {
    const form = useForm({
        code: service.code,
        name: service.name,
        summary: service.summary,
        description: service.description ?? '',
        starting_price:
            service.starting_price === null
                ? ''
                : String(service.starting_price),
        pricing_label: service.pricing_label,
        service_mode: service.service_mode,
        risk_level: service.risk_level,
        warranty_days: String(service.warranty_days),
        sort_order: String(service.sort_order),
        is_active: service.is_active,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(updateService.url(service.id), { preserveScroll: true });
    }

    return (
        <article className="liquid-panel rounded-[24px] p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="bg-cyan/10 text-cyan rounded-full px-2.5 py-0.5 text-[11px] font-black">
                            {service.code}
                        </span>
                        <h2 className="text-ink font-black">{service.name}</h2>
                        <span
                            className={`rounded-full px-2.5 py-0.5 text-[10px] font-black ${
                                service.is_active
                                    ? 'status-pill-success'
                                    : 'status-pill-danger'
                            }`}
                        >
                            {service.is_active ? 'AKTIF' : 'NONAKTIF'}
                        </span>
                    </div>
                    <p className="text-muted-foreground mt-1 text-xs">
                        {service.summary}
                    </p>
                    <p className="text-muted-foreground/80 mt-1 text-[11px]">
                        {service.pricing_label} · Garansi{' '}
                        {service.warranty_days} hari · {service.orders_count}{' '}
                        tiket
                    </p>
                </div>
                <Wrench className="text-cyan/60 size-4 shrink-0" />
            </div>

            <details className="border-border mt-4 border-t pt-3">
                <summary className="text-muted-foreground hover:text-foreground cursor-pointer list-none text-xs font-black">
                    Edit layanan & status +
                </summary>
                <form
                    onSubmit={submit}
                    className="mt-4 grid gap-3 sm:grid-cols-2"
                >
                    <div>
                        <Label>Kode</Label>
                        <Input
                            value={form.data.code}
                            onChange={(e) =>
                                form.setData('code', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.code} />
                    </div>
                    <div>
                        <Label>Nama layanan</Label>
                        <Input
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="sm:col-span-2">
                        <Label>Ringkasan</Label>
                        <Input
                            value={form.data.summary}
                            onChange={(e) =>
                                form.setData('summary', e.target.value)
                            }
                            required
                        />
                    </div>
                    <div>
                        <Label>Harga awal (Rp)</Label>
                        <Input
                            type="number"
                            min={0}
                            value={form.data.starting_price}
                            onChange={(e) =>
                                form.setData('starting_price', e.target.value)
                            }
                        />
                    </div>
                    <div>
                        <Label>Label harga</Label>
                        <Input
                            value={form.data.pricing_label}
                            onChange={(e) =>
                                form.setData('pricing_label', e.target.value)
                            }
                            required
                        />
                    </div>
                    <div>
                        <Label>Mode</Label>
                        <select
                            className="liquid-input mt-1 h-10 w-full rounded-xl px-3 text-sm"
                            value={form.data.service_mode}
                            onChange={(e) =>
                                form.setData(
                                    'service_mode',
                                    e.target.value as
                                        | 'onsite'
                                        | 'dropoff'
                                        | 'remote',
                                )
                            }
                        >
                            <option value="onsite">Onsite</option>
                            <option value="dropoff">Drop-off</option>
                            <option value="remote">Remote</option>
                        </select>
                    </div>
                    <div>
                        <Label>Garansi (hari)</Label>
                        <Input
                            type="number"
                            min={0}
                            max={365}
                            value={form.data.warranty_days}
                            onChange={(e) =>
                                form.setData('warranty_days', e.target.value)
                            }
                        />
                    </div>
                    <label className="liquid-control flex items-center gap-2 rounded-xl p-3 text-xs font-bold sm:col-span-2">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(e) =>
                                form.setData('is_active', e.target.checked)
                            }
                            className="accent-cyan size-4"
                        />
                        Layanan aktif di katalog pemesanan
                    </label>
                    <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
                        <Button disabled={form.processing}>
                            <BadgeCheck /> Simpan perubahan
                        </Button>
                        {form.recentlySuccessful && (
                            <span
                                role="status"
                                className="text-xs font-bold text-emerald-700"
                            >
                                Perubahan tersimpan.
                            </span>
                        )}
                    </div>
                </form>
            </details>
        </article>
    );
}
