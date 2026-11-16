import { test } from 'node:test';
import assert from 'node:assert/strict';
import { color } from './theme.ts';

function luminance(hex) {
    const channels = hex
        .slice(1)
        .match(/.{2}/g)
        .map((channel) => {
            const value = parseInt(channel, 16) / 255;
            return value <= 0.04045
                ? value / 12.92
                : ((value + 0.055) / 1.055) ** 2.4;
        });
    return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
}

test('normal text and primary button labels meet a 4.5:1 contrast floor', () => {
    for (const [foreground, background] of [
        [color.ink, color.cream],
        [color.muted, color.cream],
        [color.muted, color.surface],
        [color.deep, color.rose],
        [color.surface, color.red],
    ]) {
        const light = Math.max(luminance(foreground), luminance(background));
        const dark = Math.min(luminance(foreground), luminance(background));
        assert.ok(
            (light + 0.05) / (dark + 0.05) >= 4.5,
            `${foreground} on ${background}`,
        );
    }
});
