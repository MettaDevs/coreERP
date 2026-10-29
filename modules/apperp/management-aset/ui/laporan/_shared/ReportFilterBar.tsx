import { RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@apperp/ui/button';
import { FieldDescription } from '@apperp/ui/field';
import type { ReportDateParameters } from './reportOptions';
import { ReportPresets } from './ReportPresets';
import type { ReportPresetState } from './useReportData';

export type ReportFilterBarProps = {
    /** Opsi dan bagian filter yang disusun engine berdasarkan profil laporan. */
    children: ReactNode;
    /** Tombol atur ulang hanya tampil bila ada filter yang terisi. */
    canReset: boolean;
    onReset: () => void;
    /** Preset laporan dari `useReportData`; tampil di depan filter. */
    presets?: ReportPresetState;
    /** Parameter tanggal laporan, supaya preset dapat menyimpan "bulan ini" alih-alih tanggal tetap. */
    dates?: ReportDateParameters;
    notice?: string;
};

/**
 * Susunan filter vertikal pada panel samping laporan.
 *
 * Engine mengisi bagian Opsi. Filter data item mengikuti bagian tersendiri di request page
 * Business Central; opsi yang sudah ada tidak ditawarkan lagi sebagai filter kolom.
 */
export function ReportFilterBar({
    children,
    canReset,
    onReset,
    presets,
    dates,
    notice,
}: ReportFilterBarProps) {
    return (
        <div className="flex flex-col gap-4 p-4">
            {canReset && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onReset}
                    className="self-start"
                >
                    <RotateCcw aria-hidden="true" />
                    Atur ulang filter
                </Button>
            )}
            {presets && (
                <div className="flex flex-col items-start gap-3 border-b pb-4">
                    <ReportPresets state={presets} dates={dates} />
                </div>
            )}
            {notice && (
                <FieldDescription role="status">{notice}</FieldDescription>
            )}
            {children}
        </div>
    );
}
