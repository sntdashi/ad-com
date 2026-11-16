<?php

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use App\Models\Estimate;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\TechnicianReview;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $users = $this->seedUsers();
        $services = $this->seedServices();
        $this->seedOrders($users, $services);
        $this->seedTechnicianReviews($users);
        $this->seedInventory($users);
        $this->seedFinanceAndMessages($users);
    }

    /** @return array<string, User> */
    private function seedUsers(): array
    {
        $accounts = [
            'operator' => ['name' => 'Operator Utama AD Com', 'email' => 'operator@adcom.test', 'role' => 'operator', 'phone' => '628123450000'],
            'admin' => ['name' => 'Admin AD Com', 'email' => 'admin@adcom.test', 'role' => 'admin', 'phone' => '628123450001'],
            'supervisor' => ['name' => 'Andi Pratama', 'email' => 'supervisor@adcom.test', 'role' => 'supervisor', 'phone' => '628123450002'],
            'technician' => ['name' => 'Raka Wijaya', 'email' => 'teknisi@adcom.test', 'role' => 'technician', 'phone' => '628123450003', 'specialty' => 'Lead teknisi CCTV & jaringan', 'bio' => 'Berpengalaman menangani instalasi lapangan, konfigurasi jaringan, dan dokumentasi serah terima.', 'show_phone_publicly' => true],
            'technician_network' => ['name' => 'Nabila Putri', 'email' => 'nabila.teknisi@adcom.test', 'role' => 'technician', 'phone' => '628123450005', 'specialty' => 'Jaringan & wireless', 'bio' => 'Fokus pada audit sinyal, access point, segmentasi jaringan, dan pengujian koneksi.', 'show_phone_publicly' => true],
            'technician_device' => ['name' => 'Dedi Kurnia', 'email' => 'dedi.teknisi@adcom.test', 'role' => 'technician', 'phone' => '628123450006', 'specialty' => 'Komputer & perangkat', 'bio' => 'Menangani diagnosis perangkat, perawatan, upgrade, dan pengujian fungsi akhir.', 'show_phone_publicly' => true],
            'warehouse' => ['name' => 'Maya Logistik', 'email' => 'gudang@adcom.test', 'role' => 'warehouse', 'phone' => '628123450007', 'specialty' => 'Inventaris & pengadaan'],
            'customer' => ['name' => 'Siti Rahma', 'email' => 'pelanggan@adcom.test', 'role' => 'customer', 'phone' => '628123450004'],
        ];

        return collect($accounts)->mapWithKeys(function (array $account, string $key): array {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [...$account, 'password' => Hash::make('password'), 'email_verified_at' => now(), 'is_active' => true],
            );

            return [$key => $user];
        })->all();
    }

    /** @return array<string, Service> */
    private function seedServices(): array
    {
        $items = [
            'wifi' => [
                'code' => 'NET.01', 'slug' => 'jaringan-wifi', 'name' => 'Jaringan & Wi-Fi',
                'summary' => 'Audit sinyal, instalasi kabel, konfigurasi router dan access point.',
                'description' => 'Untuk koneksi lambat, area blank spot, jaringan baru, atau konfigurasi perangkat jaringan.',
                'starting_price' => 75000, 'pricing_label' => 'Mulai Rp75 ribu', 'service_mode' => 'onsite',
                'risk_level' => 'medium', 'icon' => 'router', 'warranty_days' => 14, 'sort_order' => 1,
                'outcomes' => ['Peta masalah jaringan', 'Konfigurasi terdokumentasi', 'Hasil uji koneksi'],
            ],
            'cctv' => [
                'code' => 'SEC.02', 'slug' => 'instalasi-cctv', 'name' => 'Instalasi CCTV',
                'summary' => 'Survei titik, pemasangan kamera, konfigurasi rekaman, dan uji akses.',
                'description' => 'Perencanaan dan instalasi CCTV untuk rumah, UMKM, laboratorium, dan area sekolah.',
                'starting_price' => 50000, 'pricing_label' => 'Survei mulai Rp50 ribu', 'service_mode' => 'onsite',
                'risk_level' => 'high', 'icon' => 'camera', 'warranty_days' => 30, 'sort_order' => 2,
                'outcomes' => ['Peta titik kamera', 'Tes rekaman dan playback', 'Panduan penggunaan'],
            ],
            'computer' => [
                'code' => 'DEV.03', 'slug' => 'servis-komputer', 'name' => 'Servis Komputer',
                'summary' => 'Diagnosis, perawatan, upgrade, dan instalasi software legal.',
                'description' => 'Penanganan komputer lambat, gagal menyala, panas, storage bermasalah, atau upgrade komponen.',
                'starting_price' => 50000, 'pricing_label' => 'Diagnosis mulai Rp50 ribu', 'service_mode' => 'dropoff',
                'risk_level' => 'medium', 'icon' => 'computer', 'warranty_days' => 14, 'sort_order' => 3,
                'outcomes' => ['Hasil diagnosis', 'Catatan komponen', 'Tes fungsi setelah servis'],
            ],
            'consulting' => [
                'code' => 'ADV.04', 'slug' => 'konsultasi-it', 'name' => 'Konsultasi IT',
                'summary' => 'Bantu memilih perangkat dan menyusun kebutuhan teknologi yang masuk akal.',
                'description' => 'Konsultasi awal untuk warga atau UMKM yang belum yakin harus membeli atau memperbaiki apa.',
                'starting_price' => 0, 'pricing_label' => 'Konsultasi awal gratis', 'service_mode' => 'remote',
                'risk_level' => 'low', 'icon' => 'wrench', 'warranty_days' => 0, 'sort_order' => 4,
                'outcomes' => ['Ringkasan kebutuhan', 'Opsi sesuai anggaran', 'Rekomendasi langkah berikutnya'],
            ],
        ];

        return collect($items)->mapWithKeys(function (array $item, string $key): array {
            $service = Service::query()->updateOrCreate(['code' => $item['code']], [...$item, 'is_active' => true]);
            $checklists = match ($key) {
                'wifi' => [
                    ['safety', 'Konfirmasi keamanan area kerja', 'NET-SAFE-01', false],
                    ['baseline', 'Catat hasil uji koneksi awal', 'NET-TEST-01', true],
                    ['config', 'Dokumentasikan konfigurasi nonrahasia', 'NET-DOC-01', true],
                    ['final-test', 'Jalankan tes koneksi akhir', 'NET-QA-01', true],
                ],
                'cctv' => [
                    ['survey', 'Validasi titik dan privasi kamera', 'CCTV-PRIV-01', true],
                    ['height-safety', 'Checklist kerja di ketinggian', 'CCTV-K3-01', true],
                    ['recording', 'Tes rekaman dan playback', 'CCTV-QA-01', true],
                    ['handover', 'Edukasi penggunaan ke pelanggan', 'CCTV-HO-01', false],
                ],
                'computer' => [
                    ['intake', 'Catat kondisi awal perangkat', 'PC-IN-01', true],
                    ['backup', 'Konfirmasi status backup data', 'DATA-SAFE-01', false],
                    ['diagnostic', 'Jalankan diagnosis terukur', 'PC-DIAG-01', true],
                    ['final-test', 'Tes fungsi dan suhu akhir', 'PC-QA-01', true],
                ],
                default => [
                    ['brief', 'Validasi kebutuhan dan batas anggaran', 'CONS-BRIEF-01', false],
                    ['options', 'Bandingkan minimal dua opsi', 'CONS-COMP-01', false],
                ],
            };

            foreach ($checklists as $index => [$itemKey, $title, $standard, $evidence]) {
                ChecklistTemplate::query()->updateOrCreate(
                    ['service_id' => $service->id, 'item_key' => $itemKey],
                    ['title' => $title, 'standard_code' => $standard, 'requires_evidence' => $evidence, 'is_required' => true, 'sort_order' => $index + 1],
                );
            }

            return [$key => $service];
        })->all();
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Service>  $services
     */
    private function seedOrders(array $users, array $services): void
    {
        $orders = [
            [
                'ticket' => 'ADC-2609-0101', 'token' => 'demo-adcom-cctv-2026', 'code' => 'ADCOM101',
                'service' => 'cctv', 'customer_name' => 'Toko Berkah Jaya', 'phone' => '6281234567890',
                'email' => 'demo.customer@example.test', 'address' => 'Jl. Pendidikan No. 12, lingkungan sekitar sekolah',
                'district' => 'Kecamatan Contoh', 'complaint' => 'Membutuhkan empat titik CCTV untuk area kasir, pintu masuk, dan gudang kecil.',
                'status' => 'in_progress', 'risk_level' => 'high', 'estimated_total' => 1850000,
                'preferred_date' => today(), 'preferred_window' => 'morning', 'scheduled_at' => now()->startOfDay()->addHours(9),
                'customer_id' => $users['customer']->id,
                'events' => [
                    ['new', 'Permintaan berhasil dicatat', 'Detail kebutuhan telah masuk ke antrean AD Com', now()->subDays(3)],
                    ['triaged', 'Triase oleh supervisor selesai', 'Kunjungan memerlukan pengawasan koordinator keselamatan karena pemasangan di ketinggian.', now()->subDays(2)->setTime(9, 10)],
                    ['waiting_approval', 'Estimasi dikirim', 'Estimasi versi 1 menunggu persetujuan pelanggan.', now()->subDays(2)->setTime(11, 20)],
                    ['scheduled', 'Jadwal dan tim dikonfirmasi', 'Tim akan datang pukul 09.00 WIB.', now()->subDay()->setTime(14, 5)],
                    ['in_progress', 'Pemasangan sedang berlangsung', 'Dua dari empat titik kamera telah terpasang dan diuji.', now()->startOfDay()->addHours(10)],
                ],
                'estimate' => [
                    ['Jasa instalasi 4 titik', 1, 600000],
                    ['Kamera indoor 2MP', 4, 250000],
                    ['Kabel dan konektor', 1, 250000],
                ],
            ],
            [
                'ticket' => 'ADC-2609-0102', 'token' => 'demo-adcom-wifi-2026', 'code' => 'ADCOM102',
                'service' => 'wifi', 'customer_name' => 'Bu Ratna', 'phone' => '6281334567890', 'email' => null,
                'address' => 'Perumahan Melati Blok C4, lingkungan sekitar sekolah', 'district' => 'Kecamatan Contoh',
                'complaint' => 'Wi-Fi sering putus di lantai dua dan sinyal tidak sampai ke kamar belakang.',
                'status' => 'waiting_approval', 'risk_level' => 'medium', 'estimated_total' => 275000,
                'preferred_date' => today()->addDay(), 'preferred_window' => 'afternoon',
                'events' => [
                    ['new', 'Permintaan berhasil dicatat', 'Keluhan Wi-Fi diterima.', now()->subDay()->setTime(8, 15)],
                    ['triaged', 'Pemeriksaan kebutuhan selesai', 'Disarankan survei sinyal dan optimasi access point.', now()->subDay()->setTime(10, 20)],
                    ['waiting_approval', 'Estimasi dikirim', 'Pekerjaan akan dijadwalkan setelah estimasi disetujui.', now()->subDay()->setTime(11, 5)],
                ],
                'estimate' => [['Survei dan audit sinyal', 1, 75000], ['Konfigurasi access point', 1, 200000]],
            ],
            [
                'ticket' => 'ADC-2609-0103', 'token' => 'demo-adcom-pc-2026', 'code' => 'ADCOM103',
                'service' => 'computer', 'customer_name' => 'Dimas Saputra', 'phone' => '6285798765432', 'email' => null,
                'address' => 'Kompleks Pasar Lama No. 8', 'district' => 'Kecamatan Contoh',
                'complaint' => 'Komputer menyala tetapi layar hitam setelah listrik padam kemarin malam.',
                'status' => 'new', 'risk_level' => 'unassessed', 'estimated_total' => null,
                'preferred_date' => today()->addDays(2), 'preferred_window' => 'midday',
                'events' => [['new', 'Permintaan berhasil dicatat', 'Tim akan memeriksa detail pada jam operasional.', now()->subHours(2)]],
            ],
            [
                'ticket' => 'ADC-2608-0098', 'token' => 'demo-adcom-done-2026', 'code' => 'ADCOM098',
                'service' => 'wifi', 'customer_name' => 'Warung Kopi Nusa', 'phone' => '6281211112233', 'email' => null,
                'address' => 'Jl. Raya Sekolah No. 27', 'district' => 'Kecamatan Contoh',
                'complaint' => 'Butuh pemisahan jaringan Wi-Fi pelanggan dan kasir untuk keamanan transaksi.',
                'status' => 'completed', 'risk_level' => 'medium', 'estimated_total' => 450000,
                'preferred_date' => today()->subDays(5), 'preferred_window' => 'morning', 'completed_at' => now()->subDays(4),
                'customer_id' => $users['customer']->id,
                'events' => [
                    ['new', 'Permintaan berhasil dicatat', null, now()->subDays(8)],
                    ['scheduled', 'Jadwal dan tim dikonfirmasi', null, now()->subDays(6)],
                    ['in_progress', 'Konfigurasi jaringan dimulai', null, now()->subDays(5)->setTime(9, 0)],
                    ['verification', 'Hasil diverifikasi supervisor', 'Jaringan pelanggan dan kasir telah dipisahkan dan diuji.', now()->subDays(5)->setTime(12, 30)],
                    ['completed', 'Pekerjaan selesai', 'Tech Passport dan garansi 14 hari telah aktif.', now()->subDays(4)],
                ],
            ],
        ];

        foreach ($orders as $data) {
            $service = $services[$data['service']];
            $hasTechnicianTeam = in_array($data['status'], ['scheduled', 'in_progress', 'completed'], true);
            $order = ServiceOrder::query()->updateOrCreate(
                ['ticket_number' => $data['ticket']],
                [
                    'tracking_token_hash' => hash('sha256', $data['token']),
                    'access_code_hash' => hash('sha256', $data['code']),
                    'customer_id' => $data['customer_id'] ?? null,
                    'service_id' => $service->id,
                    'assigned_technician_id' => $hasTechnicianTeam ? $users['technician']->id : null,
                    'supervisor_id' => $data['status'] !== 'new' ? $users['supervisor']->id : null,
                    'customer_name' => $data['customer_name'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
                    'address' => $data['address'],
                    'district' => $data['district'],
                    'service_mode' => $service->service_mode,
                    'complaint' => $data['complaint'],
                    'preferred_date' => $data['preferred_date'],
                    'preferred_window' => $data['preferred_window'],
                    'urgency' => 'normal',
                    'risk_level' => $data['risk_level'],
                    'status' => $data['status'],
                    'estimated_total' => $data['estimated_total'],
                    'scheduled_at' => $data['scheduled_at'] ?? null,
                    'approved_at' => in_array($data['status'], ['scheduled', 'in_progress', 'completed'], true) ? now()->subDays(2) : null,
                    'completed_at' => $data['completed_at'] ?? null,
                ],
            );

            $order->assignments()->delete();
            if ($hasTechnicianTeam) {
                foreach ($this->technicianTeamFor($data['service'], $users) as $technician) {
                    $order->assignments()->create([
                        'user_id' => $technician->id,
                        'assignment_role' => 'technician',
                        'assigned_at' => $data['scheduled_at'] ?? now()->subDay(),
                    ]);
                }
            }

            $order->statusEvents()->delete();
            foreach ($data['events'] as [$status, $title, $description, $occurredAt]) {
                $order->statusEvents()->create([
                    'actor_id' => $status === 'new' ? null : $users['supervisor']->id,
                    'status' => $status,
                    'title' => $title,
                    'description' => $description,
                    'visibility' => 'customer',
                    'occurred_at' => $occurredAt,
                ]);
            }

            if (isset($data['estimate'])) {
                $estimate = Estimate::query()->updateOrCreate(
                    ['service_order_id' => $order->id, 'version' => 1],
                    ['status' => $data['status'] === 'waiting_approval' ? 'sent' : 'approved', 'total' => $data['estimated_total'], 'valid_until' => today()->addDays(7), 'sent_at' => now()->subDay(), 'approved_at' => $data['status'] === 'waiting_approval' ? null : now()->subHours(20)],
                );
                $estimate->items()->delete();
                foreach ($data['estimate'] as $index => [$label, $quantity, $unitPrice]) {
                    $estimate->items()->create(['label' => $label, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'total' => $quantity * $unitPrice, 'sort_order' => $index + 1]);
                }
            }
        }
    }

    /** @param array<string, User> $users */
    private function seedTechnicianReviews(array $users): void
    {
        $completedOrder = ServiceOrder::query()
            ->where('ticket_number', 'ADC-2608-0098')
            ->firstOrFail();

        TechnicianReview::query()->updateOrCreate(
            [
                'service_order_id' => $completedOrder->id,
                'technician_id' => $users['technician']->id,
            ],
            [
                'customer_id' => $users['customer']->id,
                'rating' => 5,
                'quality_rating' => 5,
                'communication_rating' => 5,
                'punctuality_rating' => 4,
                'tags' => ['rapi', 'komunikatif', 'solutif'],
                'comment' => 'Konfigurasi rapi, penjelasan mudah dipahami, dan jaringan kasir lebih aman.',
            ],
        );
    }

    /**
     * @param  array<string, User>  $users
     * @return list<User>
     */
    private function technicianTeamFor(string $service, array $users): array
    {
        return match ($service) {
            'computer' => [$users['technician'], $users['technician_device']],
            default => [$users['technician'], $users['technician_network']],
        };
    }

    /** @param array<string, User> $users */
    private function seedInventory(array $users): void
    {
        $items = [
            ['sku' => 'NET-CAT6-305', 'name' => 'Kabel UTP Cat6', 'category' => 'Jaringan', 'unit' => 'meter', 'quantity_on_hand' => 180, 'reorder_level' => 80, 'unit_cost' => 6500, 'storage_location' => 'Rak A1'],
            ['sku' => 'NET-RJ45-100', 'name' => 'Konektor RJ45', 'category' => 'Jaringan', 'unit' => 'pcs', 'quantity_on_hand' => 64, 'reorder_level' => 30, 'unit_cost' => 2500, 'storage_location' => 'Rak A2'],
            ['sku' => 'CCTV-CAM-2MP', 'name' => 'Kamera CCTV 2MP', 'category' => 'CCTV', 'unit' => 'unit', 'quantity_on_hand' => 3, 'reorder_level' => 4, 'unit_cost' => 245000, 'storage_location' => 'Lemari B1'],
            ['sku' => 'PC-SSD-512', 'name' => 'SSD SATA 512GB', 'category' => 'Komputer', 'unit' => 'unit', 'quantity_on_hand' => 2, 'reorder_level' => 2, 'unit_cost' => 480000, 'storage_location' => 'Lemari aman'],
            ['sku' => 'OPS-TIES-100', 'name' => 'Cable ties', 'category' => 'Consumable', 'unit' => 'pack', 'quantity_on_hand' => 8, 'reorder_level' => 3, 'unit_cost' => 22000, 'storage_location' => 'Rak C1'],
        ];

        foreach ($items as $data) {
            $item = InventoryItem::query()->updateOrCreate(['sku' => $data['sku']], [...$data, 'is_active' => true]);

            if ($item->movements()->doesntExist()) {
                $item->movements()->create([
                    'actor_id' => $users['warehouse']->id,
                    'type' => 'stock_in',
                    'quantity' => $item->quantity_on_hand,
                    'balance_after' => $item->quantity_on_hand,
                    'unit_cost' => $item->unit_cost,
                    'notes' => 'Saldo awal pilot AD Com.',
                    'occurred_at' => now()->subDays(2),
                ]);
            }
        }
    }

    /** @param array<string, User> $users */
    private function seedFinanceAndMessages(array $users): void
    {
        $order = ServiceOrder::query()->where('ticket_number', 'ADC-2609-0101')->firstOrFail();

        FinancialTransaction::query()->updateOrCreate(
            ['reference' => 'TRF-DEMO-0101-DP'],
            [
                'service_order_id' => $order->id,
                'actor_id' => $users['admin']->id,
                'verified_by' => $users['operator']->id,
                'type' => 'income',
                'category' => 'service_payment',
                'amount' => 900000,
                'payment_method' => 'bank_transfer',
                'status' => 'verified',
                'description' => 'Uang muka instalasi CCTV.',
                'occurred_at' => now()->subDay(),
                'verified_at' => now()->subDay()->addMinutes(15),
            ],
        );

        $conversation = [
            [$users['customer'], $users['technician'], 'Halo Kak, apakah tim jadi datang pukul 09.00?'],
            [$users['technician'], $users['customer'], 'Jadi, Bu. Saya Raka sebagai PIC. Tim sedang menyiapkan alat dan akan mengabari saat berangkat.'],
            [$users['technician'], $users['customer'], 'Kami sudah di lokasi dan mulai pemasangan dua titik pertama.'],
        ];

        foreach ($conversation as $index => [$sender, $recipient, $body]) {
            $order->messages()->updateOrCreate(
                ['sender_id' => $sender->id, 'body' => $body],
                ['recipient_id' => $recipient->id, 'created_at' => now()->subHours(5 - $index)],
            );
        }
    }
}
