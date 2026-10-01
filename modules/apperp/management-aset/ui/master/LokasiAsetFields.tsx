import type { RefObject } from 'react';
import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { FieldDescription } from '@apperp/ui/field';
import { api } from '../api';
import DynamicField from './DynamicField';
import type { FieldConfig, FieldValue } from './fields';
import type { MasterRecord } from './masters';

type Warisan = {
    id: string;
    nama: string | null;
    alamat?: string | null;
    diwarisi_dari: { id: string; kode: string; nama: string } | null;
};

type Saran = { id: string; nama: string; alamat: string };

const satuBaris = (alamat: string | null | undefined) =>
    (alamat ?? '').split('\n').filter(Boolean).join(', ');

/**
 * Unit kerja bawaan dan alamat lokasi aset, padanan Address pada functional location D365.
 *
 * Keduanya boleh kosong, dan kosong berarti mengikuti lokasi induk terdekat yang mengisinya. Nilai
 * warisan itu ditulis di bawah field supaya pengguna tahu apa yang berlaku tanpa membuka induknya.
 * Alamat unit kerja bawaan hanya ditawarkan sebagai saran; lokasi menyimpan alamatnya sendiri.
 */
export default function LokasiAsetFields({
    fields,
    value,
    extra,
    parentId,
    onChange,
    portalContainer,
}: {
    fields: FieldConfig[];
    value: MasterRecord | null;
    extra: Record<string, FieldValue>;
    /** Induk yang sedang dipilih di form; warisan yang disajikan server hanya sah untuk induk tersimpan. */
    parentId: string;
    onChange: (name: string, next: FieldValue) => void;
    portalContainer: RefObject<HTMLDivElement | null>;
}) {
    const departemen = String(extra.departemen_bawaan_id ?? '');
    const alamat = String(extra.alamat_id ?? '');
    const indukTetap = (value?.parent_id ?? '') === parentId;
    const saran = useSaranAlamat(alamat === '' ? departemen : '');

    const field = (name: string) =>
        fields.find((candidate) => candidate.name === name);
    const render = (name: string) => {
        const config = field(name);

        return config ? (
            <DynamicField
                config={config}
                value={extra[name]}
                onChange={(next) => onChange(name, next)}
                portalContainer={portalContainer}
            />
        ) : null;
    };
    const kosongkan = (name: string, label: string) => (
        <Button
            type="button"
            variant="link"
            size="sm"
            className="h-auto px-0"
            onClick={() => onChange(name, '')}
        >
            Kosongkan {label}
        </Button>
    );

    const unitWarisan = value?.departemen_bawaan_efektif as Warisan | null;
    const alamatWarisan = value?.alamat_efektif as Warisan | null;
    const alamatSendiri = value?.alamat as Saran | null;

    return (
        <>
            <div className="space-y-1">
                {render('departemen_bawaan_id')}
                {departemen !== ''
                    ? kosongkan('departemen_bawaan_id', 'unit kerja bawaan')
                    : warisan(
                          indukTetap,
                          unitWarisan,
                          (nilai) =>
                              nilai.nama ?? 'unit kerja yang sudah tidak ada',
                          'unit kerja',
                      )}
            </div>
            <div className="space-y-1">
                {render('alamat_id')}
                {alamat !== '' ? (
                    <>
                        {alamatSendiri?.id === alamat && (
                            <FieldDescription>
                                {satuBaris(alamatSendiri.alamat)}
                            </FieldDescription>
                        )}
                        {kosongkan('alamat_id', 'alamat')}
                    </>
                ) : (
                    <>
                        {warisan(
                            indukTetap,
                            alamatWarisan,
                            (nilai) =>
                                [nilai.nama, satuBaris(nilai.alamat)]
                                    .filter(Boolean)
                                    .join(' — '),
                            'alamat',
                        )}
                        {saran && (
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <span className="text-muted-foreground">
                                    Alamat unit kerja bawaan: {saran.nama}
                                    {saran.alamat
                                        ? ` — ${satuBaris(saran.alamat)}`
                                        : ''}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        onChange('alamat_id', saran.id)
                                    }
                                >
                                    Pakai alamat ini
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

/** Keterangan nilai yang berlaku selama field dikosongkan. */
function warisan(
    indukTetap: boolean,
    nilai: Warisan | null,
    tampil: (nilai: Warisan) => string,
    sebutan: string,
) {
    if (!indukTetap) {
        return (
            <FieldDescription>
                Kosong: {sebutan} mengikuti lokasi induk yang dipilih setelah
                disimpan.
            </FieldDescription>
        );
    }

    if (nilai?.diwarisi_dari) {
        return (
            <FieldDescription>
                Mengikuti {nilai.diwarisi_dari.nama}: {tampil(nilai)}
            </FieldDescription>
        );
    }

    return (
        <FieldDescription>
            Kosong: tidak ada lokasi induk yang mengisi {sebutan}.
        </FieldDescription>
    );
}

/** Alamat utama unit kerja yang dipilih, untuk ditawarkan sebagai alamat lokasi. */
function useSaranAlamat(unitKerjaId: string): Saran | null {
    const [hasil, setHasil] = useState<{
        unit: string;
        saran: Saran | null;
    } | null>(null);

    useEffect(() => {
        if (unitKerjaId === '') {
            return;
        }

        let dibatalkan = false;
        api<{ data: Saran[] }>(
            `/reference-data/alamat?unit_kerja_id=${encodeURIComponent(unitKerjaId)}`,
        )
            .then((result) => {
                if (!dibatalkan) {
                    setHasil({
                        unit: unitKerjaId,
                        saran: result.data[0] ?? null,
                    });
                }
            })
            .catch(() => {
                // Saran saja: gagal memuatnya tidak menghalangi apa pun.
                if (!dibatalkan) {
                    setHasil({ unit: unitKerjaId, saran: null });
                }
            });

        return () => {
            dibatalkan = true;
        };
    }, [unitKerjaId]);

    return hasil && hasil.unit === unitKerjaId ? hasil.saran : null;
}
