import { Head, router } from '@inertiajs/react';
import type { CoreErpTheme } from '@apperp/ui/theme';
import { useEffect, useRef, useState } from 'react';
import { useAppearance } from '@/hooks/use-appearance';

type HostedApp = {
    id: string;
    name: string;
    contentEntry: string;
    contextToken: string;
};

/**
 * Iframe tidak memberi tahu apa pun saat isinya gagal dimuat, dan `onLoad` bukan
 * bukti berhasil: saat container UI mati, reverse proxy membalas halaman errornya
 * sendiri, halaman itu dimuat dengan sukses, dan pengguna melihat "500 Proxy Error"
 * berbahasa Inggris seolah seluruh sistem mati.
 *
 * Karena itu app dianggap siap hanya setelah ia mengumumkan diri lewat
 * `coreerp.ready` — satu-satunya sinyal yang tidak bisa dipalsukan halaman error.
 * Selebihnya shell yang memegang status memuat dan status gagal.
 */
const LOAD_TIMEOUT_MS = 15_000;

type FrameState = 'loading' | 'ready' | 'failed';

export default function HostedApp({ app }: { app: HostedApp }) {
    useEffect(() => {
        const htmlOverflow = document.documentElement.style.overflow;
        const bodyOverflow = document.body.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';

        return () => {
            document.documentElement.style.overflow = htmlOverflow;
            document.body.style.overflow = bodyOverflow;
        };
    }, []);

    return (
        <>
            <Head title={app.name} />
            {/*
             * Frame di-remount lewat key setiap entry atau token berubah, sehingga
             * statusnya kembali ke "memuat" tanpa perlu menulis state dari dalam
             * effect.
             */}
            <AppFrame
                key={`${app.contentEntry}:${app.contextToken}`}
                app={app}
            />
        </>
    );
}

/**
 * Sandbox pada iframe di bawah adalah pembatas tambahan, bukan batas isolasi.
 * Konten app disajikan same-origin, sehingga `allow-same-origin` — yang dibutuhkan
 * agar pengecekan origin postMessage dan storage app bekerja — membuat frame tetap
 * sederajat dengan shell. Isolasi sungguhan menuntut origin terpisah, dan itu
 * bergantung pada keputusan addressing yang belum diambil; lihat
 * App\Support\AppContentPath.
 */
function AppFrame({ app }: { app: HostedApp }) {
    const frame = useRef<HTMLIFrameElement>(null);
    const loadedEntry = useRef<string | null>(null);
    const [state, setState] = useState<FrameState>('loading');
    const { resolvedAppearance } = useAppearance();

    const sendContext = () => {
        if (loadedEntry.current !== app.contentEntry) return;
        const origin = new URL(app.contentEntry, window.location.origin).origin;
        const theme: CoreErpTheme = {
            appearance: resolvedAppearance,
            font:
                document.documentElement.dataset.font === 'geist'
                    ? 'geist'
                    : 'poppins',
        };
        frame.current?.contentWindow?.postMessage(
            {
                type: 'coreerp.context',
                appId: app.id,
                token: app.contextToken,
                theme,
            },
            origin,
        );
    };

    useEffect(() => {
        const frameOrigin = new URL(app.contentEntry, window.location.origin)
            .origin;
        const receiveReady = (event: MessageEvent) => {
            if (
                event.source !== frame.current?.contentWindow ||
                event.origin !== frameOrigin ||
                event.data?.type !== 'coreerp.ready' ||
                event.data?.appId !== app.id
            ) {
                return;
            }

            setState('ready');
            sendContext();
        };

        window.addEventListener('message', receiveReady);
        sendContext();
        const refresh = window.setInterval(
            () => router.reload({ only: ['app'] }),
            240_000,
        );

        return () => {
            window.removeEventListener('message', receiveReady);
            window.clearInterval(refresh);
        };
    }, [app.contentEntry, app.contextToken, app.id, resolvedAppearance]);

    useEffect(() => {
        // Proxy yang tidak melayani path app umumnya membalas cepat, tetapi
        // container UI yang hidup-tapi-menggantung tidak membalas sama sekali.
        // Timeout menutup kasus kedua supaya frame tidak diam selamanya.
        const timer = window.setTimeout(() => {
            setState((current) => (current === 'loading' ? 'failed' : current));
        }, LOAD_TIMEOUT_MS);

        return () => window.clearTimeout(timer);
    }, []);

    return (
        <div className="relative h-[calc(100svh-5rem)] w-full">
            <iframe
                ref={frame}
                className="block h-full w-full border-0 bg-transparent"
                src={app.contentEntry}
                title={app.name}
                sandbox="allow-scripts allow-forms allow-popups allow-downloads allow-same-origin"
                onLoad={() => {
                    loadedEntry.current = app.contentEntry;
                    sendContext();
                }}
                onError={() => setState('failed')}
            />

            {state !== 'ready' && (
                <div className="bg-background absolute inset-0 flex items-center justify-center p-6">
                    {state === 'loading' ? (
                        <p className="text-muted-foreground text-sm">
                            Memuat {app.name}…
                        </p>
                    ) : (
                        <div className="max-w-md space-y-2 text-center">
                            <p className="font-medium">
                                {app.name} belum bisa dimuat
                            </p>
                            <p className="text-muted-foreground text-sm">
                                Halaman app tidak merespons. Biasanya ini
                                berarti layanannya sedang tidak berjalan atau
                                belum selesai dipasang. Coba muat ulang sebentar
                                lagi; kalau tetap begini, beri tahu tim yang
                                mengelola sistem.
                            </p>
                            <button
                                type="button"
                                className="text-sm underline underline-offset-4"
                                onClick={() => router.reload()}
                            >
                                Muat ulang
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
