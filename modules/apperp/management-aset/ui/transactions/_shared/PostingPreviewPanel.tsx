import { TriangleAlert } from 'lucide-react';
import { PostingCheck } from '@/components/finance/posting-check';
import type {
    PostingCheckLine,
    PostingCheckProblem,
} from '@/components/finance/posting-check';

/**
 * Jawaban `GET …/{id}/pratinjau-posting` pelepasan dan penyesuaian nilai aset: yang menahan posting,
 * catatan bila tidak ada jurnal, dan jurnal yang akan terbit.
 */
export type PostingPreview = {
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

/** Kalimat keadaan jurnal yang akan terbit, dalam bahasa pengguna. */
function keadaan(status: string, adaPenghalang: boolean): string {
    switch (status) {
        case 'pending':
            return 'Jurnal ini siap dikirim ke aplikasi finance begitu dokumennya diposting.';
        case 'held':
            return adaPenghalang
                ? 'Jurnal ini akan tertahan sampai masalah di bawah dibenahi.'
                : 'Jurnal ini akan tertahan sampai masalah di bawah dibenahi. Dokumennya tetap dapat diposting; setelah dibenahi, owner atau admin menekan Validasi ulang di layar Posting finance.';
        case 'manual':
            return 'Jurnal ini dicatat tetapi tidak dikirim: pengiriman posting entitas legal ini belum aktif, atau tanggalnya sebelum cutover.';
        default:
            return '';
    }
}

/**
 * Pratinjau posting gaya *Preview Posting* Business Central: penghalang lebih dulu, lalu jurnalnya dengan
 * komponen pemeriksaan posting yang sama dengan layar pantau Core (K-22).
 */
export function PostingPreviewPanel({
    preview,
    currencyCode,
}: {
    preview: PostingPreview;
    currencyCode: string;
}) {
    const posting = preview.posting;

    return (
        <div className="space-y-3">
            {preview.blockers.length > 0 && (
                <div className="border-destructive/40 bg-destructive/5 space-y-2 rounded-md border px-4 py-3">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <TriangleAlert className="size-4" />
                        Belum dapat diposting
                    </p>
                    {preview.blockers.map((blocker) => (
                        <p key={blocker.field} className="text-sm">
                            {blocker.message}
                        </p>
                    ))}
                </div>
            )}
            {preview.note && (
                <p className="text-muted-foreground text-sm">{preview.note}</p>
            )}
            {posting && (
                <>
                    <p className="text-sm">
                        {keadaan(posting.status, preview.blockers.length > 0)}
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
