import { Head } from '@inertiajs/react';

type HostedApp = { id: string; name: string; contentEntry: string };

export default function HostedApp({ app }: { app: HostedApp }) {
    return (
        <>
            <Head title={app.name} />
            <div className="flex h-full flex-1 flex-col p-4">
                <iframe
                    className="min-h-[calc(100vh-9rem)] w-full flex-1 rounded-xl border border-sidebar-border bg-background"
                    src={app.contentEntry}
                    title={app.name}
                />
            </div>
        </>
    );
}
