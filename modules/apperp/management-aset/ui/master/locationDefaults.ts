import type { MasterOption } from './useMasterOptions';

/**
 * Unit kerja bawaan yang berlaku pada sebuah lokasi aset, termasuk warisan lokasi induknya.
 *
 * Dibaca dari `departemen_bawaan_efektif` yang disajikan API lokasi. Penerimaan dan mutasi memakainya
 * untuk mengisi unit penanggung jawab saat lokasinya dipilih; pengguna tetap boleh menggantinya.
 */
export function defaultDepartmentOf(
    location: MasterOption | undefined,
): string | null {
    const efektif = location?.departemen_bawaan_efektif;

    if (efektif && typeof efektif === 'object' && 'id' in efektif) {
        const id = (efektif as { id: unknown }).id;

        return typeof id === 'string' ? id : null;
    }

    return null;
}
