import { Alert, AlertDescription } from '@apperp/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@apperp/ui/alert-dialog';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogBody,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Empty, EmptyHeader, EmptyMedia, EmptyTitle } from '@apperp/ui/empty';
import { Spinner } from '@apperp/ui/spinner';
import { ImageIcon, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    AttachmentPreview,
    attachmentError,
    fileSize,
} from '@/components/record-attachments';
import type { Attachment } from '@/components/record-attachments';
import { apiJson, apiRequest } from '@/lib/core-api';

type PicturePage = {
    data: Attachment | null;
    meta: { can_change: boolean; max_kb: number; extensions: string[] };
};

/** Foto utama satu record, memakai hak record dan penyimpanan Core; tidak masuk daftar lampiran. */
export function RecordPicture({
    recordType,
    recordId,
}: {
    recordType: string;
    recordId: string;
}) {
    const [page, setPage] = useState<PicturePage | null>(null);
    const [loading, setLoading] = useState(true);
    const [reloadVersion, setReloadVersion] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [previewOpen, setPreviewOpen] = useState(false);
    const [archiveOpen, setArchiveOpen] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const path = `/api/v1/records/${encodeURIComponent(recordType)}/${encodeURIComponent(recordId)}/picture`;

    useEffect(() => {
        const controller = new AbortController();
        apiJson<PicturePage>(path, { signal: controller.signal })
            .then((result) => {
                if (!controller.signal.aborted) {
                    setPage(result);
                    setError(null);
                }
            })
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setError(
                        attachmentError(caught, 'Foto belum dapat dimuat.'),
                    );
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [path, reloadVersion]);

    async function upload(file: File) {
        if (!page) {
            return;
        }

        setError(null);
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

        if (
            !page.meta.extensions.includes(extension) ||
            file.size > page.meta.max_kb * 1024
        ) {
            setError(
                `Pilih foto ${page.meta.extensions.join(', ').toUpperCase()} dengan ukuran maksimal ${fileSize(page.meta.max_kb * 1024)}.`,
            );

            return;
        }

        setBusy(true);
        const body = new FormData();
        body.append('file', file);

        try {
            const result = await apiJson<{ data: Attachment }>(path, {
                method: 'POST',
                body,
            });
            setPage((current) =>
                current ? { ...current, data: result.data } : current,
            );
            toast.success('Foto sudah disimpan.');
        } catch (caught) {
            setError(attachmentError(caught, 'Foto belum dapat disimpan.'));
        } finally {
            setBusy(false);
        }
    }

    async function archive() {
        if (!page?.data) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            await apiRequest(
                `/api/v1/attachments/${encodeURIComponent(page.data.id)}`,
                {
                    method: 'DELETE',
                    body: JSON.stringify({ version: page.data.version }),
                },
            );
            setPage((current) =>
                current ? { ...current, data: null } : current,
            );
            toast.success('Foto sudah diarsipkan.');
        } catch (caught) {
            setError(attachmentError(caught, 'Foto belum dapat diarsipkan.'));
        } finally {
            setBusy(false);
            setArchiveOpen(false);
        }
    }

    return (
        <div className="space-y-4">
            {error && (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={busy || loading}
                        onClick={() => {
                            setLoading(true);
                            setError(null);
                            setReloadVersion((current) => current + 1);
                        }}
                    >
                        Muat ulang foto
                    </Button>
                </Alert>
            )}
            {loading ? (
                <div role="status" className="flex items-center gap-2 text-sm">
                    <Spinner />
                    Memuat foto…
                </div>
            ) : page?.data ? (
                <button
                    type="button"
                    className="flex h-64 w-full items-center justify-center overflow-hidden"
                    aria-label="Buka foto"
                    onClick={() => setPreviewOpen(true)}
                >
                    <AttachmentPreview
                        key={page.data.id}
                        attachment={page.data}
                    />
                </button>
            ) : (
                page && (
                    <Empty>
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <ImageIcon />
                            </EmptyMedia>
                            <EmptyTitle>Belum ada foto</EmptyTitle>
                        </EmptyHeader>
                    </Empty>
                )
            )}
            {page?.meta.can_change && (
                <>
                    <input
                        ref={input}
                        type="file"
                        className="hidden"
                        aria-label="Pilih foto"
                        accept={page.meta.extensions
                            .map((item) => `.${item}`)
                            .join(',')}
                        onChange={(event) => {
                            const file = event.currentTarget.files?.[0];
                            event.currentTarget.value = '';

                            if (file) {
                                void upload(file);
                            }
                        }}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={busy || loading}
                            onClick={() => input.current?.click()}
                        >
                            <Upload />
                            {busy
                                ? 'Memproses…'
                                : page.data
                                  ? 'Ganti foto'
                                  : 'Upload foto'}
                        </Button>
                        {page.data && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={busy}
                                onClick={() => setArchiveOpen(true)}
                            >
                                Arsipkan foto
                            </Button>
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {page.meta.extensions.join(', ').toUpperCase()} ·
                        Maksimal {fileSize(page.meta.max_kb * 1024)}
                    </p>
                </>
            )}
            <Dialog open={previewOpen} onOpenChange={setPreviewOpen}>
                <DialogContent size="wide">
                    <DialogHeader>
                        <DialogTitle>Foto</DialogTitle>
                        <DialogDescription>
                            {page?.data?.file_name}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        {page?.data && (
                            <AttachmentPreview attachment={page.data} />
                        )}
                    </DialogBody>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Tutup
                            </Button>
                        </DialogClose>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <AlertDialog
                open={archiveOpen}
                onOpenChange={(open) => {
                    if (!busy) {
                        setArchiveOpen(open);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Arsipkan foto?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Foto ini akan disembunyikan. Berkasnya tetap
                            tersimpan.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>
                            Batal
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={busy}
                            onClick={(event) => {
                                event.preventDefault();
                                void archive();
                            }}
                        >
                            {busy ? 'Mengarsipkan…' : 'Arsipkan'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
