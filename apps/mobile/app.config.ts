import type { ExpoConfig } from 'expo/config';

const production =
    process.env.EAS_BUILD_PROFILE === 'production' ||
    process.env.EXPO_PUBLIC_APP_VARIANT === 'production';
const packageName = process.env.EXPO_ANDROID_PACKAGE;
const apiUrl = process.env.EXPO_PUBLIC_API_URL;

if (production && (!packageName || packageName === 'local.dev.adcom')) {
    throw new Error(
        'Set EXPO_ANDROID_PACKAGE to the permanent Play Console application ID.',
    );
}
if (
    production &&
    (!apiUrl?.startsWith('https://') ||
        /localhost|127\.0\.0\.1|10\.0\.2\.2/.test(apiUrl))
) {
    throw new Error(
        'Production requires an EXPO_PUBLIC_API_URL on your public HTTPS domain.',
    );
}

const config: ExpoConfig = {
    name: production ? 'AD Com' : 'AD Com Preview',
    slug: 'adcom-mobile',
    version: '0.1.0',
    orientation: 'portrait',
    userInterfaceStyle: 'light',
    icon: './assets/adcom-logo.png',
    android: {
        package: packageName || 'local.dev.adcom',
        versionCode: 1,
        blockedPermissions: [
            'android.permission.RECORD_AUDIO',
            'android.permission.CAMERA',
            'android.permission.READ_MEDIA_VIDEO',
        ],
        adaptiveIcon: {
            foregroundImage: './assets/adcom-logo.png',
            backgroundColor: '#fff7f4',
        },
    },
    plugins: [
        'expo-secure-store',
        [
            'expo-image-picker',
            {
                photosPermission: 'Pilih foto untuk profil AD Com.',
                cameraPermission: false,
                microphonePermission: false,
            },
        ],
        './plugins/with-local-network.cjs',
    ],
    extra: { production },
};

export default config;
