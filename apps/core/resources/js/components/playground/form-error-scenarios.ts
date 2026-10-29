export type Scenario =
    | 'validation'
    | 'lines'
    | 'system'
    | 'conflict'
    | 'permission'
    | 'session'
    | 'setup'
    | 'duplicate'
    | 'file-size'
    | 'file-type'
    | 'partial';

export type Draft = { reference: string; email: string; quantity: string };
export type Line = { id: string; quantity: string };
export type Attachment = { name: string; size: number; type: string };
export type FieldName =
    'reference' | 'email' | 'quantity' | 'attachment' | `line:${string}`;
export type FieldIssue = { field: FieldName; message: string };

type ScenarioDefinition = {
    value: Scenario;
    label: string;
    title: string;
    message: string;
    action: string;
};

const scenarioDetails: Record<Scenario, ScenarioDefinition> = {
    validation: {
        value: 'validation',
        label: 'Isian salah',
        title: 'Beberapa isian perlu diperbaiki',
        message:
            'Data belum tersimpan. Pilih pesan di bawah untuk menuju isian yang perlu diperbaiki, lalu pilih Simpan lagi.',
        action: 'Periksa isian',
    },
    lines: {
        value: 'lines',
        label: 'Error pada baris rincian',
        title: 'Jumlah pada baris rincian belum sesuai',
        message:
            'Data belum tersimpan. Perbaiki jumlah pada baris yang ditandai, lalu pilih Simpan lagi. Isian lainnya tetap ada.',
        action: 'Periksa baris',
    },
    system: {
        value: 'system',
        label: 'Gangguan sistem',
        title: 'Penyimpanan belum dapat diselesaikan',
        message:
            'Terjadi gangguan sistem. Isian kamu masih ada di form ini. Salin detail bantuan dan berikan kepada pengelola aplikasi agar penyebabnya dapat diperiksa.',
        action: 'Salin detail bantuan',
    },
    conflict: {
        value: 'conflict',
        label: 'Data diubah pengguna lain',
        title: 'Dokumen sudah berubah sejak dibuka',
        message:
            'Perubahan kamu belum tersimpan. Bandingkan isian kamu dengan versi terbaru sebelum melanjutkan. Isian kamu masih ada di form ini.',
        action: 'Bandingkan perubahan',
    },
    permission: {
        value: 'permission',
        label: 'Hak akses tidak cukup',
        title: 'Kamu belum memiliki akses untuk menyimpan',
        message:
            'Data belum tersimpan. Periksa akses yang dibutuhkan, lalu minta pengelola akun menyesuaikannya. Mengulang Simpan tidak mengubah hak akses.',
        action: 'Lihat akses yang dibutuhkan',
    },
    session: {
        value: 'session',
        label: 'Sesi login berakhir',
        title: 'Sesi login sudah berakhir',
        message:
            'Permintaan penyimpanan ditolak. Masuk kembali untuk melanjutkan. Isian masih ada selama form ini tetap terbuka; jangan tutup atau muat ulang halaman sebelum mengamankannya.',
        action: 'Masuk kembali',
    },
    setup: {
        value: 'setup',
        label: 'Pengaturan belum lengkap',
        title: 'Akun tujuan belum dipilih',
        message:
            'Dokumen belum diproses karena akun tujuan belum diatur. Pilih akun tujuan pada pengaturan, lalu kembali ke dokumen ini dan pilih Simpan lagi.',
        action: 'Lengkapi pengaturan',
    },
    duplicate: {
        value: 'duplicate',
        label: 'Referensi duplikat',
        title: 'Referensi dokumen sudah digunakan',
        message:
            'Dokumen baru belum dibuat. Lihat dokumen yang memakai referensi ini untuk memastikan apakah itu dokumen yang kamu cari, atau gunakan referensi lain.',
        action: 'Lihat dokumen yang sudah ada',
    },
    'file-size': {
        value: 'file-size',
        label: 'Berkas terlalu besar',
        title: 'Ukuran berkas melebihi batas upload',
        message:
            'Contoh berkas berukuran 12 MB, sedangkan batas upload 10 MB. Berkas belum diunggah. Pilih berkas yang lebih kecil.',
        action: 'Pilih berkas lain',
    },
    'file-type': {
        value: 'file-type',
        label: 'Format berkas tidak didukung',
        title: 'Format berkas belum didukung',
        message:
            'Contoh berkas memakai format .exe. Berkas belum diunggah. Pilih berkas PDF, PNG, atau JPEG.',
        action: 'Pilih berkas lain',
    },
    partial: {
        value: 'partial',
        label: 'Proses berhasil sebagian',
        title: 'Sebagian dokumen belum diproses',
        message:
            'Periksa hasil setiap baris. Baris yang berhasil tidak diproses ulang. Perbaiki baris yang gagal, lalu proses ulang baris gagal saja.',
        action: 'Proses ulang dokumen gagal',
    },
};

export const scenarios = Object.values(scenarioDetails);

export function getScenario(value: Scenario): ScenarioDefinition {
    return scenarioDetails[value];
}

function hasValidQuantity(value: string): boolean {
    const quantity = Number(value);

    return Number.isSafeInteger(quantity) && quantity >= 1;
}

export function validateDraft(draft: Draft): FieldIssue[] {
    const issues: FieldIssue[] = [];

    if (!draft.reference.trim()) {
        issues.push({ field: 'reference', message: 'Isi referensi dokumen.' });
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(draft.email.trim())) {
        issues.push({
            field: 'email',
            message: 'Isi alamat email yang lengkap, termasuk @ dan domain.',
        });
    }

    if (!hasValidQuantity(draft.quantity)) {
        issues.push({
            field: 'quantity',
            message: 'Isi jumlah dengan bilangan bulat minimal 1.',
        });
    }

    return issues;
}

export function validateLines(
    lines: readonly Line[],
    processedIds: readonly string[] = [],
): FieldIssue[] {
    return lines.flatMap((line, index): FieldIssue[] =>
        processedIds.includes(line.id) || hasValidQuantity(line.quantity)
            ? []
            : [
                  {
                      field: `line:${line.id}`,
                      message: `Baris ${index + 1}: isi jumlah dengan bilangan bulat minimal 1.`,
                  },
              ],
    );
}

export function processLines(
    lines: readonly Line[],
    processedIds: readonly string[],
): { processed: string[]; issues: FieldIssue[] } {
    const processed = new Set(processedIds);

    for (const line of lines) {
        if (!processed.has(line.id) && hasValidQuantity(line.quantity)) {
            processed.add(line.id);
        }
    }

    return {
        processed: [...processed],
        issues: validateLines(lines, [...processed]),
    };
}

export function validateAttachment(file: Attachment): FieldIssue[] {
    const issues: FieldIssue[] = [];

    if (file.size > 10 * 1024 * 1024) {
        issues.push({
            field: 'attachment',
            message:
                'Berkas melebihi batas 10 MB. Pilih berkas yang lebih kecil.',
        });
    }

    const extension = file.name.split('.').pop()?.toLowerCase();
    const supported =
        (file.type === 'application/pdf' && extension === 'pdf') ||
        (file.type === 'image/png' && extension === 'png') ||
        (file.type === 'image/jpeg' &&
            (extension === 'jpg' || extension === 'jpeg'));

    if (!supported) {
        issues.push({
            field: 'attachment',
            message:
                'Pilih berkas PDF, PNG, atau JPEG dengan format yang sesuai.',
        });
    }

    return issues;
}
