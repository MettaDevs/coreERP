import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Field, FieldLabel } from '@apperp/ui/field';
import { Switch } from '@apperp/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { CircleAlert, ExternalLink } from 'lucide-react';
import { useId, useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * Pemeriksaan posting gaya *Journal Check* Business Central (K-22): baris jurnal, saldo
 * berjalannya, dan masalah tiap baris beserta jalan pintas ke layar perbaikannya.
 *
 * Dipakai tiga layar — pantau posting, pratinjau penerimaan aset, dan pratinjau "Post
 * penyusutan" — jadi komponen ini hanya menampilkan apa yang diberikan: tidak mengambil data
 * dan tidak berpindah halaman sendiri. Tombol "Validasi ulang" dipasang pemanggil lewat
 * `action`, karena hanya pemanggil yang tahu endpoint mana yang memeriksa ulang.
 */

export type PostingCheckDimension = {
    code: string;
    display_name: string | null;
    value_code: string | null;
    value_display_name: string | null;
};
export type PostingCheckLine = {
    line_no: number;
    account_code: string | null;
    account_name: string | null;
    description: string | null;
    debit: string;
    credit: string;
    dimensions: PostingCheckDimension[];
};
export type PostingCheckProblem = {
    line_no: number;
    code: string;
    message: string;
    object: { type: string; id: string; label: string } | null;
    fix: { label: string; url: string } | null;
};
export type PostingCheckProps = {
    lines: PostingCheckLine[];
    problems: PostingCheckProblem[];
    currencyCode: string;
    currencyDecimals: number;
    action?: React.ReactNode;
    className?: string;
};

type Row = {
    index: number;
    line: PostingCheckLine;
    debit: bigint | null;
    credit: bigint | null;
    balance: bigint | null;
    problems: PostingCheckProblem[];
};

// Jenis objek yang dikirim penerbit posting hari ini. Jenis lain tetap tampil, hanya labelnya.
const OBJECT_TYPE = new Map([
    ['account', 'Akun'],
    ['organization', 'Unit organisasi'],
]);

const AMOUNT = /^(-?)(\d+)(?:\.(\d+))?$/;
const GROUPING = new Intl.NumberFormat('id-ID');

/**
 * String desimal menjadi bilangan bulat satuan terkecil, supaya penjumlahan tidak pernah lewat
 * float: saldo berjalan puluhan baris bernilai miliaran harus berakhir tepat nol.
 *
 * Nol di belakang presisi mata uang diterima karena tidak mengubah nilai — kolom baris posting
 * di database berskala 6, jadi `"500000000.000000"` sah untuk IDR dua desimal. Angka bukan nol
 * di sana, atau string yang bukan desimal, menghasilkan `null`: nilainya ditampilkan apa adanya
 * dan saldo berjalan berhenti dihitung, bukan dibulatkan diam-diam.
 */
function toUnits(value: string, decimals: number): bigint | null {
    const match = AMOUNT.exec(value.trim());

    if (!match) {
        return null;
    }

    const [, sign, whole, fraction = ''] = match;

    if (/[^0]/.test(fraction.slice(decimals))) {
        return null;
    }

    const units = BigInt(
        whole + fraction.slice(0, decimals).padEnd(decimals, '0'),
    );

    return sign === '-' ? -units : units;
}

function formatUnits(units: bigint, decimals: number): string {
    const negative = units < 0n;
    const digits = (negative ? -units : units)
        .toString()
        .padStart(decimals + 1, '0');
    const cut = digits.length - decimals;
    // BigInt tidak punya pecahan, jadi Intl hanya mengelompokkan ribuan bagian bulatnya;
    // pecahannya disambung sendiri dengan koma, pemisah desimal id-ID.
    const whole = GROUPING.format(BigInt(digits.slice(0, cut)));
    const text = decimals > 0 ? `${whole},${digits.slice(cut)}` : whole;

    return negative ? `-${text}` : text;
}

// Sisi yang nol dikosongkan, seperti jurnal pada umumnya, supaya sisi yang terisi langsung terbaca.
function amountText(
    raw: string,
    units: bigint | null,
    decimals: number,
): string {
    if (units === null) {
        return raw;
    }

    return units === 0n ? '' : formatUnits(units, decimals);
}

function totalText(units: bigint | null, decimals: number): string {
    return units === null ? '—' : formatUnits(units, decimals);
}

function sum(values: (bigint | null)[]): bigint | null {
    return values.reduce<bigint | null>(
        (total, value) =>
            total === null || value === null ? null : total + value,
        0n,
    );
}

/**
 * Masalah yang nomor barisnya tidak ada di `lines` dipisah, supaya tampil di "Masalah lain"
 * alih-alih hilang tanpa jejak.
 */
function groupProblems(
    lines: PostingCheckLine[],
    problems: PostingCheckProblem[],
) {
    const lineNumbers = new Set(lines.map((line) => line.line_no));
    const byLine = new Map<number, PostingCheckProblem[]>();
    const others: PostingCheckProblem[] = [];

    for (const problem of problems) {
        if (lineNumbers.has(problem.line_no)) {
            byLine.set(problem.line_no, [
                ...(byLine.get(problem.line_no) ?? []),
                problem,
            ]);
        } else {
            others.push(problem);
        }
    }

    return { byLine, others };
}

/**
 * Saldo berjalan dihitung atas seluruh baris sebelum tabel disaring: angkanya milik posisi
 * baris di jurnal, bukan milik baris yang kebetulan sedang tampil.
 */
function buildRows(
    lines: PostingCheckLine[],
    problemsByLine: Map<number, PostingCheckProblem[]>,
    decimals: number,
): Row[] {
    const rows: Row[] = [];
    let balance: bigint | null = 0n;

    for (const [index, line] of lines.entries()) {
        const debit = toUnits(line.debit, decimals);
        const credit = toUnits(line.credit, decimals);
        balance =
            balance === null || debit === null || credit === null
                ? null
                : balance + debit - credit;
        rows.push({
            index,
            line,
            debit,
            credit,
            balance,
            problems: problemsByLine.get(line.line_no) ?? [],
        });
    }

    return rows;
}

function objectText(
    object: NonNullable<PostingCheckProblem['object']>,
): string {
    const type = OBJECT_TYPE.get(object.type);

    return type ? `${type}: ${object.label}` : object.label;
}

function ProblemList({
    problems,
    withLine = false,
}: {
    problems: PostingCheckProblem[];
    withLine?: boolean;
}) {
    return (
        <ul className="space-y-3">
            {problems.map((problem, index) => {
                const details = [
                    withLine && problem.line_no > 0
                        ? `Baris ${problem.line_no}`
                        : null,
                    problem.object ? objectText(problem.object) : null,
                ]
                    .filter(Boolean)
                    .join(' · ');

                return (
                    <li
                        key={`${problem.code}-${index}`}
                        className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div className="flex min-w-0 gap-2">
                            <CircleAlert
                                aria-hidden
                                className="mt-0.5 size-4 shrink-0 text-destructive"
                            />
                            <div className="min-w-0">
                                <p className="text-sm">
                                    <span className="sr-only">Masalah: </span>
                                    {problem.message}
                                </p>
                                {details ? (
                                    <p className="text-xs text-muted-foreground">
                                        {details}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                        {/* Tab baru: layar pemeriksaan beserta isiannya tetap di tempat, jadi
                            setelah memperbaiki pengguna tinggal kembali dan menekan "Validasi
                            ulang". */}
                        {problem.fix ? (
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="shrink-0 self-start"
                            >
                                <a
                                    href={problem.fix.url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink aria-hidden />
                                    {problem.fix.label}
                                    <span className="sr-only"> (tab baru)</span>
                                </a>
                            </Button>
                        ) : null}
                    </li>
                );
            })}
        </ul>
    );
}

function Dimensions({ dimensions }: { dimensions: PostingCheckDimension[] }) {
    if (dimensions.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <ul className="space-y-1">
            {dimensions.map((dimension) => (
                <li key={dimension.code}>
                    {dimension.value_display_name ||
                        dimension.value_code ||
                        '—'}
                    {dimension.value_display_name && dimension.value_code ? (
                        <span className="block text-xs text-muted-foreground">
                            {dimension.value_code}
                        </span>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

function LineRows({ row, decimals }: { row: Row; decimals: number }) {
    const { line, problems } = row;
    const flagged = problems.length > 0;

    return (
        <>
            <TableRow className={flagged ? 'bg-destructive/5' : undefined}>
                <TableCell className="tabular-nums">{line.line_no}</TableCell>
                <TableCell>
                    <span className="font-mono">
                        {line.account_code || '—'}
                    </span>
                    {line.account_name ? (
                        <span className="block text-xs text-muted-foreground">
                            {line.account_name}
                        </span>
                    ) : null}
                </TableCell>
                <TableCell>{line.description || '—'}</TableCell>
                <TableCell>
                    <Dimensions dimensions={line.dimensions} />
                </TableCell>
                <TableCell className="text-right tabular-nums">
                    {amountText(line.debit, row.debit, decimals)}
                </TableCell>
                <TableCell className="text-right tabular-nums">
                    {amountText(line.credit, row.credit, decimals)}
                </TableCell>
                <TableCell className="text-right font-medium tabular-nums">
                    {totalText(row.balance, decimals)}
                </TableCell>
            </TableRow>
            {flagged ? (
                <TableRow className="bg-destructive/5">
                    <TableCell />
                    {/* Pesan dibiarkan melipat; tanpa itu satu pesan panjang melebarkan
                        seluruh tabel. */}
                    <TableCell colSpan={6} className="whitespace-normal">
                        <ProblemList problems={problems} />
                    </TableCell>
                </TableRow>
            ) : null}
        </>
    );
}

export function PostingCheck({
    lines,
    problems,
    currencyCode,
    currencyDecimals,
    action,
    className,
}: PostingCheckProps): React.ReactElement {
    const switchId = useId();
    const [onlyProblems, setOnlyProblems] = useState(false);
    const { byLine, others } = groupProblems(lines, problems);
    const rows = buildRows(lines, byLine, currencyDecimals);
    const problemLines = new Set(problems.map((problem) => problem.line_no));
    // Sakelar hanya menyaring tabel, jadi ia juga mati bila semua masalah ada di "Masalah
    // lain". Keadaannya diturunkan, bukan disimpan: setelah "Validasi ulang" masalahnya bisa
    // habis, dan saringan yang tertinggal menyala akan mengosongkan tabel.
    const canFilter = byLine.size > 0;
    const filtering = onlyProblems && canFilter;
    const visibleRows = filtering
        ? rows.filter((row) => row.problems.length > 0)
        : rows;
    const summary = [
        { label: 'Baris diperiksa', count: lines.length, alert: false },
        {
            label: 'Baris bermasalah',
            count: problemLines.size,
            alert: problemLines.size > 0,
        },
        {
            label: 'Total masalah',
            count: problems.length,
            alert: problems.length > 0,
        },
    ];

    return (
        <div className={cn('flex min-w-0 flex-col gap-4', className)}>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 flex-col gap-3">
                    <div className="flex flex-wrap gap-2">
                        {summary.map(({ label, count, alert }) => (
                            <Badge
                                key={label}
                                variant="outline"
                                className={
                                    alert
                                        ? 'border-destructive/40 text-destructive'
                                        : undefined
                                }
                            >
                                {label}: {count}
                            </Badge>
                        ))}
                    </div>
                    <Field orientation="horizontal" data-disabled={!canFilter}>
                        <Switch
                            id={switchId}
                            checked={filtering}
                            disabled={!canFilter}
                            onCheckedChange={setOnlyProblems}
                        />
                        <FieldLabel htmlFor={switchId}>
                            Tampilkan baris bermasalah saja
                        </FieldLabel>
                    </Field>
                </div>
                {action ? (
                    <div className="flex shrink-0 flex-wrap gap-2">
                        {action}
                    </div>
                ) : null}
            </div>

            {rows.length === 0 ? (
                <Empty className="border">
                    <EmptyHeader>
                        <EmptyTitle>Tidak ada baris jurnal</EmptyTitle>
                        <EmptyDescription>
                            Posting ini tidak membawa baris jurnal, jadi belum
                            ada yang bisa diperiksa.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-16">Baris</TableHead>
                                <TableHead>Akun</TableHead>
                                <TableHead>Keterangan</TableHead>
                                <TableHead>Dimensi</TableHead>
                                <TableHead className="text-right">
                                    Debit ({currencyCode})
                                </TableHead>
                                <TableHead className="text-right">
                                    Kredit ({currencyCode})
                                </TableHead>
                                <TableHead className="text-right">
                                    Saldo berjalan ({currencyCode})
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visibleRows.map((row) => (
                                <LineRows
                                    key={row.index}
                                    row={row}
                                    decimals={currencyDecimals}
                                />
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={4}>
                                    Jumlah seluruh baris
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {totalText(
                                        sum(rows.map((row) => row.debit)),
                                        currencyDecimals,
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {totalText(
                                        sum(rows.map((row) => row.credit)),
                                        currencyDecimals,
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {totalText(
                                        rows[rows.length - 1].balance,
                                        currencyDecimals,
                                    )}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            )}

            {others.length > 0 ? (
                <div className="space-y-2">
                    <p className="text-sm font-medium">Masalah lain</p>
                    <div className="rounded-lg border bg-destructive/5 p-3">
                        <ProblemList problems={others} withLine />
                    </div>
                </div>
            ) : null}
        </div>
    );
}
