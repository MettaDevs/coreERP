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
import { ChevronLeft, ChevronRight, ImageIcon, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    AttachmentPreview,
    attachmentError,
    fileSize,
} from '@/components/record-attachments';
import type { Attachment } from '@/components/record-attachments';
import { apiJson, apiRequest } from '@/lib/core-api';

type PicturesPage = {
    data: Attachment[];
    meta: { can_change: boolean; max_kb: number; extensions: string[] };
};

/** Koleksi foto record. Upload menambah foto; pengarsipan hanya menyentuh foto yang dipilih. */
export function RecordPictures({
    recordType,
    recordId,
}: {
    recordType: string;
    recordId: string;
}) {
    const [page, setPage] = useState<PicturesPage | null>(null);
    const [loading, setLoading] = useState(true);
    const [reloadVersion, setReloadVersion] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [archiving, setArchiving] = useState<Attachment | null>(null);
    const input = useRef<HTMLInputElement>(null);
    const path = `/api/v1/records/${encodeURIComponent(recordType)}/${encodeURIComponent(recordId)}/pictures`;
    const pictures = page?.data ?? [];
    const selectedIndex = pictures.findIndex(
        (picture) => picture.id === selectedId,
    );
    const selected = pictures[selectedIndex] ?? null;

    useEffect(() => {
        const controller = new AbortController();
        apiJson<PicturesPage>(path, { signal: controller.signal })
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

    async function upload(files: File[]) {
        if (!page || files.length === 0) {
            return;
        }

        setError(null);
        const invalid = files.find(
            (file) =>
                !page.meta.extensions.includes(
                    file.name.split('.').pop()?.toLowerCase() ?? '',
                ) || file.size > page.meta.max_kb * 1024,
        );

        if (invalid) {
            setError(
                `${invalid.name}: pilih foto ${page.meta.extensions.join(', ').toUpperCase()} dengan ukuran maksimal ${fileSize(page.meta.max_kb * 1024)} per berkas.`,
            );

            return;
        }

        setBusy(true);
        let uploaded = 0;

        try {
            for (const file of files) {
                const body = new FormData();
                body.append('file', file);
                const result = await apiJson<{ data: Attachment }>(path, {
                    method: 'POST',
                    body,
                });
                setPage((current) =>
                    current
                        ? { ...current, data: [...current.data, result.data] }
                        : current,
                );
                uploaded += 1;
            }

            toast.success(`${uploaded} foto sudah ditambahkan.`);
        } catch (caught) {
            setError(
                `${uploaded ? `${uploaded} foto sudah ditambahkan. ` : ''}${attachmentError(caught, 'Foto berikutnya belum dapat diunggah.')} Foto lainnya belum diunggah; pilih kembali untuk mencoba lagi.`,
            );
        } finally {
            setBusy(false);
        }
    }

    async function archive(picture: Attachment) {
        setBusy(true);
        setError(null);

        try {
            await apiRequest(
                `/api/v1/attachments/${encodeURIComponent(picture.id)}`,
                {
                    method: 'DELETE',
                    body: JSON.stringify({ version: picture.version }),
                },
            );
            setPage((current) =>
                current
                    ? {
                          ...current,
                          data: current.data.filter(
                              (item) => item.id !== picture.id,
                          ),
                      }
                    : current,
            );
            toast.success('Foto sudah diarsipkan.');
        } catch (caught) {
            setError(attachmentError(caught, 'Foto belum dapat diarsipkan.'));
        } finally {
            setBusy(false);
            setArchiving(null);
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
            ) : (
                page &&
                (pictures.length === 0 ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <ImageIcon />
                            </EmptyMedia>
                            <EmptyTitle>Belum ada foto</EmptyTitle>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            {pictures.length} foto
                        </p>
                        <div className="grid grid-cols-2 gap-3">
                            {pictures.map((picture) => (
                                <figure
                                    key={picture.id}
                                    className="min-w-0 space-y-2"
                                >
                                    <button
                                        type="button"
                                        className="flex h-32 w-full items-center justify-center overflow-hidden rounded-md border"
                                        aria-label={`Buka foto: ${picture.file_name}`}
                                        onClick={() =>
                                            setSelectedId(picture.id)
                                        }
                                    >
                                        <AttachmentPreview
                                            attachment={picture}
                                        />
                                    </button>
                                    <figcaption
                                        className="truncate text-xs"
                                        title={picture.file_name}
                                    >
                                        {picture.file_name}
                                    </figcaption>
                                    {page.meta.can_change && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={busy}
                                            aria-label={`Arsipkan foto: ${picture.file_name}`}
                                            onClick={() =>
                                                setArchiving(picture)
                                            }
                                        >
                                            Arsipkan
                                        </Button>
                                    )}
                                </figure>
                            ))}
                        </div>
                    </>
                ))
            )}
            {page?.meta.can_change && (
                <>
                    <input
                        ref={input}
                        type="file"
                        multiple
                        className="hidden"
                        aria-label="Pilih foto"
                        accept={page.meta.extensions
                            .map((item) => `.${item}`)
                            .join(',')}
                        onChange={(event) => {
                            const files = Array.from(
                                event.currentTarget.files ?? [],
                            );
                            event.currentTarget.value = '';
                            void upload(files);
                        }}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={busy || loading}
                        onClick={() => input.current?.click()}
                    >
                        <Upload />
                        {busy ? 'Mengunggah…' : 'Tambah foto'}
                    </Button>
                    <p className="text-xs text-muted-foreground">
                        Bisa memilih beberapa foto sekaligus.{' '}
                        {page.meta.extensions.join(', ').toUpperCase()} ·
                        Maksimal {fileSize(page.meta.max_kb * 1024)} per berkas
                    </p>
                </>
            )}
            <Dialog
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelectedId(null);
                    }
                }}
            >
                <DialogContent size="wide">
                    <DialogHeader>
                        <DialogTitle>
                            Foto {selectedIndex + 1} dari {pictures.length}
                        </DialogTitle>
                        <DialogDescription>
                            {selected?.file_name}
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
                            disabled={selectedIndex <= 0}
                            onClick={() =>
                                setSelectedId(pictures[selectedIndex - 1].id)
                            }
                        >
                            <ChevronLeft />
                            Sebelumnya
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={
                                selectedIndex < 0 ||
                                selectedIndex >= pictures.length - 1
                            }
                            onClick={() =>
                                setSelectedId(pictures[selectedIndex + 1].id)
                            }
                        >
                            Berikutnya
                            <ChevronRight />
                        </Button>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Tutup
                            </Button>
                        </DialogClose>
                    </DialogFooter>
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
                        <AlertDialogTitle>Arsipkan foto ini?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {archiving?.file_name} akan disembunyikan. Foto
                            lainnya tetap tersedia, dan berkas foto ini tetap
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
        </div>
    );
}
