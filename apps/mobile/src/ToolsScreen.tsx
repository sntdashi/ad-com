import { useState } from 'react';
import { ActivityIndicator, Text, View } from 'react-native';
import { endpoints as api } from './endpoints';
import { useRemote } from './useRemote';
import { dateLabel } from './api';
import type { Page } from './types';
import { Button, Chip, Hero, Notice, Panel, color, styles } from './ui';

type Performance = {
    rating_average: number | null;
    quality_average: number | null;
    communication_average: number | null;
    punctuality_average: number | null;
    reviews_count: number;
    completed_orders_count: number;
    low_rating_reviews_count: number;
    recent_reviews: {
        id: number;
        rating: number;
        comment: string | null;
        ticket_number: string;
        created_at: string;
    }[];
};
type Inventory = {
    id: number;
    sku: string;
    name: string;
    unit: string;
    quantity_on_hand: number;
    reorder_level: number;
    storage_location: string | null;
    is_low_stock: boolean;
};
export function ToolsScreen() {
    const [page, setPage] = useState(1);
    const performance = useRemote<{ data: Performance }>(api.performance.url());
    const inventory = useRemote<Page<Inventory>>(
        api.inventory.index.url({ query: { page } }),
    );
    const summary = performance.data?.data;
    return (
        <>
            <Hero
                eyebrow="CATATAN KUALITAS"
                title="Penilaian pekerjaan"
                description="Penilaian pelanggan adalah bahan evaluasi bersama supervisor, bukan hukuman otomatis."
            />
            <Notice message={performance.error} />
            {performance.loading && <ActivityIndicator color={color.red} />}
            {summary && (
                <>
                    <Panel>
                        <Text style={[styles.title, { fontSize: 42 }]}>
                            ★ {summary.rating_average ?? '—'}
                        </Text>
                        <Text style={styles.body}>
                            {summary.reviews_count} ulasan ·{' '}
                            {summary.completed_orders_count} pekerjaan selesai
                        </Text>
                        <View style={styles.divider} />
                        <Text style={styles.body}>
                            Kualitas: {summary.quality_average ?? '—'} / 5
                        </Text>
                        <Text style={styles.body}>
                            Komunikasi: {summary.communication_average ?? '—'} /
                            5
                        </Text>
                        <Text style={styles.body}>
                            Ketepatan waktu:{' '}
                            {summary.punctuality_average ?? '—'} / 5
                        </Text>
                        {summary.low_rating_reviews_count > 0 && (
                            <Notice
                                message={`${summary.low_rating_reviews_count} ulasan perlu perhatian. Diskusikan perbaikan bersama supervisor.`}
                            />
                        )}
                    </Panel>
                    {summary.recent_reviews.map((review) => (
                        <Panel key={review.id}>
                            <View style={styles.between}>
                                <Text style={styles.label}>
                                    {review.ticket_number}
                                </Text>
                                <Chip>★ {review.rating} / 5</Chip>
                            </View>
                            <Text style={styles.body}>
                                {review.comment ||
                                    'Pelanggan tidak menambahkan komentar.'}
                            </Text>
                            <Text style={styles.body}>
                                {dateLabel(review.created_at)}
                            </Text>
                        </Panel>
                    ))}
                </>
            )}
            <Button
                secondary
                label="Perbarui performa"
                busy={performance.loading}
                onPress={() => void performance.reload()}
            />
            <Text style={styles.title}>Ketersediaan gudang</Text>
            <Text style={styles.body}>
                Informasi stok baca-saja. Pengeluaran barang tetap melalui
                petugas gudang.
            </Text>
            <Notice message={inventory.error} />
            {inventory.data?.data.map((item) => (
                <Panel key={item.id}>
                    <View style={styles.between}>
                        <Text style={[styles.heading, { flex: 1 }]}>
                            {item.name}
                        </Text>
                        <Chip>
                            {item.quantity_on_hand} {item.unit}
                        </Chip>
                    </View>
                    <Text style={styles.body}>
                        {item.sku} ·{' '}
                        {item.storage_location || 'Lokasi belum ditentukan'}
                    </Text>
                    {item.is_low_stock && (
                        <Notice
                            message={`Stok menipis · batas minimum ${item.reorder_level} ${item.unit}`}
                        />
                    )}
                </Panel>
            ))}
            {inventory.data?.data.length === 0 && (
                <Notice message="Belum ada barang aktif di gudang." />
            )}
            <View style={styles.row}>
                <View style={{ flex: 1 }}>
                    <Button
                        secondary
                        label="Sebelumnya"
                        disabled={page <= 1 || inventory.loading}
                        onPress={() => setPage(page - 1)}
                    />
                </View>
                <Text style={styles.label}>{page}</Text>
                <View style={{ flex: 1 }}>
                    <Button
                        secondary
                        label="Berikutnya"
                        disabled={
                            page >= (inventory.data?.meta?.last_page || 1) ||
                            inventory.loading
                        }
                        onPress={() => setPage(page + 1)}
                    />
                </View>
            </View>
            <Button
                secondary
                label="Perbarui stok"
                busy={inventory.loading}
                onPress={() => void inventory.reload()}
            />
        </>
    );
}
