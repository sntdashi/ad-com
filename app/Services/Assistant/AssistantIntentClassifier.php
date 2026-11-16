<?php

namespace App\Services\Assistant;

use App\Models\User;
use Illuminate\Support\Str;

class AssistantIntentClassifier
{
    public function __construct(
        private ?UserContextResolver $userContextResolver = null,
    ) {}

    /**
     * Classify a user message and conversation history into an explicit intent decision
     * with full conversation context metadata.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $pageContext
     * @return array{
     *     category: 'SAFETY'|'OUT_OF_DOMAIN'|'IDENTITY'|'OPERATIONAL'|'GENERAL_IT'|'CONVERSATION',
     *     intent: string,
     *     tool: string|null,
     *     tool_params: array<string, mixed>,
     *     entity: string|null,
     *     result_type: string|null,
     *     context_entity: string|null,
     *     context: array{
     *         last_intent: string|null,
     *         last_entity: string|null,
     *         last_result_type: string|null,
     *         previous_turn: array{user_message: string|null, assistant_message: string|null},
     *         user_context: array{
     *             is_authenticated: bool,
     *             user_id: int|null,
     *             name: string|null,
     *             role: string,
     *             role_label: string
     *         }
     *     },
     *     is_allowed: bool
     * }
     */
    public function classify(string $message, array $messages = [], ?User $user = null, array $pageContext = []): array
    {
        $normalized = $this->normalize($message);
        $context = $this->resolveConversationContext($messages, $user, $pageContext);
        $contextEntity = $context['last_entity'];

        if ($this->hasSafetyRisk($message)) {
            return [
                'category' => 'SAFETY',
                'intent' => 'safety.electrical',
                'tool' => null,
                'tool_params' => [],
                'entity' => null,
                'result_type' => null,
                'context_entity' => $contextEntity,
                'context' => $context,
                'is_allowed' => false,
            ];
        }

        if ($this->isExplicitlyOutOfScope($normalized)) {
            return [
                'category' => 'OUT_OF_DOMAIN',
                'intent' => 'guardrail.out_of_scope',
                'tool' => null,
                'tool_params' => [],
                'entity' => null,
                'result_type' => null,
                'context_entity' => $contextEntity,
                'context' => $context,
                'is_allowed' => false,
            ];
        }

        $identityIntent = $this->matchIdentityIntent($normalized);
        if ($identityIntent !== null) {
            return [
                'category' => 'IDENTITY',
                'intent' => $identityIntent['intent'],
                'tool' => $identityIntent['tool'],
                'tool_params' => [],
                'entity' => $identityIntent['entity'],
                'result_type' => $identityIntent['result_type'],
                'context_entity' => $contextEntity,
                'context' => $context,
                'is_allowed' => true,
            ];
        }

        $operationalIntent = $this->matchOperationalIntent($normalized, $context, $message, $pageContext, $user, $messages);
        if ($operationalIntent !== null) {
            return [
                'category' => 'OPERATIONAL',
                'intent' => $operationalIntent['intent'],
                'tool' => $operationalIntent['tool'],
                'tool_params' => $operationalIntent['tool_params'] ?? [],
                'entity' => $operationalIntent['entity'],
                'result_type' => $operationalIntent['result_type'],
                'context_entity' => $contextEntity,
                'context' => $context,
                'is_allowed' => true,
            ];
        }

        $conversationIntent = $this->matchConversationIntent($normalized, $context, $messages);
        if ($conversationIntent !== null) {
            return [
                'category' => 'CONVERSATION',
                'intent' => $conversationIntent['intent'],
                'tool' => null,
                'tool_params' => [],
                'entity' => $conversationIntent['entity'],
                'result_type' => $conversationIntent['result_type'],
                'context_entity' => $contextEntity,
                'context' => $context,
                'is_allowed' => true,
            ];
        }

        $generalItIntent = $this->matchGeneralItIntent($normalized, $context);
        if ($generalItIntent !== null) {
            return [
                'category' => 'GENERAL_IT',
                'intent' => $generalItIntent['intent'],
                'tool' => null,
                'tool_params' => [],
                'entity' => $generalItIntent['entity'],
                'result_type' => $generalItIntent['result_type'],
                'context_entity' => $contextEntity,
                'context' => $context,
                'is_allowed' => true,
            ];
        }

        return [
            'category' => 'OUT_OF_DOMAIN',
            'intent' => 'guardrail.unrelated_topic',
            'tool' => null,
            'tool_params' => [],
            'entity' => null,
            'result_type' => null,
            'context_entity' => $contextEntity,
            'context' => $context,
            'is_allowed' => false,
        ];
    }

    /**
     * Resolve structured conversation context across turns, scoped to the current user/session.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{route?: string|null, order_id?: string|null}  $pageContext
     * @return array{
     *     last_intent: string|null,
     *     last_entity: string|null,
     *     last_result_type: string|null,
     *     previous_turn: array{user_message: string|null, assistant_message: string|null},
     *     user_context: array{
     *         is_authenticated: bool,
     *         user_id: int|null,
     *         name: string|null,
     *         role: string,
     *         role_label: string
     *     }
     * }
     */
    public function resolveConversationContext(array $messages = [], ?User $user = null, array $pageContext = []): array
    {
        $resolver = $this->userContextResolver ?? app(UserContextResolver::class);
        $resolvedUser = $resolver->resolve($user, $pageContext);

        $lastIntent = null;
        $lastEntity = null;
        $lastResultType = null;
        $lastUserMessage = null;
        $lastAssistantMessage = null;

        $history = array_slice($messages, -8);
        foreach ($history as $index => $turn) {
            $role = $turn['role'];
            $content = trim($turn['content']);
            if ($content === '') {
                continue;
            }

            $normalizedTurn = $this->normalize($content);

            if ($role === 'user') {
                $lastUserMessage = $content;
                $turnState = [
                    'last_intent' => $lastIntent,
                    'last_entity' => $lastEntity,
                    'last_result_type' => $lastResultType,
                    'previous_turn' => [
                        'user_message' => $lastUserMessage,
                        'assistant_message' => $lastAssistantMessage,
                    ],
                    'user_context' => [
                        'is_authenticated' => $resolvedUser['is_authenticated'],
                        'user_id' => $resolvedUser['user_id'],
                        'name' => $resolvedUser['name'],
                        'role' => $resolvedUser['role'],
                        'role_label' => $resolvedUser['role_label'],
                    ],
                ];

                $directOp = $this->matchOperationalIntent($normalizedTurn, $turnState, $content, $pageContext, $user, array_slice($history, 0, $index));
                if ($directOp !== null) {
                    $lastIntent = $directOp['intent'];
                    $lastEntity = $directOp['entity'];
                    $lastResultType = $directOp['result_type'];

                    continue;
                }

                $directId = $this->matchIdentityIntent($normalizedTurn);
                if ($directId !== null) {
                    $lastIntent = $directId['intent'];
                    $lastEntity = $directId['entity'];
                    $lastResultType = $directId['result_type'];

                    continue;
                }

                $directIt = $this->matchGeneralItIntent($normalizedTurn, $turnState);
                if ($directIt !== null) {
                    $lastIntent = $directIt['intent'];
                    $lastEntity = $directIt['entity'];
                    $lastResultType = $directIt['result_type'];

                    continue;
                }

                $extracted = $this->extractEntityFromText($normalizedTurn);
                if ($extracted !== null) {
                    $lastEntity = $extracted;
                }
            } elseif ($role === 'assistant') {
                $lastAssistantMessage = $content;
                if ($lastEntity === null) {
                    $extracted = $this->extractEntityFromText($normalizedTurn);
                    if ($extracted !== null) {
                        $lastEntity = $extracted;
                    }
                }
            }
        }

        return [
            'last_intent' => $lastIntent,
            'last_entity' => $lastEntity,
            'last_result_type' => $lastResultType,
            'previous_turn' => [
                'user_message' => $lastUserMessage,
                'assistant_message' => $lastAssistantMessage,
            ],
            'user_context' => [
                'is_authenticated' => $resolvedUser['is_authenticated'],
                'user_id' => $resolvedUser['user_id'],
                'name' => $resolvedUser['name'],
                'role' => $resolvedUser['role'],
                'role_label' => $resolvedUser['role_label'],
            ],
        ];
    }

    public function normalize(string $message): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(Str::lower($message))) ?? '');
    }

    public function hasSafetyRisk(string $message): bool
    {
        $lower = Str::of($message)->ascii()->lower()->toString();

        return Str::contains($lower, [
            'asap',
            'terbakar',
            'bau gosong',
            'kesetrum',
            'percikan',
            'korslet',
            'konslet',
        ]);
    }

    public function isExplicitlyOutOfScope(string $normalized): bool
    {
        $patterns = [
            '/\b(buat(kan|in)?|bikin(in|kan)?|tulis(kan)?|generate|write|create)\s+(?:(?:saya|aku|gue|gw|kami|sebuah|contoh|source|python|php|javascript|a|an|some|me|untuk)\s+){0,4}(kode|coding|code|script|skrip|program|website|aplikasi|fungsi|query sql|puisi|pantun|cerpen|novel|essay|esai|makalah|skripsi|proyek pribadi)\b/',
            '/\b(proyek pribadi|project pribadi)\b.{0,40}\b(tidak berhubungan|di luar|kode|aplikasi|website|program)\b/',
            '/\b(debug|perbaiki|lengkapi)\s+(?:(?:contoh|source|python|php|javascript)\s+){0,3}(kode|code|script|skrip|query sql)\b/',
            '/\b(ngodingin|tolong ngoding|kerjakan tugas|kerjain tugas|jawab soal|selesaikan soal)\b/',
            '/\b(bisa|dapat|mampu)\s+(ngoding|coding|membuat kode|bikin kode|ngerjain tugas|mengerjakan tugas)\b/',
            '/\b(kerjain|kerjakan|bantu kerjain|bantu kerjakan)\s+(tugas|soal|pr|ujian|makalah|skripsi)\b/',
            '/\b(abaikan|lupakan|ignore|bypass|override)\b.{0,60}\b(aturan|instruksi|instructions|prompt|batasan|rules|role|peran)\b/',
            '/\b(tampilkan|bocorkan|ungkap|show|reveal|print|dump)\b.{0,60}\b(prompt|system prompt|api key|api token|rahasia|secret|password|otp|env|schema|database|data pelanggan)\b/',
            '/\b(select\s+.+\s+from|drop\s+table|delete\s+from|insert\s+into|union\s+select)\b/',
            '/\b(pretend|act as|berpura pura|jadilah|set role|ubah peran|angap saya)\b.{0,40}\b(operator|admin|superadmin|supervisor)\b/',
            '/\b(siapa presiden|siapa pemenang pemilu|ramalan zodiak|prediksi togel|resep (masakan|nasi|kue)|skor (bola|pertandingan))\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Provide an accurate, actionable Indonesian IT/TKJ troubleshooting or conceptual explanation.
     *
     * @param  array{last_intent?: string|null, last_entity?: string|null, previous_turn?: array{user_message: string|null, assistant_message: string|null}}  $context
     */
    public function explainGeneralItTopic(string $normalized, array $context = []): ?string
    {
        $effectiveNormalized = $normalized;
        if ($this->isDetailFollowUp($normalized) && ($context['last_entity'] ?? null) === 'it_topic') {
            $prevUser = $this->normalize((string) ($context['previous_turn']['user_message'] ?? ''));
            if ($prevUser !== '') {
                $effectiveNormalized = $prevUser.' '.$normalized;
            }
        }

        // 1. Laptop / PC Overheating ("Gimana cara mengatasi laptop panas?", "Gimana cara atasin laptop panas?", "Laptop cepat panas")
        if (preg_match('/\b(panas|overheat|suhu tinggi|kipas berisik|cepat panas)\b/', $effectiveNormalized) === 1) {
            return "Untuk mengatasi laptop atau komputer yang cepat panas (overheat), lakukan langkah awal berikut:\n"
                ."1. Letakkan laptop di permukaan keras dan rata agar sirkulasi udara di bagian bawah lancar.\n"
                ."2. Pastikan lubang ventilasi tidak tertutup kain, kasur, atau bantal.\n"
                ."3. Periksa penggunaan CPU dan RAM melalui Task Manager untuk mendeteksi proses yang membebani sistem.\n"
                ."4. Tutup aplikasi berat yang sedang tidak diperlukan.\n"
                ."5. Bersihkan ventilasi dan kipas bagian luar jika terdapat debu tanpa membongkar paksa perangkat.\n"
                ."6. Gunakan laptop dengan ventilasi ruangan yang baik.\n"
                .'Pemeriksaan teknisi profesional diperlukan apabila perangkat tetap panas berlebih saat idle, kipas mati/berbunyi kasar, atau sering mati mendadak karena thermal paste mengering—layanan Servis Komputer AD Com dapat membantu pembersihan internal dan penggantian thermal paste secara aman.';
        }

        // 2. Slow Computers / Hang ("Komputer lemot dan sering hang", "Laptop lambat")
        if (preg_match('/\b(lemot|lambat|hang|freeze|macet|ngelag|lag|tidak responsif)\b/', $effectiveNormalized) === 1
            && preg_match('/\b(komputer|laptop|pc|notebook|netbook|perangkat|windows)\b/', $effectiveNormalized) === 1) {
            return "Untuk mengatasi komputer atau laptop yang terasa lemot dan sering hang, lakukan pemeriksaan awal berikut:\n"
                ."1. Restart perangkat untuk membersihkan proses sementara yang menumpuk di memori.\n"
                ."2. Buka Task Manager untuk memeriksa aplikasi yang mengonsumsi CPU, RAM, atau Disk mendekati 100%.\n"
                ."3. Tutup aplikasi berat di latar belakang dan nonaktifkan program startup yang tidak diperlukan.\n"
                ."4. Pastikan drive sistem (C:) masih memiliki ruang penyimpanan kosong minimal 15–20%.\n"
                ."5. Jalankan pemindaian antivirus/Windows Security untuk memastikan sistem bebas malware.\n"
                .'Pemeriksaan profesional diperlukan apabila penggunaan Disk selalu 100% akibat penurunan kesehatan penyimpanan, kapasitas RAM kurang, atau sistem sering macet total—tim Servis Komputer AD Com dapat membantu diagnosis terukur maupun upgrade SSD/RAM bila diperlukan.';
        }

        // 3. Wi-Fi Disconnections ("Kenapa Wi-Fi sering putus?", "Wi-Fi sering putus, apa yang perlu dicek?")
        if (preg_match('/\b(wifi|wi fi|internet|koneksi|jaringan)\b/', $effectiveNormalized) === 1
            && preg_match('/\b(putus|disconnect|hilang|tidak stabil|putus nyambung|sering mati)\b/', $effectiveNormalized) === 1) {
            return "Penyebab Wi-Fi sering putus umumnya berkaitan dengan kestabilan sinyal, beban perangkat pada router, atau gangguan jalur ISP. Lakukan langkah berikut:\n"
                ."1. Restart modem dan router dengan mematikan daya selama 30 detik lalu nyalakan kembali.\n"
                ."2. Uji koneksi lebih dekat ke router untuk memastikan masalah bukan karena jarak atau penghalang tembok.\n"
                ."3. Lupakan jaringan (Forget Network) pada perangkat lalu sambungkan ulang.\n"
                ."4. Periksa apakah pemutusan terjadi di semua perangkat (indikasi router/ISP) atau hanya satu perangkat (indikasi driver/adaptor Wi-Fi).\n"
                ."5. Pastikan router memiliki sirkulasi udara yang baik dan tidak kelebihan beban perangkat aktif.\n"
                .'Jika koneksi tetap putus-nyambung di semua perangkat atau indikator LOS menyala merah, diperlukan pemeriksaan konfigurasi router, channel Wi-Fi, atau kabel jaringan oleh tim Jaringan & Wi-Fi AD Com.';
        }

        // 4. Weak Wi-Fi Signals ("Sinyal Wi-Fi lemah", "Blank spot", "Wi-Fi tidak sampai")
        if (preg_match('/\b(sinyal|jangkauan|blank spot|dead zone)\b/', $effectiveNormalized) === 1
            || (preg_match('/\b(wifi|wi fi)\b/', $effectiveNormalized) === 1 && preg_match('/\b(lemah|jauh|tidak sampai|lambat|kecil)\b/', $effectiveNormalized) === 1)) {
            return "Untuk mengatasi sinyal Wi-Fi yang lemah atau area blank spot, coba langkah berikut:\n"
                ."1. Posisikan router di area tengah bangunan, lebih tinggi, dan tidak terhalang lemari logam atau dinding tebal.\n"
                ."2. Jauhkan router dari perangkat pemicu interferensi frekuensi seperti microwave atau perangkat nirkabel lain.\n"
                ."3. Gunakan frekuensi 2.4 GHz untuk jangkauan jarak lebih jauh menembus tembok, atau 5 GHz untuk kecepatan tinggi di jarak dekat.\n"
                ."4. Atur channel Wi-Fi router ke kanal yang tidak padat (seperti channel 1, 6, atau 11 pada 2.4 GHz).\n"
                .'Jika area rumah atau kantor bertingkat dan luas sehingga satu router tidak cukup, diperlukan penambahan Access Point dengan tarikan kabel LAN—layanan Jaringan & Wi-Fi AD Com dapat membantu survei titik dan pemasangannya.';
        }

        // 5. Router Configuration Basics ("Cara setting router", "Konfigurasi router")
        if (preg_match('/\b(router)\b/', $effectiveNormalized) === 1
            && preg_match('/\b(setting|konfigurasi|atur|pengaturan|setup|ganti password|ssid)\b/', $effectiveNormalized) === 1) {
            return "Langkah dasar konfigurasi router jaringan secara aman adalah sebagai berikut:\n"
                ."1. Hubungkan perangkat ke router via kabel LAN atau Wi-Fi, lalu akses alamat IP gateway router (umumnya 192.168.1.1 atau 192.168.0.1) melalui browser.\n"
                ."2. Login ke halaman admin dan segera ubah password administrator bawaan pabrik.\n"
                ."3. Atur nama Wi-Fi (SSID) dan pilih keamanan WPA2-PSK atau WPA3 dengan kata sandi yang kuat.\n"
                ."4. Pastikan DHCP Server aktif agar perangkat klien memperoleh IP address otomatis.\n"
                ."5. Simpan pengaturan dan reboot router.\n"
                .'Apabila diperlukan konfigurasi lanjutan seperti pembagian VLAN, Guest Network, atau beberapa Access Point, tim Jaringan & Wi-Fi AD Com siap membantu.';
        }

        // 6. Router vs Modem / Router Function ("Apa fungsi router?", "Apa bedanya modem dan router?")
        if (preg_match('/\b(router)\b/', $effectiveNormalized) === 1 && preg_match('/\b(modem)\b/', $effectiveNormalized) === 1) {
            return 'Modem berfungsi menghubungkan jaringan lokal ke penyedia layanan internet (ISP), sedangkan router bertugas meneruskan paket data dan membagikan koneksi internet tersebut ke berbagai perangkat melalui Wi-Fi maupun kabel LAN. Jika Anda mengalami kendala konfigurasi modem atau router, layanan Jaringan & Wi-Fi AD Com siap membantu.';
        }

        if (preg_match('/\b(router)\b/', $effectiveNormalized) === 1) {
            return 'Fungsi utama router adalah menghubungkan dua atau lebih jaringan, mengatur lalu lintas paket data (routing), serta membagikan koneksi internet ke berbagai perangkat seperti komputer, laptop, dan ponsel melalui Wi-Fi atau kabel LAN. Jika jaringan atau router Anda bermasalah, tim Jaringan & Wi-Fi AD Com dapat membantu pemeriksaan dan konfigurasinya.';
        }

        // 7. IP Address & DNS Problems ("IP conflict", "DNS error", "Apa itu IP address?")
        if (preg_match('/\b(ip address|alamat ip|ip conflict|konflik ip|subnet|dhcp|dns|vlan|gateway)\b/', $effectiveNormalized) === 1) {
            if (preg_match('/\b(konflik|conflict|error|masalah|gagal|tidak bisa|cara|mengatasi|atasin|perbaiki)\b/', $effectiveNormalized) === 1) {
                return "Untuk mengatasi kendala IP address conflict atau DNS yang tidak merespon:\n"
                    ."1. Pastikan pengaturan adaptor jaringan disetel ke Obtain an IP address automatically (DHCP), kecuali jaringan kantor mewajibkan IP statis.\n"
                    ."2. Segarkan alamat IP dan bersihkan cache DNS melalui Command Prompt: jalankan ipconfig /release, ipconfig /renew, lalu ipconfig /flushdns.\n"
                    ."3. Uji dengan DNS publik seperti 1.1.1.1 atau 8.8.8.8 pada pengaturan jaringan.\n"
                    ."4. Restart router utama untuk menyegarkan tabel sewa DHCP.\n"
                    .'Jika konflik IP terus berulang di jaringan lokal, kemungkinan terdapat DHCP server ganda atau benturan IP statis yang memerlukan penataan ulang oleh teknisi Jaringan & Wi-Fi AD Com.';
            }

            return 'Dalam jaringan komputer (TKJ), IP address adalah identitas numerik perangkat di jaringan, DHCP memberikan IP secara otomatis, DNS menerjemahkan nama domain ke IP, dan VLAN memisahkan segmen jaringan secara logis. Jika ada konflik IP atau kendala konfigurasi jaringan, tim AD Com siap membantu.';
        }

        // 8. General Network Troubleshooting ("Tidak bisa konek internet", "Jaringan LAN bermasalah")
        if (preg_match('/\b(jaringan|internet|lan|kabel jaringan|utp|rj45)\b/', $effectiveNormalized) === 1
            && preg_match('/\b(bermasalah|tidak bisa|mati|error|tanda seru|no internet|cara|mengatasi|atasin|cek)\b/', $effectiveNormalized) === 1) {
            return "Untuk memeriksa kendala jaringan kabel (LAN) atau status No Internet pada perangkat:\n"
                ."1. Pastikan konektor RJ45 terpasang rapat dan lampu indikator port LAN menyala.\n"
                ."2. Restart modem ISP dan router/switch distribusi secara berurutan.\n"
                ."3. Periksa di perangkat lain apakah gangguan terjadi di seluruh jaringan atau hanya satu komputer.\n"
                ."4. Pastikan adaptor Ethernet/Wi-Fi tidak berstatus Disabled pada pengaturan Network Connections.\n"
                .'Apabila jalur kabel UTP putus, konektor RJ45 aus sehingga perlu crimping ulang, atau distribusi switch bermasalah, tim Jaringan & Wi-Fi AD Com dapat melakukan pengujian jalur di lokasi.';
        }

        // 9. CCTV Connection & Recording Problems ("CCTV tidak merekam", "CCTV offline")
        if (preg_match('/\b(cctv|nvr|dvr|ip camera|poe|rekaman)\b/', $effectiveNormalized) === 1) {
            if (preg_match('/\b(apa (itu|fungsi|bedanya|gunanya)|fungsi|jelaskan|cara kerja)\b/', $effectiveNormalized) === 1) {
                return 'Sistem CCTV memantau dan merekam aktivitas visual di suatu area; DVR memproses kamera analog, sedangkan NVR memproses IP camera berbasis jaringan (sering dipadukan dengan PoE untuk daya dan data sekaligus). Layanan Instalasi CCTV AD Com membantu survei titik, pemasangan, hingga pengaturan pantauan ponsel.';
            }

            if (preg_match('/\b(tidak merekam|offline|mati|tidak muncul|no video|gelap|bermasalah|putus|error|cara|mengatasi|atasin|kenapa)\b/', $effectiveNormalized) === 1) {
                return "Untuk memeriksa kendala CCTV yang offline, tidak menampilkan gambar, atau tidak merekam:\n"
                    ."1. Pastikan adaptor daya kamera, DVR/NVR, atau switch PoE menyala normal dan kabel daya aman.\n"
                    ."2. Periksa konektor BNC atau RJ45 pada unit DVR/NVR agar tidak longgar.\n"
                    ."3. Jika kamera tampil tetapi tidak merekam, periksa status kesehatan harddisk (HDD) pada menu Storage DVR/NVR dan pastikan jadwal rekaman aktif.\n"
                    ."4. Jika pantauan ponsel offline, pastikan DVR/NVR terhubung ke router internet dan status Cloud/P2P Online.\n"
                    .'Hindari memanjat titik kamera di ketinggian sendiri; jika diperlukan pengecekan jalur kabel atau penggantian komponen, tim Instalasi CCTV AD Com siap melakukan pemeriksaan aman.';
            }
        }

        // 10. Printer Problems ("Printer tidak bisa print", "Kertas macet")
        if (preg_match('/\b(printer|print|cetak|cetakan|paper jam|kertas macet|spooler|tinta)\b/', $effectiveNormalized) === 1) {
            return "Untuk mengatasi kendala printer yang gagal mencetak atau macet:\n"
                ."1. Pastikan printer menyala tanpa lampu indikator error berkedip, serta kabel USB atau koneksi jaringan printer tersambung baik.\n"
                ."2. Buka Print Queue di komputer, batalkan antrean dokumen yang tertahan, dan pastikan opsi Use Printer Offline tidak aktif.\n"
                ."3. Jika terjadi kertas macet (paper jam), matikan printer terlebih dahulu lalu tarik kertas searah jalur keluar secara perlahan agar sensor tidak rusak.\n"
                ."4. Jika hasil cetak bergaris atau pudar, periksa ketersediaan tinta/toner dan jalankan fitur Head Cleaning dari utilitas printer.\n"
                .'Apabila printer tetap tidak terdeteksi oleh komputer atau komponen roller penarik kertas mengalami keausan, diperlukan pemeriksaan teknis lebih lanjut.';
        }

        // 11. Basic Hardware Maintenance ("Cara merawat laptop", "Perawatan dasar komputer")
        if (preg_match('/\b(merawat|perawatan|membersihkan|awet|baterai)\b/', $effectiveNormalized) === 1
            && preg_match('/\b(komputer|laptop|pc|perangkat|hardware|baterai)\b/', $effectiveNormalized) === 1) {
            return "Langkah perawatan dasar perangkat komputer dan laptop agar tetap awet dan stabil:\n"
                ."1. Gunakan perangkat di permukaan keras dan bersih agar debu tidak mudah tersedot ke kipas pendingin.\n"
                ."2. Bersihkan layar, keyboard, dan ventilasi luar secara berkala dengan kain microfiber kering atau kuas halus saat perangkat mati.\n"
                ."3. Gunakan stabilizer atau UPS pada PC desktop untuk mencegah kerusakan akibat lonjakan atau pemadaman listrik mendadak.\n"
                ."4. Hindari membiarkan baterai laptop kosong hingga 0% berulang kali atau terpapar suhu panas ekstrem.\n"
                ."5. Cadangkan (backup) data penting secara berkala ke penyimpanan eksternal atau cloud.\n"
                .'Untuk pembersihan debu internal menyeluruh dan penggantian thermal paste berkala setiap 1–2 tahun, Anda dapat menggunakan layanan Servis Komputer AD Com.';
        }

        // 12. Operating System Troubleshooting ("Blue screen", "BSOD", "Gagal booting", "Windows bermasalah")
        if (preg_match('/\b(windows|linux|sistem operasi|blue screen|bsod|bootloop|gagal booting|masuk bios|automatic repair|install ulang|virus|malware|driver)\b/', $effectiveNormalized) === 1) {
            return "Untuk menangani kendala sistem operasi seperti Blue Screen (BSOD), gagal booting, atau gangguan software:\n"
                ."1. Catat kode pesan error di layar (misalnya INACCESSIBLE_BOOT_DEVICE atau CRITICAL_PROCESS_DIED).\n"
                ."2. Lepaskan semua perangkat USB eksternal yang tidak diperlukan lalu restart komputer.\n"
                ."3. Jika kendala muncul setelah instalasi driver atau aplikasi baru, masuk ke Safe Mode untuk menghapus driver atau aplikasi tersebut.\n"
                ."4. Jalankan fitur Startup Repair atau System Restore dari menu pemulihan tanpa memformat partisi data.\n"
                ."5. Pastikan data penting telah diamankan (backup) sebelum melakukan instalasi ulang sistem operasi.\n"
                .'Jika perangkat tetap gagal masuk ke sistem operasi atau terindikasi kerusakan pada SSD/HDD maupun RAM, tim Servis Komputer AD Com dapat melakukan pemeriksaan perangkat dan penyelamatan data.';
        }

        // 13. Other Conceptual IT/TKJ Hardware & Networking Topics
        if (preg_match('/\b(switch)\b/', $effectiveNormalized) === 1) {
            return 'Switch jaringan berfungsi menghubungkan beberapa perangkat dalam satu jaringan lokal (LAN) menggunakan kabel UTP/RJ45 dan meneruskan data secara terarah berdasarkan alamat MAC perangkat. Tim AD Com dapat membantu instalasi maupun penataan switch jaringan di rumah, sekolah, atau kantor.';
        }

        if (preg_match('/\b(access point|repeater|extender|mesh)\b/', $effectiveNormalized) === 1) {
            return 'Access point berfungsi memancarkan sinyal Wi-Fi dari jaringan kabel agar jangkauan koneksi nirkabel lebih luas dan stabil di area yang memiliki titik buta sinyal (dead zone). Layanan Jaringan & Wi-Fi AD Com dapat membantu survei titik dan pemasangannya.';
        }

        if (preg_match('/\b(utp|rj45|lan|kabel jaringan|crimping|fiber optik)\b/', $effectiveNormalized) === 1) {
            return 'Kabel UTP dengan konektor RJ45 serta fiber optik digunakan sebagai media transmisi jaringan kabel (LAN) agar koneksi lebih stabil dan berkecepatan tinggi dibanding nirkabel. Tim AD Com melayani penarikan kabel LAN, crimping ulang, dan pengujian jalur jaringan.';
        }

        if (preg_match('/\b(ssd|hdd|harddisk|nvme)\b/', $effectiveNormalized) === 1) {
            return 'SSD (Solid State Drive) menyimpan data menggunakan chip flash tanpa komponen berputar sehingga jauh lebih cepat dan tahan guncangan dibandingkan HDD (Hard Disk Drive) mekanis. Upgrade ke SSD melalui layanan Servis Komputer AD Com efektif mengatasi laptop atau PC yang lambat.';
        }

        if (preg_match('/\b(ram|memori|memory)\b/', $effectiveNormalized) === 1) {
            return 'RAM (Random Access Memory) berfungsi menyimpan data sementara dari aplikasi yang sedang berjalan agar prosesor dapat mengaksesnya dengan cepat; kapasitas RAM yang cukup mencegah komputer hang saat multitasking.';
        }

        if (preg_match('/\b(prosesor|processor|cpu|motherboard|power supply|psu|thermal paste|bios)\b/', $effectiveNormalized) === 1) {
            return 'Komponen inti komputer meliputi CPU sebagai pusat pemrosesan, motherboard sebagai papan penghubung sirkuit, PSU sebagai penyuplai daya listrik stabil, dan thermal paste untuk menjaga suhu prosesor tetap dingin. Tim Servis Komputer AD Com melayani perawatan, pembersihan, dan pemeriksaan komponen tersebut.';
        }

        if (preg_match('/\b(tkj|teknik komputer dan jaringan)\b/', $effectiveNormalized) === 1) {
            return 'Teknik Komputer dan Jaringan (TKJ) mempelajari perakitan dan perawatan komputer, administrasi jaringan (LAN/WAN/Wi-Fi), server, serta sistem keamanan seperti CCTV. AD Com merupakan unit layanan TEFA TKJ yang menerapkan keahlian tersebut dengan tim teknisi khusus dan supervisi teknis.';
        }

        if (preg_match('/\b(komputer|laptop|pc)\b/', $effectiveNormalized) === 1
            && preg_match('/\b(mati|tidak mau menyala|layar hitam|black screen|bunyi beep)\b/', $effectiveNormalized) === 1) {
            return "Untuk memeriksa komputer atau laptop yang gagal menyala atau layar hitam:\n"
                ."1. Pastikan kabel daya atau adaptor charger terpasang dengan baik dan indikator daya menyala.\n"
                ."2. Lepaskan perangkat USB eksternal lalu coba nyalakan kembali.\n"
                ."3. Perhatikan apakah kipas berputar atau terdengar bunyi beep (indikasi RAM atau tampilan).\n"
                ."4. Jangan membongkar unit Power Supply (PSU) atau sirkuit daya sendiri karena berisiko sengatan listrik.\n"
                .'Jika perangkat tetap tidak menyala atau layar tetap gelap, bawa atau jadwalkan pemeriksaan melalui layanan Servis Komputer AD Com agar komponen diuji secara aman.';
        }

        if (preg_match('/\b(apa (itu|fungsi|gunanya|manfaat|perbedaan|bedanya)|fungsi|jelaskan|cara kerja)\b/', $effectiveNormalized) === 1) {
            return 'Dalam lingkup teknologi informasi dan TKJ, perangkat jaringan, komputer, dan CCTV saling bekerja untuk memastikan konektivitas, komputasi, serta pemantauan keamanan berjalan stabil. Anda juga dapat menceritakan perangkat atau kendala spesifik yang dialami agar saya dapat memberikan langkah penanganan yang tepat.';
        }

        return null;
    }

    /**
     * @return array{intent: string, tool: string|null, entity: string, result_type: string}|null
     */
    private function matchIdentityIntent(string $normalized): ?array
    {
        // 1. Current user identity ("Siapa saya?", "Aku siapa?", "Apa role saya?", "Profil saya")
        if (preg_match('/\b(siapa(kah)? (saya|aku|diri saya|diriku)|(saya|aku) (ini )?siapa|nama (saya|akun saya)|profil (saya|akun saya)|customer saya siapa|akun saya siapa|siapa customer saya|(saya|aku) (login|masuk) sebagai (siapa|apa)|apa (role|peran|status akun) (saya|aku)|(role|peran) (saya|aku|akun saya) (apa|siapa)|identitas (saya|aku))\b/', $normalized) === 1) {
            return [
                'intent' => 'identity.current_user',
                'tool' => 'get_current_user',
                'entity' => 'current_user',
                'result_type' => 'detail',
            ];
        }

        // 2. Assistant identity ("Siapa kamu?", "Siapa Anda?", "Siapa ADI?", "Kenalkan dirimu")
        if (preg_match('/\b(siapa(kah)? (kamu|anda|adi|dirimu|asisten ini)|(kamu|anda|adi) (ini |itu )?siapa|who are you|identitas (kamu|anda|adi)|apa nama(mu| kamu| anda| adi)|nama(mu| kamu| anda| adi) siapa|apa itu adi|perkenalkan diri(mu| kamu| anda)|kenalkan diri(mu| kamu| anda))\b/', $normalized) === 1) {
            return [
                'intent' => 'identity.assistant',
                'tool' => null,
                'entity' => 'assistant',
                'result_type' => 'detail',
            ];
        }

        // 3. Assistant capabilities ("Apa tugas kamu?", "Kamu bisa apa?", "Tugasmu apa?", "Fungsimu apa?")
        if (preg_match('/\b(apa (saja )?(tugas|fungsi|peran|kemampuan|kegunaan|pekerjaan) (dari )?(anda|kamu|adi)|(tugas|fungsi|peran|kemampuan)mu apa|(tugas|fungsi|peran|kemampuan) (anda|kamu|adi) (itu )?apa|(kamu|anda|adi) bisa (apa|bantu apa|ngapain)|apa (saja )?yang bisa (kamu|anda|adi) (bantu|lakukan|kerjakan)|bisa bantu apa( saja)?)\b/', $normalized) === 1) {
            return [
                'intent' => 'identity.capabilities',
                'tool' => null,
                'entity' => 'capabilities',
                'result_type' => 'detail',
            ];
        }

        // 4. Platform identity ("Apa itu AD Com?", "AD Com itu apa?", "Apa itu TEFA?", "Apa fungsi AD Com?")
        if (preg_match('/\b((apa (fungsi|itu|sih|kegunaan|tujuan|peran)|jelaskan( tentang| mengenai)?|tentang|siapa( itu)?) (dari )?(layanan |portal |aplikasi |website )?(ad com|adcom|tefa( tkj)?|teaching factory)|(ad com|adcom|tefa( tkj)?|teaching factory) (itu )?(apa(an)?|siapa|untuk apa))\b/', $normalized) === 1) {
            return [
                'intent' => 'identity.platform',
                'tool' => null,
                'entity' => 'platform',
                'result_type' => 'detail',
            ];
        }

        return null;
    }

    /**
     * @param  array{last_intent: string|null, last_entity: string|null, last_result_type: string|null, previous_turn: array{user_message: string|null, assistant_message: string|null}}  $context
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $pageContext
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{intent: string, tool: string, tool_params?: array<string, mixed>, entity: string, result_type: string}|null
     */
    private function matchOperationalIntent(
        string $normalized,
        array $context,
        string $rawMessage = '',
        array $pageContext = [],
        ?User $user = null,
        array $messages = [],
    ): ?array {
        $contextEntity = $context['last_entity'];

        $actionIntent = app(AssistantActionIntentParser::class)->matchWriteActionIntent(
            $normalized,
            $rawMessage,
            $pageContext,
            app(AssistantActionCoordinator::class),
            $messages,
        );
        if ($actionIntent !== null) {
            return $actionIntent;
        }

        $phase4 = $this->matchPhase4AnalyticsIntent($normalized, $rawMessage, $context, $pageContext, $user);
        if ($phase4 !== null) {
            return $phase4;
        }

        // 1. Direct Finance queries ("Berapa total pemasukan?", "Pemasukan bulan ini berapa?", "Berapa transaksi pending?", "Transaksi ditolak", "Jumlah transaksi")
        if ($this->isDirectFinanceQuery($normalized)) {
            return [
                'intent' => 'operational.finance',
                'tool' => 'finance_summary',
                'entity' => 'finance',
                'result_type' => $this->inferFinanceResultType($normalized),
            ];
        }

        // 2. Contextual Finance follow-up ("Kalau bulan lalu?", "Kalau bulan ini?", "Kalau tahun ini?", "Kalau yang pending?", "Kalau yang ditolak?", "Kalau per kategori?")
        if ($contextEntity === 'finance' && $this->isFinanceFollowUp($normalized)) {
            return [
                'intent' => 'operational.finance',
                'tool' => 'finance_summary',
                'entity' => 'finance',
                'result_type' => $this->inferFinanceResultType($normalized),
            ];
        }

        // 3. Direct Inventory queries
        if (preg_match('/\b(stok|gudang|inventaris|sparepart|restock|kritis)\b/', $normalized) === 1) {
            return [
                'intent' => 'operational.inventory',
                'tool' => 'inventory_summary',
                'entity' => 'inventory',
                'result_type' => 'summary',
            ];
        }

        // 4. Unassigned tickets & own orders
        if (preg_match('/\b(tanpa pic|belum ada teknisi|unassigned|tiket masuk|antrean tiket)\b/', $normalized) === 1) {
            return [
                'intent' => 'operational.tickets.count',
                'tool' => 'unassigned_queue_summary',
                'entity' => 'ticket',
                'result_type' => 'count',
            ];
        }

        if (preg_match('/\b(tiket saya|pesanan saya|order saya|tugas saya|jadwal saya|pekerjaan saya)\b/', $normalized) === 1) {
            return [
                'intent' => 'operational.tickets.count',
                'tool' => 'user_orders_summary',
                'entity' => 'ticket',
                'result_type' => 'list',
            ];
        }

        // 5. Platform & Operational summary
        if (preg_match('/\b(ringkasan platform|status sistem)\b/', $normalized) === 1) {
            return [
                'intent' => 'operational.platform',
                'tool' => 'platform_summary',
                'entity' => 'platform',
                'result_type' => 'summary',
            ];
        }

        if (preg_match('/\b(ringkasan operasional|ringkasan kerja|status operasional)\b/', $normalized) === 1) {
            return [
                'intent' => 'operational.summary',
                'tool' => 'get_operational_summary',
                'entity' => 'operational',
                'result_type' => 'summary',
            ];
        }

        // 6. Direct technician questions
        if (preg_match('/\b(teknisi(?:nya)?|tim teknisi)\b/', $normalized) === 1
            && preg_match('/\b(teknisi siapa|siapa teknisi pesanan)\b/', $normalized) === 0) {
            if (preg_match('/\b(siapa|daftar|list|nama|sebutkan)\b/', $normalized) === 1
                && preg_match('/\b(tersedia|available|kosong|siap|nganggur|idle)\b/', $normalized) === 1) {
                return [
                    'intent' => 'operational.technicians.list',
                    'tool' => 'get_available_technicians',
                    'entity' => 'technician',
                    'result_type' => 'available_list',
                ];
            }

            if (preg_match('/\b(berapa|jumlah|banyak|total|ada)\b/', $normalized) === 1
                && preg_match('/\b(siapa|daftar|list|nama|sebutkan)\b/', $normalized) === 0) {
                return [
                    'intent' => 'operational.technicians.count',
                    'tool' => 'get_technician_count',
                    'entity' => 'technician',
                    'result_type' => 'count',
                ];
            }

            if (preg_match('/\b(siapa|daftar|list|nama|sebutkan)\b/', $normalized) === 1) {
                return [
                    'intent' => 'operational.technicians.list',
                    'tool' => 'list_technicians',
                    'entity' => 'technician',
                    'result_type' => 'list',
                ];
            }

            if (preg_match('/\b(tersedia|available|kosong|siap)\b/', $normalized) === 1) {
                return [
                    'intent' => 'operational.technicians.list',
                    'tool' => 'get_available_technicians',
                    'entity' => 'technician',
                    'result_type' => 'available_list',
                ];
            }
        }

        // 7. Direct customer questions (checked before generic user/akun)
        if (preg_match('/\b(customer(?:s|nya)?|pelanggan(?:nya)?)\b/', $normalized) === 1) {
            if (preg_match('/\b(ringkasan|summary|rekap)\b/', $normalized) === 1) {
                return [
                    'intent' => 'operational.customers.count',
                    'tool' => 'get_customer_summary',
                    'entity' => 'customer',
                    'result_type' => 'summary',
                ];
            }

            if (preg_match('/\b(detail|profil|info)\b/', $normalized) === 1
                && preg_match('/\b(siapa|daftar|list)\b/', $normalized) === 0) {
                return [
                    'intent' => 'operational.customers.list',
                    'tool' => 'get_customer_detail',
                    'entity' => 'customer',
                    'result_type' => 'detail',
                ];
            }

            if (preg_match('/\b(siapa|daftar|list|nama|sebutkan|tampilkan)\b/', $normalized) === 1) {
                return [
                    'intent' => 'operational.customers.list',
                    'tool' => 'list_customers',
                    'entity' => 'customer',
                    'result_type' => 'list',
                ];
            }

            if (preg_match('/\b(berapa|jumlah|banyak|total|ada)\b/', $normalized) === 1) {
                return [
                    'intent' => 'operational.customers.count',
                    'tool' => 'get_customer_count',
                    'entity' => 'customer',
                    'result_type' => 'count',
                ];
            }
        }

        // 8. Direct user questions
        if (preg_match('/\b(user(?:nya)?|users|pengguna(?:nya)?|akun(?:nya)?)\b/', $normalized) === 1) {
            if (preg_match('/\b(siapa|daftar|list|nama|sebutkan|tampilkan)\b/', $normalized) === 1) {
                return [
                    'intent' => 'operational.users.list',
                    'tool' => 'list_users',
                    'entity' => 'user',
                    'result_type' => 'list',
                ];
            }

            if (preg_match('/\b(berapa|jumlah|banyak|total|ada)\b/', $normalized) === 1) {
                return [
                    'intent' => 'operational.users.count',
                    'tool' => 'get_user_count',
                    'entity' => 'user',
                    'result_type' => 'count',
                ];
            }
        }

        // 9. Direct service catalog count & list questions
        if (preg_match('/\b(jasa(?:nya)?|layanan(?:nya)?|service(?:s)?)\b/', $normalized) === 1) {
            if (preg_match('/\b(berapa|jumlah|banyak|total|ada berapa)\b/', $normalized) === 1
                && preg_match('/\b(harga|biaya|tarif|garansi|lama)\b/', $normalized) === 0
                && preg_match('/\b(apa saja|daftar|list|sebutkan)\b/', $normalized) === 0) {
                return [
                    'intent' => 'operational.services.count',
                    'tool' => 'get_service_count',
                    'entity' => 'service',
                    'result_type' => 'count',
                ];
            }

            if (preg_match('/\b(apa saja|apa aja|daftar|list|sebutkan|mana saja|tersedia|apa yang ada|layanan apa|jasa apa|apa layanan)\b/', $normalized) === 1
                && preg_match('/\b(harga|biaya|tarif|garansi)\b/', $normalized) === 0) {
                return [
                    'intent' => 'operational.services.list',
                    'tool' => 'list_services',
                    'entity' => 'service',
                    'result_type' => 'list',
                ];
            }
        }

        // 10. Direct ticket / order count questions
        if (preg_match('/\b(tiket(?:nya)?|pesanan(?:nya)?|order(?:nya|an)?)\b/', $normalized) === 1
            && preg_match('/\b(berapa|jumlah|banyak|total|ada berapa)\b/', $normalized) === 1
            && preg_match('/\b(harga|biaya|tarif|garansi|lama)\b/', $normalized) === 0) {
            return [
                'intent' => 'operational.tickets.count',
                'tool' => 'get_ticket_count',
                'entity' => 'ticket',
                'result_type' => 'count',
            ];
        }

        // 11. Contextual Operational Follow-Ups (Section 1)
        // 11a. Available filter ("Yang tersedia siapa?", "Siapa yang tersedia?")
        if ($this->isAvailableFilterFollowUp($normalized) && ($contextEntity === 'technician' || $contextEntity === null)) {
            return [
                'intent' => 'operational.technicians.list',
                'tool' => 'get_available_technicians',
                'entity' => 'technician',
                'result_type' => 'available_list',
            ];
        }

        // 11b. Active filter ("Yang aktif siapa?", "Siapa yang aktif?")
        if ($this->isActiveFilterFollowUp($normalized) && $contextEntity !== null) {
            return match ($contextEntity) {
                'technician' => [
                    'intent' => 'operational.technicians.list',
                    'tool' => 'list_technicians',
                    'entity' => 'technician',
                    'result_type' => 'active_list',
                ],
                'customer' => [
                    'intent' => 'operational.customers.list',
                    'tool' => 'list_customers',
                    'entity' => 'customer',
                    'result_type' => 'active_list',
                ],
                'user' => [
                    'intent' => 'operational.users.list',
                    'tool' => 'list_users',
                    'entity' => 'user',
                    'result_type' => 'active_list',
                ],
                'service' => [
                    'intent' => 'operational.services.list',
                    'tool' => 'list_services',
                    'entity' => 'service',
                    'result_type' => 'list',
                ],
                default => null,
            };
        }

        // 11c. Person list follow-ups ("Siapa?", "Siapa saja?", "Namanya siapa?", "Ada siapa saja?")
        if ($this->isPersonListFollowUp($normalized) && $contextEntity !== null) {
            return match ($contextEntity) {
                'technician' => [
                    'intent' => 'operational.technicians.list',
                    'tool' => 'list_technicians',
                    'entity' => 'technician',
                    'result_type' => 'list',
                ],
                'customer' => [
                    'intent' => 'operational.customers.list',
                    'tool' => 'list_customers',
                    'entity' => 'customer',
                    'result_type' => 'list',
                ],
                'user' => [
                    'intent' => 'operational.users.list',
                    'tool' => 'list_users',
                    'entity' => 'user',
                    'result_type' => 'list',
                ],
                default => null,
            };
        }

        // 11d. General list follow-ups ("Apa saja?", "Daftar lengkapnya?", "Sebutkan?")
        if ($this->isGeneralListFollowUp($normalized) && $contextEntity !== null) {
            return match ($contextEntity) {
                'service' => [
                    'intent' => 'operational.services.list',
                    'tool' => 'list_services',
                    'entity' => 'service',
                    'result_type' => 'list',
                ],
                'technician' => [
                    'intent' => 'operational.technicians.list',
                    'tool' => 'list_technicians',
                    'entity' => 'technician',
                    'result_type' => 'list',
                ],
                'customer' => [
                    'intent' => 'operational.customers.list',
                    'tool' => 'list_customers',
                    'entity' => 'customer',
                    'result_type' => 'list',
                ],
                'user' => [
                    'intent' => 'operational.users.list',
                    'tool' => 'list_users',
                    'entity' => 'user',
                    'result_type' => 'list',
                ],
                default => null,
            };
        }

        // 11e. Count follow-ups ("Berapa jumlahnya?", "Totalnya berapa?", "Ada berapa?")
        if ($this->isCountFollowUp($normalized) && $contextEntity !== null) {
            return match ($contextEntity) {
                'technician' => [
                    'intent' => 'operational.technicians.count',
                    'tool' => 'get_technician_count',
                    'entity' => 'technician',
                    'result_type' => 'count',
                ],
                'customer' => [
                    'intent' => 'operational.customers.count',
                    'tool' => 'get_customer_count',
                    'entity' => 'customer',
                    'result_type' => 'count',
                ],
                'user' => [
                    'intent' => 'operational.users.count',
                    'tool' => 'get_user_count',
                    'entity' => 'user',
                    'result_type' => 'count',
                ],
                'service' => [
                    'intent' => 'operational.services.count',
                    'tool' => 'get_service_count',
                    'entity' => 'service',
                    'result_type' => 'count',
                ],
                'ticket' => [
                    'intent' => 'operational.tickets.count',
                    'tool' => 'get_ticket_count',
                    'entity' => 'ticket',
                    'result_type' => 'count',
                ],
                'finance' => [
                    'intent' => 'operational.finance',
                    'tool' => 'finance_summary',
                    'entity' => 'finance',
                    'result_type' => 'finance_count',
                ],
                default => null,
            };
        }

        // 11f. Complementary / "other" follow-ups ("Kalau yang lainnya?", "Bagaimana yang lainnya?")
        if ($this->isOtherFilterFollowUp($normalized) && $contextEntity !== null) {
            return match ($contextEntity) {
                'technician' => [
                    'intent' => 'operational.technicians.list',
                    'tool' => 'list_technicians',
                    'entity' => 'technician',
                    'result_type' => 'list',
                ],
                'customer' => [
                    'intent' => 'operational.customers.count',
                    'tool' => 'get_customer_summary',
                    'entity' => 'customer',
                    'result_type' => 'summary',
                ],
                'user' => [
                    'intent' => 'operational.users.list',
                    'tool' => 'list_users',
                    'entity' => 'user',
                    'result_type' => 'list',
                ],
                'service' => [
                    'intent' => 'operational.services.list',
                    'tool' => 'list_services',
                    'entity' => 'service',
                    'result_type' => 'list',
                ],
                'finance' => [
                    'intent' => 'operational.finance',
                    'tool' => 'finance_summary',
                    'entity' => 'finance',
                    'result_type' => 'finance_summary',
                ],
                default => null,
            };
        }

        // 11g. Detail follow-ups ("Bisa jelaskan lebih detail?", "Rinciannya bagaimana?")
        if ($this->isDetailFollowUp($normalized) && $contextEntity !== null) {
            return match ($contextEntity) {
                'customer' => [
                    'intent' => 'operational.customers.count',
                    'tool' => 'get_customer_summary',
                    'entity' => 'customer',
                    'result_type' => 'summary',
                ],
                'technician' => [
                    'intent' => 'operational.technicians.list',
                    'tool' => 'list_technicians',
                    'entity' => 'technician',
                    'result_type' => 'list',
                ],
                'user' => [
                    'intent' => 'operational.users.count',
                    'tool' => 'get_user_count',
                    'entity' => 'user',
                    'result_type' => 'summary',
                ],
                'service' => [
                    'intent' => 'operational.services.list',
                    'tool' => 'list_services',
                    'entity' => 'service',
                    'result_type' => 'list',
                ],
                'finance' => [
                    'intent' => 'operational.finance',
                    'tool' => 'finance_summary',
                    'entity' => 'finance',
                    'result_type' => 'finance_summary',
                ],
                'inventory' => [
                    'intent' => 'operational.inventory',
                    'tool' => 'inventory_summary',
                    'entity' => 'inventory',
                    'result_type' => 'summary',
                ],
                'ticket' => [
                    'intent' => 'operational.tickets.count',
                    'tool' => 'get_ticket_count',
                    'entity' => 'ticket',
                    'result_type' => 'count',
                ],
                'analytics_executive' => [
                    'intent' => 'analytics.executive',
                    'tool' => 'get_executive_analytics',
                    'tool_params' => $this->extractPeriodParams($normalized, []),
                    'entity' => 'analytics_executive',
                    'result_type' => 'summary',
                ],
                'analytics_financial' => [
                    'intent' => 'analytics.financial',
                    'tool' => 'get_financial_analytics',
                    'tool_params' => $this->extractPeriodParams($normalized, []),
                    'entity' => 'analytics_financial',
                    'result_type' => 'summary',
                ],
                'analytics_insights' => [
                    'intent' => 'analytics.insights',
                    'tool' => 'get_business_insights',
                    'tool_params' => $this->extractPeriodParams($normalized, []),
                    'entity' => 'analytics_insights',
                    'result_type' => 'summary',
                ],
                'analytics_inventory' => [
                    'intent' => 'analytics.inventory',
                    'tool' => 'get_inventory_analytics',
                    'tool_params' => $this->extractPeriodParams($normalized, []),
                    'entity' => 'analytics_inventory',
                    'result_type' => 'summary',
                ],
                default => null,
            };
        }

        return null;
    }

    /**
     * Match Phase 4 BI analytics, BusinessInsightsEngine reasoning, SLA risk, and order/warranty lookup intents.
     *
     * @param  array{last_intent: string|null, last_entity: string|null, last_result_type: string|null, previous_turn: array{user_message: string|null, assistant_message: string|null}}  $context
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $pageContext
     * @return array{intent: string, tool: string, tool_params: array<string, mixed>, entity: string, result_type: string}|null
     */
    private function matchPhase4AnalyticsIntent(
        string $normalized,
        string $rawMessage,
        array $context,
        array $pageContext,
        ?User $user,
    ): ?array {
        $periodParams = $this->extractPeriodParams($normalized, $pageContext);
        $contextEntity = $context['last_entity'];

        // 1. Insight & anomaly reasoning ("Kenapa AD Com mendeteksi dead-stock?", "Apa insight bisnis saat ini?")
        if (preg_match('/\b(kenapa|mengapa|penyebab|alasan)\b.{0,50}\b(dead.?stock|insight|anomali|peringatan|penurunan|kenaikan|naik|turun)\b/', $normalized) === 1
            || preg_match('/\b(business insight|daftar insight|insight bisnis|insight operasional|peringatan bisnis|anomali bisnis|rekomendasi bisnis)\b/', $normalized) === 1
            || preg_match('/\b(insight|anomali)\b.{0,35}\b(bisnis|operasional|prioritas|saat ini)\b/', $normalized) === 1) {
            return [
                'intent' => 'analytics.insights',
                'tool' => 'get_business_insights',
                'tool_params' => $periodParams,
                'entity' => 'analytics_insights',
                'result_type' => 'insights',
            ];
        }

        // 2. Inventory alerts ("Barang inventori apa yang perlu segera di-restock?", "Apakah ada indikasi dead-stock?")
        if (preg_match('/\b(perlu|segera|harus|apa|daftar|cek|indikasi|kandidat)\b.{0,45}\b(restock|re.?order|dead.?stock|slow.?moving|stok habis|out of stock)\b/', $normalized) === 1
            || preg_match('/\b(dead.?stock)\b/', $normalized) === 1) {
            return [
                'intent' => 'analytics.inventory_alerts',
                'tool' => 'get_inventory_alerts',
                'tool_params' => $periodParams,
                'entity' => 'analytics_inventory',
                'result_type' => 'alerts',
            ];
        }

        // 3. Inventory BI (valuation, turnover, fast-moving)
        if (preg_match('/\b(valuasi (aset )?(stok|inventori|gudang)|turnover (stok|inventori)|analitik (stok|inventori|gudang)|fast.?moving)\b/', $normalized) === 1) {
            return [
                'intent' => 'analytics.inventory',
                'tool' => 'get_inventory_analytics',
                'tool_params' => $periodParams,
                'entity' => 'analytics_inventory',
                'result_type' => 'summary',
            ];
        }

        // 4. Financial BI (accrual vs cash, unpaid receivables, gross profit, net cash flow)
        if (preg_match('/\b(akrual|accrual|piutang|belum dibayar|unpaid|overdue|jatuh tempo|laba kotor|gross profit|arus kas bersih|net cash flow|analitik keuangan|kas masuk)\b/', $normalized) === 1) {
            return [
                'intent' => 'analytics.financial',
                'tool' => 'get_financial_analytics',
                'tool_params' => $periodParams,
                'entity' => 'analytics_financial',
                'result_type' => 'summary',
            ];
        }

        // 5. Service & quotation profitability BI
        if (preg_match('/\b(layanan|jasa)\b.{0,45}\b(paling )?(menguntungkan|profitabel|margin|laris|terlaris|diminati|rugi|rendah)\b/', $normalized) === 1
            || preg_match('/\b(profitabilitas layanan|margin layanan|analitik layanan|konversi quotation|persetujuan quotation|approval rate)\b/', $normalized) === 1) {
            return [
                'intent' => 'analytics.services',
                'tool' => 'get_service_analytics',
                'tool_params' => $periodParams,
                'entity' => 'analytics_services',
                'result_type' => 'summary',
            ];
        }

        // 6. SLA status & risks
        if (preg_match('/\b(risiko sla|berisiko( melanggar)? sla|melanggar sla|langgar sla|status sla|kepatuhan sla|breached|melewati sla|mendekati batas sla|sla (saat ini|order|tiket|hari ini))\b/', $normalized) === 1) {
            return [
                'intent' => 'analytics.sla',
                'tool' => 'get_sla_status',
                'tool_params' => $periodParams,
                'entity' => 'analytics_sla',
                'result_type' => 'status',
            ];
        }

        // 7. Workforce BI
        if (preg_match('/\b(performa|kinerja|efisiensi|beban kerja|utilisasi|produktivitas)\b.{0,25}\b(teknisi|workforce|tim|saya)\b/', $normalized) === 1
            || preg_match('/\b(analitik workforce|analitik teknisi)\b/', $normalized) === 1) {
            return [
                'intent' => 'analytics.workforce',
                'tool' => 'get_workforce_analytics',
                'tool_params' => $periodParams,
                'entity' => 'analytics_workforce',
                'result_type' => 'summary',
            ];
        }

        // 8. Customer & Warranty BI
        if (preg_match('/\b(pelanggan berulang|repeat customer|repeat service|retensi pelanggan|tren pelanggan|lifetime value|ltv|analitik pelanggan|tingkat klaim garansi)\b/', $normalized) === 1) {
            return [
                'intent' => 'analytics.customers',
                'tool' => 'get_customer_analytics',
                'tool_params' => $periodParams,
                'entity' => 'analytics_customers',
                'result_type' => 'summary',
            ];
        }

        // 9. Executive BI (period order volume, business performance)
        if (preg_match('/\b(performa bisnis|ringkasan eksekutif|kpi bisnis|analitik eksekutif|analitik bisnis|kesehatan bisnis|laporan bisnis)\b/', $normalized) === 1
            || (preg_match('/\b(total|berapa|jumlah)\b.{0,25}\b(order|pesanan)\b/', $normalized) === 1
                && preg_match('/\b(\d+\s*hari|minggu ini|bulan ini|bulan lalu|tahun ini|kuartal|periode ini)\b/', $normalized) === 1)) {
            return [
                'intent' => 'analytics.executive',
                'tool' => 'get_executive_analytics',
                'tool_params' => $periodParams,
                'entity' => 'analytics_executive',
                'result_type' => 'summary',
            ];
        }

        // 10. Specific order details by ticket number or authenticated portfolio/warranty check
        if ($user !== null) {
            if (preg_match('/\b(ADC[- ]?[A-Z0-9-]+)\b/i', $rawMessage, $ticketMatch) === 1) {
                return [
                    'intent' => 'operational.order.detail',
                    'tool' => 'get_order_details',
                    'tool_params' => [
                        'ticket_number' => strtoupper(str_replace(' ', '-', $ticketMatch[1])),
                    ],
                    'entity' => 'ticket',
                    'result_type' => 'detail',
                ];
            }

            if (preg_match('/\b(detail (order|tiket|pesanan)|garansi saya|perangkat saya|aset saya|riwayat servis saya)\b/', $normalized) === 1) {
                return [
                    'intent' => 'operational.order.detail',
                    'tool' => 'get_order_details',
                    'tool_params' => isset($pageContext['order_id']) && $pageContext['order_id'] !== ''
                        ? ['order_id' => $pageContext['order_id']]
                        : [],
                    'entity' => 'ticket',
                    'result_type' => 'detail',
                ];
            }
        }

        // 11. Period-switching or recommendation follow-up over prior analytics topic
        if ($contextEntity !== null && str_starts_with($contextEntity, 'analytics_')
            && preg_match('/\b(kalau|bagaimana|coba|pakai|rentang|kenapa|mengapa|rekomendasi|saran|tindakan|solusi|90 hari|30 hari|12 bulan|7 hari|hari ini|bulan ini|tahun ini)\b/', $normalized) === 1) {
            $mappedTool = match ($contextEntity) {
                'analytics_financial' => 'get_financial_analytics',
                'analytics_services' => 'get_service_analytics',
                'analytics_workforce' => 'get_workforce_analytics',
                'analytics_inventory' => 'get_inventory_analytics',
                'analytics_customers' => 'get_customer_analytics',
                'analytics_insights' => 'get_business_insights',
                'analytics_sla' => 'get_sla_status',
                default => 'get_executive_analytics',
            };

            return [
                'intent' => str_replace('_', '.', $contextEntity),
                'tool' => $mappedTool,
                'tool_params' => $periodParams,
                'entity' => $contextEntity,
                'result_type' => 'summary',
            ];
        }

        return null;
    }

    /**
     * Extract validated period parameters from user phrasing or active analytics page context.
     *
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $pageContext
     * @return array<string, string>
     */
    public function extractPeriodParams(string $normalized, array $pageContext = []): array
    {
        if (preg_match('/\b(\d{4}) (\d{2}) (\d{2})\b.*\b(\d{4}) (\d{2}) (\d{2})\b/', $normalized, $m) === 1) {
            return [
                'period' => 'custom',
                'start_date' => "{$m[1]}-{$m[2]}-{$m[3]}",
                'end_date' => "{$m[4]}-{$m[5]}-{$m[6]}",
            ];
        }

        if (preg_match('/\b(hari ini|today|daily)\b/', $normalized) === 1) {
            return ['period' => 'daily'];
        }
        if (preg_match('/\b(7 hari|minggu ini|pekan ini|weekly)\b/', $normalized) === 1) {
            return ['period' => 'weekly'];
        }
        if (preg_match('/\b(90 hari|3 bulan|tiga bulan|kuartal|quarterly)\b/', $normalized) === 1) {
            return ['period' => 'quarterly'];
        }
        if (preg_match('/\b(12 bulan|setahun|tahun ini|yearly)\b/', $normalized) === 1) {
            return ['period' => 'yearly'];
        }
        if (preg_match('/\b(30 hari|bulan ini|monthly)\b/', $normalized) === 1) {
            return ['period' => 'monthly'];
        }

        if (isset($pageContext['period'])
            && in_array($pageContext['period'], ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'custom'], true)) {
            $params = ['period' => $pageContext['period']];
            if ($pageContext['period'] === 'custom'
                && ! empty($pageContext['start_date'])
                && ! empty($pageContext['end_date'])) {
                $params['start_date'] = (string) $pageContext['start_date'];
                $params['end_date'] = (string) $pageContext['end_date'];
            }

            return $params;
        }

        return ['period' => 'monthly'];
    }

    /**
     * @param  array{last_intent: string|null, last_entity: string|null, last_result_type: string|null, previous_turn: array{user_message: string|null, assistant_message: string|null}}  $context
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{intent: string, entity: string|null, result_type: string|null}|null
     */
    private function matchConversationIntent(string $normalized, array $context, array $messages): ?array
    {
        $contextEntity = $context['last_entity'];

        // 1. Greetings
        if (preg_match('/^(halo|hai|hi|hello|hei|pagi|siang|sore|malam|permisi|assalamualaikum|selamat (pagi|siang|sore|malam))( adi| kak| bro| min| pak| bu)?( apa kabar)?$/', $normalized) === 1) {
            return ['intent' => 'conversation.greeting', 'entity' => null, 'result_type' => null];
        }

        // 2. Thanks / closing
        if (preg_match('/^(oke |ok |baik |sip )?(terima kasih|makasih|thanks|thank you|mantap terima kasih)( adi| ya| banyak| kak)?$/', $normalized) === 1) {
            return ['intent' => 'conversation.thanks', 'entity' => null, 'result_type' => null];
        }

        // 3. Ambiguous contextual follow-up without compatible prior entity -> ask polite clarification
        if ($this->isContextualListFollowUp($normalized)
            || $this->isCountFollowUp($normalized)
            || $this->isActiveFilterFollowUp($normalized)
            || $this->isAvailableFilterFollowUp($normalized)
            || $this->isOtherFilterFollowUp($normalized)
            || ($this->isDetailFollowUp($normalized) && $contextEntity !== 'it_topic')) {
            return ['intent' => 'conversation.clarification', 'entity' => null, 'result_type' => null];
        }

        // 4. Portal workflow help (booking, tracking, payment, warranty)
        if (preg_match('/\b(lacak|status|progres|progress|sudah selesai|teknisi siapa)\b/', $normalized) === 1) {
            return ['intent' => 'conversation.portal_help', 'entity' => 'tracking', 'result_type' => null];
        }

        if (preg_match('/\b(bayar|pembayaran|transfer|rekening|qris)\b/', $normalized) === 1) {
            return ['intent' => 'conversation.portal_help', 'entity' => 'payment', 'result_type' => null];
        }

        if (preg_match('/\b(garansi|komplain|keluhan)\b/', $normalized) === 1) {
            return ['intent' => 'conversation.portal_help', 'entity' => 'warranty', 'result_type' => null];
        }

        if (preg_match('/\b(cara|gimana|bagaimana)\b.*\b(pesan|pesanan|pemesanan|booking|memesan)\b/', $normalized) === 1) {
            return ['intent' => 'conversation.portal_help', 'entity' => 'booking', 'result_type' => null];
        }

        // 5. Follow-up questions on pricing or previous service topic
        if ($this->isShortFollowUp($normalized) || preg_match('/\b(harga(nya)?|biaya(nya)?|berapa biayanya|berapa harganya|tarif(nya)?)\b/', $normalized) === 1) {
            return ['intent' => 'conversation.follow_up', 'entity' => $contextEntity, 'result_type' => null];
        }

        if (! empty($messages) && preg_match('/\b(kedua alat itu|alat tersebut|yang tadi|kalau untuk rumah|kalau untuk kantor|kalau untuk sekolah)\b/i', $normalized) === 1) {
            return ['intent' => 'conversation.follow_up', 'entity' => $contextEntity, 'result_type' => null];
        }

        return null;
    }

    /**
     * @param  array{last_intent?: string|null, last_entity?: string|null, last_result_type?: string|null, previous_turn?: array{user_message: string|null, assistant_message: string|null}}  $context
     * @return array{intent: string, entity: string, result_type: string}|null
     */
    private function matchGeneralItIntent(string $normalized, array $context = []): ?array
    {
        if ($this->isDetailFollowUp($normalized) && ($context['last_entity'] ?? null) === 'it_topic') {
            return [
                'intent' => 'general_it.troubleshooting',
                'entity' => 'it_topic',
                'result_type' => 'troubleshooting',
            ];
        }

        $itTermsPattern = '/\b(router|modem|switch|access point|repeater|extender|mesh|wifi|wi fi|internet|jaringan|lan|wan|wlan|utp|rj45|crimping|fiber optik|ip address|alamat ip|ip conflict|konflik ip|subnet|dhcp|dns|vlan|gateway|ping|bandwidth|kecepatan internet|sinyal|blank spot|dead zone|hotspot|server|firewall|cctv|kamera|ip camera|nvr|dvr|poe|rekaman|komputer|laptop|pc|notebook|netbook|printer|print|paper jam|spooler|monitor|layar|keyboard|mouse|harddisk|hdd|ssd|nvme|ram|memori|prosesor|processor|cpu|gpu|vga|motherboard|mainboard|power supply|psu|thermal paste|kipas|overheat|panas|bios|windows|linux|driver|software|aplikasi|sistem operasi|install ulang|virus|malware|backup|lemot|lambat|hang|freeze|macet|ngelag|blue screen|bsod|bootloop|gagal booting|mati total|tidak mau menyala|bunyi beep|konsultasi it|tkj|teknik komputer)\b/';

        if (preg_match($itTermsPattern, $normalized) !== 1) {
            return null;
        }

        $isConceptualQuestion = preg_match('/\b(apa (itu|fungsi|gunanya|manfaat|perbedaan|bedanya|arti|maksud)|fungsi (dari )?|jelaskan|cara kerja|kenapa|mengapa|bagaimana cara|gimana cara|cara mengatasi|cara atasin|cara memperbaiki|cara merawat)\b/', $normalized) === 1
            || preg_match('/\b(itu apa( sih)?|fungsinya apa)\b/', $normalized) === 1;

        return [
            'intent' => $isConceptualQuestion ? 'general_it.knowledge' : 'general_it.troubleshooting',
            'entity' => 'it_topic',
            'result_type' => $isConceptualQuestion ? 'knowledge' : 'troubleshooting',
        ];
    }

    public function isDirectFinanceQuery(string $normalized): bool
    {
        if (preg_match('/\b(pemasukan|pendapatan|omzet|uang masuk|dana masuk|pengeluaran|keuangan|saldo|kas|ledger)\b/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(transaksi)\b/', $normalized) === 1
            && preg_match('/\b(berapa|jumlah|total|ada|pending|menunggu verifikasi|terverifikasi|verified|ditolak|rejected|void|batal|kategori|bulan|hari|minggu|tahun|daftar|ringkasan|laporan)\b/', $normalized) === 1) {
            return true;
        }

        return false;
    }

    public function isFinanceFollowUp(string $normalized): bool
    {
        if (preg_match('/\b(bulan (ini|lalu|kemarin|sebelumnya|januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)|hari ini|kemarin|minggu (ini|lalu|kemarin)|tahun (ini|lalu|kemarin))\b/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(pending|menunggu verifikasi|ditolak|rejected|void|terverifikasi|verified|per kategori|berdasarkan kategori|kategori|pengeluaran|saldo|jumlah transaksi|total transaksi)\b/', $normalized) === 1) {
            return true;
        }

        return false;
    }

    private function inferFinanceResultType(string $normalized): string
    {
        if (preg_match('/\b(bulan|hari ini|kemarin|minggu|tahun|\d{4}\s+\d{2}\s+\d{2})\b/', $normalized) === 1) {
            return 'finance_period';
        }

        if (preg_match('/\b(kategori)\b/', $normalized) === 1) {
            return 'finance_category';
        }

        if (preg_match('/\b(pending|menunggu verifikasi)\b/', $normalized) === 1) {
            return 'finance_pending';
        }

        if (preg_match('/\b(ditolak|rejected|void|batal)\b/', $normalized) === 1) {
            return 'finance_rejected';
        }

        if (preg_match('/\b(jumlah transaksi|berapa transaksi|ada berapa transaksi|total transaksi)\b/', $normalized) === 1) {
            return 'finance_count';
        }

        return 'finance_income';
    }

    public function isPersonListFollowUp(string $normalized): bool
    {
        return preg_match('/^(?:kalau |kalo |lalu |terus |jadi )?(?:siapa|siapa saja|siapa aja|siapa namanya|namanya siapa(?: saja| aja)?|ada siapa(?: saja| aja)?|daftarnya(?: siapa)?|listnya(?: siapa)?|sebutkan namanya)(?: mereka| itu| tadi)?(?: ya| dong| kak| min)?$/', $normalized) === 1;
    }

    public function isGeneralListFollowUp(string $normalized): bool
    {
        return preg_match('/^(?:kalau |kalo |lalu |terus |jadi )?(?:apa saja|apa aja|daftarnya|listnya|sebutkan(?: saja)?|mana saja)(?: itu| tadi)?(?: ya| dong| kak| min)?$/', $normalized) === 1;
    }

    public function isContextualListFollowUp(string $normalized): bool
    {
        return $this->isPersonListFollowUp($normalized) || $this->isGeneralListFollowUp($normalized);
    }

    public function isCountFollowUp(string $normalized): bool
    {
        return preg_match('/^(?:kalau |kalo |lalu |terus |jadi )?(?:berapa jumlahnya|jumlahnya berapa|berapa totalnya|totalnya berapa|ada berapa(?: jumlahnya| totalnya)?)(?: ya| dong| kak)?$/', $normalized) === 1;
    }

    public function isActiveFilterFollowUp(string $normalized): bool
    {
        return preg_match('/^(?:kalau |kalo |lalu |terus |jadi )?(?:yang aktif(?: siapa(?: saja| aja)?)?|siapa(?: saja| aja)? yang aktif|kalau yang aktif(?: siapa)?|berapa yang aktif)(?: ya| dong| kak)?$/', $normalized) === 1;
    }

    public function isAvailableFilterFollowUp(string $normalized): bool
    {
        return preg_match('/^(?:kalau |kalo |lalu |terus |jadi )?(?:yang tersedia(?: siapa(?: saja| aja)?)?|siapa(?: saja| aja)? yang tersedia|tersedia siapa|yang kosong(?: siapa)?|yang siap(?: siapa)?|kalau yang tersedia)(?: ya| dong| kak)?$/', $normalized) === 1;
    }

    public function isOtherFilterFollowUp(string $normalized): bool
    {
        return preg_match('/^(?:kalau |kalo |lalu |terus |jadi )?(?:yang lainnya(?: bagaimana| siapa| apa)?|kalau yang lainnya|bagaimana yang lainnya|kalau yang lain|kalau sisanya|sisanya bagaimana|sisanya siapa)(?: ya| dong| kak)?$/', $normalized) === 1;
    }

    public function isDetailFollowUp(string $normalized): bool
    {
        return preg_match('/^(?:bisa |tolong |coba )?(?:jelaskan lebih detail|jelaskan lebih lengkap|lebih detail(?: lagi)?|detailnya(?: bagaimana| apa)?|rinciannya(?: bagaimana| apa)?|bisa lebih jelas)(?: ya| dong| kak)?$/', $normalized) === 1;
    }

    public function isShortFollowUp(string $normalized): bool
    {
        return preg_match('/^(?:(?:kalau|kalo|terus|lalu|dan|untuk|yang|itu|tadi|tersebut|berapa|harga|harganya|biaya|biayanya|tarif|ongkos|total|ya|dong|kak|nya|sih|jadi|termasuk|apakah|bagaimana|selanjutnya|gimana)(?:\s+|$))+$/', $normalized) === 1;
    }

    /**
     * Infer the most recent operational entity from conversation history.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function inferContextEntity(array $messages): ?string
    {
        $context = $this->resolveConversationContext($messages);

        return $context['last_entity'];
    }

    private function extractEntityFromText(string $text): ?string
    {
        if ($text === ''
            || $this->isContextualListFollowUp($text)
            || $this->isCountFollowUp($text)
            || $this->isActiveFilterFollowUp($text)
            || $this->isAvailableFilterFollowUp($text)
            || $this->isOtherFilterFollowUp($text)
            || $this->isDetailFollowUp($text)) {
            return null;
        }

        if ($this->isDirectFinanceQuery($text)) {
            return 'finance';
        }

        if (preg_match('/\b(customer(?:s|nya)?|pelanggan(?:nya)?)\b/', $text) === 1) {
            return 'customer';
        }

        if (preg_match('/\b(teknisi(?:nya)?|tim teknisi)\b/', $text) === 1) {
            return 'technician';
        }

        if (preg_match('/\b(user(?:nya)?|users|pengguna(?:nya)?|akun(?:nya)?)\b/', $text) === 1) {
            return 'user';
        }

        if (preg_match('/\b(jasa(?:nya)?|layanan(?:nya)?|service(?:s)?)\b/', $text) === 1) {
            return 'service';
        }

        if (preg_match('/\b(tiket(?:nya)?|pesanan(?:nya)?|order(?:nya|an)?)\b/', $text) === 1) {
            return 'ticket';
        }

        if (preg_match('/\b(stok|gudang|inventaris|sparepart)\b/', $text) === 1) {
            return 'inventory';
        }

        return null;
    }
}
