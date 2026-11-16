import { useState } from 'react';
import { Alert, Linking, Switch, Text, View } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { WEB_URL, errorMessage, request } from './api';
import { endpoints as api } from './endpoints';
import { edit as security } from './generated/routes/security';
import type { User } from './types';
import {
    Avatar,
    Button,
    Chip,
    Field,
    Notice,
    Panel,
    color,
    styles,
} from './ui';

export function ProfileScreen({
    user,
    onUpdated,
    onLogout,
}: {
    user: User;
    onUpdated: (user: User) => void;
    onLogout: () => Promise<void>;
}) {
    const [form, setForm] = useState({
        name: user.name,
        email: user.email,
        username: user.username || '',
        phone: user.phone || '',
        address: user.address || '',
        bio: user.bio || '',
        specialty: user.specialty || '',
    });
    const [consent, setConsent] = useState(user.show_phone_publicly);
    const [photo, setPhoto] = useState<ImagePicker.ImagePickerAsset | null>(
        null,
    );
    const [removePhoto, setRemovePhoto] = useState(false);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [deleting, setDeleting] = useState(false);
    const [password, setPassword] = useState('');
    const field = (key: keyof typeof form, value: string) =>
        setForm((previous) => ({ ...previous, [key]: value }));
    async function pick() {
        try {
            const result = await ImagePicker.launchImageLibraryAsync({
                mediaTypes: ['images'],
                allowsEditing: true,
                aspect: [1, 1],
                quality: 0.7,
            });
            if (!result.canceled) {
                const image = result.assets[0];
                if ((image.fileSize || 0) > 2 * 1024 * 1024)
                    throw new Error('Pilih foto maksimal 2 MB.');
                setPhoto(image);
                setRemovePhoto(false);
            }
        } catch (err) {
            setNotice(errorMessage(err));
        }
    }
    async function save() {
        if (busy) return;
        setBusy(true);
        setNotice('');
        try {
            const body = new FormData();
            body.append('_method', 'PATCH');
            for (const [key, value] of Object.entries(form))
                if (key !== 'specialty' || user.role === 'technician')
                    body.append(key, value);
            body.append('show_phone_publicly', consent ? '1' : '0');
            body.append('remove_avatar', removePhoto ? '1' : '0');
            if (photo)
                body.append('avatar', {
                    uri: photo.uri,
                    name: photo.fileName || 'avatar.jpg',
                    type: photo.mimeType || 'image/jpeg',
                } as unknown as Blob);
            const result = await request<{ data: User }>(
                api.profile.update.url(),
                { method: 'POST', body },
            );
            if (result.data.email !== user.email) {
                Alert.alert(
                    'Email diperbarui',
                    'Silakan masuk kembali, lalu verifikasi alamat email yang baru. Sesi lama sudah dicabut.',
                );
                await onLogout();
                return;
            }
            onUpdated(result.data);
            setPhoto(null);
            setRemovePhoto(false);
            setNotice('Profil berhasil disimpan.');
        } catch (err) {
            setNotice(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    async function removeAccount() {
        setBusy(true);
        setNotice('');
        try {
            await request(api.profile.destroy.url(), {
                method: 'DELETE',
                body: { password },
            });
            setPassword('');
            await onLogout();
        } catch (err) {
            setNotice(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <Panel>
                <View style={styles.row}>
                    <Avatar
                        name={form.name}
                        url={removePhoto ? null : photo?.uri || user.avatar_url}
                        size={80}
                    />
                    <View style={{ flex: 1 }}>
                        <Text style={styles.title}>{user.name}</Text>
                        <Chip>
                            {user.role === 'technician'
                                ? 'TIM TEKNISI'
                                : 'PELANGGAN'}
                        </Chip>
                    </View>
                </View>
                <Button
                    secondary
                    label="Ganti foto profil"
                    onPress={() => void pick()}
                />
                <Button
                    secondary
                    label="Hapus foto unggahan"
                    onPress={() => {
                        setPhoto(null);
                        setRemovePhoto(true);
                    }}
                />
            </Panel>
            <Panel>
                <Text style={styles.heading}>Profilmu, identitasmu.</Text>
                <Field
                    label="Nama lengkap"
                    value={form.name}
                    onChangeText={(value) => field('name', value)}
                />
                <Field
                    label="Username"
                    value={form.username}
                    onChangeText={(value) => field('username', value)}
                    autoCapitalize="none"
                    autoCorrect={false}
                />
                <Field
                    label="Email"
                    value={form.email}
                    onChangeText={(value) => field('email', value)}
                    keyboardType="email-address"
                    autoCapitalize="none"
                    autoCorrect={false}
                />
                <Field
                    label="Nomor HP"
                    value={form.phone}
                    onChangeText={(value) => field('phone', value)}
                    keyboardType="phone-pad"
                />
                <Field
                    label="Alamat"
                    value={form.address}
                    onChangeText={(value) => field('address', value)}
                    multiline
                    maxLength={1000}
                />
                <Field
                    label="Bio"
                    value={form.bio}
                    onChangeText={(value) => field('bio', value)}
                    multiline
                    maxLength={500}
                />
                {user.role === 'technician' && (
                    <>
                        <Field
                            label="Keahlian teknis"
                            value={form.specialty}
                            onChangeText={(value) => field('specialty', value)}
                            maxLength={160}
                        />
                        <View style={styles.row}>
                            <Text style={[styles.body, { flex: 1 }]}>
                                Izinkan pelanggan tiket melihat nomor HP saya.
                            </Text>
                            <Switch
                                accessibilityLabel="Tampilkan nomor HP ke pelanggan tiket"
                                value={consent}
                                onValueChange={setConsent}
                                trackColor={{ true: color.red }}
                            />
                        </View>
                    </>
                )}
                <Notice message={notice} />
                <Button
                    label="Simpan profil"
                    busy={busy}
                    onPress={() => void save()}
                />
            </Panel>
            <Panel>
                <Text style={styles.heading}>Keamanan akun</Text>
                <Text style={styles.body}>
                    OTP autentikator:{' '}
                    {user.two_factor_enabled ? 'aktif' : 'belum aktif'}.
                    Penggantian kata sandi dan pengaturan keamanan lanjutan
                    tersedia di portal web.
                </Text>
                <Button
                    secondary
                    label="Buka pengaturan keamanan"
                    onPress={() =>
                        void Linking.openURL(
                            `${WEB_URL}${security.url()}`,
                        ).catch((err) => setNotice(errorMessage(err)))
                    }
                />
                <Button
                    label="Keluar dari perangkat ini"
                    secondary
                    onPress={() =>
                        void onLogout().catch((err) =>
                            setNotice(errorMessage(err)),
                        )
                    }
                />
            </Panel>
            {user.role === 'customer' && (
                <Panel>
                    <Text style={styles.heading}>Kontrol akun</Text>
                    <Text style={styles.body}>
                        Menghapus akun menghentikan akses dan mencabut semua
                        sesi. Riwayat transaksi yang wajib disimpan dapat tetap
                        berada pada arsip layanan.
                    </Text>
                    <Button
                        secondary
                        label={
                            deleting
                                ? 'Batalkan penghapusan'
                                : 'Hapus akun saya'
                        }
                        onPress={() => setDeleting(!deleting)}
                    />
                    {deleting && (
                        <>
                            <Field
                                label="Kata sandi untuk konfirmasi"
                                secureTextEntry
                                value={password}
                                onChangeText={setPassword}
                            />
                            <Button
                                label="Konfirmasi hapus akun"
                                busy={busy}
                                disabled={!password}
                                onPress={() =>
                                    Alert.alert(
                                        'Hapus akun?',
                                        'Akun ini tidak dapat dipulihkan melalui aplikasi.',
                                        [
                                            { text: 'Batal', style: 'cancel' },
                                            {
                                                text: 'Hapus akun',
                                                style: 'destructive',
                                                onPress: () =>
                                                    void removeAccount(),
                                            },
                                        ],
                                    )
                                }
                            />
                        </>
                    )}
                </Panel>
            )}
        </>
    );
}
