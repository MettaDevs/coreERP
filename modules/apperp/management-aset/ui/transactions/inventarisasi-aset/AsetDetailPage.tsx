import { usePage } from '@inertiajs/react';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import type { ChangeHistoryPage } from '@/components/change-history';
import { ChangeHistory } from '@/components/change-history';
import { RecordFactBox } from '@/components/record-fact-box';
import { useToday } from '@/hooks/use-work-date';
import type { Workspace } from '@/types';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DatePicker } from '@apperp/ui/date-picker';
import { FieldError } from '@apperp/ui/field';

import { FormControl } from '@apperp/ui/form-control';
import { getFormValidationErrors } from '@apperp/ui/form-feedback';
import { Input } from '@apperp/ui/input';
import { usePageDialogRecord } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';

import { Textarea } from '@apperp/ui/textarea';
import EditShield from '../../_shared/EditShield';
import { RecordLookup } from '../../_shared/RecordLookup';
import { ApiError, api, errorMessage } from '../../api';
import DynamicField from '../../master/DynamicField';
import type { FieldValue } from '../../master/fields';
import { emptyValue, payloadValue } from '../../master/fields';
import { useMasterOptions } from '../../master/useMasterOptions';
import { useRecordOptions } from '../../use-record-list';
import { useSaveFeedback } from '../../use-save-feedback';
import { RecordCardHeader } from '../_shared/record-card';
import type { RecordCardControls } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import AssetDowntimeSection from '../downtime/AssetDowntimeSection';
import AssetInsuranceSection from '../insurance/AssetInsuranceSection';
import AssetWarrantySection from '../warranty/AssetWarrantySection';
import {
    AcquisitionAdjustmentPreview,
    pesanKoreksi,
} from './AcquisitionAdjustmentPreview';
import type { HasilKoreksi } from './AcquisitionAdjustmentPreview';
import type { Aset, RincianAset, Context, Placement } from './aset';
import {
    CLASSIFICATION,
    DepreciationPreview,
    EDITABLE,
    LIFECYCLE,
    MANUFACTURER,
    PENERIMAAN_REFERENCES,
    ReferenceFields,
    TANPA_ATRIBUT,
    bukaAset as navigateView,
    bukaAsetUbah as navigateEdit,
    bukaDaftar as navigateClose,
    money,
    referenceDefaults,
    textDefaults,
} from './aset';
import AssetPicture from './AssetPicture';
import type { AttributeDefinition } from './attributes';
import { toFieldConfig } from './attributes';
import { printAssetLabels } from './labels';
import { bukaPenerimaan } from './penerimaan';

type Mode = 'view' | 'edit';

/**
 * Rincian satu aset, pada halaman penuh dengan FastTab.
 *
 * Bentuknya mengikuti halaman aset di Dynamics 365: satu halaman dengan seksi terlipat,
 * bukan panel geser. Sampai 18 September 2026 form ini terkurung di dalam `Sheet`, dan
 * itu yang menahannya: sebuah sheet tidak boleh membuka sheet lain, sehingga seksi di
 * dalamnya tidak pernah bisa punya pemilih sendiri.
 */
export default function AsetDetailPage({
    context,
    canUpdate,
    permissions,
    asetId,
    mode,
    onClose,
    onSaved,
    onRequestView,
    onRequestEdit,
    onBusyChange,
    onDirtyChange,
    portalContainer,
}: {
    context: Context;
    canUpdate: boolean;
    /** Izin pengguna; bagian asuransi, garansi, dan downtime tampil hanya bila boleh dibaca. */
    permissions: string[];
    asetId?: string;
    mode: Mode;
} & RecordCardControls) {
    const [feedbackSections, setFeedbackSections] = useState<string[]>([
        'klasifikasi',
        'perolehan',
    ]);

    const {
        scopeRef: feedbackScopeRef,
        feedbackSummary,
        reportSaveError,
        clearSaveFeedback,
        fieldError,
    } = useSaveFeedback(undefined, asetId, () =>
        setFeedbackSections([
            'klasifikasi',
            'pabrikan',
            'bukti-perolehan',
            'atribut',
            'perolehan',
            'penyusutan',
            'penempatan',
            'struktur',
            'riwayat',
            'asuransi',
            'garansi',
            'downtime',
            'riwayat-perubahan',
            'catatan',
        ]),
    );

    const embedded = usePageDialogRecord(undefined);
    const bodyRef = useRef<HTMLDivElement>(null);

    const bukaAset = (id: string) => (onRequestView ?? navigateView)(id);
    const bukaAsetUbah = (id: string) => (onRequestEdit ?? navigateEdit)(id);
    const bukaDaftar = () => (onClose ?? navigateClose)();

    const { workspace } = usePage<{ workspace: Workspace }>().props;
    // Tanggal koreksi nilai perolehan: hari ini menurut zona pengguna, dari jam server (K-34).
    const hariIni = useToday();
    const [detail, setDetail] = useState<RincianAset | null>(null);
    const [memuat, setMemuat] = useState(true);
    const [menyimpan, setMenyimpan] = useState(false);
    const [mainDirty, setMainDirty] = useState(false);
    const [auxiliaryDirty, setAuxiliaryDirty] = useState(false);
    useEffect(() => {
        onBusyChange?.(menyimpan);

        return () => onBusyChange?.(false);
    }, [menyimpan, onBusyChange]);
    const [references, setReferences] = useState<Record<string, FieldValue>>(
        () => referenceDefaults(context),
    );
    // Seluruh isian dikendalikan state, bukan dibaca dari FormData saat submit. Bagian
    // yang terlipat dilepas dari DOM oleh accordion, sehingga isian tak terkendali akan
    // hilang begitu penggunanya menutup bagiannya.
    const [values, setValues] = useState<Record<string, string>>(() =>
        textDefaults(),
    );
    const [parentAsetId, setParentAsetId] = useState('');
    // Atribut diwarisi dari jenis aset, jadi definisinya dibaca ulang tiap jenis berubah.
    const [attributesMuatan, setAttributesMuatan] = useState<{
        typeId: string;
        items: AttributeDefinition[];
    } | null>(null);
    const [attributeValues, setAttributeValues] = useState<
        Record<string, FieldValue>
    >({});
    const [placements, setPlacements] = useState<Placement[]>([]);
    const [alasanKoreksi, setAlasanKoreksi] = useState('');

    const readOnly = mode === 'view';
    // Nilai perolehan yang berubah menerbitkan jurnal koreksi (TODO 12): alasannya wajib, dan
    // pratinjau jurnalnya tampil sebelum disimpan (K-36).
    const nilaiBerubah =
        !readOnly &&
        detail !== null &&
        values.acquisition_value.trim() !== '' &&
        Number(values.acquisition_value) !== Number(detail.acquisition_value);
    const typeId = String(references.jenis_aset_id ?? '');
    const groupId = String(references.group_aset_id ?? '');
    const attributes =
        attributesMuatan?.typeId === typeId
            ? attributesMuatan.items
            : TANPA_ATRIBUT;

    const { options: locationOptions } = useMasterOptions(
        'lokasi-aset',
        '',
        [
            String(references.lokasi_aset_id ?? ''),
            ...placements.map((item) => item.lokasi_aset_id ?? ''),
        ].filter(Boolean),
    );
    const { options: unitOptions } = useMasterOptions(
        'reference-data/unit-kerja',
        '',
        [
            String(references.usage_org_unit_id ?? ''),
            ...placements.map((item) => item.usage_org_unit_id ?? ''),
        ].filter(Boolean),
    );
    const { options: anggotaOptions } = useMasterOptions(
        'reference-data/anggota',
        '',
        [
            String(references.custodian_user_id ?? ''),
            ...placements.map((item) => item.custodian_user_id ?? ''),
        ].filter(Boolean),
    );

    // Daftar aset hanya dibutuhkan untuk memilih induk, dan itu tidak berlaku saat
    // membaca: menariknya di mode baca berarti satu permintaan untuk kotak yang tidak
    // pernah dibuka.

    const assetSource = useRecordOptions<Aset>(
        '/aset',
        (item) => (item.nama ? `${item.kode} \u00b7 ${item.nama}` : item.kode),
        parentAsetId,
    );
    const aset = assetSource.options;

    useEffect(() => {
        if (!asetId) {
            return;
        }

        let dibatalkan = false;
        api<{ data: RincianAset }>(`/aset/${asetId}`)
            .then((result) => {
                if (dibatalkan) {
                    return;
                }

                const isi = result.data;
                setDetail(isi);
                setReferences(
                    Object.fromEntries(
                        PENERIMAAN_REFERENCES.map((field) => [
                            field.name,
                            String(isi[field.name] ?? ''),
                        ]),
                    ),
                );
                setValues({
                    ...textDefaults(),
                    nama: isi.nama,
                    serial_number: String(isi.serial_number ?? ''),
                    model_number: String(isi.model_number ?? ''),
                    acquired_on: String(isi.acquired_on ?? '').slice(0, 10),
                    placed_in_service_on: String(
                        isi.placed_in_service_on ?? '',
                    ).slice(0, 10),
                    acquisition_value: String(isi.acquisition_value ?? ''),
                    currency_code: String(isi.currency_code ?? 'IDR'),
                    keterangan: String(isi.keterangan ?? ''),
                });
                setParentAsetId(String(isi.induk_aset_id ?? ''));
                setAttributeValues(
                    Object.fromEntries(
                        isi.atribut.map((row) => [
                            row.tipe_atribut_id,
                            row.nilai ?? '',
                        ]),
                    ),
                );
            })
            .catch((caught) =>
                toast.error(errorMessage(caught, 'Aset belum dapat dibuka.')),
            )
            .finally(() => {
                if (!dibatalkan) {
                    setMemuat(false);
                }
            });

        return () => {
            dibatalkan = true;
        };
    }, [asetId, context]);

    // Riwayat perubahan dibaca lewat rute module, yang memeriksa hak dan cakupan atas aset ini.
    const loadChangeHistory = useCallback(
        (page: number) =>
            api<ChangeHistoryPage>(
                `/aset/${asetId}/riwayat-perubahan?page=${page}`,
            ),
        [asetId],
    );

    // Riwayat penempatan hanya dibaca pada mode baca; ia bukan isian dan tidak pernah
    // ikut disimpan.
    useEffect(() => {
        if (!asetId || !readOnly) {
            return;
        }

        api<{ data: { aset: Aset; placements: Placement[] } }>(
            `/aset/${asetId}/history`,
        )
            .then((result) => setPlacements(result.data.placements))
            .catch(() => setPlacements([]));
    }, [asetId, readOnly]);

    useEffect(() => {
        if (!typeId) {
            return;
        }

        let cancelled = false;
        api<{ data: AttributeDefinition[] }>(
            `/jenis-aset/${typeId}/atribut-definisi`,
        )
            .then((result) => {
                if (cancelled) {
                    return;
                }

                setAttributesMuatan({ typeId, items: result.data });
                // Nilai yang sudah ada dipertahankan, bukan ditimpa kosong. Saat membuka
                // aset, definisinya baru selesai dimuat setelah nilainya dipasang;
                // menimpa di sini menghapus isian yang barusan dibaca dari server.
                setAttributeValues((current) =>
                    Object.fromEntries(
                        result.data.map((definition) => [
                            definition.tipe_atribut_id,
                            current[definition.tipe_atribut_id] ??
                                emptyValue(toFieldConfig(definition)),
                        ]),
                    ),
                );
            })
            .catch(() => {
                if (!cancelled) {
                    setAttributesMuatan({ typeId, items: [] });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [typeId]);

    const parentOptions = useMemo(
        () =>
            aset
                .filter((aset) => aset.id !== asetId)
                .map((aset) => ({
                    id: aset.id,
                    label: `${aset.kode} — ${aset.nama}`,
                })),
        [aset, asetId],
    );

    const setValue = (name: string, next: string) =>
        setValues((current) => ({ ...current, [name]: next }));
    const ubahReferensi = (name: string, next: FieldValue) =>
        setReferences((current) => ({ ...current, [name]: next }));
    const namaDari = (options: { id: string; nama: string }[], id: unknown) =>
        options.find((option) => option.id === String(id ?? ''))?.nama ?? null;

    const attributePayload = () =>
        attributes.map((definition) => ({
            tipe_atribut_id: definition.tipe_atribut_id,
            nilai: payloadValue(
                toFieldConfig(definition),
                attributeValues[definition.tipe_atribut_id],
            ),
        }));

    async function simpan() {
        clearSaveFeedback();
        const validationErrors = bodyRef.current
            ? getFormValidationErrors(bodyRef.current)
            : {};

        if (Object.keys(validationErrors).length) {
            reportSaveError(
                new ApiError(
                    'Periksa isian yang ditandai.',
                    validationErrors,
                    422,
                ),
                'Data belum dapat disimpan.',
            );

            return;
        }

        setMenyimpan(true);

        try {
            const jawab = await api<{
                data: { adjustment: HasilKoreksi; version: number };
            }>(`/aset/${asetId}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    version: detail?.version,
                    ...Object.fromEntries(
                        EDITABLE.map((field) => [
                            field.name,
                            payloadValue(field, references[field.name]),
                        ]),
                    ),
                    nama: values.nama,
                    induk_aset_id: parentAsetId || null,
                    serial_number: values.serial_number || null,
                    model_number: values.model_number || null,
                    placed_in_service_on: values.placed_in_service_on || null,
                    keterangan: values.keterangan || null,
                    atribut: attributePayload(),
                    // Hanya dikirim bila berubah: nilai yang sama tidak menerbitkan apa pun.
                    ...(nilaiBerubah
                        ? {
                              acquisition_value: values.acquisition_value,
                              reason: alasanKoreksi,
                              adjustment_date: hariIni,
                          }
                        : {}),
                }),
            });
            setDetail((sebelumnya) =>
                sebelumnya
                    ? { ...sebelumnya, version: jawab.data.version }
                    : sebelumnya,
            );
            clearSaveFeedback();
            toast.success(pesanKoreksi(jawab.data.adjustment));
            (onSaved ?? bukaAset)(String(asetId));
        } catch (caught) {
            reportSaveError(caught, 'Koreksi belum dapat disimpan.');
        } finally {
            setMenyimpan(false);
        }
    }

    const textField = (
        name: string,
        label: string,
        extra: Record<string, unknown> = {},
    ) => (
        <FormControl>
            <Input
                label={label}
                readOnly={readOnly}
                value={values[name] ?? ''}
                onChange={(event) => setValue(name, event.target.value)}
                {...extra}
            />
        </FormControl>
    );

    /** Kelompok penunjuk; pada mode baca ia dibungkus perisai, bukan dinonaktifkan. */
    const rujukan = (fields: typeof CLASSIFICATION, label: string) => {
        const isi = (
            <ReferenceFields
                portal={portalContainer}
                fields={fields}
                references={references}
                onChange={ubahReferensi}
            />
        );

        return readOnly ? (
            <EditShield
                active
                label={label}
                onActivate={() => asetId && bukaAsetUbah(asetId)}
            >
                {isi}
            </EditShield>
        ) : (
            isi
        );
    };

    const attributeFields = attributes.map((definition) => (
        <DynamicField
            portalContainer={portalContainer}
            key={definition.tipe_atribut_id}
            config={toFieldConfig(definition)}
            value={attributeValues[definition.tipe_atribut_id]}
            onChange={(next) =>
                setAttributeValues((current) => ({
                    ...current,
                    [definition.tipe_atribut_id]: next,
                }))
            }
        />
    ));

    useRecordCardDirty({
        value: { values, references, parentAsetId, alasanKoreksi },
        ready: !memuat,
        scopeKey: JSON.stringify([asetId, mode, detail?.version]),
        onDirtyChange: setMainDirty,
    });
    useRecordCardDirty({
        value: attributeValues,
        ready: !typeId || attributesMuatan?.typeId === typeId,
        scopeKey: JSON.stringify([asetId, mode, detail?.version, typeId]),
        onDirtyChange: setAuxiliaryDirty,
    });
    useEffect(() => {
        onDirtyChange?.(mainDirty || auxiliaryDirty);

        return () => onDirtyChange?.(false);
    }, [mainDirty, auxiliaryDirty, onDirtyChange]);

    if (memuat) {
        return (
            <div className="text-muted-foreground p-5 text-sm">
                Memuat aset…
            </div>
        );
    }

    const legalEntityName = workspace.legal_entities.find(
        (entity) => entity.id === detail?.legal_entity_id,
    )?.name;
    const judul = [legalEntityName, `${detail?.kode} — ${detail?.nama}`]
        .filter(Boolean)
        .join(' · ');
    const status = detail ? LIFECYCLE[detail.lifecycle_state] : undefined;
    const sudahDilepas = detail?.lifecycle_state === 'disposed';

    return (
        <div
            ref={feedbackScopeRef}
            className="flex h-full min-h-0 flex-col overflow-hidden"
        >
            <RecordCardHeader
                recordTitle={
                    detail ? `${detail.kode} · ${detail.nama}` : 'Baru'
                }
                editing={!readOnly}
                recordUrl={
                    asetId
                        ? `/management-aset/inventarisasi-aset/${asetId}`
                        : undefined
                }
                onEdit={
                    readOnly && canUpdate && !sudahDilepas
                        ? () => bukaAsetUbah(String(asetId))
                        : undefined
                }
            />
            <RecordActionBar
                feedback={feedbackSummary}
                title={embedded ? undefined : judul}
                trailing={
                    status ? (
                        <Badge variant={status.variant}>{status.label}</Badge>
                    ) : undefined
                }
            >
                {readOnly && canUpdate && !sudahDilepas && !embedded && (
                    <ActionButton
                        action="edit"
                        type="button"
                        onClick={() => bukaAsetUbah(String(asetId))}
                    />
                )}
                {readOnly && asetId && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => printAssetLabels({ ids: [asetId] })}
                        title="Cetak label berkode QR untuk ditempel pada aset ini"
                    >
                        Cetak label
                    </Button>
                )}
                {readOnly && !embedded && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={bukaDaftar}
                    >
                        Kembali ke daftar
                    </Button>
                )}
                {!readOnly && (
                    <>
                        <ActionButton
                            action="save"
                            type="button"
                            onClick={() => void simpan()}
                            disabled={
                                menyimpan ||
                                (nilaiBerubah && !alasanKoreksi.trim())
                            }
                            pending={menyimpan}
                        />
                        <ActionButton
                            action="cancel"
                            disabled={menyimpan}
                            type="button"
                            onClick={() =>
                                asetId ? bukaAset(asetId) : bukaDaftar()
                            }
                        />
                    </>
                )}
            </RecordActionBar>

            <div
                ref={bodyRef}
                data-page-dialog-body
                className="min-h-0 flex-1 overflow-y-auto"
            >
                <RecordFactBox
                    className="p-5"
                    key={asetId}
                    recordType="aset_tr_aset"
                    recordId={asetId}
                    details={asetId ? <AssetPicture assetId={asetId} /> : null}
                >
                    {mode === 'edit' && (
                        <p className="text-muted-foreground text-sm">
                            Group aset dan lokasi tidak dapat diganti di sini.
                            Buku penyusutannya sudah terbentuk dari matriks
                            group, dan perpindahan lokasi dicatat sebagai
                            mutasi, bukan koreksi.
                        </p>
                    )}

                    <CollapsibleSectionGroup
                        className="min-w-0"
                        value={feedbackSections}
                        onValueChange={setFeedbackSections}
                    >
                        <CollapsibleSection
                            value="klasifikasi"
                            title="Identitas dan klasifikasi"
                            summary={
                                namaDari(
                                    locationOptions,
                                    references.lokasi_aset_id,
                                ) ?? undefined
                            }
                        >
                            <div className="space-y-4">
                                {textField('nama', 'Nama aset', {
                                    maxLength: 150,
                                    required: true,
                                })}
                                {rujukan(
                                    CLASSIFICATION.filter((field) =>
                                        EDITABLE.some(
                                            (item) => item.name === field.name,
                                        ),
                                    ),
                                    'klasifikasi',
                                )}
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="pabrikan"
                            title="Pabrikan dan unit"
                            summary={values.serial_number || undefined}
                        >
                            <div className="space-y-4">
                                {rujukan(MANUFACTURER, 'pabrikan')}
                                {textField('serial_number', 'Nomor seri')}
                                {textField('model_number', 'Nomor model')}
                            </div>
                        </CollapsibleSection>

                        {detail?.penerimaan_aset_id &&
                            permissions.includes(
                                'management-aset.penerimaan-aset.read',
                            ) && (
                                <CollapsibleSection
                                    value="bukti-perolehan"
                                    title="Bukti perolehan"
                                    summary="Dokumen penerimaan asal"
                                >
                                    <div className="space-y-3">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                bukaPenerimaan(
                                                    String(
                                                        detail.penerimaan_aset_id,
                                                    ),
                                                )
                                            }
                                        >
                                            Buka penerimaan asal
                                        </Button>
                                        <p className="text-muted-foreground text-sm">
                                            Buka penerimaan untuk melihat nomor
                                            PO dan dokumen pendukung perolehan
                                            aset ini.
                                        </p>
                                    </div>
                                </CollapsibleSection>
                            )}

                        {attributes.length > 0 && (
                            <CollapsibleSection
                                value="atribut"
                                title="Atribut jenis aset"
                                summary={`${attributes.length} atribut`}
                            >
                                <div className="space-y-4">
                                    {readOnly ? (
                                        <EditShield
                                            active
                                            label="atribut"
                                            onActivate={() =>
                                                asetId && bukaAsetUbah(asetId)
                                            }
                                        >
                                            {attributeFields}
                                        </EditShield>
                                    ) : (
                                        attributeFields
                                    )}
                                </div>
                            </CollapsibleSection>
                        )}

                        <CollapsibleSection
                            value="perolehan"
                            title="Perolehan dan nilai"
                            summary={
                                values.acquisition_value
                                    ? money(
                                          values.acquisition_value,
                                          values.currency_code,
                                      )
                                    : undefined
                            }
                        >
                            <div className="space-y-4">
                                {/* Tanggal perolehan milik dokumen penerimaan dan tidak dikoreksi
                                    di sini. Nilai perolehan boleh dikoreksi selama belum ada
                                    periode penyusutan; koreksinya menerbitkan jurnal koreksi ke
                                    aplikasi finance (TODO 12). */}
                                <FormControl
                                    data-error-field="acquired_on"
                                    error={fieldError('acquired_on')}
                                >
                                    <DatePicker
                                        label="Tanggal perolehan"
                                        value={values.acquired_on ?? ''}
                                        readOnly
                                        portalContainer={portalContainer}
                                    />
                                </FormControl>
                                <FormControl
                                    data-error-field="placed_in_service_on"
                                    error={fieldError('placed_in_service_on')}
                                    hint={
                                        <>
                                            Dasar perhitungan awal penyusutan.
                                            Kosong berarti sama dengan tanggal
                                            perolehan.
                                        </>
                                    }
                                >
                                    <DatePicker
                                        label="Tanggal mulai digunakan"
                                        portalContainer={portalContainer}
                                        readOnly={readOnly}
                                        value={values.placed_in_service_on}
                                        onValueChange={(value) =>
                                            setValue(
                                                'placed_in_service_on',
                                                value,
                                            )
                                        }
                                    />
                                </FormControl>
                                <FormControl
                                    data-error-field="acquisition_value"
                                    error={fieldError('acquisition_value')}
                                >
                                    <Input
                                        label="Nilai perolehan"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        readOnly={readOnly}
                                        value={values.acquisition_value}
                                        onChange={(event) =>
                                            setValue(
                                                'acquisition_value',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {!readOnly && (
                                        <FieldError>
                                            Mengubahnya menerbitkan jurnal
                                            koreksi ke aplikasi finance. Hanya
                                            dapat diubah sebelum ada periode
                                            penyusutan.
                                        </FieldError>
                                    )}
                                </FormControl>
                                {nilaiBerubah && asetId && (
                                    <AcquisitionAdjustmentPreview
                                        asetId={asetId}
                                        after={values.acquisition_value}
                                        currencyCode={values.currency_code}
                                        reason={alasanKoreksi}
                                        onReasonChange={setAlasanKoreksi}
                                    />
                                )}
                                {textField('currency_code', 'Mata uang', {
                                    maxLength: 3,
                                    readOnly: true,
                                })}
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="penyusutan"
                            title="Penyusutan"
                        >
                            <DepreciationPreview groupId={groupId} />
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="penempatan"
                            title="Penempatan"
                            summary={
                                namaDari(
                                    unitOptions,
                                    references.usage_org_unit_id,
                                ) ?? undefined
                            }
                        >
                            <div className="space-y-4">
                                {/* Sesudah aset diterima, penempatan berpindah lewat
                                    dokumen mutasi — bukan lewat koreksi. Yang tampil di
                                    sini keadaan sekarang, dan namanya, bukan idnya. */}
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    {[
                                        [
                                            'Lokasi',
                                            namaDari(
                                                locationOptions,
                                                references.lokasi_aset_id,
                                            ),
                                        ],
                                        [
                                            'Unit pengguna',
                                            namaDari(
                                                unitOptions,
                                                references.usage_org_unit_id,
                                            ),
                                        ],
                                        [
                                            'Penanggung jawab',
                                            namaDari(
                                                anggotaOptions,
                                                references.custodian_user_id,
                                            ),
                                        ],
                                    ].map(([label, isi]) => (
                                        <div key={label}>
                                            <dt className="text-muted-foreground text-xs">
                                                {label}
                                            </dt>
                                            <dd className="text-sm">
                                                {isi ?? 'Belum diisi'}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="struktur"
                            title="Struktur"
                            summary={
                                parentOptions.find(
                                    (option) => option.id === parentAsetId,
                                )?.label
                            }
                        >
                            {readOnly ? (
                                <p className="text-muted-foreground text-sm">
                                    {parentOptions.find(
                                        (option) => option.id === parentAsetId,
                                    )?.label ?? 'Tanpa aset induk'}
                                </p>
                            ) : (
                                <FormControl
                                    hint={
                                        <>
                                            Isi bila aset ini bagian dari aset
                                            lain, misalnya mesin yang terpasang
                                            pada satu gedung.
                                        </>
                                    }
                                >
                                    <RecordLookup
                                        portalContainer={portalContainer}
                                        source={assetSource}
                                        label="Aset induk"
                                        items={parentOptions.map((record) => ({
                                            value: record.id,
                                            label: record.label,
                                        }))}
                                        value={parentAsetId || null}
                                        placeholder="Tanpa induk"
                                        searchPlaceholder="Cari aset induk"
                                        emptyMessage="Aset tidak ditemukan."
                                        ariaLabel="Pilih aset induk"
                                        onValueChange={(item) =>
                                            setParentAsetId(item ?? '')
                                        }
                                        filterKey={asetId ?? ''}
                                        filterRecord={(record) =>
                                            record.id !== asetId
                                        }
                                    />
                                </FormControl>
                            )}
                        </CollapsibleSection>

                        {readOnly && (
                            <CollapsibleSection
                                value="riwayat"
                                title="Riwayat penempatan"
                                summary={
                                    placements.length
                                        ? `${placements.length} perpindahan`
                                        : undefined
                                }
                            >
                                {placements.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        Belum ada perpindahan selain penerimaan.
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        {placements.map((placement) => (
                                            <div
                                                className="rounded-md border px-3 py-2"
                                                key={placement.id}
                                            >
                                                <p className="text-sm font-medium">
                                                    {placement.effective_on}
                                                    <span className="text-muted-foreground ml-2 font-normal">
                                                        {placement.reason ||
                                                            'Penempatan aset'}
                                                    </span>
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    {[
                                                        namaDari(
                                                            locationOptions,
                                                            placement.lokasi_aset_id,
                                                        ),
                                                        namaDari(
                                                            unitOptions,
                                                            placement.usage_org_unit_id,
                                                        ),
                                                        namaDari(
                                                            anggotaOptions,
                                                            placement.custodian_user_id,
                                                        ),
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ') ||
                                                        'Tanpa lokasi, unit, atau PIC'}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CollapsibleSection>
                        )}

                        {readOnly &&
                            asetId &&
                            permissions.includes(
                                'management-aset.polis-asuransi.read',
                            ) && (
                                <CollapsibleSection
                                    value="asuransi"
                                    title="Asuransi"
                                >
                                    <AssetInsuranceSection asetId={asetId} />
                                </CollapsibleSection>
                            )}

                        {readOnly &&
                            asetId &&
                            (permissions.includes(
                                'management-aset.garansi-aset.read',
                            ) ||
                                permissions.includes(
                                    'management-aset.kontrak-servis.read',
                                )) && (
                                <CollapsibleSection
                                    value="garansi"
                                    title="Garansi dan kontrak servis"
                                >
                                    <AssetWarrantySection
                                        asetId={asetId}
                                        canReadWarranties={permissions.includes(
                                            'management-aset.garansi-aset.read',
                                        )}
                                        canReadContracts={permissions.includes(
                                            'management-aset.kontrak-servis.read',
                                        )}
                                    />
                                </CollapsibleSection>
                            )}

                        {readOnly &&
                            asetId &&
                            permissions.includes(
                                'management-aset.downtime-aset.read',
                            ) && (
                                <CollapsibleSection
                                    value="downtime"
                                    title="Downtime"
                                >
                                    <AssetDowntimeSection asetId={asetId} />
                                </CollapsibleSection>
                            )}

                        {readOnly && asetId && (
                            <CollapsibleSection
                                value="riwayat-perubahan"
                                title="Riwayat perubahan"
                            >
                                <ChangeHistory load={loadChangeHistory} />
                            </CollapsibleSection>
                        )}

                        <CollapsibleSection value="catatan" title="Keterangan">
                            <FormControl data-error-field="keterangan">
                                <Textarea
                                    rows={3}
                                    maxLength={2000}
                                    readOnly={readOnly}
                                    placeholder="Keterangan"
                                    value={values.keterangan}
                                    onChange={(event) =>
                                        setValue(
                                            'keterangan',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormControl>
                        </CollapsibleSection>
                    </CollapsibleSectionGroup>
                </RecordFactBox>
            </div>
        </div>
    );
}
