<?php

namespace App\Services\Assistant;

class AssistantActionIntentParser
{
    /**
     * Parse an incoming message (and optional conversation history/page context) for enabled confirmation-gated write actions.
     *
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $pageContext
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{
     *     intent: string,
     *     action: string,
     *     tool: string,
     *     tool_params: array<string, mixed>,
     *     entity: string,
     *     result_type: string
     * }|null
     */
    public function matchWriteActionIntent(
        string $normalized,
        string $rawMessage,
        array $pageContext,
        AssistantActionCoordinator $coordinator,
        array $messages = [],
    ): ?array {
        $direct = $this->matchDirectWriteActionIntent($normalized, $rawMessage, $coordinator);
        if ($direct !== null) {
            return $direct;
        }

        return $this->matchClarificationFollowUp($normalized, $rawMessage, $coordinator, $messages);
    }

    /**
     * @return array{
     *     intent: string,
     *     action: string,
     *     tool: string,
     *     tool_params: array<string, mixed>,
     *     entity: string,
     *     result_type: string
     * }|null
     */
    private function matchDirectWriteActionIntent(
        string $normalized,
        string $rawMessage,
        AssistantActionCoordinator $coordinator,
    ): ?array {
        if ($coordinator->has('create_quotation') && $this->isCreateQuotationIntent($normalized)) {
            return $this->buildActionMatch('create_quotation', $this->parseCreateQuotationPayload($rawMessage));
        }

        if ($coordinator->has('update_order_status') && $this->isUpdateOrderStatusIntent($normalized)) {
            return $this->buildActionMatch('update_order_status', $this->parseUpdateOrderStatusPayload($rawMessage));
        }

        if ($coordinator->has('assign_technician') && $this->isAssignTechnicianIntent($normalized)) {
            return $this->buildActionMatch('assign_technician', $this->parseAssignTechnicianPayload($rawMessage));
        }

        if ($coordinator->has('create_inventory_restock_request') && $this->isCreateRestockIntent($normalized)) {
            return $this->buildActionMatch('create_inventory_restock_request', $this->parseRestockPayload($rawMessage));
        }

        if ($coordinator->has('create_customer_followup') && $this->isCreateFollowupIntent($normalized)) {
            return $this->buildActionMatch('create_customer_followup', $this->parseFollowupPayload($rawMessage));
        }

        if ($coordinator->has('create_service_order') && $this->isCreateServiceOrderIntent($normalized)) {
            return $this->buildActionMatch('create_service_order', $this->parseCreateServiceOrderPayload($rawMessage));
        }

        return null;
    }

    /**
     * Continue an active write-action clarification when the user supplies missing fields on a follow-up turn.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{
     *     intent: string,
     *     action: string,
     *     tool: string,
     *     tool_params: array<string, mixed>,
     *     entity: string,
     *     result_type: string
     * }|null
     */
    private function matchClarificationFollowUp(
        string $normalized,
        string $rawMessage,
        AssistantActionCoordinator $coordinator,
        array $messages,
    ): ?array {
        if ($messages === [] || $this->isClarificationCancellation($normalized) || $this->isClarificationTopicShift($normalized, $rawMessage)) {
            return null;
        }

        $tail = array_slice($messages, -8);
        $lastKey = array_key_last($tail);
        if ($lastKey === null) {
            return null;
        }

        $lastTurn = $tail[$lastKey];
        if ($lastTurn['role'] !== 'assistant') {
            return null;
        }

        $clarifiedAction = $this->detectClarifiedActionFromReply($lastTurn['content']);
        if ($clarifiedAction === null || ! $coordinator->has($clarifiedAction)) {
            return null;
        }

        $chainUserMessages = [];
        $foundActionOrigin = false;
        for ($i = count($tail) - 1; $i >= 0; $i--) {
            $turn = $tail[$i];
            $role = $turn['role'];
            $content = trim($turn['content']);
            if ($content === '') {
                continue;
            }

            if ($role === 'assistant') {
                $assistantAction = $this->detectClarifiedActionFromReply($content);
                if ($assistantAction !== $clarifiedAction) {
                    break;
                }

                continue;
            }

            if ($role === 'user') {
                array_unshift($chainUserMessages, $content);
                $userMatch = $this->matchDirectWriteActionIntent($this->normalizeClarificationText($content), $content, $coordinator);
                if ($userMatch !== null && $userMatch['action'] === $clarifiedAction) {
                    $foundActionOrigin = true;
                    break;
                }
            }
        }

        if (! $foundActionOrigin) {
            return null;
        }

        $combinedRaw = implode("\n", [...$chainUserMessages, $rawMessage]);

        $payload = match ($clarifiedAction) {
            'create_service_order' => $this->parseCreateServiceOrderPayload($combinedRaw),
            'create_quotation' => $this->parseCreateQuotationPayload($combinedRaw),
            'update_order_status' => $this->parseUpdateOrderStatusPayload($combinedRaw),
            'assign_technician' => $this->parseAssignTechnicianContinuation($combinedRaw, $rawMessage),
            'create_inventory_restock_request' => $this->parseRestockPayload($combinedRaw),
            'create_customer_followup' => $this->parseFollowupContinuation($combinedRaw, $rawMessage),
            default => [],
        };

        return $this->buildActionMatch($clarifiedAction, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     intent: string,
     *     action: string,
     *     tool: string,
     *     tool_params: array<string, mixed>,
     *     entity: string,
     *     result_type: string
     * }
     */
    private function buildActionMatch(string $action, array $payload): array
    {
        $entity = match ($action) {
            'create_quotation' => 'quotation',
            'assign_technician' => 'technician_assignment',
            'create_inventory_restock_request' => 'inventory_restock',
            'create_customer_followup' => 'customer_followup',
            default => 'service_order',
        };

        return [
            'intent' => "action.{$action}",
            'action' => $action,
            'tool' => "action:{$action}",
            'tool_params' => $payload,
            'entity' => $entity,
            'result_type' => 'action_proposal',
        ];
    }

    private function detectClarifiedActionFromReply(string $assistantReply): ?string
    {
        $lower = strtolower($assistantReply);

        if (str_contains($lower, 'pratinjau pesanan layanan')
            || str_contains($lower, 'lengkapi detail:')) {
            return 'create_service_order';
        }

        if (str_contains($lower, 'item biaya')
            || str_contains($lower, 'dibuatkan penawaran')) {
            return 'create_quotation';
        }

        if (str_contains($lower, 'status targetnya')
            || str_contains($lower, 'status baru')
            || str_contains($lower, 'diperbarui statusnya')) {
            return 'update_order_status';
        }

        if (str_contains($lower, 'nama lengkap teknisi')
            || str_contains($lower, 'nama teknisi aktif yang akan ditugaskan')
            || str_contains($lower, 'ditugaskan teknisinya')) {
            return 'assign_technician';
        }

        if (str_contains($lower, 'jumlah unit restock')
            || str_contains($lower, 'sku spesifik')
            || (str_contains($lower, 'item inventaris aktif') && str_contains($lower, 'restock'))) {
            return 'create_inventory_restock_request';
        }

        if (str_contains($lower, 'isi pesan follow-up')
            || str_contains($lower, 'nomor tiket tujuan follow-up')) {
            return 'create_customer_followup';
        }

        return null;
    }

    private function isClarificationCancellation(string $normalized): bool
    {
        return preg_match('/^(?:batal|batalkan|tidak jadi|gak jadi|nggak jadi|ga jadi|cancel|stop|lupakan)(?:\s+saja|\s+dulu)?$/', trim($normalized)) === 1;
    }

    private function isClarificationTopicShift(string $normalized, string $rawMessage): bool
    {
        if (preg_match('/\b(ADC-[A-Z0-9-]+|SKU[-A-Z0-9]+|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})\b/i', $rawMessage) === 1) {
            return false;
        }

        if (preg_match('/\b(nama|alamat|hp|telepon|telp|wa|whatsapp|email|keluhan|masalah|kerusakan|jasa|part|sparepart|sebanyak|qty|jumlah)\b/i', $rawMessage) === 1) {
            return false;
        }

        return preg_match('/^(?:halo|hai|hi|selamat\s+(?:pagi|siang|sore|malam)|ada\s+berapa|apa\s+saja|siapa\s+saja|berapa|siapa|apa\s+itu|mengapa|kenapa|kapan|jelaskan|cara|bagaimana|gimana|tutorial|panduan)\b/', trim($normalized)) === 1
            || str_ends_with(trim($rawMessage), '?');
    }

    private function normalizeClarificationText(string $text): string
    {
        $clean = strtolower(trim($text));
        $clean = preg_replace('/[^\pL\pN\s@._\/+-]+/u', ' ', $clean) ?? $clean;

        return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    }

    private function isCreateFollowupIntent(string $normalized): bool
    {
        return preg_match('/\b(kirim|buat|buatkan|sampaikan|tulis|catatkan|follow\s*up)\b.*\b(follow\s*up|pesan\s+pelanggan|kabar\s+pelanggan|notifikasi\s+pelanggan)\b/', $normalized) === 1
            || preg_match('/\bfollow\s*up\b.*\b(ADC-[A-Z0-9-]+|pelanggan|customer|tiket)\b/i', $normalized) === 1
            || preg_match('/\bcreate_customer_followup\b/', $normalized) === 1;
    }

    private function isCreateRestockIntent(string $normalized): bool
    {
        if (preg_match('/\b(apa|mana|berapa|daftar|list|cek|lihat|ringkasan|perlu|butuh)\b/', $normalized) === 1
            && preg_match('/\b(buat|buatkan|ajukan|request|minta|order|create_inventory_restock_request)\b/', $normalized) === 0) {
            return false;
        }

        return preg_match('/\b(buat|buatkan|ajukan|bikin|kirim|minta|order|create)\b.*\b(restock|isi ulang stok|pengadaan stok)\b/', $normalized) === 1
            || preg_match('/\bcreate_inventory_restock_request\b/', $normalized) === 1;
    }

    private function isAssignTechnicianIntent(string $normalized): bool
    {
        if (preg_match('/\b(siapa|daftar|list|cek|berapa|available|tersedia)\b/', $normalized) === 1
            && preg_match('/\b(tugaskan|assign|jadikan pic|tetapkan teknisi)\b/', $normalized) === 0) {
            return false;
        }

        return preg_match('/\b(tugaskan|assign|alihkan|tetapkan|pilih|kirimkan)\b.*\b(teknisi|pic|petugas)\b/', $normalized) === 1
            || preg_match('/\b(assign_technician|jadikan pic)\b/', $normalized) === 1;
    }

    private function isUpdateOrderStatusIntent(string $normalized): bool
    {
        if (preg_match('/\b(berapa|apa status|cek status|lihat status|status pesanan saya|bagaimana status)\b/', $normalized) === 1
            && preg_match('/\b(ubah|ganti|update|perbarui|set|jadikan|tandai|pindahkan)\b/', $normalized) === 0) {
            return false;
        }

        return preg_match('/\b(ubah|ganti|update|perbarui|set|jadikan|tandai|pindahkan)\b.*\b(status|tiket|pesanan|order|pekerjaan)\b/', $normalized) === 1
            || preg_match('/\bupdate_order_status\b/', $normalized) === 1;
    }

    private function isCreateQuotationIntent(string $normalized): bool
    {
        if (preg_match('/\b(berapa|daftar|list|lihat|cek|status|ringkasan|rekap)\b/', $normalized) === 1
            && preg_match('/\b(buat|buatkan|bikin|terbitkan|create)\b/', $normalized) === 0) {
            return false;
        }

        return preg_match('/\b(buat|buatkan|bikin|terbitkan|create|tambah)\b.*\b(penawaran|quotation|estimasi|rab)\b/', $normalized) === 1
            || preg_match('/\bcreate_quotation\b/', $normalized) === 1;
    }

    private function isCreateServiceOrderIntent(string $normalized): bool
    {
        if (preg_match('/\b(cara|bagaimana|gimana|syarat|panduan|langkah|tutorial)\b/', $normalized) === 1
            && preg_match('/\b(buatkan|daftarkan|ajukan|create_service_order)\b/', $normalized) === 0) {
            return false;
        }

        if (preg_match('/\b(berapa|daftar|list|lihat|cek|status|apa|ringkasan|antrean|rekap)\b/', $normalized) === 1
            && preg_match('/\b(buat|buatkan|bikin|daftarkan|ajukan|create|pesan layanan)\b/', $normalized) === 0) {
            return false;
        }

        if (preg_match('/\b(penawaran|quotation|estimasi|rab|restock|follow\s*up|status)\b/', $normalized) === 1
            && preg_match('/\b(pesanan|booking|create_service_order)\b/', $normalized) === 0) {
            return false;
        }

        return preg_match('/\b(buat|buatkan|bikin|daftarkan|ajukan|create|tambah)\b.*\b(pesanan|order|tiket|booking|servis|layanan)\b/', $normalized) === 1
            || preg_match('/\b(create_service_order|order layanan|pesan layanan)\b/', $normalized) === 1;
    }

    /**
     * Extract structured booking fields from freeform Indonesian/English user text.
     *
     * @return array<string, mixed>
     */
    private function parseCreateServiceOrderPayload(string $rawMessage): array
    {
        $payload = [
            'raw_message' => $rawMessage,
        ];

        $kvPatterns = [
            'customer_name' => '/(?:atas nama|nama\s+pelanggan|nama)\s*[:=]\s*([^\n,;]+)/i',
            'phone' => '/(?:no\.?\s*hp|hp|telepon|telp|wa|whatsapp|phone)\s*[:=\s]+\s*(\+?\d[\d\s-]{6,18}\d)/i',
            'email' => '/(?:email)\s*[:=\s]+\s*([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})/i',
            'address' => '/(?:alamat(?:\s+lengkap)?)\s*[:=\s]+\s*([^\n;]+?)(?=(?:\s*(?:,|;|\bno\.?\s*hp|\bhp\b|\btelepon|\btelp|\bwa\b|\bemail|\bkeluhan|\bmasalah|\bkerusakan|\bmode|\bprefer|\burgen)|\s*$))/i',
            'district' => '/(?:kecamatan|district|wilayah)\s*[:=\s]+\s*([^\n,;]+)/i',
            'complaint' => '/(?:keluhan(?:\s*\/\s*kebutuhan|\s+utama)?|masalah|kerusakan|deskripsi|catatan)\s*[:=\s]+\s*([^\n;]+?)(?=(?:\s*(?:,|;|\balamat|\bno\.?\s*hp|\bhp\b|\btelepon|\btelp|\bwa\b|\bemail|\bmode|\batas nama|\burgen)|\s*$))/i',
            'service' => '/(?:layanan|service|jasa)\s*[:=]\s*([^\n,;]+)/i',
            'preferred_date' => '/\b(\d{4}-\d{2}-\d{2})\b/',
        ];

        foreach ($kvPatterns as $key => $pattern) {
            if (preg_match_all($pattern, $rawMessage, $matches) > 0) {
                $lastMatch = end($matches[1]);
                if (is_string($lastMatch) && trim($lastMatch) !== '') {
                    $payload[$key] = trim($lastMatch);
                }
            }
        }

        if (! isset($payload['complaint'])
            && preg_match_all('/(?:karena|untuk)\s*[:=\s]+\s*([^\n;]+?)(?=(?:\s*(?:,|;|\balamat|\bno\.?\s*hp|\btelepon|\bwa|\bemail|\bmode|\batas nama|\burgen)|\s*$))/i', $rawMessage, $matches) > 0) {
            $lastFallbackComplaint = end($matches[1]);
            if (is_string($lastFallbackComplaint) && trim($lastFallbackComplaint) !== '') {
                $payload['complaint'] = trim($lastFallbackComplaint);
            }
        }

        if (! isset($payload['customer_name'])
            && preg_match('/\batas nama\s+([a-z][a-z\s.\']{1,60}?)(?=(?:\s*(?:,|;|\balamat|\bno\.?\s*hp|\btelepon|\bwa|\bemail|\bkeluhan|\bmode|\bkarena|$)))/i', $rawMessage, $matches) === 1) {
            $payload['customer_name'] = trim($matches[1]);
        }

        if (! isset($payload['email'])
            && preg_match('/([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})/i', $rawMessage, $matches) === 1) {
            $payload['email'] = trim($matches[1]);
        }

        $lower = strtolower($rawMessage);
        if (str_contains($lower, 'onsite') || str_contains($lower, 'kunjungan') || str_contains($lower, 'ke lokasi')) {
            $payload['service_mode'] = 'onsite';
        } elseif (str_contains($lower, 'remote')) {
            $payload['service_mode'] = 'remote';
        } elseif (str_contains($lower, 'dropoff') || str_contains($lower, 'antar')) {
            $payload['service_mode'] = 'dropoff';
        }

        if (str_contains($lower, 'segera') || str_contains($lower, 'urgent') || str_contains($lower, 'cepat') || preg_match('/\bsoon\b/', $lower) === 1) {
            $payload['urgency'] = 'soon';
        }

        if (str_contains($lower, 'sore') || str_contains($lower, 'afternoon')) {
            $payload['preferred_window'] = 'afternoon';
        } elseif (str_contains($lower, 'siang') || str_contains($lower, 'midday')) {
            $payload['preferred_window'] = 'midday';
        } elseif (str_contains($lower, 'pagi') || str_contains($lower, 'morning')) {
            $payload['preferred_window'] = 'morning';
        }

        return $payload;
    }

    /**
     * Extract quotation fields (ticket number, cost items, discount, tax, notes, send_now).
     *
     * @return array<string, mixed>
     */
    private function parseCreateQuotationPayload(string $rawMessage): array
    {
        $payload = [
            'raw_message' => $rawMessage,
        ];

        if (preg_match('/\b(ADC-[A-Z0-9-]+)\b/i', $rawMessage, $matches) === 1) {
            $payload['ticket_number'] = strtoupper($matches[1]);
        }

        if (preg_match('/(?:diskon|discount|potongan)\s*(?:rp\.?\s*)?([\d.,]+)/i', $rawMessage, $matches) === 1) {
            $payload['discount_amount'] = (int) preg_replace('/\D+/', '', $matches[1]);
        }

        if (preg_match('/(?:pajak|ppn|tax)\s*(\d+(?:[.,]\d{1,2})?)\s*%?/i', $rawMessage, $matches) === 1) {
            $payload['tax_rate'] = str_replace(',', '.', $matches[1]);
        }

        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $rawMessage, $matches) === 1) {
            $payload['valid_until'] = $matches[1];
        }

        if (preg_match('/(?:catatan|notes?)\s*[:=]\s*([^\n;]+)/i', $rawMessage, $matches) === 1) {
            $payload['notes'] = trim($matches[1]);
        }

        $lower = strtolower($rawMessage);
        if (str_contains($lower, 'kirim langsung') || str_contains($lower, 'langsung kirim') || str_contains($lower, 'send_now')) {
            $payload['send_now'] = true;
        }

        $items = [];
        $itemPattern = '/\b(jasa|labor|spare\s*part|sparepart|part|operasional|operational|biaya|item)\s+([a-z0-9\s\/&+-]{2,60}?)\s*(?:(\d+)\s*x\s*)?(?:rp\.?\s*)([\d.]{3,15})\b/i';
        if (preg_match_all($itemPattern, $rawMessage, $itemMatches, PREG_SET_ORDER) > 0) {
            foreach ($itemMatches as $match) {
                $kind = strtolower(trim($match[1]));
                $itemType = match (true) {
                    str_contains($kind, 'part') => 'spare_part',
                    str_contains($kind, 'operasional') || str_contains($kind, 'operational') => 'operational',
                    default => 'labor',
                };
                $label = trim($match[2]);
                $quantity = $match[3] !== '' ? $match[3] : '1';
                $price = (int) preg_replace('/\D+/', '', $match[4]);

                if ($label !== '' && $price >= 0) {
                    $items[] = [
                        'item_type' => $itemType,
                        'label' => ucfirst($label),
                        'quantity' => $quantity,
                        'unit_price' => $price,
                    ];
                }
            }
        }

        if ($items !== []) {
            $payload['items'] = $items;
        }

        return $payload;
    }

    /**
     * Extract ticket number, target status, and optional progress note.
     *
     * @return array<string, mixed>
     */
    private function parseUpdateOrderStatusPayload(string $rawMessage): array
    {
        $payload = [
            'raw_message' => $rawMessage,
        ];

        if (preg_match('/\b(ADC-[A-Z0-9-]+)\b/i', $rawMessage, $matches) === 1) {
            $payload['ticket_number'] = strtoupper($matches[1]);
        }

        $knownStatuses = [
            'needs_info',
            'triaged',
            'waiting_estimate',
            'waiting_approval',
            'scheduled',
            'on_the_way',
            'in_progress',
            'verification',
            'waiting_payment',
            'completed',
            'warranty',
            'closed',
            'reopened',
            'cancelled',
        ];

        $lower = strtolower($rawMessage);
        foreach ($knownStatuses as $status) {
            if (preg_match('/\b'.preg_quote($status, '/').'\b/', $lower) === 1) {
                $payload['status'] = $status;

                break;
            }
        }

        if (! isset($payload['status'])) {
            $aliases = [
                'on_the_way' => ['menuju lokasi', 'dalam perjalanan', 'otw'],
                'in_progress' => ['sedang dikerjakan', 'mulai dikerjakan', 'pengerjaan dimulai', 'dikerjakan', 'in progress'],
                'verification' => ['menunggu verifikasi', 'verifikasi'],
                'waiting_payment' => ['menunggu pembayaran', 'waiting payment'],
                'completed' => ['selesai', 'completed'],
                'cancelled' => ['dibatalkan', 'batalkan', 'batal'],
                'scheduled' => ['dijadwalkan', 'jadwal dikonfirmasi'],
                'triaged' => ['triase', 'triage'],
                'needs_info' => ['butuh info', 'perlu info'],
                'closed' => ['tutup tiket', 'ditutup'],
            ];

            foreach ($aliases as $status => $phrases) {
                foreach ($phrases as $phrase) {
                    if (str_contains($lower, $phrase)) {
                        $payload['status'] = $status;

                        break 2;
                    }
                }
            }
        }

        if (preg_match('/(?:catatan|keterangan|note|alasan)\s*[:=]\s*([^\n;]+)/i', $rawMessage, $matches) === 1) {
            $payload['note'] = trim($matches[1]);
        }

        return $payload;
    }

    /**
     * Extract ticket number and target technician hint for assign_technician.
     *
     * @return array<string, mixed>
     */
    private function parseAssignTechnicianPayload(string $rawMessage): array
    {
        $payload = [
            'raw_message' => $rawMessage,
        ];

        if (preg_match('/\b(ADC-[A-Z0-9-]+)\b/i', $rawMessage, $matches) === 1) {
            $payload['ticket_number'] = strtoupper($matches[1]);
        }

        if (preg_match('/(?:teknisi|pic)\s*[:=]\s*([^\n,;]+)/i', $rawMessage, $matches) === 1) {
            $payload['technician_name'] = trim($matches[1]);
        }

        return $payload;
    }

    /**
     * Extract inventory item hint, quantity, priority, and notes for restock requests.
     *
     * @return array<string, mixed>
     */
    private function parseRestockPayload(string $rawMessage): array
    {
        $payload = [
            'raw_message' => $rawMessage,
        ];

        $candidates = [];

        if (preg_match('/\b(?:sku|kode(?:\s+(?:barang|item))?)\s*[:=]\s*([a-z0-9]+(?:[._-][a-z0-9]+)*)/i', $rawMessage, $matches) === 1) {
            $explicitSku = strtoupper(trim($matches[1]));
            if ($explicitSku !== '') {
                $payload['sku'] = $explicitSku;
                $candidates[] = $explicitSku;
            }
        }

        if (preg_match_all('/(?<![a-z0-9_-])([a-z0-9]{1,16}(?:[-_][a-z0-9]{1,16})+)(?![a-z0-9_-])/i', $rawMessage, $skuMatches) > 0) {
            foreach ($skuMatches[1] as $rawCandidate) {
                $candidate = strtoupper(trim($rawCandidate));
                if ($candidate === '' || preg_match('/[A-Z]/', $candidate) !== 1) {
                    continue;
                }

                // Ignore ordinary hyphenated prose (e.g. re-order, dead-stock) unless SKU-prefixed or alphanumeric-coded.
                if (! str_starts_with($candidate, 'SKU-') && preg_match('/\d/', $candidate) !== 1) {
                    continue;
                }

                $candidates[] = $candidate;
            }
        }

        $candidates = array_values(array_unique($candidates));
        if ($candidates !== []) {
            $payload['sku_candidates'] = $candidates;
            if (! isset($payload['sku']) && count($candidates) === 1) {
                $payload['sku'] = $candidates[0];
            }
        }

        if (preg_match('/(?:sebanyak|qty|jumlah)\s*[:=]?\s*(-?\d+)\b|\b(-?\d+)\s*(?:pcs|unit|buah|pack|box)\b/i', $rawMessage, $matches) === 1) {
            $qtyRaw = $matches[1] !== '' ? $matches[1] : $matches[2];
            $payload['requested_quantity'] = (int) $qtyRaw;
        }

        $lower = strtolower($rawMessage);
        if (str_contains($lower, 'urgent') || str_contains($lower, 'mendesak')) {
            $payload['priority'] = 'urgent';
        } elseif (str_contains($lower, 'prioritas tinggi') || str_contains($lower, 'high')) {
            $payload['priority'] = 'high';
        }

        if (preg_match('/(?:catatan|notes?)\s*[:=]\s*([^\n;]+)/i', $rawMessage, $matches) === 1) {
            $payload['notes'] = trim($matches[1]);
        }

        return $payload;
    }

    /**
     * Extract ticket number and follow-up message body for create_customer_followup.
     *
     * @return array<string, mixed>
     */
    private function parseFollowupPayload(string $rawMessage): array
    {
        $payload = [
            'raw_message' => $rawMessage,
        ];

        if (preg_match('/\b(ADC-[A-Z0-9-]+)\b/i', $rawMessage, $matches) === 1) {
            $payload['ticket_number'] = strtoupper($matches[1]);
        }

        if (preg_match('/(?:pesan|isi|body|catatan)\s*[:=]\s*([^\n]+)/i', $rawMessage, $matches) === 1) {
            $payload['body'] = trim($matches[1]);
        } elseif (preg_match('/"([^"]{3,1500})"/', $rawMessage, $matches) === 1) {
            $payload['body'] = trim($matches[1]);
        } elseif (preg_match('/\b(?:bahwa|info(?:rmasikan)?)\s+([^\n]+)/i', $rawMessage, $matches) === 1) {
            $payload['body'] = trim($matches[1]);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAssignTechnicianContinuation(string $combinedRaw, string $latestTurn): array
    {
        $payload = $this->parseAssignTechnicianPayload($combinedRaw);

        $stripped = trim((string) (preg_replace('/\bADC-[A-Z0-9-]+\b/i', '', $latestTurn) ?? $latestTurn));
        if ($stripped !== '') {
            $payload['technician_name'] = $stripped;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFollowupContinuation(string $combinedRaw, string $latestTurn): array
    {
        $payload = $this->parseFollowupPayload($combinedRaw);

        if (! isset($payload['body'])) {
            $stripped = trim((string) (preg_replace('/\bADC-[A-Z0-9-]+\b/i', '', $latestTurn) ?? $latestTurn));
            if ($stripped !== '') {
                $payload['body'] = $stripped;
            }
        }

        return $payload;
    }
}
