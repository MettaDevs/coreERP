import { installStateLabels, labelFor } from '@/lib/display';

/**
 * Bentuk `InstallProgress::derive()` di server. Satu jenis untuk panel, daftar lingkungan, dan
 * ringkasan situs.
 */
export type InstallProgress = {
    state: string;
    final: boolean;
    step: string | null;
    failureMessage: string | null;
    release: string | null;
    reportedRelease: string | null;
    lastSeenAt: string | null;
    commandExpiresAt: string | null;
    commandExpired: boolean;
};

/**
 * Keadaan dalam satu frasa untuk kolom daftar: "Jalan (rilis 1.2.0)", bukan hanya "Jalan".
 *
 * Rilis ikut disebut hanya ketika ia menjawab pertanyaan pembaca daftar — rilis mana yang berjalan,
 * atau rilis mana yang sedang dipasang. Pada keadaan lain angkanya tidak berarti apa-apa.
 */
export function progressDetail(progress: InstallProgress): string | null {
    if (progress.state === 'ready' || progress.state === 'stale') {
        return progress.reportedRelease
            ? `rilis ${progress.reportedRelease}`
            : null;
    }

    if (progress.state === 'installing' || progress.state === 'connected') {
        return progress.release ? `rilis ${progress.release}` : null;
    }

    if (progress.state === 'failed') {
        return progress.step;
    }

    return null;
}

export function progressLabel(progress: InstallProgress): string {
    return labelFor(installStateLabels, progress.state);
}
