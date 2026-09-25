import { TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PostingCheck } from '@/components/finance/posting-check';
import type {
    PostingCheckLine,
    PostingCheckProblem,
} from '@/components/finance/posting-check';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Textarea } from '@apperp/ui/textarea';
import { api, errorMessage } from '../../api';
import { money } from './aset';
import { tanggalTampil } from './penerimaan';

/** Pratinjau koreksi nilai perolehan dari server (`GET aset/{id}/pratinjau-koreksi`). */
type PratinjauKoreksi = {
    before: string;
    after: string;
    difference: string;
    currency_code: string;
    blockers: { field: string; message: string }[];
    note: string | null;
    posting: {
        posting_id: string;
        status: string;
        posting_date: string | null;
        currency: { code: string; decimals: number } | null;
        total: string | null;
        lines: PostingCheckLine[];
        problems: PostingCheckProblem[];
    } | null;
};

/** Jurnal koreksi yang dikirim balik server sesudah koreksi disimpan; `null` bila nilainya tidak berubah. */
export type HasilKoreksi = {
    note: string | null;
    posting: { posting_id: string; status: string } | null;
} | null;

/**
 * Tanggal hari ini menurut jam pengguna, `YYYY-MM-DD`. Jurnal koreksi bertanggal hari koreksi dilakukan
 * (K-34), dan jam server memakai UTC: menjelang pagi di Indonesia tanggalnya masih kemarin.
 */
export function tanggalHariIni(): string {
    const hari = new Date();

    return [
        hari.getFullYear(),
        String(hari.getMonth() + 1).padStart(2, '0'),
        String(hari.getDate()).padStart(2, '0'),
    ].join('-');
}

/** Pesan sesudah koreksi disimpan, menurut jurnal koreksinya. */
export function pesanKoreksi(hasil: HasilKoreksi): string {
    if (hasil?.note) {
        return `Koreksi aset disimpan. ${hasil.note}`;
    }

    switch (hasil?.posting?.status) {
        case 'pending':
            return 'Koreksi aset disimpan. Jurnal koreksinya siap diambil aplikasi finance.';
        case 'held':
            return 'Koreksi aset disimpan. Jurnal koreksinya tertahan sampai masalahnya dibenahi; lihat layar Posting finance.';
        case 'manual':
            return 'Koreksi aset disimpan. Jurnal koreksinya dicatat tetapi tidak dikirim ke aplikasi finance.';
        default:
            return 'Koreksi aset disimpan.';
    }
}

/** Kalimat keadaan jurnal koreksi yang akan terbit, dalam bahasa yang dipakai pengguna. */
function keadaan(status: string, adaPenghalang: boolean): string {
    switch (status) {
        case 'pending':
            return 'Jurnal koreksi ini siap dikirim ke aplikasi finance begitu koreksinya disimpan.';
        case 'held':
            // Selama masih ada penghalang, "tetap dapat disimpan" membantah kotak di atasnya.
            return adaPenghalang
                ? 'Jurnal koreksi ini akan tertahan sampai masalah di bawah dibenahi.'
                : 'Jurnal koreksi ini akan tertahan sampai masalah di bawah dibenahi. Koreksinya tetap dapat disimpan; setelah dibenahi, owner atau admin menekan Validasi ulang di layar Posting finance.';
        case 'manual':
            return 'Jurnal koreksi dicatat tetapi tidak dikirim: pengiriman posting entitas legal ini belum aktif, atau tanggalnya sebelum cutover.';
        default:
            return '';
    }
}

/**
 * Koreksi nilai perolehan di form koreksi aset (feed posting finance, TODO 12, K-36): alasan yang wajib
 * diisi, lalu pratinjau jurnal koreksinya dengan komponen pemeriksaan posting yang sama dengan penerimaan
 * dan "Post penyusutan" (K-22). Pratinjaunya disusun ulang setiap nilai baru berhenti diketik.
 */
export function AcquisitionAdjustmentPreview({
    asetId,
    after,
    currencyCode,
    reason,
    onReasonChange,
}: {
    asetId: string;
    after: string;
    currencyCode: string;
    reason: string;
    onReasonChange: (reason: string) => void;
}) {
    const [pratinjau, setPratinjau] = useState<PratinjauKoreksi | null>(null);
    const [galat, setGalat] = useState('');
    const [memuat, setMemuat] = useState(false);

    useEffect(() => {
        let dibatalkan = false;
        const jeda = window.setTimeout(() => {
            setMemuat(true);
            api<{ data: PratinjauKoreksi }>(
                `/aset/${asetId}/pratinjau-koreksi?${new URLSearchParams({
                    acquisition_value: after,
                    adjustment_date: tanggalHariIni(),
                }).toString()}`,
            )
                .then((jawab) => {
                    if (!dibatalkan) {
                        setPratinjau(jawab.data);
                        setGalat('');
                    }
                })
                .catch((caught) => {
                    if (!dibatalkan) {
                        setPratinjau(null);
                        setGalat(
                            errorMessage(
                                caught,
                                'Pratinjau jurnal koreksi belum dapat disusun.',
                            ),
                        );
                    }
                })
                .finally(() => {
                    if (!dibatalkan) {
                        setMemuat(false);
                    }
                });
        }, 400);

        return () => {
            dibatalkan = true;
            window.clearTimeout(jeda);
        };
    }, [asetId, after]);

    const posting = pratinjau?.posting ?? null;
    const selisih = Number(pratinjau?.difference ?? 0);

    return (
        <div className="space-y-3 rounded-md border px-4 py-3">
            <p className="text-sm font-medium">Koreksi nilai perolehan</p>
            {pratinjau && (
                <p className="text-sm">
                    Dari {money(pratinjau.before, currencyCode)} menjadi{' '}
                    {money(pratinjau.after, currencyCode)}, selisih{' '}
                    {selisih < 0 ? 'turun' : 'naik'}{' '}
                    {money(String(Math.abs(selisih)), currencyCode)}.
                </p>
            )}
            <Field>
                <Textarea
                    label="Alasan koreksi"
                    required
                    rows={2}
                    maxLength={250}
                    value={reason}
                    onChange={(event) => onReasonChange(event.target.value)}
                />
                <FieldDescription>
                    Ikut ke keterangan jurnal koreksi di aplikasi finance,
                    misalnya &quot;Faktur ternyata 510.000&quot;.
                </FieldDescription>
            </Field>
            {galat && <p className="text-destructive text-sm">{galat}</p>}
            {memuat && !pratinjau && (
                <p className="text-muted-foreground text-sm">
                    Menyusun pratinjau jurnal koreksi…
                </p>
            )}
            {pratinjau && pratinjau.blockers.length > 0 && (
                <div className="border-destructive/40 bg-destructive/5 space-y-2 rounded-md border px-4 py-3">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <TriangleAlert className="size-4" />
                        Belum dapat disimpan
                    </p>
                    {pratinjau.blockers.map((blocker) => (
                        <p key={blocker.field} className="text-sm">
                            {blocker.message}
                        </p>
                    ))}
                </div>
            )}
            {pratinjau?.note && (
                <p className="text-muted-foreground text-sm">
                    {pratinjau.note}
                </p>
            )}
            {posting && (
                <>
                    <p className="text-sm">
                        {keadaan(
                            posting.status,
                            (pratinjau?.blockers.length ?? 0) > 0,
                        )}{' '}
                        Tanggal jurnal {tanggalTampil(posting.posting_date)}.
                    </p>
                    <PostingCheck
                        lines={posting.lines}
                        problems={posting.problems}
                        currencyCode={posting.currency?.code ?? currencyCode}
                        currencyDecimals={posting.currency?.decimals ?? 2}
                    />
                </>
            )}
        </div>
    );
}
