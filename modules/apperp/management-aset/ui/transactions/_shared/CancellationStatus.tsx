export type CancellationSummary = {
    status: string;
    reason: string;
    failure_message: string | null;
    posting_date: string;
    postings: {
        posting_id: string;
        status: string;
        manual_reason: string | null;
        reason: string | null;
        external_reference: string | null;
    }[];
};

export function CancellationStatus({
    summary,
}: {
    summary: CancellationSummary | null | undefined;
}) {
    if (!summary) {
        return null;
    }

    let message = 'Pengajuan pembatalan ditolak.';

    if (summary.status === 'pending') {
        message = 'Pembatalan menunggu persetujuan. Transaksi masih berlaku.';
    }

    if (summary.status === 'blocked') {
        message =
            summary.failure_message ??
            'Pembatalan perlu diperiksa kembali karena transaksi berubah.';
    }

    if (summary.status === 'applied') {
        message =
            summary.postings.length === 0
                ? 'Transaksi dibatalkan. Tidak ada jurnal finance yang perlu dibalik.'
                : summary.postings.every(
                        (posting) => posting.status === 'posted',
                    )
                  ? 'Transaksi dibatalkan dan jurnal balik sudah dibukukan oleh finance.'
                  : summary.postings.some(
                          (posting) => posting.status === 'rejected',
                      )
                    ? 'Transaksi dibatalkan, tetapi jurnal balik ditolak oleh finance. Periksa alasan penolakannya.'
                    : summary.postings.every(
                            (posting) =>
                                posting.manual_reason === 'original_rejected',
                        )
                      ? 'Transaksi dibatalkan. Jurnal asal ditolak finance sehingga tidak ada jurnal balik yang dikirim.'
                      : summary.postings.every(
                              (posting) => posting.status === 'manual',
                          )
                        ? 'Transaksi dibatalkan. Catat pembalikannya secara manual di aplikasi finance.'
                        : 'Transaksi dibatalkan. Jurnal balik masih menunggu pembukuan di finance.';
    }

    return (
        <div className="space-y-1 rounded-md border p-3 text-sm" role="status">
            <p>{message}</p>
            <p className="text-muted-foreground">Alasan: {summary.reason}</p>
            {summary.postings
                .filter((posting) => posting.reason)
                .map((posting) => (
                    <p key={posting.posting_id}>{posting.reason}</p>
                ))}
        </div>
    );
}
