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
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
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
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Spinner } from '@apperp/ui/spinner';
import { Download, FileText, Paperclip, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import { apiJson, apiRequest, CoreApiError, errorText } from '@/lib/core-api';

export type Attachment = {
    id: string;
    version: number;
    file_name: string;
    mime_type: string;
    size_bytes: number;
    created_by_name: string | null;
    created_at: string | null;
};

type AttachmentPage = {
    data: Attachment[];
    meta: { can_change: boolean; max_kb: number; extensions: string[] };
};

export function fileSize(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / (1024 * 1024)).toLocaleString('id-ID', { maximumFractionDigits: 1 })} MB`;
}

function downloadPath(attachment: Attachment): string {
    return `/api/v1/attachments/${encodeURIComponent(attachment.id)}/download`;
}

export function attachmentError(caught: unknown, fallback: string): string {
    if (caught instanceof CoreApiError && caught.status >= 500) {
        return `${fallback} Terjadi kesalahan sistem. Coba lagi atau hubungi pengelola aplikasi.`;
    }

    if (caught instanceof CoreApiError && [401, 419].includes(caught.status)) {
        return 'Sesi kamu sudah berakhir. Muat ulang halaman lalu masuk kembali.';
    }

    return caught instanceof CoreApiError
        ? errorText(caught, fallback)
        : fallback;
}

export function AttachmentPreview({ attachment }: { attachment: Attachment }) {
    const [url, setUrl] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const previewable = ['image/jpeg', 'image/png'].includes(
        attachment.mime_type,
    );

    useEffect(() => {
        if (!previewable) {
            return;
        }

        const controller = new AbortController();
        let objectUrl: string | null = null;
        apiRequest(downloadPath(attachment), { signal: controller.signal })
            .then((response) => response.blob())
            .then((blob) => {
                if (controller.signal.aborted) {
                    return;
                }

                objectUrl = URL.createObjectURL(blob);
                setUrl(objectUrl);
            })
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setError(
                        attachmentError(caught, 'Berkas belum dapat dibuka.'),
                    );
                }
            });

        return () => {
            controller.abort();

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
        };
    }, [attachment, previewable]);

    if (error) {
        return (
            <Alert variant="destructive">
                <AlertDescription>{error}</AlertDescription>
            </Alert>
        );
    }

    if (!previewable) {
        return (
            <Empty>
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <FileText />
                    </EmptyMedia>
                    <EmptyTitle>Buka di perangkatmu</EmptyTitle>
                    <EmptyDescription>
                        Unduh berkas ini untuk membukanya dengan aplikasi yang
                        sesuai.
                    </EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    if (!url) {
        return (
            <div
                role="status"
                className="flex items-center justify-center gap-2 py-12"
            >
                <Spinner />
                Memuat lampiran…
            </div>
        );
    }

    return (
        <img
            src={url}
            alt={attachment.file_name}
            className="mx-auto max-h-full max-w-full object-contain"
            onError={() =>
                setError(
                    'Gambar ini belum dapat ditampilkan. Coba unduh berkasnya.',
                )
            }
        />
    );
}

/** Panel lampiran Core; hak dan batas unggah dibaca dari record induk, bukan dari mode edit halaman. */
export function RecordAttachments({
    recordType,
    recordId,
    onCountChange,
}: {
    recordType: string;
    recordId: string;
    onCountChange?: (count: number) => void;
}) {
    const [page, setPage] = useState<AttachmentPage | null>(null);
    const [loading, setLoading] = useState(true);
    const [reloadVersion, setReloadVersion] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [selected, setSelected] = useState<Attachment | null>(null);
    const [archiving, setArchiving] = useState<Attachment | null>(null);
    const input = useRef<HTMLInputElement>(null);
    const formatDateTime = useDateTimeFormat();
    const path = `/api/v1/records/${encodeURIComponent(recordType)}/${encodeURIComponent(recordId)}/attachments`;

    useEffect(() => {
        const controller = new AbortController();
        apiJson<AttachmentPage>(path, { signal: controller.signal })
            .then((result) => {
                if (!controller.signal.aborted) {
                    setPage(result);
                    setError(null);
                    onCountChange?.(result.data.length);
                }
            })
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setError(
                        attachmentError(caught, 'Lampiran belum dapat dimuat.'),
                    );
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [path, reloadVersion, onCountChange]);

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
                `Pilih berkas ${page.meta.extensions.join(', ').toUpperCase()} dengan ukuran maksimal ${fileSize(page.meta.max_kb * 1024)}.`,
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
                current
                    ? { ...current, data: [...current.data, result.data] }
                    : current,
            );
            toast.success('Berkas sudah dilampirkan.');
            onCountChange?.(page.data.length + 1);
        } catch (caught) {
            setError(attachmentError(caught, 'Berkas belum dapat diunggah.'));
        } finally {
            setBusy(false);
        }
    }

    async function download(attachment: Attachment) {
        setBusy(true);
        setError(null);

        try {
            const response = await apiRequest(downloadPath(attachment));
            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download = attachment.file_name;
            link.click();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (caught) {
            setError(attachmentError(caught, 'Berkas belum dapat diunduh.'));
        } finally {
            setBusy(false);
        }
    }

    async function archive(attachment: Attachment) {
        setBusy(true);
        setError(null);

        try {
            await apiRequest(
                `/api/v1/attachments/${encodeURIComponent(attachment.id)}`,
                {
                    method: 'DELETE',
                    body: JSON.stringify({ version: attachment.version }),
                },
            );
            setPage((current) =>
                current
                    ? {
                          ...current,
                          data: current.data.filter(
                              (item) => item.id !== attachment.id,
                          ),
                      }
                    : current,
            );
            setArchiving(null);
            toast.success('Lampiran sudah diarsipkan.');
            onCountChange?.(Math.max(0, (page?.data.length ?? 1) - 1));
        } catch (caught) {
            setError(
                attachmentError(caught, 'Lampiran belum dapat diarsipkan.'),
            );
            setArchiving(null);
        } finally {
            setBusy(false);
        }
    }

    const columns: DataTableColumn<Attachment>[] = [
        {
            id: 'file_name',
            header: 'Nama berkas',
            width: 150,
            cell: (item) => (
                <button
                    type="button"
                    className="block w-full text-left text-primary hover:underline"
                    onClick={() => setSelected(item)}
                >
                    <span className="block truncate" title={item.file_name}>
                        {item.file_name}
                    </span>
                </button>
            ),
        },
        {
            id: 'size',
            header: 'Ukuran',
            width: 72,
            minWidth: 64,
            cell: (item) => fileSize(item.size_bytes),
        },
    ];

    return (
        <>
            <section className="space-y-4" aria-label="Dokumen lampiran">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-base font-medium">
                        Dokumen{page ? ` (${page.data.length})` : ''}
                    </h3>
                    {page?.meta.can_change && (
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={busy || loading}
                                onClick={() => input.current?.click()}
                            >
                                <Upload />
                                {busy ? 'Memproses…' : 'Upload'}
                            </Button>
                        </div>
                    )}
                </div>
                <div className="space-y-4">
                    <input
                        ref={input}
                        type="file"
                        className="hidden"
                        aria-label="Pilih berkas lampiran"
                        accept={page?.meta.extensions
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
                                Muat ulang
                            </Button>
                        </Alert>
                    )}
                    {loading ? (
                        <div
                            role="status"
                            className="flex items-center gap-2 text-sm"
                        >
                            <Spinner />
                            Memuat lampiran…
                        </div>
                    ) : (
                        page &&
                        (page.data.length > 0 ? (
                            <DataTable
                                columns={columns}
                                data={page.data}
                                getRowKey={(item) => item.id}
                                getRowLabel={(item) => item.file_name}
                                actionsPlacement="inline"
                                onRowClick={setSelected}
                                actions={[
                                    { id: 'open', label: 'Buka' },
                                    { id: 'download', label: 'Unduh' },
                                    ...(page.meta.can_change
                                        ? [
                                              {
                                                  id: 'archive',
                                                  label: 'Arsipkan',
                                                  destructive: true,
                                                  separatorBefore: true,
                                              },
                                          ]
                                        : []),
                                ]}
                                onRowAction={(action, item) => {
                                    if (busy) {
                                        return;
                                    }

                                    if (action === 'open') {
                                        setSelected(item);
                                    }

                                    if (action === 'download') {
                                        void download(item);
                                    }

                                    if (action === 'archive') {
                                        setArchiving(item);
                                    }
                                }}
                            />
                        ) : (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Paperclip />
                                    </EmptyMedia>
                                    <EmptyTitle>Belum ada lampiran</EmptyTitle>
                                    <EmptyDescription>
                                        {page.meta.can_change
                                            ? 'Upload dokumen, gambar, atau bukti pendukung.'
                                            : 'Berkas yang dilampirkan akan tampil di sini.'}
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ))
                    )}
                    {page?.meta.can_change && (
                        <p className="text-xs text-muted-foreground">
                            {page.meta.extensions.join(', ').toUpperCase()} ·
                            Maksimal {fileSize(page.meta.max_kb * 1024)} per
                            berkas
                        </p>
                    )}
                </div>
            </section>
            <Dialog
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelected(null);
                    }
                }}
            >
                <DialogContent size="wide">
                    <DialogHeader>
                        <DialogTitle>{selected?.file_name}</DialogTitle>
                        <DialogDescription>
                            {selected &&
                                `${fileSize(selected.size_bytes)} · ${selected.created_by_name ?? 'Pengguna'} · ${formatDateTime(selected.created_at)}`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        {selected && (
                            <AttachmentPreview
                                key={selected.id}
                                attachment={selected}
                            />
                        )}
                    </DialogBody>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => selected && void download(selected)}
                        >
                            <Download />
                            Unduh
                        </Button>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Tutup
                            </Button>
                        </DialogClose>
                    </DialogFooter>
                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                </DialogContent>
            </Dialog>
            <AlertDialog
                open={archiving !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) {
                        setArchiving(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Arsipkan lampiran?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {archiving?.file_name} akan disembunyikan dari
                            daftar lampiran ini. Berkasnya tetap tersimpan.
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

                                if (archiving) {
                                    void archive(archiving);
                                }
                            }}
                        >
                            {busy ? 'Mengarsipkan…' : 'Arsipkan'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
