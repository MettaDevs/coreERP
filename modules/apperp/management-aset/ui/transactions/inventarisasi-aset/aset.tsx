import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { RefObject } from 'react';
import { FieldDescription } from '@apperp/ui/field';
import { api } from '../../api';
import DynamicField from '../../master/DynamicField';
import type { FieldConfig, FieldValue } from '../../master/fields';
import { useMasterOptions } from '../../master/useMasterOptions';
import type { AttributeDefinition } from './attributes';

/**
 * Bentuk data, konfigurasi field, dan navigasi register aset — dipakai bersama oleh
 * daftar dan halaman rincian.
 *
 * Sebelumnya semua ini tinggal di satu berkas 1376 baris bersama form yang terkurung di
 * dalam `Sheet`. Formnya dipindah ke halaman tersendiri pada 18 September 2026, mengikuti
 * bentuk halaman aset di Dynamics 365 — dan begitu ia bukan lagi overlay, bagian di
 * dalamnya bebas membuka sheet sendiri.
 */

export type Context = {
    legal_entity_id: string | null;
    org_unit_id: string | null;
    user_id: string | number | null;
};

export type Aset = {
    id: string;
    kode: string;
    nama: string;
    serial_number: string | null;
    acquisition_value: string;
    currency_code: string;
    lifecycle_state: string;
    group_aset_id?: string | null;
    jenis_aset_id?: string | null;
    lokasi_aset_id?: string | null;
};

export type RincianAset = Aset &
    Record<string, unknown> & {
        atribut: { tipe_atribut_id: string; nama: string; nilai: FieldValue }[];
    };

export type Placement = {
    id: string;
    effective_on: string;
    reason: string | null;
    receiving_org_unit_id: string | null;
    usage_org_unit_id: string | null;
    received_by_user_id: string | null;
    custodian_user_id: string | null;
    lokasi_aset_id: string | null;
};

/** Satu baris matriks group x buku, apa adanya seperti yang dikirim server. */
type MatrixRow = {
    buku_id: string;
    depreciation_profile_id: string | null;
    useful_life_periods: number | null;
    convention: string | null;
    depreciate: boolean | number;
};

/**
 * Kolom penunjuk master pada penerimaan aset.
 *
 * Group dan jenis adalah dua sumbu yang sejajar dan sama-sama wajib: group membawa
 * perlakuan finansial, jenis membawa perlakuan teknis. Tidak ada yang menyaring yang
 * lain, jadi keduanya dirender berdampingan tanpa urutan pengisian.
 */
export const CLASSIFICATION: FieldConfig[] = [
    {
        name: 'group_aset_id',
        label: 'Group aset',
        type: 'reference',
        resource: 'group-aset',
        required: true,
        help: 'Menentukan buku penyusutan mana yang dibuat untuk aset ini.',
    },
    {
        name: 'jenis_aset_id',
        label: 'Jenis aset',
        type: 'reference',
        resource: 'jenis-aset',
        required: true,
        help: 'Menentukan atribut tambahan yang harus diisi.',
    },
    {
        name: 'kondisi_aset_id',
        label: 'Kondisi aset',
        type: 'reference',
        resource: 'kondisi-aset',
    },
];

/**
 * Pabrikan dan model dipisahkan dari klasifikasi.
 *
 * Keduanya menjelaskan unit fisiknya, bukan perlakuannya, dan sering dibiarkan kosong.
 * Halaman All assets di Dynamics 365 memisahkannya dengan alasan yang sama.
 */
export const MANUFACTURER: FieldConfig[] = [
    {
        name: 'pabrikan_aset_id',
        label: 'Pabrikan',
        type: 'reference',
        resource: 'pabrikan-aset',
    },
    {
        name: 'model_aset_id',
        label: 'Model aset',
        type: 'reference',
        resource: 'model-aset',
        help: 'Katalog model per pabrikan. Kosongkan bila modelnya belum terdaftar.',
    },
];

export const PLACEMENT: FieldConfig[] = [
    {
        name: 'lokasi_aset_id',
        label: 'Lokasi aset',
        type: 'reference',
        resource: 'lokasi-aset',
        help: 'Lokasi yang dipetakan ke unit organisasi menentukan dimensi keuangan aset.',
    },
];

/**
 * Unit kerja dan orang milik Core, dirender sebagai pilihan bernama.
 *
 * Sampai 18 September 2026 keempatnya kotak teks yang meminta pengguna mengetik ULID.
 * Tidak ada orang yang hafal ULID, jadi satu-satunya cara mengisinya benar adalah
 * menyalin dari tempat lain — dan satu digit tertukar tersimpan tanpa keluhan.
 */
export const PENERIMA: FieldConfig[] = [
    {
        name: 'receiving_org_unit_id',
        label: 'Unit penerima',
        type: 'reference',
        resource: 'reference-data/unit-kerja',
        help: 'Unit kerja yang menerima barangnya saat diserahkan.',
    },
    {
        name: 'usage_org_unit_id',
        label: 'Unit pengguna',
        type: 'reference',
        resource: 'reference-data/unit-kerja',
        help: 'Unit kerja yang menanggung aset ini setelah diterima.',
    },
    {
        name: 'received_by_user_id',
        label: 'Diterima oleh',
        type: 'reference',
        resource: 'reference-data/anggota',
    },
    {
        name: 'custodian_user_id',
        label: 'Penanggung jawab',
        type: 'reference',
        resource: 'reference-data/anggota',
    },
];

export const REFERENCES = [...CLASSIFICATION, ...MANUFACTURER, ...PLACEMENT];

/**
 * Yang boleh dikoreksi setelah aset diterima.
 *
 * Group aset tidak ada di sini: buku penyusutan sudah dibentuk dari matriksnya, jadi
 * menggantinya akan membuat buku yang berjalan tidak lagi cocok dengan groupnya.
 */
export const EDITABLE = REFERENCES.filter(
    (field) =>
        field.name !== 'group_aset_id' && field.name !== 'lokasi_aset_id',
);

/** Nilai awal seluruh isian teks pada layar aset. */
export const textDefaults = (): Record<string, string> => ({
    nama: '',
    serial_number: '',
    model_number: '',
    acquired_on: '',
    placed_in_service_on: '',
    acquisition_value: '',
    residual_value: '',
    currency_code: 'IDR',
    keterangan: '',
});

/** Nilai awal penunjuk unit dan orang, diambil dari konteks aktif. */
export const referenceDefaults = (
    context: Context,
): Record<string, FieldValue> => ({
    ...Object.fromEntries(REFERENCES.map((field) => [field.name, ''])),
    receiving_org_unit_id: context.org_unit_id ?? '',
    usage_org_unit_id: context.org_unit_id ?? '',
    received_by_user_id:
        context.user_id === null ? '' : String(context.user_id),
    custodian_user_id: '',
});

/** Seluruh penunjuk yang dikirim saat penerimaan, termasuk unit dan orang. */
export const PENERIMAAN_REFERENCES = [...REFERENCES, ...PENERIMA];

/**
 * Nilai uang dibaca manusia, bukan mesin.
 *
 * Server mengirim desimal sebagai string supaya presisinya tidak hilang di float, dan
 * bentuk mentahnya — `8500000000.00` — praktis tidak terbaca pada kolom yang rata kanan.
 */
export function money(value: string, currency: string): string {
    const amount = Number(value);

    if (!Number.isFinite(amount)) {
        return `${currency} ${value}`;
    }

    const formatted = new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
        maximumFractionDigits: 2,
    }).format(amount);

    return `${currency} ${formatted}`;
}

export const LIFECYCLE: Record<
    string,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
> = {
    received: { label: 'Diterima', variant: 'outline' },
    // Tidak ditulis lagi sejak 17 September 2026; labelnya dipertahankan supaya baris
    // lama yang terlanjur bernilai ini tetap terbaca.
    in_use: { label: 'Digunakan', variant: 'default' },
    decommissioned: { label: 'Didekomisioning', variant: 'secondary' },
    disposed: { label: 'Dilepas', variant: 'secondary' },
};

const CONVENTION_LABEL: Record<string, string> = {
    full_month: 'bulan perolehan penuh',
    mid_month_1st: 'tengah bulan (awal bulan)',
    mid_month_15th: 'tengah bulan (tanggal 15)',
    mid_quarter: 'tengah kuartal',
    half_year: 'setengah tahun',
    half_year_start_of_year: 'setengah tahun (mulai awal tahun)',
    half_year_next_year: 'setengah tahun (mulai tahun depan)',
};

/**
 * Sekelompok field penunjuk yang seluruhnya membuka daftar pilihannya di wadah yang sama.
 *
 * Ditulis sebagai komponen, bukan fungsi pembantu, karena ref wadah portal harus
 * berpindah lewat props JSX: ref tidak boleh dibaca saat render, dan melewatkannya
 * sebagai argumen fungsi membuatnya tidak dapat dibedakan dari pembacaan.
 */
export function ReferenceFields({
    fields,
    portal,
    references,
    onChange,
}: {
    fields: FieldConfig[];
    portal?: RefObject<HTMLDivElement | null>;
    references: Record<string, FieldValue>;
    onChange: (name: string, next: FieldValue) => void;
}) {
    return fields.map((field) => (
        <DynamicField
            key={field.name}
            config={field}
            value={references[field.name]}
            onChange={(next) => onChange(field.name, next)}
            portalContainer={portal}
        />
    ));
}

/** Dipakai bersama saat jenis asetnya belum punya definisi atribut termuat. */
export const TANPA_ATRIBUT: AttributeDefinition[] = [];

function useGroupBooks(groupId: string) {
    const [muatan, setMuatan] = useState<{
        groupId: string;
        rows: MatrixRow[] | null;
    } | null>(null);

    useEffect(() => {
        if (!groupId) {
            return;
        }

        let cancelled = false;
        api<{ data: MatrixRow[] }>(`/group-aset/${groupId}/buku-penyusutan`)
            .then((result) => {
                if (!cancelled) {
                    setMuatan({ groupId, rows: result.data });
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setMuatan({ groupId, rows: null });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [groupId]);

    return muatan?.groupId === groupId ? muatan.rows : null;
}

/**
 * Ringkasan buku penyusutan yang akan terbentuk, dibaca dari matriks group x buku.
 *
 * Sengaja hanya menampilkan, tidak meminta apa pun. Buku aset tidak pernah diisi tangan:
 * ia lahir dari matriks saat aset diterima.
 */
export function DepreciationPreview({ groupId }: { groupId: string }) {
    const rows = useGroupBooks(groupId);
    const { options: books } = useMasterOptions(
        groupId ? 'buku-penyusutan' : null,
    );
    const { options: profiles } = useMasterOptions(
        groupId ? 'profil-penyusutan' : null,
    );

    if (!groupId) {
        return (
            <p className="text-muted-foreground text-sm">
                Pilih group aset terlebih dahulu untuk melihat buku yang akan
                terbentuk.
            </p>
        );
    }

    if (rows === null) {
        return (
            <p className="text-muted-foreground text-sm">
                Konfigurasi buku group ini belum dapat dibaca.
            </p>
        );
    }

    if (rows.length === 0) {
        return (
            <p className="text-destructive text-sm">
                Group ini belum memiliki baris pada matriks group x buku. Aset
                masih dapat dicatat, tetapi bukunya tidak akan terbentuk sampai
                matriksnya diisi.
            </p>
        );
    }

    return (
        <div className="space-y-2">
            {rows.map((row) => {
                const book = books.find((option) => option.id === row.buku_id);
                const profile = profiles.find(
                    (option) => option.id === row.depreciation_profile_id,
                );
                const life =
                    row.useful_life_periods ??
                    (profile?.useful_life_periods as number | undefined);
                const convention = row.convention
                    ? (CONVENTION_LABEL[row.convention] ?? row.convention)
                    : null;
                const detail = row.depreciate
                    ? [
                          profile?.nama,
                          life ? `${life} periode` : null,
                          convention,
                      ]
                          .filter(Boolean)
                          .join(' · ')
                    : 'Tidak disusutkan';

                return (
                    <div
                        key={row.buku_id}
                        className="flex items-baseline justify-between gap-3 rounded-md border px-3 py-2"
                    >
                        <span className="text-sm font-medium">
                            {book ? book.nama : 'Buku penyusutan'}
                        </span>
                        <span className="text-muted-foreground truncate text-sm">
                            {detail || 'Aturan diambil dari buku'}
                        </span>
                    </div>
                );
            })}
            <FieldDescription>
                Buku dibentuk otomatis saat aset disimpan. Aturannya disalin,
                jadi perubahan matriks kelak tidak mengubah aset ini.
            </FieldDescription>
        </div>
    );
}

/**
 * Rincian punya alamatnya sendiri, sama seperti work order dan mutasi.
 *
 * Karena itu tombol kembali peramban, muat ulang, dan tautan yang disalin ke rekan kerja
 * semuanya mendarat di aset yang sama, bukan di daftar.
 */
export const RESOURCE = 'inventarisasi-aset';
const alamat = (...ruas: string[]) =>
    ['/management-aset', RESOURCE, ...ruas].join('/');
export const bukaDaftar = () => router.visit(alamat());
export const bukaAset = (id: string) => router.visit(alamat(id));
export const bukaAsetUbah = (id: string) => router.visit(alamat(id, 'ubah'));
