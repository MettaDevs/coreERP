import { RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@apperp/ui/button';
import { ReportPresets } from './ReportPresets';
import type { ReportDateParameters } from './ReportPresets';
import type { ReportPresetState } from './useReportData';

export type ReportFilterBarProps = {
    /** Filter milik laporan, disusun halamannya dari komponen di `ReportFilters`. */
    children: ReactNode;
    /** Tombol atur ulang hanya tampil bila ada filter yang terisi. */
    canReset: boolean;
    onReset: () => void;
    /** Preset laporan dari `useReportData`; tampil di depan filter. */
    presets?: ReportPresetState;
    /** Parameter tanggal laporan, supaya preset dapat menyimpan "bulan ini" alih-alih tanggal tetap. */
    dates?: ReportDateParameters;
};

/**
 * Baris filter sebuah laporan.
 *
 * Komponen ini hanya wadah: letak, jarak, preset, dan tombol atur ulang. Filter apa yang tampil
 * ditentukan halaman laporannya sendiri, jadi laporan yang butuh filter baru cukup
 * menambahkannya di halamannya tanpa mengubah berkas ini.
 */
export function ReportFilterBar({
    children,
    canReset,
    onReset,
    presets,
    dates,
}: ReportFilterBarProps) {
    return (
        <div className="flex flex-col gap-3 border-b px-5 py-3">
            {presets && (
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <ReportPresets state={presets} dates={dates} />
                </div>
            )}
            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                {children}
                {canReset && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onReset}
                        className="sm:ms-auto"
                    >
                        <RotateCcw />
                        Atur ulang
                    </Button>
                )}
            </div>
        </div>
    );
}
