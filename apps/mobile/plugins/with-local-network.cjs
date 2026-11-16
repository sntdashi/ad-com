const { withAndroidManifest } = require('@expo/config-plugins');

module.exports = (config) =>
    withAndroidManifest(config, (result) => {
        const application = result.modResults.manifest.application?.[0];
        if (application)
            application.$['android:usesCleartextTraffic'] =
                process.env.EAS_BUILD_PROFILE === 'production' ||
                process.env.EXPO_PUBLIC_APP_VARIANT === 'production'
                    ? 'false'
                    : 'true';
        return result;
    });
