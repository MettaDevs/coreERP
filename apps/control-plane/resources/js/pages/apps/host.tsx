import { Head, router } from '@inertiajs/react';
import type { CoreErpTheme } from '@apperp/ui/theme';
import { useEffect, useRef } from 'react';
import { useAppearance } from '@/hooks/use-appearance';

type HostedApp = {
    id: string;
    name: string;
    contentEntry: string;
    contextToken: string;
};

export default function HostedApp({ app }: { app: HostedApp }) {
    const frame = useRef<HTMLIFrameElement>(null);
    const loadedEntry = useRef<string | null>(null);
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
            <iframe
                key={`${app.contentEntry}:${app.contextToken}`}
                ref={frame}
                className="block h-[calc(100svh-5rem)] w-full border-0 bg-transparent"
                src={app.contentEntry}
                title={app.name}
                onLoad={() => {
                    loadedEntry.current = app.contentEntry;
                    sendContext();
                }}
            />
        </>
    );
}
