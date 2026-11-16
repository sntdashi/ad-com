import { useState, type PropsWithChildren } from 'react';
import {
    ActivityIndicator,
    Image,
    Pressable,
    StyleSheet,
    Text,
    TextInput,
    View,
    type TextInputProps,
} from 'react-native';
import { mediaUrl } from './api';
import { color } from './theme';

export { color } from './theme';
export function Button({
    label,
    onPress,
    busy = false,
    secondary = false,
    disabled = false,
}: {
    label: string;
    onPress: () => void;
    busy?: boolean;
    secondary?: boolean;
    disabled?: boolean;
}) {
    return (
        <Pressable
            accessibilityRole="button"
            accessibilityLabel={label}
            accessibilityState={{ disabled: busy || disabled, busy }}
            disabled={busy || disabled}
            onPress={onPress}
            style={({ pressed }) => [
                styles.button,
                secondary && styles.secondary,
                (pressed || busy || disabled) && { opacity: 0.6 },
            ]}
        >
            {busy ? (
                <ActivityIndicator color={secondary ? color.red : '#fff'} />
            ) : (
                <Text
                    style={[
                        styles.buttonText,
                        secondary && { color: color.deep },
                    ]}
                >
                    {label}
                </Text>
            )}
        </Pressable>
    );
}
export function Field({ label, ...props }: TextInputProps & { label: string }) {
    const [focused, setFocused] = useState(false);
    return (
        <View style={{ gap: 7 }}>
            <Text style={styles.label}>{label}</Text>
            <TextInput
                accessibilityLabel={label}
                placeholderTextColor={color.muted}
                {...props}
                onFocus={(event) => {
                    setFocused(true);
                    props.onFocus?.(event);
                }}
                onBlur={(event) => {
                    setFocused(false);
                    props.onBlur?.(event);
                }}
                style={[
                    styles.input,
                    focused && { borderColor: color.red },
                    props.multiline && {
                        minHeight: 95,
                        textAlignVertical: 'top',
                    },
                    props.style,
                ]}
            />
        </View>
    );
}
export function Panel({ children }: PropsWithChildren) {
    return <View style={styles.panel}>{children}</View>;
}
export function Notice({ message }: { message: string }) {
    return message ? (
        <View accessibilityLiveRegion="polite" style={styles.notice}>
            <Text style={styles.noticeText}>{message}</Text>
        </View>
    ) : null;
}
export function Chip({ children }: PropsWithChildren) {
    return (
        <View style={styles.chip}>
            <Text style={styles.chipText}>{children}</Text>
        </View>
    );
}
export function Avatar({
    name,
    url,
    size = 52,
}: {
    name: string;
    url?: string | null;
    size?: number;
}) {
    const uri = mediaUrl(url);
    return uri ? (
        <Image
            accessibilityLabel={`Foto ${name}`}
            source={{ uri }}
            style={{
                width: size,
                height: size,
                borderRadius: size / 2,
                backgroundColor: color.rose,
            }}
        />
    ) : (
        <View
            style={[
                styles.avatar,
                { width: size, height: size, borderRadius: size / 2 },
            ]}
        >
            <Text
                style={{
                    color: color.deep,
                    fontSize: size / 3,
                    fontWeight: '800',
                }}
            >
                {name.slice(0, 2).toUpperCase()}
            </Text>
        </View>
    );
}
export function Brand() {
    return (
        <View style={styles.brand}>
            <Image
                source={require('../assets/adcom-logo.png')}
                resizeMode="contain"
                style={{ width: 46, height: 34 }}
            />
            <View>
                <Text
                    style={{
                        color: color.ink,
                        fontWeight: '900',
                        fontSize: 19,
                        letterSpacing: -0.7,
                    }}
                >
                    AD COM
                </Text>
                <Text style={styles.micro}>TEFA TKJ</Text>
            </View>
        </View>
    );
}
export function Hero({
    eyebrow,
    title,
    description,
}: {
    eyebrow: string;
    title: string;
    description: string;
}) {
    return (
        <View style={styles.hero}>
            <Text style={styles.heroEyebrow}>{eyebrow}</Text>
            <Text accessibilityRole="header" style={styles.heroTitle}>
                {title}
            </Text>
            <Text style={styles.heroDescription}>{description}</Text>
        </View>
    );
}
export const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: color.cream },
    content: { padding: 20, paddingBottom: 32, gap: 18 },
    brand: { flexDirection: 'row', alignItems: 'center', gap: 10 },
    micro: {
        color: color.muted,
        fontSize: 10,
        letterSpacing: 0.8,
        marginTop: 1,
        fontWeight: '700',
    },
    hero: { paddingVertical: 12, gap: 9 },
    heroEyebrow: {
        color: color.deep,
        fontSize: 12,
        letterSpacing: 0.3,
        fontWeight: '600',
    },
    heroTitle: {
        color: color.ink,
        fontWeight: '700',
        fontSize: 29,
        lineHeight: 35,
        letterSpacing: -0.6,
    },
    heroDescription: { color: color.muted, fontSize: 15, lineHeight: 23 },
    title: {
        color: color.ink,
        fontSize: 24,
        fontWeight: '700',
        letterSpacing: -0.4,
    },
    heading: { color: color.ink, fontSize: 17, fontWeight: '700' },
    body: { color: color.muted, fontSize: 14, lineHeight: 21 },
    panel: {
        backgroundColor: color.surface,
        borderColor: color.line,
        borderWidth: 1,
        borderRadius: 16,
        padding: 18,
        gap: 13,
    },
    label: { color: color.ink, fontSize: 14, fontWeight: '600' },
    input: {
        color: color.ink,
        backgroundColor: color.surface,
        borderColor: '#b6b5b2',
        borderWidth: 1,
        borderRadius: 10,
        paddingHorizontal: 15,
        paddingVertical: 13,
        fontSize: 15,
        minHeight: 49,
    },
    button: {
        minHeight: 50,
        borderRadius: 12,
        paddingHorizontal: 19,
        paddingVertical: 14,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: color.red,
        borderWidth: 1,
        borderColor: color.deep,
    },
    buttonText: { color: '#fff', fontWeight: '600', fontSize: 15 },
    secondary: {
        backgroundColor: 'rgba(255,255,255,0.85)',
        borderColor: color.line,
    },
    notice: { padding: 14, borderRadius: 10, backgroundColor: color.rose },
    noticeText: { fontSize: 13, color: color.deep, lineHeight: 19 },
    chip: {
        paddingHorizontal: 10,
        paddingVertical: 6,
        borderRadius: 6,
        backgroundColor: color.cream,
        alignSelf: 'flex-start',
    },
    chipText: { fontSize: 12, color: color.ink, fontWeight: '600' },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
    between: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: 12,
    },
    avatar: {
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: color.rose,
    },
    divider: { height: 1, backgroundColor: color.line },
    link: { color: color.deep, fontWeight: '700', paddingVertical: 10 },
});
