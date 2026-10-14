import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { DragReorderItem } from '@apperp/ui/drag-reorder-item';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
} from '@apperp/ui/dropdown-menu';
import { Input } from '@apperp/ui/input';
import {
    ResizableHandle,
    ResizablePanel,
    ResizablePanelGroup,
} from '@apperp/ui/resizable';
import { GripVertical, X } from 'lucide-react';
import { useState } from 'react';
import type { DragEvent, KeyboardEvent } from 'react';
import type { DatasetDescription } from '@/lib/analytics/types';

type Selection = {
    columns: string[];
    column_order?: string[];
    groups: string[];
    measures: string[];
    pivot: string;
};
type Item = { kind: 'field' | 'measure'; key: string };
const mime = 'application/x-coreerp-analysis';
function read(
    event: DragEvent | string,
): Item | { kind: 'invalid'; key: string } | null {
    try {
        const item = JSON.parse(
            typeof event === 'string'
                ? event
                : event.dataTransfer.getData(mime),
        );

        return item &&
            (item.kind === 'field' || item.kind === 'measure') &&
            typeof item.key === 'string'
            ? item
            : null;
    } catch {
        return { kind: 'invalid', key: '' };
    }
}
function move(
    items: string[],
    key: string,
    before?: string,
    edge: 'before' | 'after' = 'before',
): string[] {
    if (before === key && items.includes(key)) {
        return items;
    }

    const next = items.filter((item) => item !== key);
    const index = before ? next.indexOf(before) : -1;
    next.splice(
        index < 0 ? next.length : index + (edge === 'after' ? 1 : 0),
        0,
        key,
    );

    return next;
}
/** Drag memakai metadata dataset. Alt+panah mengubah urutan tanpa tombol tambahan. */
export function AnalysisDragSettings({
    dataset,
    selection,
    onChange,
}: {
    dataset: DatasetDescription;
    selection: Selection;
    onChange: (patch: Partial<Selection>) => void;
}) {
    const [search, setSearch] = useState('');
    const order = [
        ...new Set([
            ...(selection.column_order ?? selection.columns),
            ...dataset.fields.map((field) => field.key),
        ]),
    ];
    const ordered = order
        .map((key) => dataset.fields.find((field) => field.key === key))
        .filter(
            (field): field is DatasetDescription['fields'][number] =>
                field !== undefined,
        );
    const visible = ordered.filter(
        (field) =>
            field.caption
                .toLocaleLowerCase()
                .includes(search.toLocaleLowerCase()) ||
            field.key.toLocaleLowerCase().includes(search.toLocaleLowerCase()),
    );
    const reorder = (
        kind: 'columns' | 'groups' | 'measures',
        key: string,
        direction: number,
    ) => {
        const items = kind === 'columns' ? order : selection[kind];
        const index = items.indexOf(key);
        const next = [...items];
        [next[index], next[index + direction]] = [
            next[index + direction],
            next[index],
        ];
        onChange(
            kind === 'columns'
                ? {
                      column_order: next,
                      columns: next.filter((key) =>
                          selection.columns.includes(key),
                      ),
                  }
                : { [kind]: next },
        );
    };
    const keyboard = (
        event: KeyboardEvent,
        kind: 'columns' | 'groups' | 'measures',
        key: string,
    ) => {
        const direction =
            event.key === 'ArrowUp' ? -1 : event.key === 'ArrowDown' ? 1 : 0;
        const items = kind === 'columns' ? order : selection[kind];
        const index = items.indexOf(key);

        if (
            event.altKey &&
            direction &&
            index >= 0 &&
            index + direction >= 0 &&
            index + direction < items.length
        ) {
            event.preventDefault();
            reorder(kind, key, direction);
        }
    };
    const dropGroup = (
        event: DragEvent | string,
        before?: string,
        edge: 'before' | 'after' = 'before',
    ) => {
        if (typeof event !== 'string') {
            event.preventDefault();
            event.stopPropagation();
        }

        const item = read(event);

        if (
            item?.kind !== 'field' ||
            !dataset.fields.some((field) => field.key === item.key)
        ) {
            return;
        }

        if (
            !selection.groups.includes(item.key) &&
            selection.groups.length >= 3
        ) {
            return;
        }

        onChange({
            groups: move(selection.groups, item.key, before, edge),
            pivot: selection.pivot === item.key ? '' : selection.pivot,
        });
    };
    const dropValue = (
        event: DragEvent | string,
        before?: string,
        edge: 'before' | 'after' = 'before',
    ) => {
        if (typeof event !== 'string') {
            event.preventDefault();
            event.stopPropagation();
        }

        const item = read(event);
        const key =
            item?.kind === 'measure'
                ? item.key
                : item?.kind === 'field'
                  ? dataset.measures.find(
                        (measure) =>
                            measure.field_key === item.key &&
                            measure.aggregate === 'sum',
                    )?.key
                  : undefined;

        if (key && dataset.measures.some((measure) => measure.key === key)) {
            onChange({ measures: move(selection.measures, key, before, edge) });
        }
    };

    return (
        <ResizablePanelGroup orientation="vertical" className="min-h-0">
            <ResizablePanel
                id="columns"
                defaultSize="50%"
                minSize={96}
                className="flex min-h-0 flex-col"
            >
                <div className="shrink-0 space-y-2 px-3 py-2">
                    <h2 className="text-sm font-medium">Kolom daftar</h2>
                    <Input
                        aria-label="Cari kolom"
                        placeholder="Cari kolom..."
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                <div className="min-h-0 flex-1 overflow-auto px-2">
                    {visible.map((field) => (
                        <DragReorderItem
                            key={field.key}
                            payload={JSON.stringify({
                                kind: 'field',
                                key: field.key,
                            })}
                            preview={field.caption}
                            mimeType={mime}
                            tabIndex={0}
                            onKeyDown={(event) =>
                                keyboard(event, 'columns', field.key)
                            }
                            onDropItem={(payload, edge) => {
                                const item = read(payload);

                                if (
                                    item?.kind === 'field' &&
                                    dataset.fields.some(
                                        (field) => field.key === item.key,
                                    )
                                ) {
                                    const next = move(
                                        order,
                                        item.key,
                                        field.key,
                                        edge,
                                    );
                                    onChange({
                                        column_order: next,
                                        columns: next.filter((key) =>
                                            selection.columns.includes(key),
                                        ),
                                    });
                                }
                            }}
                            className="flex min-h-8 items-center gap-3 px-1 py-1.5"
                        >
                            <GripVertical className="size-4 shrink-0 cursor-grab text-muted-foreground" />
                            <Checkbox
                                aria-label={field.caption}
                                checked={selection.columns.includes(field.key)}
                                onCheckedChange={(checked) =>
                                    onChange({
                                        column_order: order,
                                        columns: checked
                                            ? order.filter(
                                                  (key) =>
                                                      key === field.key ||
                                                      selection.columns.includes(
                                                          key,
                                                      ),
                                              )
                                            : selection.columns.filter(
                                                  (key) => key !== field.key,
                                              ),
                                    })
                                }
                            />
                            <span className="min-w-0 flex-1 text-sm">
                                {field.caption}
                            </span>
                        </DragReorderItem>
                    ))}
                    {visible.length === 0 && (
                        <p className="px-1 py-3 text-sm text-muted-foreground">
                            Kolom tidak ditemukan.
                        </p>
                    )}
                </div>
            </ResizablePanel>
            <ResizableHandle aria-label="Ubah tinggi daftar kolom dan Row Groups" />
            <ResizablePanel id="groups" defaultSize="25%" minSize={64}>
                <section
                    aria-label="Row Groups"
                    className="flex h-full min-h-0 flex-col"
                    onDragOver={(event) => event.preventDefault()}
                    onDrop={(event) => dropGroup(event)}
                >
                    <h2 className="shrink-0 px-3 py-2 text-sm font-medium">
                        Row Groups
                    </h2>
                    <div className="flex min-h-0 flex-1 flex-col gap-2 overflow-auto px-3 pb-2">
                        {selection.groups.length === 0 && (
                            <p className="text-xs text-muted-foreground">
                                Seret kolom ke sini untuk mengelompokkan.
                                Maksimal 3 tingkat.
                            </p>
                        )}
                        {selection.groups.map((key) => {
                            const caption =
                                dataset.fields.find(
                                    (field) => field.key === key,
                                )?.caption ?? key;

                            return (
                                <DragReorderItem
                                    key={key}
                                    payload={JSON.stringify({
                                        kind: 'field',
                                        key,
                                    })}
                                    preview={caption}
                                    mimeType={mime}
                                    tabIndex={0}
                                    onKeyDown={(event) =>
                                        keyboard(event, 'groups', key)
                                    }
                                    onDropItem={(payload, edge) =>
                                        dropGroup(payload, key, edge)
                                    }
                                    className="flex items-center gap-1"
                                >
                                    <GripVertical className="size-4 shrink-0 cursor-grab" />
                                    <span className="min-w-0 flex-1 text-sm">
                                        {caption}
                                    </span>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Hapus grup ${caption}`}
                                        onClick={() =>
                                            onChange({
                                                groups: selection.groups.filter(
                                                    (item) => item !== key,
                                                ),
                                            })
                                        }
                                    >
                                        <X />
                                    </Button>
                                </DragReorderItem>
                            );
                        })}
                    </div>
                </section>
            </ResizablePanel>
            <ResizableHandle aria-label="Ubah tinggi Row Groups dan Values" />
            <ResizablePanel id="values" defaultSize="25%" minSize={64}>
                <section
                    aria-label="Values"
                    className="flex h-full min-h-0 flex-col"
                    onDragOver={(event) => event.preventDefault()}
                    onDrop={(event) => dropValue(event)}
                >
                    <h2 className="shrink-0 px-3 py-2 text-sm font-medium">
                        Values
                    </h2>
                    <div className="flex min-h-0 flex-1 flex-col gap-2 overflow-auto px-3 pb-2">
                        {selection.measures.length === 0 && (
                            <p className="text-xs text-muted-foreground">
                                Seret kolom angka ke sini.
                            </p>
                        )}
                        {selection.measures.map((key) => {
                            const measure = dataset.measures.find(
                                (item) => item.key === key,
                            );

                            if (!measure) {
                                return null;
                            }

                            const field = dataset.fields.find(
                                (item) => item.key === measure.field_key,
                            );
                            const caption = field?.caption ?? measure.caption;
                            const label = (
                                {
                                    sum: 'Sum',
                                    min: 'Min',
                                    max: 'Max',
                                    avg: 'Average',
                                    count: 'Count',
                                    count_distinct: 'Count distinct',
                                } as const
                            )[measure.aggregate];
                            const choices = measure.field_key
                                ? dataset.measures.filter(
                                      (item) =>
                                          item.field_key === measure.field_key,
                                  )
                                : [measure];

                            choices.sort(
                                (a, b) =>
                                    [
                                        'sum',
                                        'min',
                                        'max',
                                        'avg',
                                        'count',
                                        'count_distinct',
                                    ].indexOf(a.aggregate) -
                                    [
                                        'sum',
                                        'min',
                                        'max',
                                        'avg',
                                        'count',
                                        'count_distinct',
                                    ].indexOf(b.aggregate),
                            );

                            return (
                                <DragReorderItem
                                    key={key}
                                    payload={JSON.stringify({
                                        kind: 'measure',
                                        key,
                                    })}
                                    preview={`${label}(${caption})`}
                                    mimeType={mime}
                                    tabIndex={0}
                                    onKeyDown={(event) =>
                                        keyboard(event, 'measures', key)
                                    }
                                    onDropItem={(payload, edge) =>
                                        dropValue(payload, key, edge)
                                    }
                                    className="flex items-center gap-1 rounded-full bg-accent px-2"
                                >
                                    <GripVertical className="size-3 shrink-0 cursor-grab" />
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                size="xs"
                                                variant="ghost"
                                                className="min-w-0 flex-1 justify-start"
                                                aria-label={`Perhitungan ${label}(${caption})`}
                                            >
                                                <span className="truncate">
                                                    {label}({caption})
                                                </span>
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent>
                                            <DropdownMenuRadioGroup
                                                value={key}
                                                onValueChange={(next) =>
                                                    onChange({
                                                        measures:
                                                            selection.measures
                                                                .map((item) =>
                                                                    item === key
                                                                        ? next
                                                                        : item,
                                                                )
                                                                .filter(
                                                                    (
                                                                        item,
                                                                        index,
                                                                        items,
                                                                    ) =>
                                                                        items.indexOf(
                                                                            item,
                                                                        ) ===
                                                                        index,
                                                                ),
                                                    })
                                                }
                                            >
                                                {choices.map((choice) => (
                                                    <DropdownMenuRadioItem
                                                        key={choice.key}
                                                        value={choice.key}
                                                    >
                                                        {
                                                            (
                                                                {
                                                                    sum: 'Sum',
                                                                    min: 'Min',
                                                                    max: 'Max',
                                                                    avg: 'Average',
                                                                    count: 'Count',
                                                                    count_distinct:
                                                                        'Count distinct',
                                                                } as const
                                                            )[choice.aggregate]
                                                        }
                                                    </DropdownMenuRadioItem>
                                                ))}
                                            </DropdownMenuRadioGroup>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                    <Button
                                        size="icon-xs"
                                        variant="ghost"
                                        aria-label={`Hapus nilai ${label}(${caption})`}
                                        onClick={() =>
                                            onChange({
                                                measures:
                                                    selection.measures.filter(
                                                        (item) => item !== key,
                                                    ),
                                            })
                                        }
                                    >
                                        <X />
                                    </Button>
                                </DragReorderItem>
                            );
                        })}
                    </div>
                </section>
            </ResizablePanel>
        </ResizablePanelGroup>
    );
}
