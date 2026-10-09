import { Alert, AlertDescription } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogBody,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Progress } from '@apperp/ui/progress';
import { Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { apiUpload } from '@/lib/core-api';
import { attachmentError, fileExtension, fileSize } from '@/lib/record-files';
import type { Attachment } from '@/lib/record-files';

/** Foto dan dokumen memakai dialog yang sama agar drop, validasi, progres, dan antrean upload selalu setara. */
export function RecordUploadDialog({
    open,
    onOpenChange,
    path,
    title,
    limits,
    onUploaded,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    path: string;
    title: string;
    limits: { extensions: string[]; max_kb: number };
    onUploaded: (attachment: Attachment) => void;
}) {
    const [files, setFiles] = useState<File[]>([]);
    const [completed, setCompleted] = useState(0);
    const [fraction, setFraction] = useState(0);
    const [busy, setBusy] = useState(false);
    const [stopping, setStopping] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const input = useRef<HTMLInputElement>(null);
    const active = useRef<AbortController | null>(null);
    const stop = useRef(false);
    useEffect(
        () => () => {
            active.current?.abort();
        },
        [],
    );

    const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
    const receivedBytes = files
        .slice(0, completed)
        .reduce((sum, file) => sum + file.size, 0);
    const percent = Math.min(
        99,
        Math.round(
            (100 * (receivedBytes + (files[completed]?.size ?? 0) * fraction)) /
                Math.max(1, totalBytes),
        ),
    );

    async function upload(selected: File[]) {
        if (active.current || selected.length === 0) {
            return;
        }

        setError(null);
        const invalid = selected.find(
            (file) =>
                !limits.extensions.includes(fileExtension(file.name)) ||
                file.size > limits.max_kb * 1024,
        );

        if (invalid) {
            setError(
                `${invalid.name}: pilih ${limits.extensions.join(', ').toUpperCase()} dengan ukuran maksimal ${fileSize(limits.max_kb * 1024)} per berkas.`,
            );

            return;
        }

        const controller = new AbortController();
        active.current = controller;
        stop.current = false;
        setFiles(selected);
        setCompleted(0);
        setFraction(0);
        setStopping(false);
        setBusy(true);
        let uploaded = 0;

        try {
            for (const file of selected) {
                setFraction(0);
                const result = await apiUpload<{ data: Attachment }>(
                    path,
                    file,
                    setFraction,
                    controller.signal,
                );

                if (controller.signal.aborted) {
                    return;
                }

                onUploaded(result.data);
                uploaded += 1;
                setCompleted(uploaded);
                setFraction(0);

                if (stop.current && uploaded < selected.length) {
                    setError(
                        `${uploaded} dari ${selected.length} berkas sudah tersimpan. Upload dihentikan; berkas lainnya belum diunggah.`,
                    );

                    return;
                }
            }

            toast.success(`${uploaded} berkas sudah diunggah.`);
            onOpenChange(false);
        } catch (caught) {
            if (!controller.signal.aborted) {
                setError(
                    `${uploaded} dari ${selected.length} berkas sudah tersimpan. ${attachmentError(caught, 'Berkas berikutnya belum dapat diunggah.')} Muat ulang daftar sebelum mencoba lagi.`,
                );
            }
        } finally {
            active.current = null;

            if (!controller.signal.aborted) {
                setBusy(false);
            }
        }
    }

    function close() {
        if (busy) {
            return;
        }

        setFiles([]);
        setError(null);
        setDragging(false);
        onOpenChange(false);
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) {
                    close();
                }
            }}
        >
            <DialogContent size="compact" showCloseButton={!busy}>
                <DialogHeader>
                    <DialogTitle>
                        {busy ? 'Mengunggah berkas…' : title}
                    </DialogTitle>
                    <DialogDescription>
                        {limits.extensions.join(', ').toUpperCase()} · Maksimal{' '}
                        {fileSize(limits.max_kb * 1024)} per berkas
                    </DialogDescription>
                </DialogHeader>
                <DialogBody>
                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                    {busy ? (
                        <div
                            className="space-y-3"
                            role="status"
                            aria-live="polite"
                        >
                            <p>Progres upload: {percent}%</p>
                            <Progress
                                value={percent}
                                aria-label="Progres upload"
                            />
                            <p>
                                Berkas selesai: {completed} dari {files.length}
                            </p>
                            <p className="text-sm break-all text-muted-foreground">
                                {files[completed]?.name}
                                {fraction === 1
                                    ? ' · Menunggu berkas tersimpan…'
                                    : ''}
                            </p>
                        </div>
                    ) : (
                        <>
                            <input
                                ref={input}
                                type="file"
                                multiple
                                className="hidden"
                                aria-label="Pilih berkas"
                                accept={limits.extensions
                                    .map((extension) => `.${extension}`)
                                    .join(',')}
                                onChange={(event) => {
                                    const selected = Array.from(
                                        event.currentTarget.files ?? [],
                                    );
                                    event.currentTarget.value = '';
                                    void upload(selected);
                                }}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                className={`h-auto w-full flex-col gap-3 border-dashed p-8 whitespace-normal ${dragging ? 'bg-accent' : ''}`}
                                onClick={() => input.current?.click()}
                                onDragOver={(event) => {
                                    event.preventDefault();

                                    if (
                                        event.dataTransfer.types.includes(
                                            'Files',
                                        )
                                    ) {
                                        setDragging(true);
                                    }
                                }}
                                onDragLeave={(event) => {
                                    if (
                                        !event.currentTarget.contains(
                                            event.relatedTarget as Node | null,
                                        )
                                    ) {
                                        setDragging(false);
                                    }
                                }}
                                onDrop={(event) => {
                                    event.preventDefault();
                                    setDragging(false);
                                    void upload(
                                        Array.from(event.dataTransfer.files),
                                    );
                                }}
                            >
                                <Upload />
                                Seret berkas ke sini, atau klik untuk memilih
                            </Button>
                        </>
                    )}
                    {files.length > 0 && (
                        <ul
                            className="mt-4 space-y-2 text-sm"
                            aria-label="Berkas yang dipilih"
                        >
                            {files.map((file, index) => (
                                <li
                                    key={`${index}-${file.name}`}
                                    className="space-y-1"
                                >
                                    <p className="break-all">{file.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {fileExtension(file.name).toUpperCase()}{' '}
                                        · {fileSize(file.size)} ·{' '}
                                        {index < completed
                                            ? 'Tersimpan'
                                            : busy && index === completed
                                              ? 'Sedang diunggah'
                                              : 'Belum diunggah'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </DialogBody>
                <DialogFooter>
                    {busy ? (
                        <div className="space-y-2">
                            <p className="text-xs text-muted-foreground">
                                Berhenti akan menyelesaikan berkas yang sedang
                                diunggah. Berkas yang sudah tersimpan tetap
                                tersedia.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={stopping}
                                onClick={() => {
                                    stop.current = true;
                                    setStopping(true);
                                }}
                            >
                                {stopping ? 'Menghentikan…' : 'Berhenti'}
                            </Button>
                        </div>
                    ) : (
                        <Button type="button" variant="outline" onClick={close}>
                            {files.length ? 'Tutup' : 'Batal'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
