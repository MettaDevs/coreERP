import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldHint,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Textarea } from '@apperp/ui/textarea';
import { CircleHelp, Plus, X } from 'lucide-react';
import type { RefObject } from 'react';
import { useEffect, useRef, useState } from 'react';
import type { FormulaError } from '@/components/analytics/use-query-preview';
import { FORMULA_FORMATS, FORMULA_FUNCTIONS } from '@/lib/analytics/query';
import type {
    DatasetMeasure,
    MeasureFormat,
    QueryFormula,
} from '@/lib/analytics/types';

/** Panjang teks rumus terbesar yang diterima server. */
const MAX_LENGTH = 500;

type Draft = Omit<QueryFormula, 'key'>;

/**
 * Editor rumus (area 13.7) di langkah Nilai pembangun dan penjelajah: nilai baru yang dihitung dari nilai lain di
 * baris yang sama, misalnya `BAGI([disposed]; [count]) * 100`. Bahasanya bahasa rumus CoreERP, bukan DAX
 * (`docs/todo/analitik/mesin-query.md`, bagian *Bahasa rumus*); server yang membacanya, dan galatnya tampil di
 * rumus yang salah dengan karakter tempatnya ditandai.
 *
 * - Nilai dan fungsi disisipkan di posisi kursor lewat tombol, supaya pengguna tidak perlu menghafal nama nilai.
 *   Tombolnya tidak mengambil fokus, jadi teks yang sedang disusun tidak dikirim di tengah jalan.
 * - Teks rumus dan namanya baru dipakai saat isian ditinggalkan atau Enter, seperti saringan: rumus yang belum
 *   selesai tidak dihitung.
 * - Rumus baru belum menjadi bagian analisis sampai teksnya diisi. Kuncinya dibuat pemanggil; pengguna hanya
 *   melihat nama rumus.
 */
export function FormulaEditor({
    measures,
    formulas,
    error,
    onChange,
    onAdd,
    onRemove,
    portalContainer,
}: {
    /** Nilai data yang dapat dipakai di rumus. */
    measures: DatasetMeasure[];
    formulas: QueryFormula[];
    error: FormulaError | null;
    onChange: (key: string, formula: Draft) => void;
    onAdd: (formula: Draft) => void;
    onRemove: (key: string) => void;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    const [pending, setPending] = useState(false);

    return (
        <div className="flex flex-col gap-3">
            {formulas.map((formula, index) => (
                <FormulaRow
                    // Nilai dari luar (tautan, bagian yang diubah, simpan) memasang ulang baris ini, jadi drafnya cukup
                    // diisi sekali.
                    key={`${formula.key}|${formula.caption ?? ''}|${formula.format ?? ''}|${formula.expression}`}
                    value={formula}
                    measures={measures}
                    error={error?.index === index ? error : null}
                    onCommit={(next) => onChange(formula.key, next)}
                    onRemove={() => onRemove(formula.key)}
                    portalContainer={portalContainer}
                />
            ))}
            {pending && (
                <FormulaRow
                    value={{
                        caption: `Rumus ${formulas.length + 1}`,
                        expression: '',
                    }}
                    measures={measures}
                    error={null}
                    autoFocus
                    onCommit={(next) => {
                        if (next.expression.trim() !== '') {
                            setPending(false);
                            onAdd(next);
                        }
                    }}
                    onRemove={() => setPending(false)}
                    portalContainer={portalContainer}
                />
            )}
            {!pending && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setPending(true)}
                    >
                        <Plus aria-hidden />
                        Tambah rumus
                    </Button>
                </div>
            )}
        </div>
    );
}

function FormulaRow({
    value,
    measures,
    error,
    autoFocus = false,
    onCommit,
    onRemove,
    portalContainer,
}: {
    value: Draft;
    measures: DatasetMeasure[];
    error: FormulaError | null;
    autoFocus?: boolean;
    onCommit: (formula: Draft) => void;
    onRemove: () => void;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    const [caption, setCaption] = useState(value.caption ?? '');
    const [expression, setExpression] = useState(value.expression);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const name = caption.trim() === '' ? 'rumus' : caption.trim();
    const example =
        measures.length >= 2
            ? `BAGI([${measures[1].key}]; [${measures[0].key}]) * 100`
            : measures.length === 1
              ? `[${measures[0].key}] * 2`
              : 'BAGI(a; b) * 100';

    const commit = (changes: Partial<Draft> = {}) => {
        const next: Draft = {
            caption: caption.trim() === '' ? undefined : caption.trim(),
            expression: expression.trim(),
            ...(value.format ? { format: value.format } : {}),
            ...changes,
        };

        if (
            next.expression !== value.expression ||
            next.caption !== value.caption ||
            next.format !== value.format
        ) {
            onCommit(next);
        }
    };

    // Galat dari server: kursor dibawa ke karakter yang ditunjuknya.
    const position = error?.position ?? null;
    useEffect(() => {
        const element = textarea.current;

        if (position === null || element === null) {
            return;
        }

        element.focus();
        element.setSelectionRange(position - 1, position);
    }, [position]);

    // Sisipan menggantikan pilihan di isian, atau masuk di posisi kursor; isian tetap fokus.
    const insert = (text: string) => {
        const element = textarea.current;
        const start = element?.selectionStart ?? expression.length;
        const end = element?.selectionEnd ?? expression.length;
        const next = expression.slice(0, start) + text + expression.slice(end);

        setExpression(next.slice(0, MAX_LENGTH));
        requestAnimationFrame(() => {
            element?.focus();
            element?.setSelectionRange(
                start + text.length,
                start + text.length,
            );
        });
    };

    return (
        <div className="flex flex-col gap-3 rounded-md border p-3">
            <div className="flex items-start gap-2">
                <Field className="min-w-0 flex-1">
                    <Input
                        label="Nama rumus"
                        required
                        maxLength={120}
                        value={caption}
                        onChange={(event) => setCaption(event.target.value)}
                        onBlur={() => commit()}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                event.currentTarget.blur();
                            }
                        }}
                    />
                </Field>
                <Field className="w-36 shrink-0">
                    <Select
                        label="Format"
                        items={FORMULA_FORMATS.map((format) => ({
                            value: format.value,
                            label: format.caption,
                        }))}
                        value={value.format ?? 'number'}
                        onValueChange={(format) =>
                            commit({
                                format:
                                    format === null || format === 'number'
                                        ? undefined
                                        : (format as MeasureFormat),
                            })
                        }
                        searchPlaceholder="Cari format"
                        emptyMessage="Format tidak ditemukan."
                        portalContainer={portalContainer}
                    />
                </Field>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="mt-0.5"
                    aria-label={`Hapus ${name}`}
                    title={`Hapus ${name}`}
                    onClick={onRemove}
                >
                    <X />
                </Button>
            </div>
            <Field data-invalid={error ? true : undefined}>
                <div className="flex items-start gap-1">
                    <Textarea
                        ref={textarea}
                        label="Rumus"
                        required
                        rows={2}
                        maxLength={MAX_LENGTH}
                        spellCheck={false}
                        autoFocus={autoFocus}
                        className="font-mono"
                        value={expression}
                        aria-invalid={error ? true : undefined}
                        onChange={(event) => setExpression(event.target.value)}
                        onBlur={() => commit()}
                        onKeyDown={(event) => {
                            // Rumus satu baris: Enter memakai rumusnya, bukan baris baru.
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                event.currentTarget.blur();
                            }
                        }}
                    />
                    <FieldHint
                        hint={
                            <span className="flex flex-col gap-1">
                                <span>
                                    Tulis nilai di dalam kurung siku, dengan
                                    tanda hitung + - * /. Pisahkan isian fungsi
                                    dengan titik koma; desimal memakai koma,
                                    misalnya 1.000,5. Bagi nol menjadi kosong.
                                </span>
                                {FORMULA_FUNCTIONS.map((item) => (
                                    <span key={item.name}>
                                        <span className="font-mono">
                                            {item.syntax}
                                        </span>{' '}
                                        — {item.caption}
                                    </span>
                                ))}
                            </span>
                        }
                    >
                        <button
                            type="button"
                            aria-label="Bantuan menulis rumus"
                            className="mt-2.5 text-muted-foreground"
                        >
                            <CircleHelp className="size-4" />
                        </button>
                    </FieldHint>
                </div>
                {error ? (
                    <>
                        <FieldError>{error.message}</FieldError>
                        {position !== null && (
                            <Marked text={expression} position={position} />
                        )}
                    </>
                ) : (
                    <FieldDescription>
                        Contoh: <span className="font-mono">{example}</span>.
                        Sisipkan nilai dan fungsi dari tombol di bawah.
                    </FieldDescription>
                )}
            </Field>
            <InsertButtons
                label="Sisipkan nilai"
                items={measures.map((measure) => ({
                    text: `[${measure.key}]`,
                    caption: measure.caption,
                    title: `${measure.caption} — ditulis [${measure.key}]`,
                }))}
                onInsert={insert}
            />
            <InsertButtons
                label="Sisipkan fungsi"
                items={FORMULA_FUNCTIONS.map((item) => ({
                    text: `${item.name}(`,
                    caption: item.name,
                    title: `${item.syntax} — ${item.caption}`,
                }))}
                onInsert={insert}
            />
        </div>
    );
}

/** Deret tombol sisip. Tombolnya tidak mengambil fokus, supaya isian rumus tidak ditinggalkan dan dikirim. */
function InsertButtons({
    label,
    items,
    onInsert,
}: {
    label: string;
    items: Array<{ text: string; caption: string; title: string }>;
    onInsert: (text: string) => void;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div
            role="group"
            aria-label={label}
            className="flex flex-wrap items-center gap-1"
        >
            <span className="mr-1 text-xs text-muted-foreground">{label}</span>
            {items.map((item) => (
                <Button
                    key={item.text}
                    type="button"
                    variant="secondary"
                    size="xs"
                    title={item.title}
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={() => onInsert(item.text)}
                >
                    {item.caption}
                </Button>
            ))}
        </div>
    );
}

/** Teks rumus dengan karakter yang ditunjuk galat ditandai; posisi sesudah akhir teks ditandai kotak kosong. */
function Marked({ text, position }: { text: string; position: number }) {
    const index = Math.min(Math.max(position - 1, 0), text.length);
    const marked = text.slice(index, index + 1);

    return (
        <p
            className="font-mono text-xs break-all text-muted-foreground"
            aria-hidden
        >
            {text.slice(0, index)}
            <mark className="rounded-sm bg-destructive/20 px-0.5 text-foreground">
                {marked === '' || marked === ' ' ? ' ' : marked}
            </mark>
            {text.slice(index + 1)}
        </p>
    );
}
