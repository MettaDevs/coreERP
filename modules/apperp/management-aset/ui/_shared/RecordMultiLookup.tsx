import { X } from 'lucide-react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import RecordLookup from './RecordLookup';
import type { RecordLookupProps } from './RecordLookup';

/** Pilihan banyak tetap memakai pencarian bertabel dan pembuat record milik lookup tunggal. */
export default function RecordMultiLookup<T extends { id: string }>({
    value,
    onValueChange,
    ...props
}: Omit<RecordLookupProps<T>, 'value' | 'onValueChange' | 'defaultValue'> & {
    value: string[];
    onValueChange: (ids: string[]) => void;
}) {
    const choices = props.items.map((item) =>
        typeof item === 'string' ? { value: item, label: item } : item,
    );

    return (
        <div className="space-y-2">
            <RecordLookup
                {...props}
                value={null}
                filterKey={JSON.stringify([props.filterKey ?? '', value])}
                required={false}
                filterRecord={(record) =>
                    !value.includes(record.id) &&
                    (!props.filterRecord || props.filterRecord(record))
                }
                onValueChange={(id) => {
                    if (id && !value.includes(id)) {
                        onValueChange([...value, id]);
                    }
                }}
            />
            {value.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {value.map((id) => {
                        const record = props.source.options.find(
                            (item) => item.id === id,
                        );
                        const label = record
                            ? props.source.getLabel(record)
                            : (choices.find((item) => item.value === id)
                                  ?.label ?? id);

                        return (
                            <Badge key={id} variant="secondary">
                                {label}
                                <Button
                                    type="button"
                                    size="icon-sm"
                                    variant="ghost"
                                    aria-label={`Keluarkan ${label}`}
                                    disabled={props.disabled}
                                    onClick={() =>
                                        onValueChange(
                                            value.filter((item) => item !== id),
                                        )
                                    }
                                >
                                    <X aria-hidden="true" />
                                </Button>
                            </Badge>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
export { RecordMultiLookup };
