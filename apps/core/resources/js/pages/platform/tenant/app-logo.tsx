import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { Head, router, usePage } from '@inertiajs/react';
import { RotateCcw, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { useSaveFeedback } from '@/hooks/use-save-feedback';
import { apiJson } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = { hasCustomLogo: boolean; canManage: boolean };

/**
 * Logo aplikasi milik tenant: satu gambar yang menggantikan logo bawaan di header, di tab
 * peramban, dan pada pratinjau ketika alamat aplikasi dibagikan.
 */
export default function AppLogoSettings({ hasCustomLogo, canManage }: Props) {
    const { branding } = usePage().props;
    const { scopeRef, clear, report } = useSaveFeedback<HTMLElement>();
    const fileInput = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);

    const send = async (
        method: 'POST' | 'DELETE',
        body: FormData | undefined,
        done: string,
        failed: string,
    ) => {
        setBusy(true);
        clear();

        try {
            await apiJson('/api/v1/app-logo', { method, body });
            toast.success(done);
            router.reload();
        } catch (caught) {
            report(caught, failed);
        } finally {
            setBusy(false);

            if (fileInput.current) {
                fileInput.current.value = '';
            }
        }
    };

    const upload = (file: File | undefined) => {
        if (!file || busy) {
            return;
        }

        const body = new FormData();
        body.append('file', file);
        void send(
            'POST',
            body,
            'Logo aplikasi diganti.',
            'Logo belum dapat diunggah.',
        );
    };

    return (
        <>
            <Head title="Logo aplikasi" />
            <main
                ref={scopeRef}
                className="mx-auto flex w-full max-w-3xl min-w-0 flex-col gap-6 p-6"
            >
                <Heading
                    title="Logo aplikasi"
                    description="Tampil di bagian atas aplikasi, di tab browser, dan saat alamat aplikasi dibagikan lewat pesan."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {hasCustomLogo
                                ? 'Logo Anda'
                                : 'Logo bawaan sedang dipakai'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap items-center gap-4">
                        <img
                            src={branding.logo_url}
                            alt="Logo aplikasi saat ini"
                            className="h-20 w-28 rounded border bg-white object-contain p-2"
                        />
                        {canManage ? (
                            <div className="flex flex-wrap items-center gap-2">
                                <input
                                    ref={fileInput}
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    className="sr-only"
                                    aria-label="Pilih berkas logo"
                                    onChange={(event) =>
                                        upload(event.target.files?.[0])
                                    }
                                />
                                <Button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => fileInput.current?.click()}
                                >
                                    <Upload data-icon="inline-start" />
                                    {hasCustomLogo
                                        ? 'Ganti logo'
                                        : 'Unggah logo'}
                                </Button>
                                {hasCustomLogo ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() =>
                                            void send(
                                                'DELETE',
                                                undefined,
                                                'Logo bawaan dipakai kembali.',
                                                'Logo belum dapat dikembalikan.',
                                            )
                                        }
                                    >
                                        <RotateCcw data-icon="inline-start" />
                                        Pakai logo bawaan
                                    </Button>
                                ) : null}
                                <p className="basis-full text-sm text-muted-foreground">
                                    PNG atau JPG, paling besar 512 KB. Gambar
                                    persegi tampil paling rapi di tab browser.
                                </p>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

AppLogoSettings.layout = {
    breadcrumbs: [
        { title: 'Logo aplikasi', href: '/settings/app-logo' },
    ] satisfies BreadcrumbItem[],
};
