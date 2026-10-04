import type {
    ResultColumn,
    ResultSet,
    ResultValue,
} from '@/lib/analytics/types';

/**
 * Satu-satunya tempat angka hasil analitik diformat di layar (`docs/todo/analitik/dasbor-dan-visual.md`,
 * bagian *Format angka*): tile, tabel, sumbu grafik, tooltip, dan `aria-label` memanggil fungsi yang sama,
 * jadi satu nilai selalu tertulis sama di mana pun ia tampil. Nilai uang dan desimal datang sebagai string
 * supaya presisinya utuh; di sini ia diubah menjadi angka hanya untuk ditulis, tidak untuk dihitung.
 *
 * Aturan desimal: nilai bulat ditulis tanpa desimal, nilai berpecahan ditulis dengan desimal lengkap
 * — `Rp 240.500.000,50`, bukan `Rp 240.500.000,5` atau `Rp 240.500.001`, dan `US$12.000`, bukan
 * `US$12.000,00`. Desimal tidak pernah tampil setengah.
 *
 * `Intl` bahasa Indonesia menulis ringkasan sebagai "rb", "jt", "M", dan "T" — "Rp 1,3 M" untuk satu
 * koma tiga miliar — sama dengan cara orang menyebutnya. Ringkasan dipakai tile dan sumbu; nilai
 * lengkapnya tetap tersedia untuk pembaca layar dan tooltip.
 */

type Row = Record<string, ResultValue>;

const LOCALE = 'id-ID';

/** Desimal angka biasa yang berpecahan, misalnya rata-rata jumlah. */
const NUMBER_FRACTION_DIGITS = 2;

const EMPTY_VALUE = '—';

/** Tanpa nilai: kosong, atau teks yang tidak dapat dibaca sebagai angka. */
function numeric(raw: ResultValue | undefined): number | null {
    if (raw === null || raw === undefined || raw === '') {
        return null;
    }

    const value = Number(raw);

    return Number.isFinite(value) ? value : null;
}

/** Desimal yang ditulis: semua digit mata uang bila nilainya berpecahan, nol bila bulat. */
function fractionDigits(value: number, digits: number) {
    const shown = Number.isInteger(value) ? 0 : digits;

    return { minimumFractionDigits: shown, maximumFractionDigits: shown };
}

/**
 * Desimal uang tersimpan. Nilai uang disimpan dengan dua desimal, sedangkan `Intl` di Chrome menganggap
 * rupiah tanpa desimal (Node menganggapnya dua): mengikuti `Intl` saja membulatkan `240500000.50` menjadi
 * `Rp 240.500.001` di satu peramban dan tidak di peramban lain.
 */
const STORED_MONEY_DIGITS = 2;

const currencyDigits = new Map<string, number>();

/** Desimal yang ditulis untuk uang berpecahan: desimal mata uang menurut `Intl`, paling sedikit dua. */
function digitsOf(currency: string): number {
    let digits = currencyDigits.get(currency);

    if (digits === undefined) {
        digits = Math.max(
            new Intl.NumberFormat(LOCALE, {
                style: 'currency',
                currency,
            }).resolvedOptions().maximumFractionDigits ?? 0,
            STORED_MONEY_DIGITS,
        );
        currencyDigits.set(currency, digits);
    }

    return digits;
}

function formatMoney(value: number, currency: string, compact: boolean) {
    try {
        return new Intl.NumberFormat(LOCALE, {
            style: 'currency',
            currency,
            ...(compact
                ? { notation: 'compact', maximumFractionDigits: 1 }
                : fractionDigits(value, digitsOf(currency))),
        }).format(value);
    } catch {
        // Kode mata uang yang tidak dikenal `Intl` tetap ditulis, di depan angkanya.
        return `${currency} ${formatPlain(value, compact)}`;
    }
}

function formatPlain(value: number, compact: boolean) {
    return new Intl.NumberFormat(
        LOCALE,
        compact
            ? { notation: 'compact', maximumFractionDigits: 1 }
            : fractionDigits(value, NUMBER_FRACTION_DIGITS),
    ).format(value);
}

/**
 * Satu nilai measure dari satu baris hasil (atau baris total), dengan mata uang dan satuannya dari baris
 * yang sama. `compact` untuk tile dan sumbu: "Rp 1,3 M", "12,3 rb".
 */
export function formatMeasureValue(
    column: ResultColumn,
    row: Row,
    compact = false,
): string {
    const value = numeric(row[column.key]);

    if (value === null) {
        return EMPTY_VALUE;
    }

    switch (column.format) {
        case 'money': {
            const currency = row[column.currency_key ?? ''];

            return typeof currency === 'string' && currency !== ''
                ? formatMoney(value, currency, compact)
                : formatPlain(value, compact);
        }
        case 'percent':
            return `${formatPlain(value, compact)}%`;
        case 'hours':
            return `${formatPlain(value, compact)} jam`;
        case 'quantity': {
            const unit = row[column.unit_key ?? ''];

            return typeof unit === 'string' && unit !== ''
                ? `${formatPlain(value, compact)} ${unit}`
                : formatPlain(value, compact);
        }
        default:
            return formatPlain(value, compact);
    }
}

/** Angka mentah sumbu grafik dalam format measure-nya, ringkas: "Rp 1,3 M". */
export function formatAxisValue(
    column: ResultColumn,
    sample: Row,
    value: number,
): string {
    return formatMeasureValue(column, { ...sample, [column.key]: value }, true);
}

const MONTH = new Intl.DateTimeFormat(LOCALE, {
    timeZone: 'UTC',
    month: 'short',
    year: 'numeric',
});
const DAY = new Intl.DateTimeFormat(LOCALE, {
    timeZone: 'UTC',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});
const DAY_MONTH = new Intl.DateTimeFormat(LOCALE, {
    timeZone: 'UTC',
    day: 'numeric',
    month: 'short',
});

/**
 * Tanggal kalender `2026-09-01` sebagai `Date` tengah malam UTC, diformat dengan zona UTC: tanggal tidak
 * punya zona dan tidak boleh bergeser karena zona perangkat.
 */
function calendarDate(value: string): Date | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);

    return match
        ? new Date(Date.UTC(+match[1], +match[2] - 1, +match[3]))
        : null;
}

/**
 * Awal ember waktu dari server (`2026-09-01`) sebagai periode yang dibaca orang: "1 Sep 2026" per hari,
 * "1–7 Sep 2026" per minggu (Senin sampai Minggu), "Sep 2026" per bulan, "K3 2026" per kuartal, "2026"
 * per tahun.
 */
export function formatPeriod(
    value: string,
    granularity: ResultColumn['granularity'],
): string {
    const date = calendarDate(value);

    if (date === null) {
        return value;
    }

    switch (granularity) {
        case 'year':
            return String(date.getUTCFullYear());
        case 'quarter':
            return `K${Math.floor(date.getUTCMonth() / 3) + 1} ${date.getUTCFullYear()}`;
        case 'month':
            return MONTH.format(date);
        case 'week': {
            const end = new Date(date.getTime() + 6 * 86_400_000);

            return end.getUTCMonth() === date.getUTCMonth()
                ? `${date.getUTCDate()}–${DAY.format(end)}`
                : `${DAY_MONTH.format(date)} – ${DAY.format(end)}`;
        }
        default:
            return DAY.format(date);
    }
}

/**
 * Nilai pengelompok untuk dibaca orang: label dari server bila ada (pilihan, rujukan, unit kerja), periode
 * dan tanggal sebagai tanggal Indonesia, dan "(kosong)" untuk nilai kosong. Waktu dengan jam ditulis
 * dalam zona yang dipakai engine menghitung hasilnya (`meta.timezone`).
 */
export function formatDimensionValue(
    column: ResultColumn,
    row: Row,
    timeZone = 'UTC',
): string {
    const label = column.label_key ? row[column.label_key] : null;

    if (label !== null && label !== undefined && label !== '') {
        return String(label);
    }

    const value = row[column.key];

    if (value === null || value === undefined || value === '') {
        return '(kosong)';
    }

    // Rujukan yang namanya tidak dikenal lagi (misalnya master milik module yang dicabut): kodenya tidak
    // dapat dibaca orang, jadi tidak ditampilkan.
    if (column.label_key && column.type === 'reference') {
        return '(tanpa nama)';
    }

    if (column.type === 'period') {
        return formatPeriod(String(value), column.granularity);
    }

    if (column.type === 'date') {
        const date = calendarDate(String(value));

        return date === null ? String(value) : DAY.format(date);
    }

    if (column.type === 'datetime') {
        const date = new Date(String(value));

        return Number.isNaN(date.getTime())
            ? String(value)
            : new Intl.DateTimeFormat(LOCALE, {
                  dateStyle: 'medium',
                  timeStyle: 'short',
                  timeZone,
              }).format(date);
    }

    if (column.type === 'number') {
        const amount = numeric(value);

        return amount === null ? String(value) : formatPlain(amount, false);
    }

    return String(value);
}

/**
 * Singkatan resmi zona Indonesia, sama dengan `UserClock::zoneLabel()` di server. Zona lain ditulis sebagai
 * selisihnya dari UTC, karena singkatan seperti IST atau CST dipakai lebih dari satu zona.
 */
const INDONESIAN_ZONES: Record<string, string> = {
    'Asia/Jakarta': 'WIB',
    'Asia/Pontianak': 'WIB',
    'Asia/Makassar': 'WITA',
    'Asia/Jayapura': 'WIT',
};

function zoneLabel(date: Date, timeZone: string): string {
    if (INDONESIAN_ZONES[timeZone]) {
        return INDONESIAN_ZONES[timeZone];
    }

    // `en-US` menulis selisihnya "GMT+08:00"; `id-ID` memakai titik.
    const offset =
        new Intl.DateTimeFormat('en-US', {
            timeZone,
            timeZoneName: 'longOffset',
        })
            .formatToParts(date)
            .find((part) => part.type === 'timeZoneName')?.value ?? 'GMT';

    // Selisih nol ditulis "GMT" atau "GMT+00:00" tergantung peramban; keduanya UTC.
    return /^GMT([+-]00:?00)?$/.test(offset)
        ? 'UTC'
        : offset.replace('GMT', 'UTC');
}

/**
 * Kapan hasil ini dihitung, dalam zona yang sama dengan engine (`meta.timezone`) dan zonanya ditulis:
 * "Dihitung pukul 14.05 WITA", atau dengan tanggal bila bukan hari ini menurut zona itu. Zona perangkat
 * tidak pernah dipakai diam-diam: pengguna tanpa zona dan tanpa entitas legal melihat "UTC" tertulis.
 */
export function formatComputedAt(meta: ResultSet['meta']): string {
    const date = new Date(meta.generated_at);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    let timeZone = meta.timezone;

    try {
        new Intl.DateTimeFormat(LOCALE, { timeZone });
    } catch {
        timeZone = 'UTC';
    }

    const day = (moment: Date) =>
        new Intl.DateTimeFormat('en-CA', { timeZone }).format(moment);
    const time = new Intl.DateTimeFormat(LOCALE, {
        timeZone,
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
    const zone = zoneLabel(date, timeZone);

    if (day(date) === day(new Date())) {
        return `Dihitung pukul ${time} ${zone}`;
    }

    const calendar = new Intl.DateTimeFormat(LOCALE, {
        timeZone,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(date);

    return `Dihitung ${calendar} pukul ${time} ${zone}`;
}

/** Bagian bilangan desimal dari string server: tanda, bagian bulat, dan pecahan. */
function decimalParts(raw: string): [bigint, number] | null {
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(raw.trim());

    if (match === null) {
        return null;
    }

    const fraction = match[3] ?? '';
    const digits = BigInt(`${match[2]}${fraction}`);

    return [match[1] === '-' ? -digits : digits, fraction.length];
}

/**
 * Jumlah beberapa nilai desimal dari server tanpa kehilangan presisi angka JavaScript, misalnya untuk
 * potongan "Lainnya" di grafik donat. Nilai yang tidak dapat dibaca membuat hasilnya null.
 */
export function sumDecimals(values: ResultValue[]): string | null {
    const parts: Array<[bigint, number]> = [];

    for (const value of values) {
        const part = value === null ? null : decimalParts(String(value));

        if (value !== null && part === null) {
            return null;
        }

        parts.push(part ?? [0n, 0]);
    }

    const scale = Math.max(0, ...parts.map(([, digits]) => digits));
    const total = parts.reduce(
        (sum, [amount, digits]) => sum + amount * 10n ** BigInt(scale - digits),
        0n,
    );
    const negative = total < 0n;
    const digits = (negative ? -total : total)
        .toString()
        .padStart(scale + 1, '0');
    const whole = digits.slice(0, digits.length - scale);
    const fraction = digits.slice(digits.length - scale);

    return `${negative ? '-' : ''}${whole}${scale > 0 ? `.${fraction}` : ''}`;
}
