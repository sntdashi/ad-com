import { useEffect, useState } from 'react';
import {
    ActivityIndicator,
    AppState,
    Linking,
    Pressable,
    Text,
    View,
} from 'react-native';
import { dateLabel, errorMessage, money, request } from './api';
import { mergeMessages } from './connection';
import { endpoints as api } from './endpoints';
import { useRemote } from './useRemote';
import type { Message, Order, Page, Service, Technician, User } from './types';
import {
    Avatar,
    Button,
    Chip,
    Field,
    Hero,
    Notice,
    Panel,
    color,
    styles,
} from './ui';

export function ServicesScreen({
    user,
    onSelect,
}: {
    user: User;
    onSelect: (service: Service) => void;
}) {
    const { data, error, loading, reload } = useRemote<Page<Service>>(
        api.services.index.url(),
    );
    return (
        <>
            <Hero
                eyebrow={`Halo, ${user.name.split(' ')[0]}`}
                title="Mau dibantu apa?"
                description="Pilih layanan. Tim akan mengonfirmasi kebutuhan, biaya, dan jadwal sebelum bekerja."
            />
            <Notice message={error} />
            {loading && <ActivityIndicator color={color.red} />}
            {data?.data.map((service) => (
                <Pressable
                    key={service.id}
                    accessibilityRole="button"
                    accessibilityLabel={`Pesan ${service.name}`}
                    onPress={() => onSelect(service)}
                    style={({ pressed }) => [
                        styles.panel,
                        pressed && { borderColor: color.red },
                    ]}
                >
                    <View style={styles.between}>
                        <Chip>{service.code}</Chip>
                        {service.warranty_days > 0 && (
                            <Text style={styles.body}>
                                {service.warranty_days} hari garansi
                            </Text>
                        )}
                    </View>
                    <Text style={styles.heading}>{service.name}</Text>
                    <Text style={styles.body}>{service.summary}</Text>
                    <Text style={{ color: color.deep, fontWeight: '800' }}>
                        {service.pricing_label || money(service.starting_price)}
                    </Text>
                    <Text style={styles.link}>Ajukan layanan →</Text>
                </Pressable>
            ))}
            {data?.data.length === 0 && (
                <Notice message="Layanan belum tersedia. Silakan hubungi operator melalui portal web." />
            )}
            <Button
                label="Muat ulang layanan"
                secondary
                busy={loading}
                onPress={() => void reload()}
            />
        </>
    );
}

export function BookingScreen({
    service,
    user,
    onCreated,
    onBack,
}: {
    service: Service;
    user: User;
    onCreated: (order: Order) => void;
    onBack: () => void;
}) {
    const [form, setForm] = useState({
        customer_name: user.name,
        phone: user.phone || '',
        email: user.email,
        address: user.address || '',
        complaint: '',
        service_mode: service.service_mode,
        urgency: 'normal',
        preferred_date: '',
        preferred_window: 'morning',
    });
    const [privacy, setPrivacy] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const field = (key: keyof typeof form, value: string) =>
        setForm((previous) => ({ ...previous, [key]: value }));
    async function submit() {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const result = await request<{ data: Order }>(
                api.orders.store.url(),
                {
                    method: 'POST',
                    body: {
                        ...form,
                        preferred_date: form.preferred_date || null,
                        service_id: service.id,
                        privacy_accepted: privacy,
                    },
                },
            );
            onCreated(result.data);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <Button
                secondary
                label="‹ Kembali ke layanan"
                disabled={busy}
                onPress={onBack}
            />
            <Hero
                eyebrow={service.code}
                title={service.name}
                description={service.description || service.summary}
            />
            <Panel>
                <Text style={styles.heading}>Detail permintaan</Text>
                <Text style={styles.body}>
                    Harga awal bukan tagihan akhir. Operator akan mengonfirmasi
                    kebutuhan, estimasi, dan jadwal terlebih dahulu.
                </Text>
                <Field
                    label="Nama pemesan"
                    value={form.customer_name}
                    onChangeText={(value) => field('customer_name', value)}
                />
                <Field
                    label="Nomor HP / WhatsApp"
                    keyboardType="phone-pad"
                    value={form.phone}
                    onChangeText={(value) => field('phone', value)}
                />
                <Field
                    label="Alamat lengkap"
                    multiline
                    value={form.address}
                    onChangeText={(value) => field('address', value)}
                />
                <Field
                    label="Ceritakan kendala (minimal 20 karakter)"
                    multiline
                    maxLength={3000}
                    value={form.complaint}
                    onChangeText={(value) => field('complaint', value)}
                />
                <Text style={styles.label}>Cara penanganan</Text>
                <Choices
                    value={form.service_mode}
                    onChange={(value) => field('service_mode', value)}
                    options={[
                        ['onsite', 'Ke lokasi'],
                        ['dropoff', 'Antar perangkat'],
                        ['remote', 'Jarak jauh'],
                    ]}
                />
                <Text style={styles.label}>Kebutuhan waktu</Text>
                <Choices
                    value={form.urgency}
                    onChange={(value) => field('urgency', value)}
                    options={[
                        ['normal', 'Fleksibel'],
                        ['soon', 'Segera'],
                    ]}
                />
                <Field
                    label="Tanggal pilihan (opsional, YYYY-MM-DD)"
                    value={form.preferred_date}
                    onChangeText={(value) => field('preferred_date', value)}
                    placeholder="2026-09-15"
                    keyboardType="numbers-and-punctuation"
                />
                <Choices
                    value={form.preferred_window}
                    onChange={(value) => field('preferred_window', value)}
                    options={[
                        ['morning', 'Pagi'],
                        ['midday', 'Siang'],
                        ['afternoon', 'Sore'],
                    ]}
                />
                <Pressable
                    accessibilityRole="checkbox"
                    accessibilityState={{ checked: privacy }}
                    onPress={() => setPrivacy(!privacy)}
                    style={[styles.row, { paddingVertical: 12 }]}
                >
                    <Text style={{ fontSize: 22, color: color.red }}>
                        {privacy ? '☑' : '□'}
                    </Text>
                    <Text style={[styles.body, { flex: 1 }]}>
                        Saya setuju data kontak dan detail kendala digunakan tim
                        AD Com untuk menangani pesanan ini.
                    </Text>
                </Pressable>
                <Notice message={error} />
                <Button
                    label="Kirim permintaan layanan"
                    disabled={!privacy}
                    busy={busy}
                    onPress={() => void submit()}
                />
            </Panel>
        </>
    );
}

export function Choices({
    value,
    options,
    onChange,
}: {
    value: string;
    options: string[][];
    onChange: (value: string) => void;
}) {
    return (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
            {options.map(([key, label]) => (
                <Pressable
                    key={key}
                    accessibilityRole="radio"
                    accessibilityState={{ selected: value === key }}
                    onPress={() => onChange(key)}
                    style={[
                        styles.chip,
                        {
                            paddingVertical: 12,
                            backgroundColor:
                                value === key ? color.deep : color.rose,
                        },
                    ]}
                >
                    <Text
                        style={[
                            styles.chipText,
                            value === key && { color: 'white' },
                        ]}
                    >
                        {label}
                    </Text>
                </Pressable>
            ))}
        </View>
    );
}

export function OrdersScreen({
    user,
    onSelect,
}: {
    user: User;
    onSelect: (order: Order) => void;
}) {
    const [page, setPage] = useState(1);
    const { data, error, loading, reload } = useRemote<Page<Order>>(
        api.orders.index.url({ query: { page } }),
    );
    return (
        <>
            <Hero
                eyebrow={
                    user.role === 'technician'
                        ? 'RUANG KERJA TEKNISI'
                        : 'TIKET LAYANANMU'
                }
                title={
                    user.role === 'technician' ? 'Tugas saya' : 'Pesanan saya'
                }
                description={
                    user.role === 'technician'
                        ? 'Tiket yang ditugaskan kepadamu tampil di sini. Penyelesaian akhir tetap diverifikasi supervisor.'
                        : 'Lihat progres, ngobrol dengan teknisi, dan beri penilaian setelah pekerjaan selesai.'
                }
            />
            <Notice message={error} />
            {loading && <ActivityIndicator color={color.red} />}
            {data?.data.map((order) => (
                <Panel key={order.id}>
                    <View style={styles.between}>
                        <Text style={styles.label}>{order.ticket_number}</Text>
                        <Chip>{order.status_label}</Chip>
                    </View>
                    <Text style={styles.heading}>{order.service.name}</Text>
                    <Text style={styles.body} numberOfLines={2}>
                        {order.complaint}
                    </Text>
                    <Text style={styles.body}>
                        {dateLabel(order.scheduled_at)}
                    </Text>
                    <Button
                        label="Buka tiket & percakapan"
                        onPress={() => onSelect(order)}
                    />
                </Panel>
            ))}
            {data?.data.length === 0 && (
                <Panel>
                    <Text style={styles.heading}>Belum ada tiket</Text>
                    <Text style={styles.body}>
                        {user.role === 'technician'
                            ? 'Tiket akan muncul setelah operator menugaskanmu.'
                            : 'Mulai dari menu Layanan untuk membuat pesanan pertama.'}
                    </Text>
                </Panel>
            )}
            <View style={styles.row}>
                <View style={{ flex: 1 }}>
                    <Button
                        secondary
                        label="Sebelumnya"
                        disabled={page <= 1 || loading}
                        onPress={() => setPage(page - 1)}
                    />
                </View>
                <Text style={styles.label}>
                    {page} / {data?.meta?.last_page || 1}
                </Text>
                <View style={{ flex: 1 }}>
                    <Button
                        secondary
                        label="Berikutnya"
                        disabled={
                            page >= (data?.meta?.last_page || 1) || loading
                        }
                        onPress={() => setPage(page + 1)}
                    />
                </View>
            </View>
            <Button
                secondary
                label="Perbarui tiket"
                busy={loading}
                onPress={() => void reload()}
            />
        </>
    );
}

type ChatPage = Page<Message> & {
    meta: { oldest_id: number | null; has_more: boolean };
};
export function OrderDetailScreen({
    id,
    onBack,
}: {
    id: string;
    onBack: () => void;
}) {
    const { data, error, loading, reload } = useRemote<{ data: Order }>(
        api.orders.show.url(id),
    );
    const [messages, setMessages] = useState<Message[]>([]);
    const [hasOlder, setHasOlder] = useState(false);
    const [body, setBody] = useState('');
    const [note, setNote] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [ratingTarget, setRatingTarget] = useState<
        | (Technician & {
              review: Order['reviewable_technicians'][number]['review'];
          })
        | null
    >(null);
    const order = data?.data;

    useEffect(() => {
        const controller = new AbortController();
        let pending = false;
        async function poll() {
            if (pending || AppState.currentState !== 'active') return;
            pending = true;
            try {
                const result = await request<ChatPage>(
                    api.messages.index.url(id),
                    { signal: controller.signal },
                );
                if (!controller.signal.aborted) {
                    setMessages((previous) =>
                        mergeMessages(previous, result.data),
                    );
                    setNotice('');
                }
            } catch (err) {
                if (!controller.signal.aborted) setNotice(errorMessage(err));
            } finally {
                pending = false;
            }
        }
        request<ChatPage>(api.messages.index.url(id), {
            signal: controller.signal,
        })
            .then((result) => {
                if (!controller.signal.aborted) {
                    setMessages(result.data);
                    setHasOlder(result.meta.has_more);
                }
            })
            .catch((err) => {
                if (!controller.signal.aborted) setNotice(errorMessage(err));
            });
        const timer = setInterval(() => void poll(), 8000);
        return () => {
            controller.abort();
            clearInterval(timer);
        };
    }, [id]);

    async function older() {
        if (!messages.length || busy) return;
        setBusy(true);
        try {
            const result = await request<ChatPage>(
                api.messages.index.url(id, {
                    query: { before_id: messages[0].id },
                }),
            );
            setMessages((previous) => mergeMessages(previous, result.data));
            setHasOlder(result.meta.has_more);
        } catch (err) {
            setNotice(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    async function send() {
        if (busy || !body.trim()) return;
        setBusy(true);
        setNotice('');
        try {
            const result = await request<{ data: Message }>(
                api.messages.store.url(id),
                { method: 'POST', body: { body } },
            );
            setMessages((previous) => mergeMessages(previous, [result.data]));
            setBody('');
        } catch (err) {
            setNotice(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    async function progress(status: string) {
        if (busy) return;
        setBusy(true);
        setNotice('');
        try {
            await request(api.status.update.url(id), {
                method: 'PATCH',
                body: { status, note },
            });
            setNote('');
            await reload();
        } catch (err) {
            setNotice(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Button secondary label="‹ Kembali ke tiket" onPress={onBack} />
            <Notice message={error} />
            {loading && <ActivityIndicator color={color.red} />}
            {order && (
                <>
                    <Hero
                        eyebrow={order.ticket_number}
                        title={order.service.name}
                        description={order.status_label}
                    />
                    <Panel>
                        <Text style={styles.heading}>Detail pekerjaan</Text>
                        <Text style={styles.body}>{order.complaint}</Text>
                        <Text style={styles.body}>
                            {order.customer_name} · {order.phone}
                        </Text>
                        <Text style={styles.body}>{order.address}</Text>
                        <Text style={styles.body}>
                            Jadwal: {dateLabel(order.scheduled_at)}
                        </Text>
                    </Panel>
                    <Panel>
                        <Text style={styles.heading}>
                            Teknisi yang menangani
                        </Text>
                        {!order.technician && (
                            <Text style={styles.body}>
                                Operator sedang menyiapkan tim. Chat aktif
                                setelah PIC ditugaskan.
                            </Text>
                        )}
                        {order.technician_team.map((technician) => (
                            <View key={technician.id} style={{ gap: 10 }}>
                                <View style={styles.row}>
                                    <Avatar
                                        name={technician.name}
                                        url={technician.avatar_url}
                                    />
                                    <View style={{ flex: 1 }}>
                                        <Text style={styles.heading}>
                                            {technician.name}
                                        </Text>
                                        <Text style={styles.body}>
                                            {technician.specialty ||
                                                'Tim teknisi AD Com'}
                                        </Text>
                                        <Text style={styles.body}>
                                            ★{' '}
                                            {technician.rating_average ??
                                                'Belum dinilai'}{' '}
                                            · {technician.reviews_count} ulasan
                                        </Text>
                                    </View>
                                </View>
                                {order.technician?.id === technician.id && (
                                    <Chip>PIC TIKET INI</Chip>
                                )}
                                {technician.phone && (
                                    <Button
                                        secondary
                                        label={`Hubungi ${technician.phone}`}
                                        onPress={() =>
                                            void Linking.openURL(
                                                `tel:${technician.phone}`,
                                            ).catch((err) =>
                                                setNotice(errorMessage(err)),
                                            )
                                        }
                                    />
                                )}
                            </View>
                        ))}
                    </Panel>
                    <Panel>
                        <Text style={styles.heading}>
                            Transparansi pembayaran
                        </Text>
                        <Text style={styles.body}>
                            Estimasi: {money(order.finance.estimated_total)}
                        </Text>
                        <Text style={styles.body}>
                            Terverifikasi:{' '}
                            {money(order.finance.verified_income)}
                        </Text>
                        <Text style={styles.body}>
                            Menunggu verifikasi:{' '}
                            {money(order.finance.pending_income)}
                        </Text>
                        <Text style={styles.body}>
                            Konfirmasi pembayaran dilakukan operator. Jangan
                            mengirim PIN, OTP, atau kata sandi lewat chat.
                        </Text>
                    </Panel>
                    <Panel>
                        <Text style={styles.heading}>Perjalanan tiket</Text>
                        {order.timeline.map((event) => (
                            <View
                                key={event.id}
                                style={[
                                    styles.row,
                                    { alignItems: 'flex-start' },
                                ]}
                            >
                                <View
                                    style={{
                                        width: 10,
                                        height: 10,
                                        borderRadius: 5,
                                        backgroundColor: color.red,
                                        marginTop: 5,
                                    }}
                                />
                                <View style={{ flex: 1 }}>
                                    <Text style={styles.label}>
                                        {event.title}
                                    </Text>
                                    <Text style={styles.body}>
                                        {event.description}
                                    </Text>
                                    <Text
                                        style={[styles.body, { fontSize: 11 }]}
                                    >
                                        {dateLabel(event.occurred_at)}
                                    </Text>
                                </View>
                            </View>
                        ))}
                        <Button
                            secondary
                            label="Perbarui progres"
                            onPress={() => void reload()}
                            busy={loading}
                        />
                    </Panel>
                    {order.allowed_statuses.length > 0 && (
                        <Panel>
                            <Text style={styles.heading}>
                                Perbarui pekerjaan
                            </Text>
                            <Field
                                label="Catatan progres (opsional)"
                                multiline
                                value={note}
                                onChangeText={setNote}
                                maxLength={1000}
                            />
                            {order.allowed_statuses.map((status) => (
                                <Button
                                    key={status.value}
                                    label={status.label}
                                    busy={busy}
                                    onPress={() => void progress(status.value)}
                                />
                            ))}
                        </Panel>
                    )}
                    <Panel>
                        <Text style={styles.heading}>Percakapan tiket</Text>
                        <Text style={styles.body}>
                            Pesan diperbarui setiap 8 detik saat aplikasi aktif.
                        </Text>
                        {hasOlder && (
                            <Button
                                label="Muat pesan sebelumnya"
                                secondary
                                busy={busy}
                                onPress={() => void older()}
                            />
                        )}
                        {messages.map((message) => (
                            <View
                                key={message.id}
                                style={{
                                    backgroundColor: message.is_mine
                                        ? color.rose
                                        : '#f5f2f1',
                                    borderRadius: 17,
                                    padding: 13,
                                    alignSelf: message.is_mine
                                        ? 'flex-end'
                                        : 'flex-start',
                                    maxWidth: '95%',
                                    gap: 4,
                                }}
                            >
                                <Text style={styles.label}>
                                    {message.is_mine
                                        ? 'Kamu'
                                        : message.sender?.name ||
                                          'Akun tidak aktif'}
                                </Text>
                                <Text
                                    selectable
                                    style={{
                                        color: color.ink,
                                        fontSize: 14,
                                        lineHeight: 21,
                                    }}
                                >
                                    {message.body}
                                </Text>
                                <Text
                                    style={{ color: color.muted, fontSize: 10 }}
                                >
                                    {dateLabel(message.created_at)}
                                </Text>
                            </View>
                        ))}
                        {!messages.length && (
                            <Text style={styles.body}>
                                Belum ada percakapan di tiket ini.
                            </Text>
                        )}
                        <Notice message={notice} />
                        {order.can_chat && (
                            <>
                                <Field
                                    label="Pesan untuk tim tiket"
                                    multiline
                                    value={body}
                                    onChangeText={setBody}
                                    maxLength={2000}
                                />
                                <Button
                                    label="Kirim pesan"
                                    busy={busy}
                                    disabled={!body.trim()}
                                    onPress={() => void send()}
                                />
                            </>
                        )}
                    </Panel>
                    {order.can_review && (
                        <Panel>
                            <Text style={styles.heading}>
                                Bagaimana hasilnya?
                            </Text>
                            <Text style={styles.body}>
                                Penilaianmu membantu supervisor mengevaluasi
                                kualitas layanan.
                            </Text>
                            {order.reviewable_technicians.map((technician) => (
                                <Button
                                    secondary
                                    key={technician.id}
                                    label={`${technician.review ? 'Edit penilaian' : 'Beri penilaian'} · ${technician.name}`}
                                    onPress={() => setRatingTarget(technician)}
                                />
                            ))}
                        </Panel>
                    )}
                    {ratingTarget && (
                        <ReviewForm
                            key={ratingTarget.id}
                            orderId={id}
                            technician={ratingTarget}
                            onSaved={() => {
                                setRatingTarget(null);
                                void reload();
                            }}
                            onCancel={() => setRatingTarget(null)}
                        />
                    )}
                </>
            )}
        </>
    );
}

function ReviewForm({
    orderId,
    technician,
    onSaved,
    onCancel,
}: {
    orderId: string;
    technician: Order['reviewable_technicians'][number];
    onSaved: () => void;
    onCancel: () => void;
}) {
    const [scores, setScores] = useState({
        rating: technician.review?.rating || 5,
        quality_rating: technician.review?.quality_rating || 5,
        communication_rating: technician.review?.communication_rating || 5,
        punctuality_rating: technician.review?.punctuality_rating || 5,
    });
    const [comment, setComment] = useState(technician.review?.comment || '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const labels = {
        rating: 'Kepuasan keseluruhan',
        quality_rating: 'Kualitas pekerjaan',
        communication_rating: 'Komunikasi',
        punctuality_rating: 'Ketepatan waktu',
    };
    async function save() {
        setBusy(true);
        setError('');
        try {
            await request(api.reviews.store.url(orderId), {
                method: 'POST',
                body: { ...scores, technician_id: technician.id, comment },
            });
            onSaved();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    return (
        <Panel>
            <Text style={styles.heading}>Nilai {technician.name}</Text>
            {(Object.keys(labels) as (keyof typeof scores)[]).map((key) => (
                <View key={key} style={{ gap: 8 }}>
                    <Text style={styles.label}>{labels[key]}</Text>
                    <View style={styles.row}>
                        {[1, 2, 3, 4, 5].map((value) => (
                            <Pressable
                                key={value}
                                accessibilityRole="radio"
                                accessibilityLabel={`${labels[key]} ${value} bintang`}
                                accessibilityState={{
                                    selected: scores[key] === value,
                                }}
                                onPress={() =>
                                    setScores((previous) => ({
                                        ...previous,
                                        [key]: value,
                                    }))
                                }
                                style={{ padding: 4 }}
                            >
                                <Text
                                    style={{
                                        fontSize: 30,
                                        color:
                                            value <= scores[key]
                                                ? color.red
                                                : color.line,
                                    }}
                                >
                                    ★
                                </Text>
                            </Pressable>
                        ))}
                    </View>
                </View>
            ))}
            <Field
                label="Ceritakan pengalamanmu (opsional)"
                multiline
                maxLength={1000}
                value={comment}
                onChangeText={setComment}
            />
            <Notice message={error} />
            <Button
                label="Simpan penilaian"
                busy={busy}
                onPress={() => void save()}
            />
            <Button
                label="Batal"
                secondary
                disabled={busy}
                onPress={onCancel}
            />
        </Panel>
    );
}
