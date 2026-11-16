import { useEffect, useRef, useState } from 'react';
import {
    ActivityIndicator,
    BackHandler,
    KeyboardAvoidingView,
    Platform,
    Pressable,
    ScrollView,
    Text,
    View,
} from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { AuthScreen } from './src/AuthScreen';
import {
    BookingScreen,
    OrderDetailScreen,
    OrdersScreen,
    ServicesScreen,
} from './src/OrdersScreen';
import { ProfileScreen } from './src/ProfileScreen';
import { ToolsScreen } from './src/ToolsScreen';
import { AssistantScreen } from './src/AssistantScreen';
import {
    errorMessage,
    request,
    restoreToken,
    saveToken,
    setUnauthorizedHandler,
} from './src/api';
import { endpoints as api } from './src/endpoints';
import type { Service, User } from './src/types';
import { Brand, Button, Hero, Notice, Panel, color, styles } from './src/ui';

type Tab = 'services' | 'orders' | 'assistant' | 'tools' | 'profile';
export default function App() {
    return (
        <SafeAreaProvider>
            <Application />
        </SafeAreaProvider>
    );
}
function Application() {
    const [user, setUser] = useState<User | null>(null);
    const [loading, setLoading] = useState(true);
    const [bootError, setBootError] = useState('');
    const [tab, setTab] = useState<Tab>('services');
    const [service, setService] = useState<Service | null>(null);
    const [orderId, setOrderId] = useState<string | null>(null);
    const [verificationNotice, setVerificationNotice] = useState('');
    const [checking, setChecking] = useState(false);
    const scroll = useRef<ScrollView>(null);
    function resetSession() {
        setUser(null);
        setService(null);
        setOrderId(null);
        setTab('services');
    }
    async function boot() {
        setLoading(true);
        setBootError('');
        try {
            if (await restoreToken())
                setUser((await request<{ data: User }>(api.me.url())).data);
        } catch (err) {
            setBootError(errorMessage(err));
        } finally {
            setLoading(false);
        }
    }
    useEffect(() => {
        setUnauthorizedHandler(resetSession);
        void boot();
    }, []);
    useEffect(() => {
        scroll.current?.scrollTo({ y: 0, animated: false });
    }, [tab, service, orderId, user?.id]);
    useEffect(() => {
        const subscription = BackHandler.addEventListener(
            'hardwareBackPress',
            () => {
                if (orderId) {
                    setOrderId(null);
                    return true;
                }
                if (service) {
                    setService(null);
                    return true;
                }
                if (tab !== 'orders' && user?.role === 'technician') {
                    setTab('orders');
                    return true;
                }
                if (tab !== 'services' && user?.role === 'customer') {
                    setTab('services');
                    return true;
                }
                return false;
            },
        );
        return () => subscription.remove();
    }, [orderId, service, tab, user]);
    async function logout() {
        try {
            await request(api.auth.logout.url(), { method: 'POST' });
        } catch {
            /* Local sign-out must also work when the server is unreachable. */
        } finally {
            await saveToken(null);
            resetSession();
            setBootError('');
        }
    }
    function login(next: User) {
        setUser(next);
        setTab(next.role === 'technician' ? 'orders' : 'services');
        setBootError('');
        setVerificationNotice('');
    }
    async function verify(refresh: boolean) {
        setChecking(true);
        setVerificationNotice('');
        try {
            if (refresh)
                setUser((await request<{ data: User }>(api.me.url())).data);
            else
                setVerificationNotice(
                    (
                        await request<{ message: string }>(
                            api.verification.send.url(),
                            { method: 'POST' },
                        )
                    ).message,
                );
        } catch (err) {
            setVerificationNotice(errorMessage(err));
        } finally {
            setChecking(false);
        }
    }
    const tabs: { id: Tab; label: string }[] = [
        ...(user?.role === 'customer'
            ? [{ id: 'services' as const, label: 'Layanan' }]
            : []),
        { id: 'orders', label: 'Tiket' },
        { id: 'assistant', label: 'Bantuan' },
        ...(user?.role === 'technician'
            ? [{ id: 'tools' as const, label: 'Performa' }]
            : []),
        { id: 'profile', label: 'Profil' },
    ];
    return (
        <SafeAreaView style={styles.screen} edges={['top', 'bottom']}>
            <StatusBar style="dark" />
            <KeyboardAvoidingView
                style={{ flex: 1 }}
                behavior={Platform.OS === 'ios' ? 'padding' : undefined}
            >
                {user && (
                    <View
                        style={[
                            styles.between,
                            {
                                paddingHorizontal: 20,
                                paddingVertical: 14,
                                backgroundColor: color.cream,
                                borderBottomWidth: 1,
                                borderBottomColor: color.line,
                            },
                        ]}
                    >
                        <Brand />
                        <Text style={styles.label}>
                            {user.role === 'technician'
                                ? 'Teknisi'
                                : 'Pelanggan'}
                        </Text>
                    </View>
                )}
                <ScrollView
                    ref={scroll}
                    contentContainerStyle={styles.content}
                    keyboardShouldPersistTaps="handled"
                >
                    {loading ? (
                        <View style={{ paddingVertical: 100, gap: 20 }}>
                            <Brand />
                            <ActivityIndicator color={color.red} />
                            <Text style={styles.body}>Memuat akun…</Text>
                        </View>
                    ) : (
                        <>
                            {bootError && (
                                <Panel>
                                    <Notice message={bootError} />
                                    <Button
                                        secondary
                                        label="Coba hubungkan kembali"
                                        onPress={() => void boot()}
                                    />
                                </Panel>
                            )}
                            {!user ? (
                                <AuthScreen onLogin={login} />
                            ) : !user.email_verified_at && tab !== 'profile' ? (
                                <>
                                    <Hero
                                        eyebrow="SATU LANGKAH LAGI"
                                        title="Verifikasi emailmu."
                                        description={`Kami perlu memastikan alamat ${user.email} milikmu sebelum membuka layanan.`}
                                    />
                                    <Panel>
                                        <Notice message={verificationNotice} />
                                        <Button
                                            label="Kirim ulang email verifikasi"
                                            busy={checking}
                                            onPress={() => void verify(false)}
                                        />
                                        <Button
                                            secondary
                                            label="Sudah verifikasi, periksa lagi"
                                            busy={checking}
                                            onPress={() => void verify(true)}
                                        />
                                        <Button
                                            secondary
                                            label="Keluar"
                                            onPress={() => void logout()}
                                        />
                                    </Panel>
                                </>
                            ) : tab === 'profile' ? (
                                <ProfileScreen
                                    key={user.id}
                                    user={user}
                                    onUpdated={setUser}
                                    onLogout={logout}
                                />
                            ) : orderId ? (
                                <OrderDetailScreen
                                    key={orderId}
                                    id={orderId}
                                    onBack={() => setOrderId(null)}
                                />
                            ) : service ? (
                                <BookingScreen
                                    service={service}
                                    user={user}
                                    onBack={() => setService(null)}
                                    onCreated={(order) => {
                                        setService(null);
                                        setTab('orders');
                                        setOrderId(order.id);
                                    }}
                                />
                            ) : tab === 'orders' ||
                              (tab === 'services' &&
                                  user.role === 'technician') ? (
                                <OrdersScreen
                                    user={user}
                                    onSelect={(order) => setOrderId(order.id)}
                                />
                            ) : tab === 'assistant' ? (
                                <AssistantScreen />
                            ) : tab === 'tools' &&
                              user.role === 'technician' ? (
                                <ToolsScreen />
                            ) : (
                                <ServicesScreen
                                    user={user}
                                    onSelect={setService}
                                />
                            )}
                        </>
                    )}
                </ScrollView>
                {user && (
                    <View
                        accessibilityRole="tablist"
                        style={{
                            flexDirection: 'row',
                            backgroundColor: 'rgba(255,255,255,0.97)',
                            borderTopWidth: 1,
                            borderTopColor: color.line,
                            padding: 8,
                        }}
                    >
                        {tabs.map((item) => (
                            <Pressable
                                key={item.id}
                                accessibilityRole="tab"
                                accessibilityState={{
                                    selected: tab === item.id,
                                }}
                                accessibilityLabel={item.label}
                                onPress={() => {
                                    setTab(item.id);
                                    setOrderId(null);
                                    setService(null);
                                }}
                                style={{
                                    flex: 1,
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    minHeight: 48,
                                    paddingVertical: 12,
                                    paddingHorizontal: 4,
                                    borderRadius: 10,
                                    borderTopWidth: 2,
                                    borderTopColor:
                                        tab === item.id
                                            ? color.red
                                            : 'transparent',
                                    backgroundColor:
                                        tab === item.id
                                            ? color.rose
                                            : 'transparent',
                                }}
                            >
                                <Text
                                    style={{
                                        fontSize: 13,
                                        fontWeight:
                                            tab === item.id ? '700' : '500',
                                        color:
                                            tab === item.id
                                                ? color.deep
                                                : color.muted,
                                    }}
                                >
                                    {item.label}
                                </Text>
                            </Pressable>
                        ))}
                    </View>
                )}
            </KeyboardAvoidingView>
        </SafeAreaView>
    );
}
