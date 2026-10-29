import { ActionButton } from '@apperp/ui/action-button';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { PageDialog, PageDialogContent } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Textarea } from '@apperp/ui/textarea';
import { CircleAlert, CircleCheck } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    getScenario,
    processLines,
    scenarios,
    validateAttachment,
    validateDraft,
    validateLines,
} from './form-error-scenarios';
import type {
    Attachment,
    Draft,
    FieldIssue,
    FieldName,
    Line,
    Scenario,
} from './form-error-scenarios';

type Result =
    | { kind: 'idle' }
    | {
          kind: 'failure';
          scenario: Scenario;
          issues: FieldIssue[];
          submitted: Draft;
          message: string;
      }
    | { kind: 'success'; title: string; message: string };
type Panel = 'conflict' | 'permission' | 'session' | 'setup' | 'duplicate';

const latestDraft: Draft = {
    reference: 'DOK-CONTOH',
    email: 'dokumen@example.test',
    quantity: '5',
};
const fields: {
    field: keyof Draft;
    label: string;
    type: 'text' | 'email' | 'number';
}[] = [
    { field: 'reference', label: 'Referensi dokumen', type: 'text' },
    { field: 'email', label: 'Email penerima', type: 'email' },
    { field: 'quantity', label: 'Jumlah', type: 'number' },
];
const newLines = (): Line[] => [
    { id: 'first', quantity: '2' },
    { id: 'second', quantity: '0' },
    { id: 'third', quantity: '-1' },
];

function initialDraft(scenario: Scenario): Draft {
    return scenario === 'validation'
        ? { reference: '', email: '', quantity: '0' }
        : { ...latestDraft, quantity: '2' };
}

function ExampleForm() {
    const formId = useId();
    const [draft, setDraft] = useState<Draft>(initialDraft('validation'));
    const [scenario, setScenario] = useState<Scenario>('validation');
    const [result, setResult] = useState<Result>({ kind: 'idle' });
    const [lines, setLines] = useState(newLines);
    const [processed, setProcessed] = useState<string[]>([]);
    const [attachment, setAttachment] = useState<Attachment>({
        name: 'contoh.pdf',
        size: 12 * 1024 * 1024,
        type: 'application/pdf',
    });
    const [openSections, setOpenSections] = useState(['identity']);
    const [focusRequest, setFocusRequest] = useState<{
        field: FieldName;
    } | null>(null);
    const [panel, setPanel] = useState<Panel | null>(null);
    const [sessionActive, setSessionActive] = useState(false);
    const [configuration, setConfiguration] = useState('');
    const [configured, setConfigured] = useState(false);
    const [latestAccepted, setLatestAccepted] = useState(false);
    const [copyFeedback, setCopyFeedback] = useState('');
    const [manualCopy, setManualCopy] = useState(false);
    const [setupError, setSetupError] = useState('');
    const summaryRef = useRef<HTMLDivElement>(null);
    const controls = useRef(new Map<FieldName, HTMLInputElement>());
    const fileRef = useRef<HTMLInputElement>(null);
    const issues = result.kind === 'failure' ? result.issues : [];
    const current = getScenario(scenario);
    const failure =
        result.kind === 'failure' ? getScenario(result.scenario) : null;
    const isBatch = scenario === 'partial';
    const hasLines = scenario === 'lines' || isBatch;
    const hasAttachment = scenario === 'file-size' || scenario === 'file-type';
    const submitted = result.kind === 'failure' ? result.submitted : draft;
    const helpDetails = `CONTOH PLAYGROUND, bukan laporan server\nTindakan: Simpan dokumen\nMasalah: ${current.label}\nReferensi dokumen: ${submitted.reference || '(belum diisi)'}\nIsian masih tersedia di form. Tidak ada permintaan ke server.`;
    const accessRequest =
        'CONTOH PERMINTAAN AKSES\nSaya belum dapat menyimpan dokumen. Mohon periksa apakah akun saya berhak membuat atau mengubah dokumen ini. Tidak ada permintaan yang otomatis dikirim.';

    useEffect(() => {
        if (result.kind !== 'idle') {
            summaryRef.current?.focus({ preventScroll: true });
        }
    }, [result]);

    useEffect(() => {
        if (!focusRequest || panel) {
            return;
        }

        const target = controls.current.get(focusRequest.field);
        target?.focus({ preventScroll: true });
        target?.scrollIntoView({ block: 'center', behavior: 'instant' });

        if (focusRequest.field === 'attachment') {
            target?.click();
        }
    }, [focusRequest, panel]);

    function reset(next: Scenario) {
        setScenario(next);
        setDraft(initialDraft(next));
        setResult({ kind: 'idle' });
        setLines(newLines());
        setProcessed([]);
        setOpenSections(['identity']);
        setFocusRequest(null);
        setSessionActive(false);
        setConfigured(false);
        setConfiguration('');
        setLatestAccepted(false);
        setCopyFeedback('');
        setManualCopy(false);
        setSetupError('');
        setAttachment(
            next === 'file-type'
                ? {
                      name: 'contoh.exe',
                      size: 1024,
                      type: 'application/octet-stream',
                  }
                : {
                      name: 'contoh.pdf',
                      size: 12 * 1024 * 1024,
                      type: 'application/pdf',
                  },
        );

        if (fileRef.current) {
            fileRef.current.value = '';
        }
    }

    function goToField(field: FieldName) {
        const section = field.startsWith('line:')
            ? 'lines'
            : field === 'attachment'
              ? 'attachment'
              : field === 'quantity'
                ? 'details'
                : 'identity';
        setOpenSections((value) =>
            value.includes(section) ? value : [...value, section],
        );
        setFocusRequest({ field });
    }

    function showFailure(
        next: Scenario,
        nextIssues: FieldIssue[] = [],
        message = getScenario(next).message,
    ) {
        setResult({
            kind: 'failure',
            scenario: next,
            issues: nextIssues,
            submitted: { ...draft },
            message,
        });
    }

    function succeed(
        title = 'Semua isian sudah benar',
        message = 'Simulasi selesai. Tidak ada data yang dikirim atau disimpan ke server.',
    ) {
        setResult({ kind: 'success', title, message });
    }

    function runBatch() {
        const outcome = processLines(lines, processed);
        setProcessed(outcome.processed);

        if (outcome.issues.length) {
            showFailure(
                'partial',
                outcome.issues.map((issue) => ({
                    ...issue,
                    message: issue.message.replace(/^Baris /, 'Dokumen '),
                })),
            );
        } else {
            succeed(
                'Semua dokumen contoh berhasil diproses',
                `${outcome.processed.length} dari ${lines.length} dokumen selesai. Dokumen yang sudah berhasil tidak diproses ulang. Ini hanya simulasi lokal.`,
            );
        }
    }

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const draftIssues = validateDraft(draft);

        if (!isBatch && draftIssues.length) {
            showFailure('validation', draftIssues);

            return;
        }

        switch (scenario) {
            case 'validation':
                succeed();
                break;
            case 'lines': {
                const lineIssues = validateLines(lines);

                if (lineIssues.length) {
                    showFailure('lines', lineIssues);
                } else {
                    succeed();
                }

                break;
            }
            case 'system':
            case 'permission':
                showFailure(scenario);
                break;
            case 'conflict':
                if (latestAccepted) {
                    succeed();
                } else {
                    showFailure(scenario);
                }

                break;
            case 'session':
                if (sessionActive) {
                    succeed();
                } else {
                    showFailure(scenario);
                }

                break;
            case 'setup':
                if (configured) {
                    succeed();
                } else {
                    showFailure(scenario);
                }

                break;
            case 'duplicate':
                if (draft.reference.trim() === latestDraft.reference) {
                    showFailure(scenario, [
                        {
                            field: 'reference',
                            message: `Referensi ${draft.reference} sudah digunakan. Gunakan referensi lain atau lihat dokumen yang sudah ada.`,
                        },
                    ]);
                } else {
                    succeed();
                }

                break;
            case 'file-size':
            case 'file-type': {
                const fileIssues = validateAttachment(attachment);

                if (fileIssues.length) {
                    showFailure(
                        attachment.size > 10 * 1024 * 1024
                            ? 'file-size'
                            : 'file-type',
                        fileIssues,
                        `Berkas ${attachment.name} berukuran ${(attachment.size / 1024 / 1024).toFixed(2)} MB. Batas contoh 10 MB, dengan format PDF, PNG, atau JPEG. Berkas belum diunggah. Pilih berkas yang sesuai.`,
                    );
                } else {
                    succeed(
                        'Berkas contoh memenuhi ketentuan',
                        'Ukuran dan format berkas sudah sesuai. Tidak ada berkas yang diunggah.',
                    );
                }

                break;
            }
            case 'partial':
                runBatch();
                break;
            default: {
                const exhaustive: never = scenario;

                return exhaustive;
            }
        }
    }

    async function copy(text: string) {
        setManualCopy(false);

        try {
            await navigator.clipboard.writeText(text);
            setCopyFeedback('Rincian sudah disalin.');
        } catch {
            setManualCopy(true);
            setCopyFeedback(
                'Rincian belum dapat disalin otomatis. Pilih teks di bawah, lalu salin secara manual.',
            );
        }
    }

    function act() {
        if (result.kind !== 'failure') {
            return;
        }

        switch (result.scenario) {
            case 'validation':
            case 'lines': {
                const first = result.issues[0];

                if (first) {
                    goToField(first.field);
                }

                break;
            }
            case 'file-size':
            case 'file-type':
                goToField('attachment');
                break;
            case 'partial':
                runBatch();
                break;
            case 'system':
                void copy(helpDetails);
                break;
            case 'conflict':
            case 'permission':
            case 'session':
            case 'setup':
            case 'duplicate':
                setCopyFeedback('');
                setPanel(result.scenario);
                break;
            default: {
                const exhaustive: never = result.scenario;

                return exhaustive;
            }
        }
    }

    function fieldError(field: FieldName) {
        const fieldIssues = issues.filter((item) => item.field === field);

        return fieldIssues.length ? (
            <span id={`${formId}-${field}-error`}>
                {fieldIssues.map((issue) => issue.message).join(' ')}
            </span>
        ) : undefined;
    }

    function registerControl(
        field: FieldName,
        element: HTMLInputElement | null,
    ) {
        if (element) {
            controls.current.set(field, element);
        } else {
            controls.current.delete(field);
        }
    }

    function controlProps(field: FieldName) {
        const invalid = issues.some((issue) => issue.field === field);

        return {
            id: `${formId}-${field}`,
            'aria-invalid': invalid,
            'aria-describedby': invalid
                ? `${formId}-${field}-error`
                : undefined,
        };
    }

    const panelTitles: Record<Panel, string> = {
        conflict: 'Bandingkan perubahan',
        permission: 'Akses yang dibutuhkan',
        session: 'Masuk kembali',
        setup: 'Pengaturan dokumen',
        duplicate: 'Dokumen dengan referensi yang sama',
    };

    return (
        <div className="flex min-h-0 flex-col gap-3">
            <div
                className="flex flex-wrap gap-2"
                role="group"
                aria-label="Skenario kegagalan"
            >
                {scenarios.map((item) => (
                    <Button
                        key={item.value}
                        type="button"
                        size="sm"
                        variant={
                            scenario === item.value ? 'secondary' : 'outline'
                        }
                        aria-pressed={scenario === item.value}
                        onClick={() => reset(item.value)}
                    >
                        {item.label}
                    </Button>
                ))}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => reset(scenario)}
                >
                    Ulangi contoh
                </Button>
            </div>
            <p className="text-sm text-muted-foreground">
                Ganti skenario untuk memulai contoh baru.{' '}
                {isBatch
                    ? 'Tombol Proses dokumen menjalankan setiap dokumen secara terpisah.'
                    : 'Tekan Simpan untuk memunculkan pesan.'}
            </p>
            <form
                noValidate
                onSubmit={save}
                className="flex h-[min(38rem,75dvh)] min-h-0 flex-col overflow-hidden rounded-lg border bg-background"
                aria-label={
                    isBatch
                        ? 'Contoh proses beberapa dokumen'
                        : 'Contoh form dokumen'
                }
            >
                <div className="shrink-0 border-b">
                    <RecordActionBar
                        title={
                            isBatch
                                ? 'Proses beberapa dokumen'
                                : 'Contoh dokumen'
                        }
                    >
                        {isBatch ? (
                            <Button type="submit" variant="ghost">
                                Proses dokumen
                            </Button>
                        ) : (
                            <ActionButton action="save" type="submit" />
                        )}
                    </RecordActionBar>
                    {result.kind !== 'idle' && (
                        <div
                            ref={summaryRef}
                            tabIndex={-1}
                            className="max-h-[min(16rem,35dvh)] overflow-y-auto p-3 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                        >
                            <Alert
                                variant={
                                    result.kind === 'success'
                                        ? 'default'
                                        : 'destructive'
                                }
                                role={
                                    result.kind === 'success'
                                        ? 'status'
                                        : 'alert'
                                }
                            >
                                {result.kind === 'success' ? (
                                    <CircleCheck />
                                ) : (
                                    <CircleAlert />
                                )}
                                <AlertTitle>
                                    {result.kind === 'success'
                                        ? result.title
                                        : result.scenario === 'partial'
                                          ? `${processed.length} dokumen berhasil, ${lines.length - processed.length} belum berhasil`
                                          : failure?.title}
                                </AlertTitle>
                                <AlertDescription>
                                    <p>
                                        {result.kind === 'success'
                                            ? result.message
                                            : result.scenario === 'partial'
                                              ? 'Dokumen yang berhasil tetap tersimpan dalam simulasi ini. Perbaiki jumlah pada dokumen yang gagal, lalu proses ulang hanya dokumen tersebut.'
                                              : result.message}
                                    </p>
                                    {result.kind === 'failure' && (
                                        <>
                                            {issues.length > 0 && (
                                                <ul className="flex flex-col gap-1">
                                                    {issues.map((issue) => (
                                                        <li
                                                            key={`${issue.field}-${issue.message}`}
                                                        >
                                                            <button
                                                                type="button"
                                                                className="text-left underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2"
                                                                onClick={() =>
                                                                    goToField(
                                                                        issue.field,
                                                                    )
                                                                }
                                                            >
                                                                {issue.message}
                                                            </button>
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={act}
                                                >
                                                    {failure?.action}
                                                </Button>
                                                {result.scenario ===
                                                    'duplicate' && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            goToField(
                                                                'reference',
                                                            )
                                                        }
                                                    >
                                                        Ubah referensi
                                                    </Button>
                                                )}
                                            </div>
                                        </>
                                    )}
                                    {result.kind === 'failure' &&
                                        result.scenario === 'system' &&
                                        copyFeedback && (
                                            <div className="flex flex-col gap-2">
                                                <p role="status">
                                                    {copyFeedback}
                                                </p>
                                                {manualCopy && (
                                                    <Textarea
                                                        label="Rincian bantuan untuk disalin"
                                                        readOnly
                                                        value={helpDetails}
                                                        rows={6}
                                                    />
                                                )}
                                            </div>
                                        )}
                                </AlertDescription>
                            </Alert>
                        </div>
                    )}
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto p-4">
                    <CollapsibleSectionGroup
                        value={openSections}
                        onValueChange={setOpenSections}
                    >
                        {!isBatch && (
                            <CollapsibleSection
                                value="identity"
                                title="Identitas dokumen"
                                summary="Referensi dan alamat email"
                            >
                                <FormGrid>
                                    {fields
                                        .filter(
                                            (field) =>
                                                field.field !== 'quantity',
                                        )
                                        .map((field) => (
                                            <FormControl
                                                key={field.field}
                                                error={fieldError(field.field)}
                                            >
                                                <Input
                                                    {...controlProps(
                                                        field.field,
                                                    )}
                                                    ref={(element) =>
                                                        registerControl(
                                                            field.field,
                                                            element,
                                                        )
                                                    }
                                                    label={field.label}
                                                    type={field.type}
                                                    required
                                                    value={draft[field.field]}
                                                    onChange={(event) =>
                                                        setDraft({
                                                            ...draft,
                                                            [field.field]:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                />
                                            </FormControl>
                                        ))}
                                </FormGrid>
                            </CollapsibleSection>
                        )}
                        {!hasLines && !hasAttachment && (
                            <CollapsibleSection
                                value="details"
                                title="Rincian dokumen"
                                summary="Jumlah yang akan diproses"
                            >
                                <FormGrid>
                                    <FormControl error={fieldError('quantity')}>
                                        <Input
                                            {...controlProps('quantity')}
                                            ref={(element) =>
                                                registerControl(
                                                    'quantity',
                                                    element,
                                                )
                                            }
                                            label="Jumlah"
                                            type="number"
                                            min={1}
                                            step={1}
                                            required
                                            value={draft.quantity}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    quantity:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </FormControl>
                                </FormGrid>
                            </CollapsibleSection>
                        )}
                        {hasLines && (
                            <CollapsibleSection
                                value="lines"
                                title={
                                    isBatch
                                        ? 'Dokumen yang diproses'
                                        : 'Baris rincian'
                                }
                                summary={`${lines.length} ${isBatch ? 'dokumen terpisah' : 'baris'}, termasuk jumlah yang perlu diperbaiki`}
                            >
                                <div className="flex flex-col gap-4">
                                    {lines.map((line, index) => (
                                        <FormControl
                                            key={line.id}
                                            error={fieldError(
                                                `line:${line.id}`,
                                            )}
                                        >
                                            <Input
                                                {...controlProps(
                                                    `line:${line.id}`,
                                                )}
                                                ref={(element) =>
                                                    registerControl(
                                                        `line:${line.id}`,
                                                        element,
                                                    )
                                                }
                                                label={`${isBatch ? 'Dokumen' : 'Baris'} ${index + 1} · Jumlah`}
                                                type="number"
                                                min={1}
                                                step={1}
                                                required
                                                disabled={processed.includes(
                                                    line.id,
                                                )}
                                                value={line.quantity}
                                                onChange={(event) =>
                                                    setLines(
                                                        lines.map((item) =>
                                                            item.id === line.id
                                                                ? {
                                                                      ...item,
                                                                      quantity:
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : item,
                                                        ),
                                                    )
                                                }
                                            />
                                            {isBatch && (
                                                <p className="text-sm text-muted-foreground">
                                                    {processed.includes(line.id)
                                                        ? 'Berhasil diproses. Tidak akan diproses ulang.'
                                                        : 'Belum diproses atau masih perlu diperbaiki.'}
                                                </p>
                                            )}
                                        </FormControl>
                                    ))}
                                </div>
                            </CollapsibleSection>
                        )}
                        {hasAttachment && (
                            <CollapsibleSection
                                value="attachment"
                                title="Lampiran"
                                summary={`${attachment.name} · ${(attachment.size / 1024 / 1024).toFixed(2)} MB`}
                            >
                                <div className="flex flex-col gap-3">
                                    <p className="text-sm">
                                        Berkas contoh: {attachment.name},{' '}
                                        {(
                                            attachment.size /
                                            1024 /
                                            1024
                                        ).toFixed(2)}{' '}
                                        MB. Batas contoh 10 MB. Format PDF, PNG,
                                        atau JPEG.
                                    </p>
                                    <FormControl
                                        error={fieldError('attachment')}
                                    >
                                        <Input
                                            {...controlProps('attachment')}
                                            ref={(element) => {
                                                fileRef.current = element;

                                                if (element) {
                                                    controls.current.set(
                                                        'attachment',
                                                        element,
                                                    );
                                                } else {
                                                    controls.current.delete(
                                                        'attachment',
                                                    );
                                                }
                                            }}
                                            label="Pilih berkas pengganti"
                                            type="file"
                                            accept=".pdf,.png,.jpg,.jpeg"
                                            onChange={(event) => {
                                                const file =
                                                    event.target.files?.[0];

                                                if (file) {
                                                    setAttachment({
                                                        name: file.name,
                                                        size: file.size,
                                                        type: file.type,
                                                    });
                                                }
                                            }}
                                        />
                                    </FormControl>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            setAttachment({
                                                name: 'contoh-valid.pdf',
                                                size: 1024 * 1024,
                                                type: 'application/pdf',
                                            });

                                            if (fileRef.current) {
                                                fileRef.current.value = '';
                                            }
                                        }}
                                    >
                                        Gunakan contoh PDF 1 MB
                                    </Button>
                                    <p className="text-sm text-muted-foreground">
                                        Berkas tidak dibaca atau diunggah. Hanya
                                        nama, ukuran, dan jenisnya yang
                                        diperiksa dalam contoh ini.
                                    </p>
                                </div>
                            </CollapsibleSection>
                        )}
                    </CollapsibleSectionGroup>
                </div>
            </form>
            <PageDialog
                open={panel !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPanel(null);
                    }
                }}
            >
                <PageDialogContent
                    title={panel ? panelTitles[panel] : 'Contoh tindakan'}
                    recordTitle="Simulasi lokal"
                >
                    <div className="flex min-h-0 flex-col gap-4 overflow-y-auto p-4">
                        <p className="text-sm text-muted-foreground">
                            Tindakan di kartu ini hanya mengubah contoh. Tidak
                            ada login, permintaan akses, atau penyimpanan ke
                            server.
                        </p>
                        {panel === 'conflict' && (
                            <>
                                <p className="text-sm">
                                    Bandingkan isianmu dengan versi terbaru.
                                    Mengganti isian akan menimpa draft di form
                                    contoh ini.
                                </p>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {[
                                        {
                                            title: 'Isian kamu',
                                            value: submitted,
                                        },
                                        {
                                            title: 'Versi terbaru',
                                            value: latestDraft,
                                        },
                                    ].map((version) => (
                                        <div
                                            key={version.title}
                                            className="flex flex-col gap-2 rounded-md border p-3"
                                        >
                                            <h3 className="text-sm font-semibold">
                                                {version.title}
                                            </h3>
                                            {fields.map((field) => (
                                                <p
                                                    className="text-sm"
                                                    key={field.field}
                                                >
                                                    {field.label}:{' '}
                                                    {version.value[field.field]}
                                                </p>
                                            ))}
                                        </div>
                                    ))}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            setDraft({ ...latestDraft });
                                            setLatestAccepted(true);
                                            setPanel(null);
                                            succeed(
                                                'Versi terbaru dimuat',
                                                'Isian form sudah diganti sesuai pilihanmu. Periksa kembali sebelum menekan Simpan.',
                                            );
                                        }}
                                    >
                                        Ganti isian dengan versi terbaru
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setPanel(null)}
                                    >
                                        Pertahankan isian saya
                                    </Button>
                                </div>
                            </>
                        )}
                        {panel === 'permission' && (
                            <>
                                <p className="text-sm">
                                    Akun contoh belum berhak membuat atau
                                    mengubah dokumen. Pengelola perlu memeriksa
                                    penugasan aksesmu. Tombol ini tidak
                                    memberikan izin baru.
                                </p>
                                <Textarea
                                    label="Rincian untuk pengelola"
                                    readOnly
                                    value={accessRequest}
                                    rows={5}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => void copy(accessRequest)}
                                >
                                    Salin permintaan akses
                                </Button>
                            </>
                        )}
                        {panel === 'session' && (
                            <>
                                <p className="text-sm">
                                    Masuk kembali dengan akun yang sama sebelum
                                    melanjutkan. Isian pada form contoh ini
                                    tetap tersedia selama halaman terbuka.
                                    Memuat ulang halaman dapat membuangnya.
                                </p>
                                <Button
                                    type="button"
                                    onClick={() => {
                                        setSessionActive(true);
                                        setPanel(null);
                                        succeed(
                                            'Sesi contoh kembali aktif',
                                            'Isian masih tersedia. Periksa kembali lalu tekan Simpan. Ini hanya simulasi login.',
                                        );
                                    }}
                                >
                                    Lanjutkan simulasi login
                                </Button>
                            </>
                        )}
                        {panel === 'setup' && (
                            <>
                                <p className="text-sm">
                                    Isi akun tujuan untuk dokumen contoh, lalu
                                    kembali ke form. Isian dokumen tetap
                                    dipertahankan.
                                </p>
                                <FormControl
                                    error={
                                        setupError ? (
                                            <span id={`${formId}-setup-error`}>
                                                {setupError}
                                            </span>
                                        ) : undefined
                                    }
                                >
                                    <Input
                                        label="Akun tujuan"
                                        required
                                        value={configuration}
                                        aria-invalid={!!setupError}
                                        aria-describedby={
                                            setupError
                                                ? `${formId}-setup-error`
                                                : undefined
                                        }
                                        onChange={(event) => {
                                            setConfiguration(
                                                event.target.value,
                                            );
                                            setSetupError('');
                                        }}
                                    />
                                </FormControl>
                                <Button
                                    type="button"
                                    onClick={() => {
                                        if (!configuration.trim()) {
                                            setSetupError(
                                                'Isi akun tujuan sebelum menyimpan pengaturan contoh.',
                                            );

                                            return;
                                        }

                                        setConfigured(true);
                                        setPanel(null);
                                        succeed(
                                            'Pengaturan contoh sudah lengkap',
                                            'Kembali ke pekerjaanmu dan tekan Simpan untuk melanjutkan. Tidak ada pengaturan server yang diubah.',
                                        );
                                    }}
                                >
                                    Simpan pengaturan contoh
                                </Button>
                            </>
                        )}
                        {panel === 'duplicate' && (
                            <>
                                <p className="text-sm">
                                    Dokumen contoh dengan referensi ini sudah
                                    ada. Periksa terlebih dahulu agar tidak
                                    membuat dokumen yang sama dua kali.
                                </p>
                                <dl className="grid grid-cols-2 gap-2 text-sm">
                                    <dt>Referensi</dt>
                                    <dd>{latestDraft.reference}</dd>
                                    <dt>Email penerima</dt>
                                    <dd>{latestDraft.email}</dd>
                                    <dt>Jumlah</dt>
                                    <dd>{latestDraft.quantity}</dd>
                                </dl>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setPanel(null);
                                        goToField('reference');
                                    }}
                                >
                                    Kembali dan ubah referensi
                                </Button>
                            </>
                        )}
                        {copyFeedback && (
                            <p role="status" className="text-sm">
                                {copyFeedback}
                            </p>
                        )}
                    </div>
                </PageDialogContent>
            </PageDialog>
        </div>
    );
}

export default function FormErrorDemo() {
    const [dialogOpen, setDialogOpen] = useState(false);

    return (
        <Card id="form-errors">
            <CardHeader>
                <CardTitle>
                    Pesan error pada form · {scenarios.length} skenario
                </CardTitle>
                <p className="text-sm text-muted-foreground">
                    Pilih skenario, jalankan tindakan, lalu coba tombol
                    perbaikannya. Pesan ada pada header dan field terkait.
                    Seluruh data dan tindakan hanya contoh lokal.
                </p>
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setDialogOpen(true)}
                    >
                        Coba dalam kartu popup
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                <ExampleForm />
            </CardContent>
            <PageDialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <PageDialogContent
                    title="Contoh pesan error"
                    recordTitle="Dokumen percobaan"
                >
                    <div className="min-h-0 overflow-y-auto p-4">
                        <ExampleForm />
                    </div>
                </PageDialogContent>
            </PageDialog>
        </Card>
    );
}
