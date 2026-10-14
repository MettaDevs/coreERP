import type { FieldValue } from './fields';

export function monthsPerPeriod(frequency: unknown): number | null {
    return (
        (
            { monthly: 1, quarterly: 3, half_yearly: 6, yearly: 12 } as Record<
                string,
                number
            >
        )[String(frequency)] ?? null
    );
}

export function serviceLifeMonths(
    periods: unknown,
    frequency: unknown,
): number | null {
    const months = monthsPerPeriod(frequency);

    if (periods === '' || periods == null || !months) {
        return null;
    }

    return Math.round(Number(periods) * months * 1e8) / 1e8;
}

export function changeProfileField(
    values: Record<string, FieldValue>,
    name: string,
    value: FieldValue,
): Record<string, FieldValue> {
    const next = { ...values, [name]: value };

    if (name === 'frequency') {
        const months = serviceLifeMonths(
            values.useful_life_periods,
            values.frequency,
        );
        const periodMonths = monthsPerPeriod(value);

        if (months !== null && periodMonths) {
            next.useful_life_periods = months / periodMonths;
        }
    }

    return next;
}

export function serviceLifeLabel(
    periods: unknown,
    frequency: unknown,
): string | null {
    const months = serviceLifeMonths(periods, frequency);

    if (months === null || months <= 0) {
        return null;
    }

    const years = Math.floor(months / 12);
    const remainder = months % 12;

    return [
        years ? `${years} tahun` : '',
        remainder ? `${remainder} bulan` : '',
    ]
        .filter(Boolean)
        .join(' ');
}
