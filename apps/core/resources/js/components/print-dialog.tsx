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
import {
    FORMAT_LABEL,
    getReportOptions,
    listLayouts,
    requestExport,
} from '@/lib/reports';
import type { Layout, ReportFormat, ReportOptions } from '@/lib/reports';

/** Pilihan keluaran: format layout, atau `data` untuk "Excel (data saja)" tanpa layout (K-26). */
type Output = ReportFormat | 'data';

/**
 * Dialog cetak milik Shell, setara request page Business Central dalam bentuk paling
 * ringkas: pilih layout, pilih format, kirim. Dibuka oleh permintaan `coreerp.print`
 * dari layar module; parameter laporan sudah ditentukan layar itu.
 *
 * Layout dan format dibuka dengan pilihan terakhir pengguna untuk laporan ini (K-24), padanan
 * "Last used options and filters" BC. "Excel (data saja)" tersedia untuk setiap laporan: isi
 * laporan apa adanya ke Excel, tanpa layout, supaya dapat diolah.
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
    const [format, setFormat] = useState<Output>('pdf');
    const [sending, setSending] = useState(false);

    useEffect(() => subscribePrintRequests(setRequest), []);

    useEffect(() => {
        if (!request) {
            return;
        }

        let cancelled = false;
        // Opsi terakhir hanya isian awal: gagal memuatnya tidak menahan cetak.
        Promise.all([
            listLayouts(request.reportCode),
            getReportOptions(request.reportCode).catch(
                (): ReportOptions | null => null,
            ),
        ])
            .then(([result, options]) => {
                if (cancelled) {
                    return;
                }

                const last = options?.last_used ?? null;
                const lastLayout = result.data.find(
                    (item) => item.ref === last?.layout_ref,
                );

                setLoaded({
                    code: request.reportCode,
                    layouts: result.data,
                    error: '',
                });
                setLayoutRef(lastLayout?.ref ?? result.meta.default_ref);

                if (last?.format) {
                    setFormat(last.format);
                }
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
    const outputs: Output[] = [...(layout?.outputs ?? []), 'data'];
    // Format yang tidak didukung layout terpilih jatuh ke format pertama layout itu.
    const effectiveFormat: Output = outputs.includes(format)
        ? format
        : (outputs[0] ?? format);
    const dataOnly = effectiveFormat === 'data';

    const close = () => setRequest(null);

    const send = async () => {
        if (!request) {
            return;
        }

        setSending(true);

        try {
            const item = await requestExport(
                request.reportCode,
                effectiveFormat === 'data'
                    ? {
                          format: 'xlsx',
                          layout_ref: null,
                          parameters: request.parameters,
                          data_only: true,
                      }
                    : {
                          format: effectiveFormat,
                          layout_ref: layoutRef || null,
                          parameters: request.parameters,
                      },
            );
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
                            disabled={loading || dataOnly}
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
                        {dataOnly ? (
                            <FieldDescription>
                                Excel (data saja) tidak memakai layout.
                            </FieldDescription>
                        ) : (
                            layout?.description && (
                                <FieldDescription>
                                    {layout.description}
                                </FieldDescription>
                            )
                        )}
                    </Field>
                    <Field>
                        <FieldLabel>Format</FieldLabel>
                        <RadioGroup
                            value={effectiveFormat}
                            onValueChange={(value) =>
                                setFormat(value as Output)
                            }
                            className="flex flex-wrap gap-4"
                        >
                            {outputs.map((item) => (
                                <label
                                    key={item}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <RadioGroupItem value={item} />
                                    {item === 'data'
                                        ? 'Excel (data saja)'
                                        : FORMAT_LABEL[item]}
                                </label>
                            ))}
                        </RadioGroup>
                        <FieldDescription>
                            {dataOnly
                                ? 'Isi laporan apa adanya ke Excel, tanpa kop dan tanpa layout: angka dan tanggal siap dijumlah, diurutkan, dan difilter.'
                                : layout?.format === 'xlsx'
                                  ? 'Layout Excel menghasilkan Excel untuk diolah, atau PDF untuk dicetak.'
                                  : 'Layout Word menghasilkan PDF untuk dikirim, atau Word untuk disunting.'}
                        </FieldDescription>
                    </Field>
                </DialogBody>
                <DialogFooter>
                    <DialogAction
                        type="button"
                        disabled={
                            sending || loading || (!dataOnly && !layoutRef)
                        }
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
