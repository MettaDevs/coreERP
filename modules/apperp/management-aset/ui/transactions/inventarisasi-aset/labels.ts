import { toast } from 'sonner';

/**
 * Cetak label aset: membuka lembar label siap cetak di tab baru.
 *
 * Lembarnya halaman HTML tanpa shell (`AssetLabelController`), supaya yang sampai ke printer
 * hanya kertas labelnya. Batasnya sama dengan sisi server; diperiksa di sini juga supaya
 * orangnya tahu sebelum tab baru terbuka hanya untuk berisi pesan penolakan.
 */
export const MAX_LABELS = 240;

type Target =
    /** Aset yang dipilih, atau satu aset dari halaman rinciannya. */
    | { ids: string[] }
    /** Semua aset yang cocok dengan pencarian register; kosong berarti semua aset. */
    | { search: string; count: number };

export function printAssetLabels(target: Target): void {
    const count = 'ids' in target ? target.ids.length : target.count;

    if (count === 0) {
        toast.error('Tidak ada aset untuk dicetak labelnya.');

        return;
    }

    if (count > MAX_LABELS) {
        toast.error(
            `Paling banyak ${MAX_LABELS} label sekali cetak. Persempit pencarian atau pilih asetnya, lalu cetak bertahap.`,
        );

        return;
    }

    const params = new URLSearchParams(
        'ids' in target
            ? { ids: target.ids.join(',') }
            : target.search.trim()
              ? { q: target.search.trim() }
              : {},
    );
    const query = params.toString();

    window.open(
        `/management-aset/label-aset${query ? `?${query}` : ''}`,
        '_blank',
        'noopener',
    );
}
