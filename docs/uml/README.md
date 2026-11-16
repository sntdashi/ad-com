# UML AD Com - Portal layanan TEFA TKJ

Snapshot implementasi lokal: 12 September 2026. Dokumen ini memodelkan kode yang diperiksa, bukan pernyataan kesiapan produksi. Nama sekolah, identitas mahasiswa, dan format sampul kampus belum diberikan sehingga tidak dikarang.

## Berkas yang dipakai

- **`AD-Com-UML.drawio`**: berkas utama sesuai permintaan; sembilan halaman dengan kotak/aktor/teks/panah native yang dapat diedit satu-satu. Buka di draw.io / diagrams.net melalui **File > Open From > Device**, lalu pilih berkas ini. Pilih diagram melalui tab halaman di bagian bawah.
- `AD-Com-UML.pdf`: laporan pendamping; diagram memakai A3/A2 agar teks tetap terbaca, penjelasan A4. PDF/PNG/SVG memakai tata letak PlantUML, bukan ekspor langsung dari editor draw.io; isi dan model domainnya sepadan.
- `rendered/*.png`: sembilan diagram untuk ditempel ke Word atau slide.
- `rendered/*.svg`: gambar vektor untuk diperbesar tanpa pecah.
- `01-*.puml` sampai `09-*.puml`: sumber diagram yang dapat diedit dengan PlantUML. Simpan bersama `theme.puml`.
- `build_pdf.py`: pembuat laporan dan pemeriksa konsistensi sumber. Tidak mengubah kode aplikasi atau database.
- `build_drawio.py`: pembuat XML draw.io native; struktur/relasi diperiksa sebelum dan sesudah penyimpanan. Tidak menempelkan gambar diagram sebagai satu objek.

Empat jenis UML disajikan dalam sembilan diagram: dua Use Case, dua Activity, satu Sequence, dan empat tampilan Class Diagram. Pemisahan tampilan tidak menambah kelas atau role baru.

## Aktor dan batas sistem

Ada **enam role akun**, semuanya memakai model `User`, dibedakan melalui atribut `role`: `operator`, `admin`, `supervisor`, `technician`, `warehouse`, dan `customer`. Pengunjung adalah aktor tanpa akun, bukan role ketujuh. Google adalah sistem eksternal. "Pengelola pesanan" merupakan aktor abstrak yang merangkum kemampuan bersama operator/admin/supervisor, bukan akun tambahan. "Pengguna akun" pada diagram publik merangkum pengaturan akun sendiri bagi keenam role.

Siswa, warga sekolah, dan masyarakat dapat menjadi pelanggan. Siswa tidak otomatis teknisi. Tim sebuah tiket dibentuk melalui `OrderAssignment`, dengan PIC di `ServiceOrder.assigned_technician_id`; belum ada model/tabel `TechnicianTeam` tersendiri. Penugasan dilakukan pengelola, bukan mekanisme rebut/ambil tiket otomatis ala ride-hailing.

Web mencakup enam role. API/aplikasi Android saat ini ditujukan untuk pelanggan dan teknisi; jangan mengartikan diagram web sebagai bukti bahwa seluruh menu operator/admin/gudang tersedia di Android.

## Daftar diagram

| Nomor | Jenis    | Fokus                                                          |
| ----- | -------- | -------------------------------------------------------------- |
| 01    | Use Case | Pengunjung, pelanggan, pengaturan akun, dan login Google       |
| 02    | Use Case | Penugasan, progres, chat, evaluasi, gudang, keuangan, dan tim  |
| 03    | Activity | Pengajuan layanan sampai penjadwalan; validasi dan pembatalan  |
| 04    | Activity | Pengerjaan, pemeriksaan ulang, penyelesaian, dan rating        |
| 05    | Sequence | Pemesanan web melalui request, action, database, dan pelacakan |
| 06    | Class    | User, Service, ServiceOrder, OrderAssignment, OrderStatusEvent |
| 07    | Class    | Profil, SocialAccount, OrderMessage, TechnicianReview          |
| 08    | Class    | InventoryItem, InventoryMovement, FinancialTransaction         |
| 09    | Class    | Estimate, EstimateItem, ChecklistTemplate                      |

## Ringkasan hak akses yang diperiksa

| Kemampuan                     | Operator        | Admin           | Supervisor      | Teknisi             | Gudang | Pelanggan                         |
| ----------------------------- | --------------- | --------------- | --------------- | ------------------- | ------ | --------------------------------- |
| Profil dan pengaturan sendiri | Ya              | Ya              | Ya              | Ya                  | Ya     | Ya                                |
| Detail tiket internal         | Semua           | Semua           | Semua           | Penugasan aktif/PIC | Tidak  | Milik sendiri                     |
| Mengubah status tiket         | Sesuai transisi | Sesuai transisi | Sesuai transisi | Transisi teknisi    | Tidak  | Tidak                             |
| Menetapkan PIC/anggota        | Ya              | Ya              | Ya              | Tidak               | Tidak  | Tidak                             |
| Chat tiket berizin            | Ya              | Ya              | Ya              | Penugasan aktif/PIC | Tidak  | Milik sendiri + PIC tersedia      |
| Lihat persediaan              | Ya              | Ya              | Ya              | Ya                  | Ya     | Tidak                             |
| Tambah barang/mutasi          | Ya              | Ya              | Tidak           | Tidak               | Ya     | Tidak                             |
| Catat/lihat keuangan global   | Ya              | Ya              | Tidak           | Tidak               | Tidak  | Tidak                             |
| Verifikasi transaksi pending  | Ya              | Tidak           | Tidak           | Tidak               | Tidak  | Tidak                             |
| Kelola akun tim               | Ya              | Non-operator    | Tidak           | Tidak               | Tidak  | Tidak                             |
| Lihat evaluasi teknisi        | Semua           | Semua           | Semua           | Diri sendiri        | Tidak  | Ringkasan teknisi pada tiket      |
| Tulis/perbarui rating         | Tidak           | Tidak           | Tidak           | Tidak               | Tidak  | Milik sendiri dan memenuhi syarat |

Hak operasional mensyaratkan akun aktif, autentikasi, verifikasi email, dan TOTP internal sesuai middleware. Tabel tidak berarti seluruh konfigurasi pengiriman email atau deployment telah siap. Pelacakan publik dengan token atau tiket + kode akses hanya memperlihatkan proyeksi data yang diizinkan, bukan seluruh dashboard.

## Aturan bisnis untuk presentasi

1. Pemesanan web dapat dilakukan tanpa akun; `customer_id` karena itu boleh kosong. Data kontak pesanan disimpan pada pesanan, tidak mengharuskan pembuatan akun.
2. Nomor tiket bukan satu-satunya kunci pelacakan. Sistem memakai token pelacakan atau nomor tiket ditambah kode akses. Database menyimpan hash token dan kode, bukan nilainya dalam bentuk asli.
3. Pelanggan hanya dapat mengirim chat pada tiket miliknya, sesudah PIC ditugaskan. `recipient_id` pesan pelanggan mengikuti PIC saat pesan dikirim. Pengelola yang berwenang juga dapat mengakses chat; ini bukan janji percakapan privat eksklusif dua pihak atau websocket realtime.
4. Teknisi hanya dapat menjalankan `scheduled -> on_the_way -> in_progress -> verification`. Operator/admin/supervisor dapat memverifikasi, meminta perbaikan, menunggu pembayaran, atau menyelesaikan sesuai tabel transisi.
5. Rating ditulis pelanggan pemilik pesanan pada status `completed`, `warranty`, atau `closed`, hanya untuk teknisi yang pernah ditugaskan. Unik per pesanan-teknisi; kiriman berikutnya memperbarui ulasan tersebut. Skor keseluruhan, kualitas, komunikasi, dan ketepatan waktu berada pada rentang 1-5.
6. Evaluasi memakai rata-rata dan jumlah ulasan. Penilaian rendah merupakan sinyal pendampingan, bukan hukuman otomatis. Pelanggan tamu tidak otomatis memperoleh hak memberi rating hanya dengan kode pelacakan; belum ada alur klaim pesanan tamu di diagram.
7. Gudang mencatat `stock_in` atau `stock_out`; pengeluaran melebihi stok ditolak. Mutasi barang dan saldo diperbarui dalam transaksi database.
8. Transaksi keuangan dibuat `pending`, lalu operator memverifikasi/menolak. Ini pencatatan dan verifikasi manual, bukan transfer bank otomatis atau payment gateway. Verifikasi keuangan tidak otomatis mengubah status tiket.
9. Activity Diagram menunjukkan skenario representatif proses, bukan seluruh kemungkinan status. Tindakan operasional seperti konfirmasi biaya/jadwal dan pemeriksaan pekerjaan tidak berarti tersedia endpoint khusus untuk setiap langkah.

## Daftar transisi status lengkap (pengelola)

| Status awal        | Status berikutnya yang diizinkan              |
| ------------------ | --------------------------------------------- |
| new                | needs_info, triaged, cancelled                |
| needs_info         | triaged, cancelled                            |
| triaged            | waiting_estimate, waiting_approval, scheduled |
| waiting_estimate   | waiting_approval                              |
| waiting_approval   | scheduled, cancelled                          |
| scheduled          | on_the_way, in_progress, cancelled            |
| on_the_way         | in_progress                                   |
| in_progress        | verification                                  |
| verification       | in_progress, waiting_payment, completed       |
| waiting_payment    | completed                                     |
| completed          | warranty, reopened, closed                    |
| warranty           | reopened, closed                              |
| reopened           | in_progress, waiting_estimate                 |
| closed / cancelled | Tidak ada transisi lanjutan                   |

## Cara membaca notasi

- Aktor berada di luar batas sistem; oval menyatakan use case/kemampuan. Garis asosiasi tidak menunjukkan urutan proses.
- Segitiga kosong menunjuk aktor yang lebih umum; turunan mewarisi asosiasinya. `<<extend>>` menunjuk use case dasar, dengan kondisi ekstensi dituliskan.
- Activity: titik awal, aktivitas, keputusan dengan cabang, swimlane penanggung jawab, dan titik akhir. Akhir diagram 03 adalah akhir subproses penerimaan, bukan berarti pekerjaan sudah selesai.
- Sequence: waktu mengalir dari atas ke bawah; garis vertikal adalah lifeline; panah utuh merupakan permintaan dan putus-putus adalah respons. Blok `alt` memisahkan jalur valid/tidak valid. Peserta "Laravel HTTP" meringkas framework, request, dan controller; bukan nama kelas PHP tunggal.
- Class: atribut dan metode dipisahkan garis. `+` menandakan metode publik yang benar-benar ada pada model; atribut Eloquent disajikan sebagai properti data, bukan klaim deklarasi field PHP eksplisit. `HasMany`/`BelongsTo` adalah tipe relasi Eloquent.
- Multiplicity di ujung hubungan: `1` tepat satu, `0..1` opsional, `0..*` nol atau banyak. `?` pada atribut berarti nullable. Beberapa asosiasi berulang ke `User` dirinci di catatan diagram agar garis tidak menumpuk.
- Class Diagram adalah tampilan model domain berbasis implementasi, **bukan ERD lengkap**. Empat tampilannya mencakup seluruh 14 model aplikasi. `User`, `Service`, dan `ServiceOrder` yang berulang tetap kelas yang sama. Controller/action dan kelas framework tidak dihitung sebagai model domain.

## Batas implementasi (jangan dipresentasikan sebagai sudah lengkap)

- `Estimate`, `EstimateItem`, dan `ChecklistTemplate` tersedia sebagai model/struktur/pembacaan data. Endpoint pengelolaan estimasi dan persetujuan mandiri pelanggan serta pengisian checklist lengkap belum ditemukan pada route aplikasi yang diperiksa.
- Tabel seperti `approvals`, `work_logs`, `order_checklist_results`, `customer_assets`, `asset_service_records`, dan `reviews` tersedia di migration, tetapi bukan model Eloquent aktif tersendiri dalam `app/Models`. Tidak digambarkan sebagai fitur aktif hanya karena tabelnya ada.
- `audit_logs` dipakai action/controller melalui query builder; belum ada model `AuditLog`. Karena itu disebut di alur, bukan direka sebagai kelas aplikasi yang sudah ada.
- Tabel infrastruktur autentikasi, sesi, token, cache, queue, dan migration tidak dimasukkan dalam Class Diagram domain.
- UML ini tidak menutup temuan keamanan, membuktikan pengiriman email sungguhan, atau menyatakan aplikasi siap dipublikasikan.

## Keterlacakan ke kode

| Bagian                     | Sumber utama dari root proyek                                                                                                                                                                              |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Role dan kebijakan bersama | `.ai/rules/general.md`, `app/Models/User.php`, `routes/web.php`                                                                                                                                            |
| Batas akses tiket/tim      | `app/Policies/ServiceOrderPolicy.php`, `app/Http/Requests/UpdateOrderTeamRequest.php`, `app/Http/Controllers/OrderTeamController.php`                                                                      |
| Pemesanan dan pelacakan    | `app/Http/Requests/StoreServiceOrderRequest.php`, `app/Http/Controllers/Public/BookingController.php`, `app/Actions/Orders/CreateServiceOrder.php`, `app/Http/Controllers/Public/TrackOrderController.php` |
| Status pekerjaan           | `app/Actions/Orders/UpdateOrderStatus.php`                                                                                                                                                                 |
| Chat tiket                 | `app/Actions/Orders/SendOrderMessage.php`                                                                                                                                                                  |
| Rating dan evaluasi        | `app/Actions/Orders/SaveTechnicianReview.php`, `app/Policies/TechnicianReviewPolicy.php`, `app/Http/Controllers/TechnicianPerformanceController.php`                                                       |
| Gudang dan keuangan        | `app/Policies/InventoryItemPolicy.php`, `app/Policies/FinancialTransactionPolicy.php`, `app/Http/Controllers/InventoryMovementController.php`, `app/Http/Controllers/FinanceController.php`                |
| Pembatasan perubahan role  | `app/Http/Requests/UpdateTeamMemberRequest.php`, `app/Providers/AppServiceProvider.php`                                                                                                                    |
| Android/API                | `routes/api.php`, `routes/api-orders.php`, `app/Http/Middleware/AuthenticateMobileToken.php`                                                                                                               |
| Kelas dan kardinalitas     | Seluruh 14 file `app/Models/*.php`, migration pada `database/migrations/`, serta skema database lokal yang dibaca tanpa data pribadi                                                                       |

Rujukan notasi/rendering: [Use Case](https://plantuml.com/use-case-diagram), [Activity](https://plantuml.com/activity-diagram-beta), [Sequence](https://plantuml.com/sequence-diagram), dan [Class](https://plantuml.com/class-diagram) dari dokumentasi resmi PlantUML. Diagram menggambarkan hasil pembacaan kode AD Com, bukan contoh yang disalin dari dokumentasi tersebut.

## Render ulang secara lokal

Dibuat memakai PlantUML 1.2026.8 dari [rilis resmi](https://plantuml.com/download), Java headless, serta ReportLab. Renderer tidak ditambahkan ke dependency Laravel/npm. Kode diagram tidak diunggah ke renderer online.

```bash
# Dari root proyek; ganti /path/to/plantuml.jar dengan lokasi renderer milikmu.
java -Djava.awt.headless=true -jar /path/to/plantuml.jar -failfast2 -tsvg -o rendered 'docs/uml/0*.puml'
java -Djava.awt.headless=true -jar /path/to/plantuml.jar -failfast2 -tpng -o rendered 'docs/uml/0*.puml'
python3 docs/uml/build_pdf.py
```

Jalankan `python3 docs/uml/build_drawio.py` sebelum `build_pdf.py` bila ingin membuat ulang sumber draw.io. Jangan menjalankannya di atas file draw.io yang sudah diedit manual tanpa menyimpan salinan; generator akan membentuk ulang file dokumentasi dari sumber awal.

Pembuat PDF membutuhkan paket Python `reportlab`, `pypdf`, dan `Pillow`. `theme.puml` memakai font DejaVu Sans. Jangan jalankan renderer publik dengan data sensitif atau kredensial asli.
