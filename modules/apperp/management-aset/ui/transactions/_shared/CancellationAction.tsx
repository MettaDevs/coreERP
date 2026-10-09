import { Ban, Send } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Field, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Textarea } from '@apperp/ui/textarea';
import { api, errorMessage } from '../../api';
import { PostingPreviewPanel } from './PostingPreviewPanel';
import type { PostingPreview } from './PostingPreviewPanel';

type Preview = {
    blockers: Record<string, string>;
    posting_date: string;
    original_posting_date: string;
    version: number | null;
    journals: PostingPreview[];
    cancellation: {
        status: string;
        reason: string;
        failure_message: string | null;
    } | null;
};

/** Cancel Entries BC: alasan, pilihan tanggal pembukuan baru, lalu pratinjau jurnal pembalik. */
export function CancellationAction({
    resource,
    documentId,
    permissions,
    onComplete,
    awaitingApproval = false,
}: {
    resource: string;
    documentId: string;
    permissions: string[];
    onComplete: () => void;
    awaitingApproval?: boolean;
}) {
    const canCancel = permissions.includes(
        `management-aset.${resource}.cancel`,
    );
    const canRequest = permissions.includes(
        `management-aset.${resource}.request-cancellation`,
    );
    const endpoint = `/${resource}/${documentId}`;
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [reason, setReason] = useState('');
    const [newDate, setNewDate] = useState(false);
    const [date, setDate] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const show = async () => {
        setOpen(true);
        setBusy(true);
        setError(null);

        try {
            const result = await api<{ data: Preview }>(
                `${endpoint}/pratinjau-pembatalan`,
            );
            setPreview(result.data);
            setDate(result.data.posting_date);

            if (result.data.cancellation?.status === 'pending') {
                setReason(result.data.cancellation.reason);
            }
        } catch (caught) {
            setError(errorMessage(caught, 'Pembatalan belum dapat diperiksa.'));
        } finally {
            setBusy(false);
        }
    };

    if (!canCancel && !canRequest) {
        return null;
    }

    const pending = preview?.cancellation?.status === 'pending';
    const submit = async () => {
        if (!preview || busy || !reason.trim()) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const result = await api<{ data: Preview['cancellation'] }>(
                `${endpoint}/${canCancel ? 'batal' : 'ajukan-pembatalan'}`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        reason: reason.trim(),
                        posting_date: newDate
                            ? date
                            : preview.original_posting_date,
                        version: preview.version,
                    }),
                },
            );
            setPreview((current) =>
                current ? { ...current, cancellation: result.data } : current,
            );
            setOpen(false);
            onComplete();
        } catch (caught) {
            setError(errorMessage(caught, 'Pembatalan belum dapat diproses.'));
        } finally {
            setBusy(false);
        }
    };
    const changeDate = async (value: string) => {
        setDate(value);

        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return;
        }

        setBusy(true);

        try {
            const result = await api<{ data: Preview }>(
                `${endpoint}/pratinjau-pembatalan?posting_date=${value}`,
            );
            setPreview(result.data);
            setError(null);
        } catch (caught) {
            setError(errorMessage(caught, 'Pratinjau belum dapat dimuat.'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Button type="button" variant="outline" onClick={() => void show()}>
                {canCancel ? <Ban /> : <Send />}
                {pending || awaitingApproval
                    ? 'Menunggu persetujuan'
                    : canCancel
                      ? 'Batal'
                      : 'Ajukan pembatalan'}
            </Button>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!busy) {
                        setOpen(value);
                    }
                }}
            >
                <DialogContent className="sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>
                            {canCancel
                                ? 'Batalkan transaksi'
                                : 'Ajukan pembatalan'}
                        </DialogTitle>
                        <DialogDescription>
                            {canCancel
                                ? 'Pembatalan mengembalikan saldo yang terdampak dan mencatat jurnal balik. Transaksi asal tetap tersimpan dalam riwayat.'
                                : 'Transaksi tetap berlaku sampai pengguna berwenang menyetujui pembatalannya. Permintaan tersedia di aplikasi dan pemberitahuan dikirim melalui email.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="max-h-[60vh] space-y-4 overflow-y-auto py-3 pr-1">
                        {error && (
                            <p
                                role="alert"
                                className="text-destructive text-sm"
                            >
                                {error}
                            </p>
                        )}
                        {!preview && !error && <p>Memeriksa transaksi…</p>}
                        {preview?.cancellation && (
                            <p className="text-sm">
                                {pending
                                    ? 'Ada pengajuan yang sedang menunggu persetujuan.'
                                    : preview.cancellation.status === 'blocked'
                                      ? preview.cancellation.failure_message
                                      : preview.cancellation.status ===
                                          'rejected'
                                        ? 'Pengajuan sebelumnya ditolak. Anda dapat mengajukan lagi dengan alasan yang diperbarui.'
                                        : 'Transaksi sudah dibatalkan.'}
                            </p>
                        )}
                        {preview &&
                            Object.values(preview.blockers).map((message) => (
                                <p
                                    key={message}
                                    role="alert"
                                    className="text-destructive text-sm"
                                >
                                    {message}
                                </p>
                            ))}
                        <Field>
                            <Textarea
                                label="Alasan pembatalan"
                                required
                                value={reason}
                                maxLength={250}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                readOnly={busy || pending}
                            />
                        </Field>
                        {!pending && (
                            <Field>
                                <FieldLabel className="flex items-center gap-2">
                                    <Checkbox
                                        checked={newDate}
                                        onCheckedChange={(value) => {
                                            setNewDate(value === true);

                                            if (!value && preview) {
                                                void changeDate(
                                                    preview.original_posting_date,
                                                );
                                            }
                                        }}
                                        disabled={busy}
                                    />
                                    Gunakan tanggal pembukuan baru
                                </FieldLabel>
                            </Field>
                        )}
                        {pending ? (
                            <p className="text-muted-foreground text-sm">
                                Tanggal pembukuan pengajuan:{' '}
                                {preview?.posting_date}.
                            </p>
                        ) : newDate ? (
                            <Input
                                label="Tanggal pembukuan baru"
                                required
                                type="date"
                                value={date}
                                onChange={(event) =>
                                    void changeDate(event.target.value)
                                }
                                disabled={busy}
                            />
                        ) : (
                            preview && (
                                <p className="text-muted-foreground text-sm">
                                    Jurnal balik memakai tanggal asal:{' '}
                                    {preview.original_posting_date}.
                                </p>
                            )
                        )}
                        {preview?.journals.map((journal) => (
                            <PostingPreviewPanel
                                key={journal.posting?.posting_id}
                                preview={journal}
                                currencyCode={
                                    journal.posting?.currency?.code ?? ''
                                }
                            />
                        ))}
                        {preview &&
                            preview.journals.length === 0 &&
                            Object.keys(preview.blockers).length === 0 && (
                                <p className="text-muted-foreground text-sm">
                                    Tidak ada jurnal finance yang perlu dibalik.
                                    Pembatalan tetap dicatat pada register aset.
                                </p>
                            )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                            disabled={busy}
                        >
                            Tutup
                        </Button>
                        <Button
                            type="button"
                            onClick={() => void submit()}
                            disabled={
                                busy ||
                                !preview ||
                                !reason.trim() ||
                                Object.keys(preview.blockers).length > 0 ||
                                (pending && !canCancel) ||
                                (newDate && !date)
                            }
                        >
                            {busy
                                ? 'Memproses…'
                                : canCancel
                                  ? 'Batalkan transaksi'
                                  : 'Kirim pengajuan'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
