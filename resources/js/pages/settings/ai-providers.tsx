import { Head, router, useForm } from '@inertiajs/react';
import {
    Activity,
    BadgeCheck,
    Bot,
    CheckCircle2,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { GlassSearchableSelect } from '@/components/glass';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    activate,
    destroy,
    discover,
    store,
    test as testProvider,
    update,
    validateModel,
} from '@/routes/ai-providers';

type DiscoveredModel = {
    id: string;
    name: string;
};

type ProviderRow = {
    id: number;
    driver: string;
    name: string;
    base_url: string | null;
    token_configured: boolean;
    masked_token: string | null;
    model_id: string | null;
    model_display_name: string | null;
    discovered_models: DiscoveredModel[];
    is_active: boolean;
    is_default: boolean;
    connection_status: string;
    last_error: string | null;
    last_validated_at: string | null;
};

type DriverOption = {
    value: string;
    label: string;
};

type Props = {
    providers: ProviderRow[];
    activeDefault: ProviderRow | null;
    drivers: DriverOption[];
};

export default function AiProvidersPage({
    providers,
    activeDefault,
    drivers,
}: Props) {
    const createForm = useForm({
        driver: 'openai',
        name: '',
        base_url: '',
        api_token: '',
        is_default: false,
    });

    function submitCreate(event: FormEvent) {
        event.preventDefault();
        createForm.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => createForm.reset('name', 'base_url', 'api_token'),
        });
    }

    return (
        <>
            <Head title="Dynamic AI Provider Manager" />
            <div className="space-y-6">
                <header>
                    <p className="section-kicker text-cyan">
                        Dynamic AI Provider & Model Manager
                    </p>
                    <h1 className="text-foreground mt-2 text-2xl font-black">
                        Kelola Provider & Model AI untuk ADI
                    </h1>
                    <p className="text-muted-foreground mt-2 max-w-2xl text-sm leading-6">
                        Tambahkan beberapa provider AI, simpan API token secara
                        terenkripsi, muat daftar model langsung dari API resmi
                        provider, dan pilih provider/model aktif tanpa ubah
                        kode.
                    </p>
                </header>

                <section className="liquid-panel rounded-[26px] p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="bg-cyan/12 text-cyan grid size-10 place-items-center rounded-xl">
                                <Bot className="size-5" />
                            </span>
                            <div>
                                <p className="text-muted-foreground text-xs font-bold uppercase">
                                    Provider & Model Aktif ADI Saat Ini
                                </p>
                                <p className="text-foreground text-sm font-black">
                                    {activeDefault
                                        ? `${activeDefault.name} (${activeDefault.driver}) · Model: ${activeDefault.model_id ?? 'Belum dipilih'}`
                                        : 'Belum ada provider default (menggunakan pengaturan fallback)'}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                {/* Add provider */}
                <section className="liquid-panel rounded-[26px] p-6">
                    <h2 className="text-foreground text-lg font-black">
                        Tambah AI Provider Baru
                    </h2>
                    <form onSubmit={submitCreate} className="mt-4 space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="driver">Provider</Label>
                                <select
                                    id="driver"
                                    value={createForm.data.driver}
                                    onChange={(e) =>
                                        createForm.setData(
                                            'driver',
                                            e.target.value,
                                        )
                                    }
                                    className="liquid-input h-10 rounded-xl border px-3 text-sm"
                                >
                                    {drivers.map((d) => (
                                        <option key={d.value} value={d.value}>
                                            {d.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={createForm.errors.driver}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="provider_name">
                                    Nama Konfigurasi
                                </Label>
                                <Input
                                    id="provider_name"
                                    placeholder="Misal: Gemini Lab TKJ"
                                    value={createForm.data.name}
                                    onChange={(e) =>
                                        createForm.setData(
                                            'name',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={createForm.errors.name} />
                            </div>
                        </div>

                        {createForm.data.driver === 'custom_openai' && (
                            <div className="grid gap-2">
                                <Label htmlFor="base_url">
                                    Custom Base URL (wajib HTTPS, anti-SSRF)
                                </Label>
                                <Input
                                    id="base_url"
                                    placeholder="https://api.example.com/v1"
                                    value={createForm.data.base_url}
                                    onChange={(e) =>
                                        createForm.setData(
                                            'base_url',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={createForm.errors.base_url}
                                />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="api_token">
                                API Token (disimpan terenkripsi)
                            </Label>
                            <Input
                                id="api_token"
                                type="password"
                                placeholder="Masukkan API token provider"
                                value={createForm.data.api_token}
                                onChange={(e) =>
                                    createForm.setData(
                                        'api_token',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={createForm.errors.api_token} />
                        </div>

                        <label className="flex items-center gap-2 text-xs font-bold">
                            <input
                                type="checkbox"
                                checked={createForm.data.is_default}
                                onChange={(e) =>
                                    createForm.setData(
                                        'is_default',
                                        e.target.checked,
                                    )
                                }
                                className="accent-cyan size-4"
                            />
                            Jadikan provider default untuk ADI setelah disimpan
                        </label>

                        <Button disabled={createForm.processing}>
                            <Plus className="size-4" /> Tambah Provider
                        </Button>
                    </form>
                </section>

                {/* Saved Providers */}
                <section className="space-y-4">
                    {providers.map((provider) => (
                        <ProviderCard key={provider.id} provider={provider} />
                    ))}
                </section>
            </div>
        </>
    );
}

function ProviderCard({ provider }: { provider: ProviderRow }) {
    const [manualModel, setManualModel] = useState(provider.model_id ?? '');
    const [newToken, setNewToken] = useState('');

    return (
        <article className="liquid-panel rounded-[26px] p-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-ink text-lg font-black">
                            {provider.name}
                        </h3>
                        <span className="bg-muted text-muted-foreground rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase">
                            {provider.driver}
                        </span>
                        {provider.is_default && (
                            <span className="bg-primary/14 inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-black text-[var(--brand-text)]">
                                <CheckCircle2 className="size-3" /> Default ADI
                            </span>
                        )}
                        <span
                            className={`rounded-full px-2.5 py-0.5 text-[11px] font-bold ${
                                provider.connection_status === 'connected'
                                    ? 'status-pill-success'
                                    : provider.connection_status === 'failed'
                                      ? 'status-pill-danger'
                                      : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            {provider.connection_status}
                        </span>
                    </div>
                    <div className="mt-2 flex flex-wrap gap-1.5 text-[11px] font-semibold">
                        <span
                            className={`rounded-full px-2.5 py-0.5 ${
                                provider.token_configured
                                    ? 'status-pill-success'
                                    : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            1. Token:{' '}
                            {provider.token_configured
                                ? `Tersimpan (${provider.masked_token})`
                                : 'Belum diatur'}
                        </span>
                        <span
                            className={`rounded-full px-2.5 py-0.5 ${
                                provider.connection_status === 'connected'
                                    ? 'status-pill-success'
                                    : provider.connection_status === 'failed'
                                      ? 'status-pill-danger'
                                      : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            2. Koneksi:{' '}
                            {provider.connection_status === 'connected'
                                ? 'Berhasil'
                                : provider.connection_status === 'failed'
                                  ? 'Gagal'
                                  : 'Belum diuji'}
                        </span>
                        <span
                            className={`rounded-full px-2.5 py-0.5 ${
                                provider.model_id
                                    ? 'bg-primary/12 text-[var(--brand-text)]'
                                    : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            3. Model: {provider.model_id ?? 'Belum dipilih'}
                        </span>
                    </div>
                    {provider.last_validated_at && (
                        <p className="text-muted-foreground mt-1 text-xs">
                            Terakhir diuji: {provider.last_validated_at}
                        </p>
                    )}
                    {provider.last_error && (
                        <p className="text-crimson mt-1 text-xs font-semibold">
                            {provider.last_error}
                        </p>
                    )}
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                testProvider.url({ aiProvider: provider.id }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Activity className="size-3.5" /> Test Connection
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                discover.url({ aiProvider: provider.id }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Bot className="size-3.5" /> Discover Models
                    </Button>
                    {!provider.is_default && (
                        <Button
                            type="button"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    activate.url({ aiProvider: provider.id }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <BadgeCheck className="size-3.5" /> Jadikan Default
                            ADI
                        </Button>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        variant="destructive"
                        onClick={() =>
                            router.delete(
                                destroy.url({ aiProvider: provider.id }),
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Trash2 className="size-3.5" /> Hapus
                    </Button>
                </div>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                {/* Dynamic discovered models selector */}
                <div className="grid min-w-0 gap-1.5">
                    <Label>
                        Pilih Model ({provider.discovered_models.length}{' '}
                        ditemukan)
                    </Label>
                    <GlassSearchableSelect
                        value={provider.model_id ?? ''}
                        placeholder="-- Pilih hasil discovery --"
                        searchPlaceholder="Cari nama atau ID model..."
                        emptyText="Model tidak ditemukan. Coba kata kunci lain."
                        options={provider.discovered_models.map((m) => ({
                            value: m.id,
                            label: m.name,
                        }))}
                        onChange={(selectedId) => {
                            const selected = provider.discovered_models.find(
                                (m) => m.id === selectedId,
                            );
                            router.patch(
                                update.url({ aiProvider: provider.id }),
                                {
                                    model_id: selectedId,
                                    model_display_name:
                                        selected?.name ?? selectedId,
                                },
                                { preserveScroll: true },
                            );
                        }}
                    />
                </div>

                {/* Manual model fallback */}
                <div className="grid min-w-0 gap-1.5">
                    <Label>Atau Validasi Model Manual</Label>
                    <div className="flex min-w-0 gap-2">
                        <Input
                            className="min-w-0 flex-1"
                            placeholder="model-id"
                            value={manualModel}
                            onChange={(e) => setManualModel(e.target.value)}
                        />
                        <Button
                            className="shrink-0"
                            type="button"
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    validateModel.url({
                                        aiProvider: provider.id,
                                    }),
                                    { model_id: manualModel },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Validasi
                        </Button>
                    </div>
                </div>

                {/* Token update */}
                <div className="grid min-w-0 gap-1.5">
                    <Label>Perbarui Token (opsional)</Label>
                    <div className="flex min-w-0 gap-2">
                        <Input
                            className="min-w-0 flex-1"
                            type="password"
                            placeholder="Token baru"
                            value={newToken}
                            onChange={(e) => setNewToken(e.target.value)}
                        />
                        <Button
                            className="shrink-0"
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={newToken.trim().length < 8}
                            onClick={() => {
                                router.patch(
                                    update.url({ aiProvider: provider.id }),
                                    { api_token: newToken },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setNewToken(''),
                                    },
                                );
                            }}
                        >
                            Simpan
                        </Button>
                    </div>
                </div>
            </div>
        </article>
    );
}
