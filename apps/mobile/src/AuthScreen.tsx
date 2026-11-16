import { useState } from 'react';
import { Linking, Text, View } from 'react-native';
import {
    API_URL,
    WEB_URL,
    IS_PREVIEW,
    changeServer,
    errorMessage,
    request,
    saveToken,
} from './api';
import { endpoints as api } from './endpoints';
import type { AuthResponse, User } from './types';
import { Brand, Button, Field, Hero, Notice, Panel, styles } from './ui';

export function AuthScreen({ onLogin }: { onLogin: (user: User) => void }) {
    const [mode, setMode] = useState<'login' | 'register' | 'forgot'>('login');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [name, setName] = useState('');
    const [challenge, setChallenge] = useState<string | null>(null);
    const [code, setCode] = useState('');
    const [recovery, setRecovery] = useState(false);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [server, setServer] = useState(API_URL);
    const [showServer, setShowServer] = useState(false);

    async function submit() {
        if (busy) return;
        setBusy(true);
        setNotice('');
        try {
            if (mode === 'forgot' && !challenge) {
                const result = await request<{ message: string }>(
                    api.auth.forgotPassword.url(),
                    { method: 'POST', body: { email: email.trim() } },
                );
                setNotice(result.message);
                return;
            }
            const route = challenge
                ? api.auth.twoFactor
                : mode === 'register'
                  ? api.auth.register
                  : api.auth.login;
            const body = challenge
                ? {
                      challenge_token: challenge,
                      ...(recovery
                          ? { recovery_code: code.trim() }
                          : { code: code.trim() }),
                  }
                : {
                      email: email.trim(),
                      password,
                      device_name: 'AD Com Android',
                      ...(mode === 'register'
                          ? { name, password_confirmation: confirmation }
                          : {}),
                  };
            const result = await request<AuthResponse>(route.url(), {
                method: 'POST',
                body,
            });
            if ('two_factor' in result) {
                setChallenge(result.challenge_token);
                setCode('');
                setPassword('');
                setConfirmation('');
            } else {
                await saveToken(result.token);
                setPassword('');
                setCode('');
                onLogin(result.user);
            }
        } catch (error) {
            setNotice(errorMessage(error));
        } finally {
            setBusy(false);
        }
    }

    function switchMode(next: typeof mode) {
        setMode(next);
        setNotice('');
        setPassword('');
        setConfirmation('');
        setChallenge(null);
        setCode('');
    }

    return (
        <>
            <Brand />
            <Hero
                eyebrow="Akun AD Com"
                title={
                    challenge
                        ? 'Verifikasi login'
                        : mode === 'register'
                          ? 'Buat akun'
                          : mode === 'forgot'
                            ? 'Lupa kata sandi?'
                            : 'Masuk ke AD Com'
                }
                description={
                    challenge
                        ? 'Masukkan kode dari aplikasi autentikator. Sesi ini berlaku 5 menit.'
                        : mode === 'register'
                          ? 'Daftar untuk mengajukan layanan dan menyimpan riwayat pesanan.'
                          : mode === 'forgot'
                            ? 'Masukkan email akunmu. Tautan dan kode verifikasi akan dikirim untuk pemulihan password.'
                            : 'Lihat pesanan, hubungi teknisi, dan kelola akunmu.'
                }
            />
            <Panel>
                <Notice message={notice} />
                {challenge ? (
                    <>
                        <Field
                            label={
                                recovery
                                    ? 'Kode pemulihan'
                                    : 'Kode OTP autentikator'
                            }
                            value={code}
                            onChangeText={setCode}
                            keyboardType={recovery ? 'default' : 'number-pad'}
                            autoCapitalize="none"
                            autoCorrect={false}
                            maxLength={recovery ? 255 : 6}
                        />
                        <Button
                            label={
                                recovery
                                    ? 'Pakai kode autentikator'
                                    : 'Pakai kode pemulihan'
                            }
                            secondary
                            onPress={() => {
                                setRecovery(!recovery);
                                setCode('');
                            }}
                        />
                    </>
                ) : (
                    <>
                        {mode === 'register' && (
                            <Field
                                label="Nama lengkap"
                                value={name}
                                onChangeText={setName}
                                autoComplete="name"
                            />
                        )}
                        <Field
                            label="Email"
                            value={email}
                            onChangeText={setEmail}
                            keyboardType="email-address"
                            autoCapitalize="none"
                            autoCorrect={false}
                            autoComplete="email"
                        />
                        {mode !== 'forgot' && (
                            <Field
                                label="Kata sandi"
                                value={password}
                                onChangeText={setPassword}
                                secureTextEntry
                                autoCapitalize="none"
                                autoComplete={
                                    mode === 'register'
                                        ? 'new-password'
                                        : 'current-password'
                                }
                            />
                        )}
                        {mode === 'register' && (
                            <Field
                                label="Ulangi kata sandi"
                                value={confirmation}
                                onChangeText={setConfirmation}
                                secureTextEntry
                                autoCapitalize="none"
                            />
                        )}
                    </>
                )}
                <Button
                    busy={busy}
                    label={
                        challenge
                            ? 'Verifikasi & masuk'
                            : mode === 'register'
                              ? 'Buat akun pelanggan'
                              : mode === 'forgot'
                                ? 'Kirim tautan pemulihan'
                                : 'Masuk ke AD Com'
                    }
                    onPress={() => void submit()}
                />
                {mode === 'login' && !challenge && (
                    <Button
                        secondary
                        label="Lupa kata sandi?"
                        onPress={() => switchMode('forgot')}
                    />
                )}
                <Button
                    secondary
                    label={
                        challenge || mode !== 'login'
                            ? 'Kembali ke login'
                            : 'Belum punya akun? Daftar'
                    }
                    onPress={() =>
                        switchMode(
                            challenge || mode !== 'login'
                                ? 'login'
                                : 'register',
                        )
                    }
                />
                <Text style={styles.body}>
                    Akun operator/admin/gudang tetap memakai dashboard web.
                    Teknisi perlu mengaktifkan OTP di pengaturan keamanan web
                    terlebih dahulu.
                </Text>
                <Button
                    secondary
                    label="Buka portal web"
                    onPress={() => {
                        void Linking.openURL(WEB_URL).catch((error) =>
                            setNotice(errorMessage(error)),
                        );
                    }}
                />
            </Panel>
            {IS_PREVIEW && (
                <Panel>
                    <Text style={styles.heading}>Versi uji coba</Text>
                    <Text style={styles.body}>
                        Gunakan akun uji. Login Google native belum diaktifkan
                        pada preview ini.
                    </Text>
                    <Button
                        secondary
                        label={
                            showServer
                                ? 'Tutup pengaturan server'
                                : 'Atur koneksi server'
                        }
                        onPress={() => setShowServer(!showServer)}
                    />
                    {showServer && (
                        <View style={{ gap: 12 }}>
                            <Field
                                label="Alamat server Laravel"
                                value={server}
                                onChangeText={setServer}
                                autoCapitalize="none"
                                autoCorrect={false}
                                keyboardType="url"
                            />
                            <Text style={styles.body}>
                                Emulator: http://10.0.2.2:8000. HP: alamat IP
                                komputer di Wi-Fi yang sama. Kredensial dikirim
                                ke server pilihanmu; jangan gunakan server yang
                                tidak dipercaya.
                            </Text>
                            <Button
                                label="Simpan koneksi"
                                onPress={() => {
                                    void changeServer(server)
                                        .then(() => {
                                            setChallenge(null);
                                            setNotice(
                                                'Koneksi disimpan. Silakan masuk.',
                                            );
                                        })
                                        .catch((error) =>
                                            setNotice(errorMessage(error)),
                                        );
                                }}
                            />
                        </View>
                    )}
                </Panel>
            )}
        </>
    );
}
