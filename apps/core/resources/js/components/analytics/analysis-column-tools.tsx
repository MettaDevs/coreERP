import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import { useState } from 'react';
import type { DatasetDescription, DatasetField } from '@/lib/analytics/types';
type Filters = Record<string, string | string[]>;
const textOperators = [
    ['contains', 'Contains'],
    ['not_contains', 'Not contains'],
    ['equals', 'Equals'],
    ['not_equal', 'Not equal'],
    ['starts', 'Starts with'],
    ['ends', 'Ends with'],
    ['blank', 'Blank'],
    ['not_blank', 'Not blank'],
];
const scalarOperators = [
    ['equals', 'Equals'],
    ['not_equal', 'Not equal'],
    ['gt', 'Greater than'],
    ['gte', 'Greater than or equal'],
    ['lt', 'Less than'],
    ['lte', 'Less than or equal'],
    ['blank', 'Blank'],
    ['not_blank', 'Not blank'],
];
function quoted(value: string) {
    return `'${value.replaceAll("'", "''")}'`;
}
function expression(operator: string, value: string, text: boolean): string {
    if (operator === 'blank') {
        return "''";
    }

    if (operator === 'not_blank') {
        return "<>''";
    }

    if (!value.trim()) {
        return '';
    }

    const literal = quoted(value);
    const insensitive = text ? '@' : '';

    switch (operator) {
        case 'contains':
            return `@*${literal}*`;
        case 'not_contains':
            return `<>@*${literal}*`;
        case 'starts':
            return `@${literal}*`;
        case 'ends':
            return `@*${literal}`;
        case 'not_equal':
            return `<>${insensitive}${literal}`;
        case 'gt':
            return `>${literal}`;
        case 'gte':
            return `>=${literal}`;
        case 'lt':
            return `<${literal}`;
        case 'lte':
            return `<=${literal}`;
        default:
            return `${insensitive}${literal}`;
    }
}
function decode(source: string, text: boolean) {
    if (source === "''") {
        return { operator: 'blank', value: '' };
    }

    if (source === "<>''") {
        return { operator: 'not_blank', value: '' };
    }

    const match = source.match(
        /^(<>|>=|<=|>|<)?(@)?(\*)?'((?:[^']|'')*)'(\*)?$/,
    );

    if (!match) {
        return {
            operator: source ? 'equals' : text ? 'contains' : 'equals',
            value: source,
        };
    }

    const [, op, , before, value, after] = match;
    const operator =
        before && after
            ? op === '<>'
                ? 'not_contains'
                : 'contains'
            : after
              ? 'starts'
              : before
                ? 'ends'
                : op === '<>'
                  ? 'not_equal'
                  : op === '>'
                    ? 'gt'
                    : op === '>='
                      ? 'gte'
                      : op === '<'
                        ? 'lt'
                        : op === '<='
                          ? 'lte'
                          : 'equals';

    return { operator, value: value.replaceAll("''", "'") };
}
function HeaderFilter({
    field,
    filterKey,
    filters,
    onChange,
}: {
    field: DatasetField;
    filterKey: string;
    filters: Filters;
    onChange: (filters: Filters) => void;
}) {
    const text = field.type === 'text' || field.type === 'reference';
    const source =
        typeof filters[filterKey] === 'string'
            ? (filters[filterKey] as string)
            : '';
    const [draft, setDraft] = useState<{
        source: string;
        operator: string;
        value: string;
    } | null>(null);
    const active =
        draft?.source === source ? draft : { source, ...decode(source, text) };
    const update = (operator: string, value: string) => {
        const next = expression(operator, value, text);
        setDraft({ source: next, operator, value });
        const result = { ...filters };

        if (next) {
            result[filterKey] = next;
        } else {
            delete result[filterKey];
        }

        onChange(result);
    };

    return (
        <div className="flex flex-col gap-2">
            <h3 className="text-sm font-medium">{field.caption}</h3>
            <NativeSelect
                aria-label={`Operator filter ${field.caption}`}
                value={active.operator}
                onChange={(event) => update(event.target.value, active.value)}
            >
                {(text ? textOperators : scalarOperators).map(
                    ([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ),
                )}
            </NativeSelect>
            {!['blank', 'not_blank'].includes(active.operator) && (
                <Input
                    aria-label={`Isi filter ${field.caption}`}
                    placeholder="Filter..."
                    maxLength={200}
                    value={active.value}
                    onChange={(event) =>
                        update(active.operator, event.target.value)
                    }
                />
            )}
            <Button
                size="xs"
                variant="ghost"
                onClick={() => {
                    setDraft(null);
                    const next = { ...filters };
                    delete next[filterKey];
                    onChange(next);
                }}
            >
                Hapus filter
            </Button>
        </div>
    );
}
export function analysisColumnTools(
    dataset: DatasetDescription,
    selection: { groups: string[]; filters: Filters },
    onChange: (patch: { groups?: string[]; filters?: Filters }) => void,
) {
    return Object.fromEntries(
        dataset.fields.map((field) => {
            const key = field.label_filter_key ?? field.key;
            const filterable =
                ['text', 'number', 'date', 'datetime'].includes(field.type) ||
                Boolean(field.label_filter_key);

            return [
                field.key,
                {
                    headerFilter: filterable ? (
                        <HeaderFilter
                            field={field}
                            filterKey={key}
                            filters={selection.filters}
                            onChange={(filters) => onChange({ filters })}
                        />
                    ) : undefined,
                    filterActive: Boolean(selection.filters[key]),
                    headerActions: selection.groups.includes(field.key)
                        ? [
                              {
                                  label: `Ungroup by ${field.caption}`,
                                  onSelect: () =>
                                      onChange({
                                          groups: selection.groups.filter(
                                              (key) => key !== field.key,
                                          ),
                                      }),
                              },
                          ]
                        : field.type !== 'number' && selection.groups.length < 3
                          ? [
                                {
                                    label: `Group by ${field.caption}`,
                                    onSelect: () =>
                                        onChange({
                                            groups: [
                                                ...selection.groups,
                                                field.key,
                                            ],
                                        }),
                                },
                            ]
                          : [],
                },
            ];
        }),
    );
}
