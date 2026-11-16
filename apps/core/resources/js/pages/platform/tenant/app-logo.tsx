import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { Head, router, usePage } from '@inertiajs/react';
import { RotateCcw, Save, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { useSaveFeedback } from '@/hooks/use-save-feedback';
import { apiJson } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

type HeaderColors = { from: string; to: string };

type Props = {
    hasCustomLogo: boolean;
    headerColors: HeaderColors | null;
    canManage: boolean;
};

const DEFAULT_HEADER_COLORS: HeaderColors = { from: '#2a84c1', to: '#e3f0fa' };

/**
 * Logo aplikasi milik tenant: satu gambar yang menggantikan logo bawaan di header, di tab
 * peramban, dan pada pratinjau ketika alamat aplikasi dibagikan. Di sini juga warna header,
 * karena keduanya sama-sama tampilan milik tenant di bagian atas aplikasi.
 */
export default function AppLogoSettings({
    hasCustomLogo,
    headerColors,
    canManage,
}: Props) {
    const { branding } = usePage().props;
    const { scopeRef, clear, report } = useSaveFeedback<HTMLElement>();
    const fileInput = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [colors, setColors] = useState<HeaderColors>(
        headerColors ?? DEFAULT_HEADER_COLORS,
    );

    const send = async (
        url: string,
        method: 'POST' | 'PUT' | 'DELETE',
        body: FormData | string | undefined,
        done: string,
        failed: string,
    ) => {
        setBusy(true);
        clear();

        try {
            await apiJson(url, { method, body });
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
            '/api/v1/app-logo',
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
                                                '/api/v1/app-logo',
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
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {headerColors
                                ? 'Warna header Anda'
                                : 'Warna header bawaan sedang dipakai'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <div
                            aria-hidden
                            className="h-10 w-full rounded border"
                            style={{
                                backgroundImage: `linear-gradient(to right, ${colors.from}, ${colors.to})`,
                            }}
                        />
                        {canManage ? (
                            <div className="flex flex-wrap items-end gap-4">
                                {(
                                    [
                                        ['from', 'Warna kiri'],
                                        ['to', 'Warna kanan'],
                                    ] as const
                                ).map(([key, label]) => (
                                    <label
                                        key={key}
                                        className="flex flex-col gap-1.5 text-sm"
                                    >
                                        {label}
                                        <span className="flex items-center gap-2">
                                            <input
                                                type="color"
                                                value={colors[key]}
                                                disabled={busy}
                                                onChange={(event) =>
                                                    setColors((current) => ({
                                                        ...current,
                                                        [key]: event.target
                                                            .value,
                                                    }))
                                                }
                                                className="h-9 w-12 cursor-pointer rounded border bg-background p-1"
                                            />
                                            <span className="font-mono text-muted-foreground">
                                                {colors[key]}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        disabled={busy}
                                        onClick={() =>
                                            void send(
                                                '/api/v1/app-header-colors',
                                                'PUT',
                                                JSON.stringify(colors),
                                                'Warna header disimpan.',
                                                'Warna header belum dapat disimpan.',
                                            )
                                        }
                                    >
                                        <Save data-icon="inline-start" />
                                        Simpan warna
                                    </Button>
                                    {headerColors ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={busy}
                                            onClick={() =>
                                                void send(
                                                    '/api/v1/app-header-colors',
                                                    'DELETE',
                                                    undefined,
                                                    'Warna header bawaan dipakai kembali.',
                                                    'Warna header belum dapat dikembalikan.',
                                                )
                                            }
                                        >
                                            <RotateCcw data-icon="inline-start" />
                                            Pakai warna bawaan
                                        </Button>
                                    ) : null}
                                </div>
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
