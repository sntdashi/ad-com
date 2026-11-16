/**
 * AD Com Source-Driven Liquid Glass Optical Engine
 *
 * Adapted from `gentpan/liquidglass` (https://liquidglass.dev)
 * MIT License — Copyright (c) 2026 gentpan
 *
 * Renders physical Snell's-law refraction, curved bezel edge lensing,
 * specular rim highlights, and chromatic dispersion using SVG `feDisplacementMap`.
 *
 * Supports two rendering paths:
 * 1. `mode: 'content'` (and Surface `'copy'`): bends the element's own pixels or
 *    an aligned backdrop copy via `filter: url(#...)`. Works identically in
 *    Chromium, WebKit (Safari / iOS), and Gecko (Firefox).
 * 2. `mode: 'backdrop'`: bends live DOM behind the element via `backdrop-filter: url(#...)`
 *    in Chromium, with an explicit CSS `blur(16px) saturate(1.6)` fallback on other engines.
 */

const NS = 'http://www.w3.org/2000/svg';
const XLINK = 'http://www.w3.org/1999/xlink';
const NEUTRAL = 128;
const MAP_CACHE_SIZE = 48;

export type BrowserEngine = 'blink' | 'webkit' | 'gecko' | 'unknown';
export type GlassRenderMode = 'content' | 'backdrop';
export type SurfaceMode = 'copy' | 'backdrop' | 'fallback';

export type GlassOptics = {
    x?: number;
    y?: number;
    width?: number;
    height?: number;
    radius?: number;
    refraction?: number;
    bezel?: number;
    curvature?: number;
    ior?: number;
    invert?: boolean;
    chroma?: number;
    blur?: number;
    specular?: number;
    specularWidth?: number;
    lightAngle?: number;
    mapScale?: number;
};

export type GlassControllerOptions = GlassOptics & {
    mode?: GlassRenderMode;
    fit?: boolean;
    clip?: boolean;
    fallback?: string;
    sync?: boolean;
    hidden?: boolean;
};

export type GlassController = {
    update: (next?: GlassControllerOptions) => void;
    flush: () => void;
    readonly params: Required<GlassOptics>;
    readonly filterId: string;
    readonly effectiveMode: 'content' | 'backdrop-url' | 'blur-fallback';
    destroy: () => void;
};

export const DEFAULTS: Readonly<Required<GlassOptics>> = Object.freeze({
    x: 0,
    y: 0,
    width: 120,
    height: 64,
    radius: 32,
    refraction: 18,
    bezel: 0.6,
    curvature: 4,
    ior: 1.5,
    invert: false,
    chroma: 0.12,
    blur: 0,
    specular: 0.45,
    specularWidth: 2,
    lightAngle: -120,
    mapScale: 0,
});

const MAP_KEYS: Array<keyof GlassOptics> = [
    'width',
    'height',
    'radius',
    'bezel',
    'curvature',
    'ior',
    'invert',
    'specular',
    'specularWidth',
    'lightAngle',
];

function detectEngine(): BrowserEngine {
    if (typeof navigator === 'undefined') {
        return 'unknown';
    }
    const navWithUAData = navigator as Navigator & {
        userAgentData?: { brands?: Array<{ brand: string }> };
    };
    const brands = navWithUAData.userAgentData?.brands;
    if (brands?.some((b) => /Chromium/.test(b.brand))) {
        return 'blink';
    }
    const ua = navigator.userAgent;
    if (/Firefox\//.test(ua)) {
        return 'gecko';
    }
    if (/AppleWebKit\//.test(ua) && !/Chrome\/|Chromium\/|Edg\//.test(ua)) {
        return 'webkit';
    }
    return /Chrome\//.test(ua) ? 'blink' : 'unknown';
}

export const engine: BrowserEngine = detectEngine();

export const reducedMotion: { matches: boolean } =
    typeof window !== 'undefined' && typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : { matches: false };

export const reducedTransparency: { matches: boolean } =
    typeof window !== 'undefined' && typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-reduced-transparency: reduce)')
        : { matches: false };

/* ------------------------------------------------------------------------------------------------ */
/* Displacement Map (Snell's Law + 4-way quadrant symmetry)                                         */
/* ------------------------------------------------------------------------------------------------ */

const profileCache = new Map<string, Float32Array>();

function profileTable(curvature: number, ior: number): Float32Array {
    const key = `${curvature}|${ior}`;
    let table = profileCache.get(key);
    if (table) {
        return table;
    }

    const N = 512;
    table = new Float32Array(N + 1);
    let max = 0;
    for (let i = 0; i <= N; i++) {
        const s = Math.min(i / N, 1 - 1e-6);
        const a = Math.pow(1 - s, curvature);
        const slope =
            Math.pow(1 - s, curvature - 1) *
            Math.pow(Math.max(1 - a, 1e-9), 1 / curvature - 1);
        const theta = Math.atan(slope);
        const refracted = Math.asin(Math.sin(theta) / ior);
        const bend = Math.tan(theta - refracted);
        table[i] = bend;
        if (bend > max) {
            max = bend;
        }
    }
    for (let i = 0; i <= N; i++) {
        table[i] /= max || 1;
    }
    table[N] = 0;
    profileCache.set(key, table);
    return table;
}

function rimLight(rim: number, facing: number): number {
    const shade = 0.12 + 0.88 * (facing > 0 ? facing : -0.4 * facing);
    return NEUTRAL + Math.round(127 * Math.min(1, rim * shade));
}

function writePixel(
    px: Uint8ClampedArray,
    o: number,
    r: number,
    g: number,
    b: number,
    a: number,
) {
    px[o] = r;
    px[o + 1] = g;
    px[o + 2] = b;
    px[o + 3] = a;
}

export function renderLensMap(
    options: GlassOptics = {},
    scale = 1,
): { canvas: HTMLCanvasElement; width: number; height: number; scale: number } {
    const p = { ...DEFAULTS, ...options };
    const w = Math.max(2, Math.round(p.width * scale));
    const h = Math.max(2, Math.round(p.height * scale));
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return { canvas, width: w, height: h, scale };
    }

    const image = ctx.createImageData(w, h);
    const px = image.data;

    const sx = p.width / w;
    const sy = p.height / h;
    const hw = p.width / 2;
    const hh = p.height / 2;
    const r = Math.max(0, Math.min(p.radius, hw, hh));
    const bezel = Math.max(0.5, p.bezel * Math.min(hw, hh));
    const table = profileTable(p.curvature, p.ior);
    const N = table.length - 1;
    const rimWidth = Math.max(0.5, p.specularWidth);
    const aa = Math.max(sx, sy);
    const angle = (p.lightAngle * Math.PI) / 180;
    const lx = Math.cos(angle);
    const ly = Math.sin(angle);
    const dir = p.invert ? -1 : 1;

    const qw = Math.ceil(w / 2);
    const qh = Math.ceil(h / 2);
    for (let j = 0; j < qh; j++) {
        const y = (j + 0.5) * sy - hh;
        for (let i = 0; i < qw; i++) {
            const x = (i + 0.5) * sx - hw;
            const qx = -x - (hw - r);
            const qy = -y - (hh - r);
            const ox = Math.max(qx, 0);
            const oy = Math.max(qy, 0);
            const corner = Math.hypot(ox, oy);
            const dist = corner + Math.min(Math.max(qx, qy), 0) - r;
            const alpha = Math.min(1, Math.max(0, 0.5 - dist / aa));
            if (alpha <= 0) {
                continue;
            }

            let nx: number;
            let ny: number;
            if (qx > 0 && qy > 0) {
                nx = ox / corner;
                ny = oy / corner;
            } else if (qx > qy) {
                nx = 1;
                ny = 0;
            } else {
                nx = 0;
                ny = 1;
            }

            const inset = Math.max(0, -dist);
            const s = inset / bezel;
            const m = s >= 1 ? 0 : table[Math.min(N, Math.round(s * N))];
            const bx = Math.round(127 * m * nx * dir);
            const by = Math.round(127 * m * ny * dir);

            const crisp = Math.max(0, 1 - inset / rimWidth);
            const glow = Math.max(0, 1 - inset / (rimWidth * 5));
            const rim = p.specular * (crisp * crisp + 0.3 * glow * glow);
            const a = Math.round(alpha * 255);

            const i2 = w - 1 - i;
            const j2 = h - 1 - j;
            writePixel(
                px,
                (j * w + i) * 4,
                NEUTRAL + bx,
                NEUTRAL + by,
                rimLight(rim, -nx * lx - ny * ly),
                a,
            );
            if (i2 !== i) {
                writePixel(
                    px,
                    (j * w + i2) * 4,
                    NEUTRAL - bx,
                    NEUTRAL + by,
                    rimLight(rim, nx * lx - ny * ly),
                    a,
                );
            }
            if (j2 !== j) {
                writePixel(
                    px,
                    (j2 * w + i) * 4,
                    NEUTRAL + bx,
                    NEUTRAL - by,
                    rimLight(rim, -nx * lx + ny * ly),
                    a,
                );
            }
            if (i2 !== i && j2 !== j) {
                writePixel(
                    px,
                    (j2 * w + i2) * 4,
                    NEUTRAL - bx,
                    NEUTRAL - by,
                    rimLight(rim, nx * lx + ny * ly),
                    a,
                );
            }
        }
    }

    ctx.putImageData(image, 0, 0);
    return { canvas, width: w, height: h, scale };
}

type MapEntry = {
    key: string;
    url: string;
    decoded: boolean;
    ready: Promise<void>;
};

const mapCache = new Map<string, MapEntry>();

function mapKeyOf(p: Required<GlassOptics>, scale: number): string {
    return MAP_KEYS.map((k) => p[k]).join('|') + '|' + scale;
}

function lensMap(
    p: Required<GlassOptics>,
    scale: number,
    key: string,
): MapEntry {
    let entry = mapCache.get(key);
    if (entry) {
        mapCache.delete(key);
    } else {
        const url = renderLensMap(p, scale).canvas.toDataURL('image/png');
        const img = new Image();
        img.src = url;
        const nextEntry: MapEntry = {
            key,
            url,
            decoded: false,
            ready: Promise.resolve(),
        };
        nextEntry.ready = img
            .decode()
            .catch(() => {})
            .then(() => {
                nextEntry.decoded = true;
            });
        entry = nextEntry;
        if (mapCache.size >= MAP_CACHE_SIZE) {
            const oldest = mapCache.keys().next().value;
            if (oldest !== undefined) {
                mapCache.delete(oldest);
            }
        }
    }
    mapCache.set(key, entry);
    return entry;
}

/* ------------------------------------------------------------------------------------------------ */
/* SVG Filter Graph                                                                                 */
/* ------------------------------------------------------------------------------------------------ */

let svgRoot: SVGSVGElement | null = null;

function defsNode(): SVGDefsElement {
    if (svgRoot?.isConnected) {
        return svgRoot.firstChild as SVGDefsElement;
    }
    svgRoot = document.createElementNS(NS, 'svg');
    svgRoot.setAttribute('aria-hidden', 'true');
    svgRoot.setAttribute('width', '0');
    svgRoot.setAttribute('height', '0');
    svgRoot.style.cssText =
        'position:absolute;width:0;height:0;overflow:hidden;pointer-events:none';
    const defs = document.createElementNS(NS, 'defs');
    svgRoot.appendChild(defs);
    document.body.appendChild(svgRoot);
    return defs;
}

function svgNode<K extends keyof SVGElementTagNameMap>(
    name: K,
    attrs: Record<string, string | number>,
): SVGElementTagNameMap[K] {
    const n = document.createElementNS(NS, name);
    for (const k in attrs) {
        n.setAttribute(k, String(attrs[k]));
    }
    return n;
}

const CHANNELS = [
    '1 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 1 0',
    '0 0 0 0 0  0 1 0 0 0  0 0 0 0 0  0 0 0 1 0',
    '0 0 0 0 0  0 0 0 0 0  0 0 1 0 0  0 0 0 1 0',
];

type FilterGraph = {
    image: SVGFEImageElement;
    aspect: SVGFEColorMatrixElement | null;
    blur: SVGFEGaussianBlurElement | null;
    displace: SVGFEDisplacementMapElement[];
    lensRect: SVGElement[];
};

function buildGraph(
    filter: SVGFilterElement,
    p: Required<GlassOptics>,
): FilterGraph {
    filter.replaceChildren();
    const lensRect: SVGElement[] = [];
    const add = <K extends keyof SVGElementTagNameMap>(
        name: K,
        attrs: Record<string, string | number>,
        inLens = false,
    ): SVGElementTagNameMap[K] => {
        const n = svgNode(name, attrs);
        filter.appendChild(n);
        if (inLens) {
            lensRect.push(n);
        }
        return n;
    };

    add(
        'feFlood',
        {
            'flood-color': 'rgb(128,128,128)',
            'flood-opacity': '1',
            result: 'neutral',
        },
        true,
    );
    const image = add(
        'feImage',
        { preserveAspectRatio: 'none', result: 'lensShape' },
        true,
    );
    add(
        'feComposite',
        {
            in: 'lensShape',
            in2: 'neutral',
            operator: 'over',
            result: 'map',
        },
        true,
    );
    const aspect =
        engine === 'webkit'
            ? add(
                  'feColorMatrix',
                  { in: 'map', type: 'matrix', result: 'bend' },
                  true,
              )
            : null;
    const bendMap = aspect ? 'bend' : 'map';

    let source = 'SourceGraphic';
    let blur: SVGFEGaussianBlurElement | null = null;
    if (p.blur > 0) {
        blur = add('feGaussianBlur', {
            in: 'SourceGraphic',
            result: 'frosted',
        });
        source = 'frosted';
    }

    const displace: SVGFEDisplacementMapElement[] = [];
    if (p.chroma > 0) {
        CHANNELS.forEach((values, i) => {
            displace.push(
                add(
                    'feDisplacementMap',
                    {
                        in: source,
                        in2: bendMap,
                        xChannelSelector: 'R',
                        yChannelSelector: 'G',
                    },
                    true,
                ),
            );
            add(
                'feColorMatrix',
                { type: 'matrix', values, result: `ch${i}` },
                true,
            );
        });
        add(
            'feComposite',
            {
                in: 'ch0',
                in2: 'ch1',
                operator: 'arithmetic',
                k1: 0,
                k2: 1,
                k3: 1,
                k4: 0,
                result: 'rg',
            },
            true,
        );
        add(
            'feComposite',
            {
                in: 'rg',
                in2: 'ch2',
                operator: 'arithmetic',
                k1: 0,
                k2: 1,
                k3: 1,
                k4: 0,
                result: 'refracted',
            },
            true,
        );
    } else {
        displace.push(
            add(
                'feDisplacementMap',
                {
                    in: source,
                    in2: bendMap,
                    xChannelSelector: 'R',
                    yChannelSelector: 'G',
                    result: 'refracted',
                },
                true,
            ),
        );
    }

    let lit = 'refracted';
    if (p.specular > 0) {
        add(
            'feColorMatrix',
            {
                in: 'map',
                type: 'matrix',
                values: `0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 2 0 ${-(2 * NEUTRAL) / 255}`,
                result: 'rim',
            },
            true,
        );
        add(
            'feComposite',
            {
                in: 'rim',
                in2: 'refracted',
                operator: 'arithmetic',
                k1: 0,
                k2: 1,
                k3: 1,
                k4: 0,
                result: 'lit',
            },
            true,
        );
        lit = 'lit';
    }

    add(
        'feComposite',
        { in: lit, in2: 'lensShape', operator: 'in', result: 'lens' },
        true,
    );
    add('feComposite', {
        in: 'SourceGraphic',
        in2: 'lensShape',
        operator: 'out',
        result: 'outside',
    });
    add('feComposite', { in: 'lens', in2: 'outside', operator: 'over' });

    return { image, aspect, blur, displace, lensRect };
}

function bendScale(
    px: number,
    w: number,
    h: number,
): { scale: number; kx: number; ky: number } {
    if (engine === 'webkit') {
        const m = Math.min(w, h);
        return { scale: (2 * px) / m, kx: m / w, ky: m / h };
    }
    if (engine === 'gecko') {
        return {
            scale: (2 * px) / Math.sqrt((w * w + h * h) / 2),
            kx: 1,
            ky: 1,
        };
    }
    return { scale: (2 * px) / w, kx: 1, ky: 1 };
}

function setRect(n: SVGElement, x: number, y: number, w: number, h: number) {
    n.setAttribute('x', String(x));
    n.setAttribute('y', String(y));
    n.setAttribute('width', String(w));
    n.setAttribute('height', String(h));
}

function pickOptics(o: GlassOptics): Partial<Required<GlassOptics>> {
    const out: Partial<Required<GlassOptics>> = {};
    for (const k of Object.keys(DEFAULTS) as Array<keyof GlassOptics>) {
        if (o[k] !== undefined) {
            (out as Record<string, unknown>)[k] = o[k];
        }
    }
    return out;
}

/* ------------------------------------------------------------------------------------------------ */
/* Glass Controller                                                                                 */
/* ------------------------------------------------------------------------------------------------ */

let uid = 0;

export function createGlass(
    target: HTMLElement,
    options: GlassControllerOptions = {},
): GlassController {
    const base = `adcom-lg-${++uid}`;
    const mode: GlassRenderMode = options.mode ?? 'content';
    const fallback = options.fallback ?? 'blur(16px) saturate(1.6)';
    const p: Required<GlassOptics> = { ...DEFAULTS, ...pickOptics(options) };
    const flags = {
        fit: !!options.fit,
        clip: !!options.clip,
        sync: !!options.sync,
        hidden: !!options.hidden,
    };

    const useBackdropURL = mode === 'backdrop' && engine === 'blink';
    const active =
        !reducedTransparency.matches && (mode !== 'backdrop' || useBackdropURL);

    if (!active && !reducedTransparency.matches) {
        target.style.backdropFilter = fallback;
        (
            target.style as CSSStyleDeclaration & {
                webkitBackdropFilter?: string;
            }
        ).webkitBackdropFilter = fallback;
    }

    const filter = svgNode('filter', {
        filterUnits: 'objectBoundingBox',
        x: 0,
        y: 0,
        width: 1,
        height: 1,
        primitiveUnits: 'objectBoundingBox',
        'color-interpolation-filters': 'sRGB',
    });
    if (active) {
        defsNode().appendChild(filter);
    }

    let graph: FilterGraph | null = null;
    let structure = '';
    let wanted = '';
    let requested = 0;
    let shown = { key: '', seq: -1 };
    let version = 0;
    let frame = 0;
    let settleTimers: ReturnType<typeof setTimeout>[] = [];
    let destroyed = false;
    let box = { width: target.offsetWidth, height: target.offsetHeight };

    const observer =
        active && typeof ResizeObserver !== 'undefined'
            ? new ResizeObserver(() => {
                  box = {
                      width: target.offsetWidth,
                      height: target.offsetHeight,
                  };
                  schedule();
              })
            : null;
    observer?.observe(target);

    function settle() {
        settleTimers.forEach(clearTimeout);
        settleTimers = [60, 250].map((ms) => setTimeout(schedule, ms));
    }

    function show(entry: MapEntry, seq: number): boolean {
        if (seq <= shown.seq || !graph) {
            return false;
        }
        graph.image.setAttribute('href', entry.url);
        graph.image.setAttributeNS(XLINK, 'xlink:href', entry.url);
        shown = { key: entry.key, seq };
        settle();
        return true;
    }

    function flush() {
        frame = 0;
        if (destroyed || !active) {
            return;
        }
        if (flags.hidden) {
            if (useBackdropURL) {
                target.style.backdropFilter = '';
            } else {
                target.style.filter = '';
            }
            if (flags.clip) {
                target.style.clipPath = 'inset(50%)';
            }
            return;
        }
        const W = box.width;
        const H = box.height;
        if (W < 1 || H < 1) {
            return;
        }
        if (flags.fit) {
            p.x = 0;
            p.y = 0;
            p.width = W;
            p.height = H;
        }
        if (p.width < 1 || p.height < 1) {
            return;
        }

        const s = `${p.chroma > 0}|${p.blur > 0}|${p.specular > 0}`;
        if (s !== structure) {
            graph = buildGraph(filter, p);
            structure = s;
            shown = { key: '', seq: -1 };
            wanted = '';
        }

        const dpr =
            typeof window !== 'undefined' ? window.devicePixelRatio || 1 : 1;
        const scale = p.mapScale > 0 ? p.mapScale : Math.min(dpr, 2);
        const snap = (v: number) => Math.round(v * dpr) / dpr;
        const x = snap(p.x);
        const y = snap(p.y);
        const w = snap(p.width);
        const h = snap(p.height);

        const key = mapKeyOf(p, scale);
        if (wanted !== key) {
            wanted = key;
            const entry = lensMap(p, scale, key);
            const seq = ++requested;
            if (entry.decoded) {
                show(entry, seq);
            } else {
                void entry.ready.then(() => {
                    if (!destroyed && show(entry, seq)) {
                        schedule();
                    }
                });
            }
        }

        if (flags.clip) {
            const r = Math.min(p.radius, w / 2, h / 2);
            target.style.clipPath = `inset(${y}px ${W - x - w}px ${H - y - h}px ${x}px round ${r}px)`;
        }
        if (!shown.key || !graph) {
            return;
        }

        for (const n of graph.lensRect) {
            setRect(n, x / W, y / H, w / W, h / H);
        }
        const { scale: k, kx, ky } = bendScale(p.refraction, W, H);
        const scales =
            graph.displace.length === 3
                ? [k, k * (1 + p.chroma / 2), k * (1 + p.chroma)]
                : [k];
        graph.displace.forEach((n, i) =>
            n.setAttribute('scale', String(scales[i])),
        );
        if (graph.aspect) {
            graph.aspect.setAttribute(
                'values',
                `${kx} 0 0 0 ${(1 - kx) / 2}  0 ${ky} 0 0 ${(1 - ky) / 2}  0 0 1 0 0  0 0 0 1 0`,
            );
        }
        if (graph.blur) {
            graph.blur.setAttribute(
                'stdDeviation',
                `${p.blur / W} ${p.blur / H}`,
            );
            const pad = Math.ceil(p.refraction * (1 + p.chroma) + 3 * p.blur);
            setRect(
                graph.blur,
                (x - pad) / W,
                (y - pad) / H,
                (w + 2 * pad) / W,
                (h + 2 * pad) / H,
            );
        }

        filter.id = `${base}-v${++version}`;
        const ref = `url(#${filter.id})`;
        if (useBackdropURL) {
            target.style.backdropFilter = ref;
        } else {
            target.style.filter = ref;
        }
    }

    function schedule() {
        if (flags.sync) {
            flush();
        } else if (!frame && typeof requestAnimationFrame === 'function') {
            frame = requestAnimationFrame(flush);
        }
    }

    schedule();

    return {
        update(next: GlassControllerOptions = {}) {
            const changes = pickOptics(next);
            const hidden =
                next.hidden === undefined ? flags.hidden : !!next.hidden;
            if (
                hidden === flags.hidden &&
                (Object.keys(changes) as Array<keyof GlassOptics>).every(
                    (k) => changes[k] === p[k],
                )
            ) {
                return;
            }
            Object.assign(p, changes);
            flags.hidden = hidden;
            schedule();
        },
        flush() {
            if (frame && typeof cancelAnimationFrame === 'function') {
                cancelAnimationFrame(frame);
            }
            flush();
        },
        get params() {
            return { ...p };
        },
        get filterId() {
            return filter.id;
        },
        get effectiveMode() {
            if (!active) {
                return 'blur-fallback';
            }
            return useBackdropURL ? 'backdrop-url' : 'content';
        },
        destroy() {
            destroyed = true;
            if (frame && typeof cancelAnimationFrame === 'function') {
                cancelAnimationFrame(frame);
            }
            settleTimers.forEach(clearTimeout);
            observer?.disconnect();
            filter.remove();
            target.style.filter = '';
            target.style.backdropFilter = '';
            (
                target.style as CSSStyleDeclaration & {
                    webkitBackdropFilter?: string;
                }
            ).webkitBackdropFilter = '';
            if (flags.clip) {
                target.style.clipPath = '';
            }
        },
    };
}

/* ------------------------------------------------------------------------------------------------ */
/* Spring Physics                                                                                   */
/* ------------------------------------------------------------------------------------------------ */

export type SpringController = {
    set: (next: number, opts?: { immediate?: boolean }) => void;
    readonly value: number;
    readonly target: number;
    stop: () => void;
};

export function createSpring(
    initial: number,
    onUpdate: (value: number, velocity: number) => void,
    {
        stiffness = 520,
        damping = 38,
        mass = 1,
    }: { stiffness?: number; damping?: number; mass?: number } = {},
): SpringController {
    let value = initial;
    let velocity = 0;
    let target = initial;
    let frame = 0;
    let last = 0;

    function step(now: number) {
        let remaining = Math.min(0.1, (now - last) / 1000 || 1 / 60);
        last = now;
        while (remaining > 1e-6) {
            const dt = Math.min(1 / 120, remaining);
            const force = -stiffness * (value - target) - damping * velocity;
            velocity += (force / mass) * dt;
            value += velocity * dt;
            remaining -= dt;
        }
        if (Math.abs(velocity) < 0.01 && Math.abs(value - target) < 0.01) {
            value = target;
            velocity = 0;
            frame = 0;
            onUpdate(value, 0);
            return;
        }
        onUpdate(value, velocity);
        frame = requestAnimationFrame(step);
    }

    return {
        set(next: number, { immediate = false } = {}) {
            target = next;
            if (immediate || reducedMotion.matches) {
                if (frame) {
                    cancelAnimationFrame(frame);
                }
                frame = 0;
                value = next;
                velocity = 0;
                onUpdate(value, 0);
                return;
            }
            if (!frame && typeof requestAnimationFrame === 'function') {
                last = performance.now();
                frame = requestAnimationFrame(step);
            }
        },
        get value() {
            return value;
        },
        get target() {
            return target;
        },
        stop() {
            if (frame) {
                cancelAnimationFrame(frame);
            }
            frame = 0;
        },
    };
}

/* ------------------------------------------------------------------------------------------------ */
/* Backdrop Surface (Copy / Live Backdrop / Accessible Blur Fallback)                               */
/* ------------------------------------------------------------------------------------------------ */

function resolveBackdrop(
    host: HTMLElement,
    selector?: string,
): HTMLElement | null {
    if (selector === 'none') {
        return null;
    }
    if (selector) {
        return document.querySelector<HTMLElement>(selector);
    }
    const scope =
        host.parentElement?.closest<HTMLElement>('[data-lg-backdrop]');
    if (!scope) {
        return null;
    }
    const inner = scope.getAttribute('data-lg-backdrop');
    return inner ? scope.querySelector<HTMLElement>(inner) : scope;
}

function cloneBackdrop(source: HTMLElement): HTMLElement {
    let copy: HTMLElement;
    if (source instanceof HTMLImageElement) {
        const img = source.cloneNode(false) as HTMLImageElement;
        img.removeAttribute('id');
        img.removeAttribute('class');
        img.removeAttribute('data-lg-backdrop');
        img.alt = '';
        img.loading = 'eager';
        const cs = getComputedStyle(source);
        img.style.objectFit = cs.objectFit;
        img.style.objectPosition = cs.objectPosition;
        copy = img;
    } else {
        copy = document.createElement('span');
        const cs = getComputedStyle(source);
        const keys = [
            'backgroundColor',
            'backgroundImage',
            'backgroundSize',
            'backgroundPosition',
            'backgroundRepeat',
            'backgroundOrigin',
            'backgroundClip',
        ] as const;
        for (const k of keys) {
            copy.style[k] = cs[k];
        }
    }
    copy.classList.add('lg-surface__copy');
    copy.setAttribute('aria-hidden', 'true');
    return copy;
}

const activeSurfaces = new Set<{ update: () => void }>();
let syncFrame = 0;
let listenersBound = false;

function requestSurfaceSync() {
    if (syncFrame || typeof requestAnimationFrame !== 'function') {
        return;
    }
    syncFrame = requestAnimationFrame(() => {
        syncFrame = 0;
        for (const s of activeSurfaces) {
            s.update();
        }
    });
}

function ensureGlobalSyncListeners() {
    if (listenersBound || typeof window === 'undefined') {
        return;
    }
    listenersBound = true;
    window.addEventListener('scroll', requestSurfaceSync, {
        capture: true,
        passive: true,
    });
    window.addEventListener('resize', requestSurfaceSync);
}

export type BackdropSurfaceOptions = {
    radius?: number;
    bleed?: number;
    optics?: GlassOptics;
    backdropSelector?: string;
    tintClassName?: string;
};

export type BackdropSurface = {
    readonly mode: SurfaceMode;
    setDelta: (delta: {
        x?: number;
        y?: number;
        w?: number;
        h?: number;
    }) => void;
    update: () => void;
    destroy: () => void;
};

export function createBackdropSurface(
    host: HTMLElement,
    options: BackdropSurfaceOptions = {},
): BackdropSurface {
    const radiusCap = options.radius ?? 16;
    const opticsOpts = options.optics ?? {};

    const root = document.createElement('span');
    root.className = 'lg-surface';
    root.setAttribute('aria-hidden', 'true');

    const shadow = document.createElement('span');
    shadow.className = 'lg-surface__shadow';

    const glassEl = document.createElement('span');
    glassEl.className = 'lg-surface__glass';

    const tint = document.createElement('span');
    tint.className = options.tintClassName
        ? `lg-surface__tint ${options.tintClassName}`
        : 'lg-surface__tint';

    root.append(shadow, glassEl, tint);

    let delta = { x: 0, y: 0, w: 0, h: 0 };
    let source: HTMLElement | null = null;
    let copy: HTMLElement | null = null;
    let glass: GlassController | null = null;
    let observer: ResizeObserver | null = null;
    let mode: SurfaceMode = 'fallback';

    const attach = () => {
        if (!host.firstChild) {
            host.append(root);
        } else {
            host.prepend(root);
        }

        source = resolveBackdrop(host, options.backdropSelector);
        if (source) {
            mode = 'copy';
            copy = cloneBackdrop(source);
            glassEl.replaceChildren(copy);
            glass = createGlass(glassEl, { ...opticsOpts, mode: 'content' });
            ensureGlobalSyncListeners();
            activeSurfaces.add(surfaceObj);
        } else if (!reducedTransparency.matches) {
            mode = 'backdrop';
            glassEl.classList.add('lg-surface__glass--backdrop');
        } else {
            mode = 'fallback';
            glassEl.classList.add('lg-surface__glass--fallback');
        }

        if (typeof ResizeObserver !== 'undefined') {
            observer = new ResizeObserver(update);
            observer.observe(host);
            if (source) {
                observer.observe(source);
            }
        }
        update();
    };

    const update = () => {
        const w = host.offsetWidth + delta.w;
        const h = host.offsetHeight + delta.h;
        if (w < 1 || h < 1) {
            return;
        }
        const r = Math.min(radiusCap, w / 2, h / 2);

        root.style.borderRadius = `${r}px`;

        shadow.style.width = `${w}px`;
        shadow.style.height = `${h}px`;
        shadow.style.borderRadius = `${r}px`;
        shadow.style.transform = `translate(${delta.x}px, ${delta.y}px)`;

        tint.style.width = `${w}px`;
        tint.style.height = `${h}px`;
        tint.style.borderRadius = `${r}px`;
        tint.style.transform = `translate(${delta.x}px, ${delta.y}px)`;

        if (mode === 'copy' && source && copy) {
            const hr = host.getBoundingClientRect();
            const sr = source.getBoundingClientRect();
            copy.style.width = `${sr.width}px`;
            copy.style.height = `${sr.height}px`;
            copy.style.transform = `translate(${sr.left - hr.left}px, ${sr.top - hr.top}px)`;
            glass?.update({
                x: delta.x,
                y: delta.y,
                width: w,
                height: h,
                radius: r,
            });
        } else {
            glassEl.style.width = `${w}px`;
            glassEl.style.height = `${h}px`;
            glassEl.style.borderRadius = `${r}px`;
            glassEl.style.transform = `translate(${delta.x}px, ${delta.y}px)`;
        }
    };

    const surfaceObj: BackdropSurface = {
        get mode() {
            return mode;
        },
        setDelta(next) {
            delta = { ...delta, ...next };
            update();
        },
        update,
        destroy() {
            activeSurfaces.delete(surfaceObj);
            observer?.disconnect();
            glass?.destroy();
            glass = null;
            copy = null;
            root.remove();
        },
    };

    attach();
    return surfaceObj;
}

export function getGlassCapabilities() {
    return {
        engine,
        supportsBackdropUrl: engine === 'blink',
        reducedMotion: reducedMotion.matches,
        reducedTransparency: reducedTransparency.matches,
    };
}
