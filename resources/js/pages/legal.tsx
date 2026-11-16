import { Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import PublicShell from '@/components/public-shell';

const privacySections = [
    [
        'Data yang dikumpulkan',
        'Nama, nomor kontak, email opsional, alamat layanan, keluhan, preferensi jadwal, riwayat status, dan bukti pekerjaan yang memang diperlukan untuk menangani tiket.',
    ],
    [
        'Tujuan penggunaan',
        'Data digunakan untuk triase, komunikasi, penjadwalan, pelaksanaan layanan, supervisi, garansi, pencatatan transaksi, serta evaluasi agregat unit bisnis TKJ.',
    ],
    [
        'Percakapan dengan ADI',
        'Percakapan tidak dicatat sebagai tiket atau disimpan dalam basis data AD Com. Saat mode AI aktif, pesan saat ini dan maksimal delapan pesan terakhir diproses oleh penyedia AI untuk menyusun jawaban. Jangan kirim password, OTP, kode akses tiket, atau data pribadi sensitif.',
    ],
    [
        'Akses dan keamanan',
        'Akses dibatasi sesuai peran. Tautan pelacakan menggunakan token atau kode akses privat. Password, kode akses, serta kredensial tidak disimpan dalam bentuk teks biasa.',
    ],
    [
        'Bukti pekerjaan',
        'Tim tidak boleh mengunggah password Wi-Fi, private key, dokumen pribadi, wajah yang tidak relevan, atau layar yang berisi data sensitif.',
    ],
    [
        'Retensi dan permintaan',
        'Kebijakan masa simpan final mengikuti keputusan sekolah. Pelanggan dapat menghubungi admin AD Com untuk koreksi atau penghapusan data sesuai kebijakan yang berlaku.',
    ],
];

const termsSections = [
    [
        'Sifat permintaan',
        'Pengiriman formulir membuat tiket untuk diperiksa, bukan persetujuan otomatis atas jadwal, harga, atau hasil diagnosis.',
    ],
    [
        'Panduan ADI',
        'Jawaban ADI adalah pemetaan awal berbasis katalog layanan, bukan diagnosis final, janji jadwal, atau penetapan harga. Untuk kondisi berisiko, hentikan penggunaan perangkat dan tunggu pemeriksaan orang dewasa atau teknisi.',
    ],
    [
        'Estimasi dan perubahan biaya',
        'Harga final berlaku setelah pemeriksaan dan persetujuan pelanggan. Biaya tambahan harus disetujui sebelum pekerjaan terkait dilakukan.',
    ],
    [
        'Keselamatan dan batas layanan',
        'AD Com dapat menjadwalkan ulang atau menolak pekerjaan di luar keahlian tim, area, kapasitas, legalitas, atau batas keselamatan unit bisnis sekolah.',
    ],
    [
        'Garansi dan pembukaan ulang',
        'Cakupan serta durasi garansi mengikuti jenis layanan dan dicatat pada tiket. Kerusakan baru, kelalaian, atau intervensi pihak lain dapat berada di luar cakupan.',
    ],
    [
        'Status dokumen',
        'Draf ini harus ditinjau dan disahkan pihak sekolah sebelum peluncuran publik. Identitas badan, kontak pengaduan, dan kebijakan pembayaran wajib dilengkapi.',
    ],
];

export default function Legal({ kind }: { kind: 'privacy' | 'terms' }) {
    const privacy = kind === 'privacy';
    const sections = privacy ? privacySections : termsSections;
    const title = privacy ? 'Kebijakan privasi' : 'Syarat layanan';

    return (
        <PublicShell>
            <Head title={title} />
            <main className="min-h-[calc(100vh-9rem)] py-12 sm:py-16">
                <div className="mx-auto max-w-3xl px-4 sm:px-6">
                    <span className="bg-cyan/12 text-cyan grid size-12 place-items-center rounded-2xl">
                        <ShieldCheck />
                    </span>
                    <p className="section-kicker text-cyan mt-7">
                        Dokumen layanan AD Com
                    </p>
                    <h1 className="mt-3 text-4xl font-black tracking-[-0.055em] sm:text-5xl">
                        {title}
                    </h1>
                    <p className="text-ink/52 mt-4 text-sm leading-6">
                        Versi draf pilot · 3 September 2026. Dokumen ini perlu
                        disahkan sekolah sebelum peluncuran resmi.
                    </p>
                    <div className="mt-9 space-y-4">
                        {sections.map(([heading, copy], index) => (
                            <section
                                key={heading}
                                className="liquid-panel rounded-[24px] p-5 sm:p-6"
                            >
                                <p className="text-cyan font-mono text-[10px] font-bold">
                                    {String(index + 1).padStart(2, '0')}
                                </p>
                                <h2 className="text-ink mt-2 text-lg font-black">
                                    {heading}
                                </h2>
                                <p className="text-ink/55 mt-2 text-sm leading-7">
                                    {copy}
                                </p>
                            </section>
                        ))}
                    </div>
                </div>
            </main>
        </PublicShell>
    );
}
