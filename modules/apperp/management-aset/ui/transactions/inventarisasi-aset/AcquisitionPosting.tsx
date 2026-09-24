import { TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { PostingCheck } from '@/components/finance/posting-check';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { api } from '../../api';
import type {
    PratinjauPosting,
    StatusPosting,
    VendorRingkas,
} from './penerimaan';

/**
 * Vendor dan jurnal perolehan pada dokumen penerimaan (feed posting finance, TODO 9.3).
 *
 * Vendor milik Core (K-06): pemilihnya membaca vendor aktif entitas legal dokumen lewat module,
 * dan dokumen hanya menyimpan id-nya. Jurnal perolehan ditampilkan sebelum penerimaan
 * diselesaikan dengan komponen pemeriksaan posting yang sama dengan layar pantau Core (K-22),
 * supaya yang dilihat di sini persis yang akan terbit.
 */

const TANPA_VENDOR = '__tanpa_vendor__';

const labelVendor = (vendor: VendorRingkas) =>
    `${vendor.number} — ${vendor.name}`;

export function VendorPicker({
    legalEntityId,
    value,
    saved,
    readOnly,
    error,
    portal,
    onChange,
}: {
    legalEntityId: string | null;
    value: string;
    /** Vendor yang tersimpan di dokumen, supaya namanya tampil sebelum pencarian apa pun. */
    saved: VendorRingkas | null;
    readOnly: boolean;
    error?: string;
    portal: RefObject<HTMLDivElement | null>;
    onChange: (vendorId: string) => void;
}) {
    const [hasil, setHasil] = useState<VendorRingkas[]>([]);
    const [dikenal, setDikenal] = useState<Record<string, VendorRingkas>>({});
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Pilihan awal, sebelum pengguna mengetik apa pun.
    useEffect(() => {
        if (readOnly || !legalEntityId) {
            return;
        }

        let dilepas = false;

        const muat = async () => {
            try {
                const response = await api<{ data: VendorRingkas[] }>(
                    `/penerimaan-aset/vendor?legal_entity_id=${encodeURIComponent(legalEntityId)}`,
                );

                if (!dilepas) {
                    setHasil(response.data);
                }
            } catch {
                // Pemilih tetap dapat dipakai lewat pencarian.
            }
        };

        void muat();

        return () => {
            dilepas = true;
        };
    }, [readOnly, legalEntityId]);

    const kenali = (vendors: VendorRingkas[]) => {
        setHasil(vendors);
        setDikenal((sebelumnya) => ({
            ...sebelumnya,
            ...Object.fromEntries(vendors.map((vendor) => [vendor.id, vendor])),
        }));
    };

    // Satu permintaan setelah pengguna berhenti mengetik, bukan satu per huruf.
    const cariNanti = (query: string) => {
        if (!legalEntityId) {
            return;
        }

        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => {
            api<{ data: VendorRingkas[] }>(
                `/penerimaan-aset/vendor?legal_entity_id=${encodeURIComponent(legalEntityId)}&q=${encodeURIComponent(query)}`,
            )
                .then((response) => kenali(response.data))
                .catch(() => setHasil([]));
        }, 250);
    };

    const sekarang =
        (value && (dikenal[value] ?? hasil.find((v) => v.id === value))) ||
        (saved && saved.id === value ? saved : null);

    const items = useMemo(() => {
        const vendors = new Map(hasil.map((vendor) => [vendor.id, vendor]));

        if (sekarang) {
            vendors.set(sekarang.id, sekarang);
        }

        return [
            { value: TANPA_VENDOR, label: 'Tanpa vendor' },
            ...[...vendors.values()].map((vendor) => ({
                value: vendor.id,
                label: labelVendor(vendor),
            })),
        ];
    }, [hasil, sekarang]);

    if (readOnly) {
        return (
            <Field>
                <Input
                    label="Vendor"
                    readOnly
                    value={sekarang ? labelVendor(sekarang) : 'Tanpa vendor'}
                />
            </Field>
        );
    }

    return (
        <Field data-invalid={Boolean(error)}>
            <Select
                label="Vendor"
                items={items}
                value={value || TANPA_VENDOR}
                placeholder="Pilih vendor"
                searchPlaceholder="Cari nomor atau nama vendor"
                emptyMessage="Vendor tidak ditemukan."
                ariaLabel="Vendor"
                portalContainer={portal}
                onSearchChange={cariNanti}
                onValueChange={(next) =>
                    onChange(next === null || next === TANPA_VENDOR ? '' : next)
                }
            />
            <FieldDescription>
                {error ??
                    'Pemasok asetnya. Wajib untuk pembelian, kecuali entitas legal ini mencatat perolehan lewat akun perantara.'}
            </FieldDescription>
        </Field>
    );
}

/** Label singkat keadaan jurnal perolehan untuk ringkasan bagian yang tertutup. */
export function labelStatusPosting(status: string | null): string {
    switch (status) {
        case 'pending':
            return 'Siap dikirim';
        case 'held':
            return 'Tertahan';
        case 'manual':
            return 'Tidak dikirim';
        case 'posted':
            return 'Sudah dibukukan';
        case 'rejected':
            return 'Ditolak';
        default:
            return 'Tanpa jurnal';
    }
}

/** Kalimat keadaan jurnal perolehan, dalam bahasa yang dipakai pengguna. */
function keadaan(
    status: string | null,
    selesai: boolean,
    posting?: StatusPosting | null,
    adaPenghalang = false,
): string {
    switch (status) {
        case 'pending':
            return selesai
                ? 'Siap diambil aplikasi finance.'
                : 'Jurnal ini siap dikirim ke aplikasi finance begitu penerimaan diselesaikan.';
        case 'held':
            if (selesai) {
                return 'Tertahan sampai masalah di bawah dibenahi. Setelah dibenahi, owner atau admin menekan Validasi ulang di layar Posting finance.';
            }

            // Selama masih ada penghalang, "tetap dapat diselesaikan" membantah kotak di atasnya.
            return adaPenghalang
                ? 'Jurnal ini akan tertahan sampai masalah di bawah dibenahi.'
                : 'Jurnal ini akan tertahan sampai masalah di bawah dibenahi. Penerimaan tetap dapat diselesaikan.';
        case 'manual':
            return 'Jurnal dicatat tetapi tidak dikirim: pengiriman posting entitas legal ini belum aktif, atau tanggalnya sebelum cutover.';
        case 'posted':
            return posting?.external_reference
                ? `Sudah dibukukan aplikasi finance (${posting.external_reference}).`
                : 'Sudah dibukukan aplikasi finance.';
        case 'rejected':
            return `Ditolak aplikasi finance${posting?.reason ? `: ${posting.reason}` : '.'}`;
        default:
            return 'Tidak ada nilai yang dijurnal.';
    }
}

/**
 * Jurnal perolehan dokumen ini: pratinjau selama draf, keadaannya sesudah diselesaikan.
 */
export function AcquisitionJournal({
    pratinjau,
    memuat,
    selesai,
    posting,
}: {
    pratinjau: PratinjauPosting | null;
    memuat: boolean;
    selesai: boolean;
    posting: StatusPosting | null;
}) {
    if (selesai) {
        return (
            <div className="space-y-3 text-sm">
                <p>{keadaan(posting?.status ?? null, true, posting)}</p>
                {(posting?.problems ?? []).map((masalah, index) => (
                    <p
                        key={`${masalah.code}-${index}`}
                        className="text-destructive"
                    >
                        Baris {masalah.line_no}: {masalah.message}
                    </p>
                ))}
            </div>
        );
    }

    if (memuat && !pratinjau) {
        return (
            <p className="text-muted-foreground text-sm">
                Menyusun pratinjau jurnal…
            </p>
        );
    }

    if (!pratinjau) {
        return (
            <p className="text-muted-foreground text-sm">
                Pratinjau jurnal belum dapat disusun.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            {pratinjau.blockers.length > 0 && (
                <div className="border-destructive/40 bg-destructive/5 space-y-2 rounded-md border px-4 py-3">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <TriangleAlert className="size-4" />
                        Belum dapat diselesaikan
                    </p>
                    {pratinjau.blockers.map((blocker) => (
                        <p key={blocker.field} className="text-sm">
                            {blocker.message}
                        </p>
                    ))}
                </div>
            )}
            <p className="text-sm">
                {keadaan(
                    pratinjau.status,
                    false,
                    null,
                    pratinjau.blockers.length > 0,
                )}
            </p>
            {pratinjau.lines.length > 0 && (
                <PostingCheck
                    lines={pratinjau.lines}
                    problems={pratinjau.problems}
                    currencyCode={pratinjau.currency?.code ?? 'IDR'}
                    currencyDecimals={pratinjau.currency?.decimals ?? 2}
                />
            )}
        </div>
    );
}
