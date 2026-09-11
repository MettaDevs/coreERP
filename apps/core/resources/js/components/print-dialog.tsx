import {
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Field, FieldDescription, FieldLabel } from '@apperp/ui/field';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { RadioGroup, RadioGroupItem } from '@apperp/ui/radio-group';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { trackExport } from '@/lib/export-watch';
import type { PrintRequest } from '@/lib/print-requests';
import { subscribePrintRequests } from '@/lib/print-requests';
import { FORMAT_LABEL, listLayouts, requestExport } from '@/lib/reports';
import type { Layout, ReportFormat } from '@/lib/reports';

/**
 * Dialog cetak milik Shell, setara request page Business Central dalam bentuk paling
 * ringkas: pilih layout, pilih format, kirim. Dibuka oleh permintaan `coreerp.print`
 * dari app di iframe; parameter laporan sudah ditentukan app.
 *
 * Permintaan dijawab 202 dan dikerjakan worker Core; dialog menutup dan tray ekspor
 * di header yang menampilkan kemajuannya.
 */
export function PrintDialog() {
    const [request, setRequest] = useState<PrintRequest | null>(null);
    // Layout dimuat per permintaan; "sedang memuat" diturunkan dari kecocokan kode,
    // bukan disetel terpisah, supaya tidak ada state yang bisa tertinggal.
    const [loaded, setLoaded] = useState<{
        code: string;
        layouts: Layout[];
        error: string;
    } | null>(null);
    const [layoutRef, setLayoutRef] = useState('');
    const [format, setFormat] = useState<ReportFormat>('pdf');
    const [sending, setSending] = useState(false);

    useEffect(() => subscribePrintRequests(setRequest), []);

    useEffect(() => {
        if (!request) {
            return;
        }

        let cancelled = false;
        listLayouts(request.reportCode)
            .then((result) => {
                if (cancelled) {
                    return;
                }

                setLoaded({
                    code: request.reportCode,
                    layouts: result.data,
                    error: '',
                });
                setLayoutRef(result.meta.default_ref);
            })
            .catch((caught: Error) => {
                if (!cancelled) {
                    setLoaded({
                        code: request.reportCode,
                        layouts: [],
                        error:
                            caught.message ||
                            'Daftar layout belum dapat dimuat.',
                    });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [request]);

    const current =
        request !== null &&
        loaded !== null &&
        loaded.code === request.reportCode
            ? loaded
            : null;
    const loading = request !== null && current === null;
    const layouts = current?.layouts ?? [];
    const error = current?.error ?? '';

    const layout = layouts.find((item) => item.ref === layoutRef) ?? null;
    const outputs = layout?.outputs ?? [];
    // Format yang tidak didukung layout terpilih jatuh ke format pertama layout itu.
    const effectiveFormat = outputs.includes(format)
        ? format
        : (outputs[0] ?? format);

    const close = () => setRequest(null);

    const send = async () => {
        if (!request) {
            return;
        }

        setSending(true);

        try {
            const item = await requestExport(request.reportCode, {
                format: effectiveFormat,
                layout_ref: layoutRef || null,
                parameters: request.parameters,
            });
            trackExport(item);
            toast.info(
                'Ekspor dimulai. Anda dapat melanjutkan pekerjaan lain.',
            );
            close();
        } catch (caught) {
            toast.error(
                caught instanceof Error && caught.message
                    ? caught.message
                    : 'Ekspor belum dapat dimulai.',
            );
        } finally {
            setSending(false);
        }
    };

    return (
        <Dialog
            open={request !== null}
            onOpenChange={(open) => !open && close()}
        >
            <DialogContent size="compact">
                <DialogHeader>
                    <DialogTitle>{request?.title ?? 'Cetak'}</DialogTitle>
                    <DialogDescription>
                        Dokumen dibuat di latar belakang. Setelah siap, tombol
                        unduh muncul pada ikon Ekspor di header dan di lonceng
                        notifikasi.
                    </DialogDescription>
                </DialogHeader>
                <DialogBody className="space-y-4">
                    {error && (
                        <p className="text-sm text-destructive">{error}</p>
                    )}
                    <Field>
                        <NativeSelect
                            label="Layout"
                            value={layoutRef}
                            disabled={loading}
                            onChange={(event) =>
                                setLayoutRef(event.target.value)
                            }
                        >
                            {layouts.map((item) => (
                                <NativeSelectOption
                                    key={item.ref}
                                    value={item.ref}
                                >
                                    {item.name}
                                    {item.is_default ? ' (default)' : ''}
                                </NativeSelectOption>
                            ))}
                        </NativeSelect>
                        {layout?.description && (
                            <FieldDescription>
                                {layout.description}
                            </FieldDescription>
                        )}
                    </Field>
                    <Field>
                        <FieldLabel>Format</FieldLabel>
                        <RadioGroup
                            value={effectiveFormat}
                            onValueChange={(value) =>
                                setFormat(value as ReportFormat)
                            }
                            className="flex flex-wrap gap-4"
                        >
                            {outputs.map((item) => (
                                <label
                                    key={item}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <RadioGroupItem value={item} />
                                    {FORMAT_LABEL[item]}
                                </label>
                            ))}
                        </RadioGroup>
                        <FieldDescription>
                            {layout?.format === 'xlsx'
                                ? 'Layout Excel menghasilkan Excel untuk diolah, atau PDF untuk dicetak.'
                                : 'Layout Word menghasilkan PDF untuk dikirim, atau Word untuk disunting.'}
                        </FieldDescription>
                    </Field>
                </DialogBody>
                <DialogFooter>
                    <DialogAction
                        type="button"
                        disabled={sending || loading || !layoutRef}
                        onClick={() => void send()}
                    >
                        {sending ? 'Mengirim…' : 'Mulai ekspor'}
                    </DialogAction>
                    <DialogCancel type="button" onClick={close}>
                        Batal
                    </DialogCancel>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
