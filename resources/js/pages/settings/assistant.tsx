import { Form, Head, Link } from '@inertiajs/react';
import AssistantSettingsController from '@/actions/App/Http/Controllers/Settings/AssistantSettingsController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as aiProvidersIndex } from '@/routes/ai-providers';

type Props = {
    model: string;
    hasApiKey: boolean;
    isEnabled: boolean;
    aiActive: boolean;
    activeProvider: {
        name: string;
        driver_label: string;
        model_id: string | null;
        connection_status: string;
        last_validated_at: string | null;
    } | null;
    maxOutputTokens: number;
    customInstructions: string;
    activeServicesCount: number;
    limits: Array<{ label: string; value: string }>;
};

export default function AssistantSettings({
    model,
    hasApiKey,
    isEnabled,
    aiActive,
    activeProvider,
    maxOutputTokens,
    customInstructions,
    activeServicesCount,
    limits,
}: Props) {
    return (
        <>
            <Head title="Pengaturan ADI" />
            <div className="space-y-6">
                <div>
                    <h1 className="text-foreground text-2xl font-black">
                        Pengaturan ADI
                    </h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Atur model AI, endpoint API, token jawaban, instruksi
                        tambahan, dan batas kuota asisten ADI.
                    </p>
                </div>

                <section
                    className="grid gap-3 sm:grid-cols-3"
                    aria-label="Ringkasan status ADI"
                >
                    <article className="liquid-panel rounded-2xl p-4">
                        <p className="text-muted-foreground text-xs">
                            Mode aktif ADI
                        </p>
                        <p className="text-foreground mt-1.5 text-lg font-black">
                            {aiActive
                                ? 'AI OpenAI aktif'
                                : isEnabled
                                  ? 'Mode panduan (tanpa key)'
                                  : 'AI dinonaktifkan'}
                        </p>
                    </article>
                    <article className="liquid-panel rounded-2xl p-4">
                        <p className="text-muted-foreground text-xs">
                            Model & batas
                        </p>
                        <p className="text-foreground mt-1.5 truncate text-lg font-black">
                            {model}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            Maks {maxOutputTokens} token/balasan
                        </p>
                    </article>
                    <article className="liquid-panel rounded-2xl p-4">
                        <p className="text-muted-foreground text-xs">
                            Pengetahuan layanan
                        </p>
                        <p className="text-foreground mt-1.5 text-lg font-black">
                            {activeServicesCount} layanan aktif
                        </p>
                        <p className="text-muted-foreground text-xs">
                            Sinkron otomatis dari katalog
                        </p>
                    </article>
                </section>

                <section className="liquid-panel rounded-[24px] p-5 sm:p-7">
                    <Form
                        {...AssistantSettingsController.update.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-5"
                    >
                        {({ errors, processing, wasSuccessful }) => (
                            <>
                                <label className="liquid-control flex items-center gap-3 rounded-2xl p-3.5 text-sm font-bold">
                                    <input
                                        type="hidden"
                                        name="is_enabled"
                                        value="0"
                                    />
                                    <input
                                        type="checkbox"
                                        name="is_enabled"
                                        value="1"
                                        defaultChecked={isEnabled}
                                        className="accent-cyan size-4"
                                    />
                                    <span>
                                        Aktifkan balasan AI eksternal (jika
                                        dimatikan, ADI memakai mode panduan
                                        internal AD Com)
                                    </span>
                                </label>
                                <div className="liquid-control flex flex-wrap items-center justify-between gap-4 rounded-2xl p-4">
                                    <div>
                                        <p className="text-foreground text-sm font-black">
                                            Provider & Token AI (Single Source
                                            of Truth)
                                        </p>
                                        <p className="text-muted-foreground mt-0.5 text-xs">
                                            {activeProvider
                                                ? `${activeProvider.name} (${activeProvider.driver_label}) · Model: ${activeProvider.model_id ?? 'Belum dipilih'} · Status: ${activeProvider.connection_status}`
                                                : hasApiKey
                                                  ? `Menggunakan konfigurasi cadangan (${model}). Disarankan kelola di AI Provider Manager.`
                                                  : 'Belum ada AI Provider aktif. Tambahkan provider dan token di AI Provider Manager.'}
                                        </p>
                                    </div>
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={aiProvidersIndex()}>
                                            Kelola Provider & Token AI
                                        </Link>
                                    </Button>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="max_output_tokens">
                                        Batas token per balasan
                                    </Label>
                                    <Input
                                        id="max_output_tokens"
                                        name="max_output_tokens"
                                        type="number"
                                        min={150}
                                        max={2000}
                                        defaultValue={maxOutputTokens}
                                    />
                                    {errors.max_output_tokens && (
                                        <p className="text-destructive text-xs">
                                            {errors.max_output_tokens}
                                        </p>
                                    )}
                                    <p className="text-muted-foreground text-xs">
                                        Rentang 150 – 2000 token (default 450).
                                    </p>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="custom_instructions">
                                        Instruksi tambahan untuk ADI
                                    </Label>
                                    <textarea
                                        id="custom_instructions"
                                        name="custom_instructions"
                                        defaultValue={customInstructions}
                                        maxLength={2000}
                                        rows={3}
                                        placeholder="Contoh: Jam operasional bengkel Senin–Sabtu 08.00–17.00 WITA. Prioritaskan penawaran pengecekan awal."
                                        className="liquid-input w-full resize-y rounded-xl px-3 py-2 text-sm"
                                    />
                                    {errors.custom_instructions && (
                                        <p className="text-destructive text-xs">
                                            {errors.custom_instructions}
                                        </p>
                                    )}
                                    <p className="text-muted-foreground text-xs">
                                        Opsional. Ditambahkan ke konteks sistem
                                        ADI tanpa mengubah aturan keamanan
                                        dasar.
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Menyimpan…'
                                            : 'Simpan pengaturan'}
                                    </Button>
                                    {wasSuccessful && (
                                        <span role="status" className="text-sm">
                                            Pengaturan tersimpan.
                                        </span>
                                    )}
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <section className="liquid-panel rounded-[24px] p-5 sm:p-7">
                    <h2 className="text-foreground font-black">
                        Batas pemakaian ADI di AD Com
                    </h2>
                    <p className="text-muted-foreground mt-1 text-xs">
                        Batas ini dijaga server agar kuota API tidak habis oleh
                        spam.
                    </p>
                    <dl className="mt-4 grid gap-2 sm:grid-cols-2">
                        {limits.map((limit) => (
                            <div
                                key={limit.label}
                                className="surface-tile rounded-xl px-3 py-2 text-sm"
                            >
                                <dt className="text-muted-foreground text-xs">
                                    {limit.label}
                                </dt>
                                <dd className="text-foreground font-semibold">
                                    {limit.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>
            </div>
        </>
    );
}
