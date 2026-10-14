import { useEffect, useEffectEvent, useState } from 'react';
import type { RefObject } from 'react';
import { api, errorMessage } from '../../api';
import DynamicField from '../../master/DynamicField';
import { payloadValue, valueFrom } from '../../master/fields';
import type { AttributeDefinition } from './attributes';
import { toFieldConfig } from './attributes';

export type AttributeValue = { tipe_atribut_id: string; nilai: unknown };

export default function ReceiptAttributes({
    typeId,
    rowKey,
    values,
    readOnly,
    portalContainer,
    onChange,
}: {
    typeId: string;
    rowKey: string;
    values: AttributeValue[];
    readOnly: boolean;
    portalContainer: RefObject<HTMLDivElement | null>;
    onChange: (values: AttributeValue[]) => void;
}) {
    const [definitions, setDefinitions] = useState<AttributeDefinition[]>([]);
    const [error, setError] = useState('');
    const initialize = useEffectEvent((items: AttributeDefinition[]) => {
        if (readOnly) {
            return;
        }

        const current = Object.fromEntries(
            values.map((row) => [row.tipe_atribut_id, row.nilai]),
        );
        onChange(
            items.map((item) => {
                const config = toFieldConfig(item);

                return {
                    tipe_atribut_id: item.tipe_atribut_id,
                    nilai: payloadValue(config, valueFrom(current, config)),
                };
            }),
        );
    });

    useEffect(() => {
        let cancelled = false;
        api<{ data: AttributeDefinition[] }>(
            `/jenis-aset/${typeId}/atribut-definisi`,
        )
            .then((result) => {
                if (!cancelled) {
                    setDefinitions(result.data);
                    initialize(result.data);
                }
            })
            .catch((caught) => {
                if (!cancelled) {
                    setError(
                        errorMessage(
                            caught,
                            'Isian tambahan barang belum dapat dimuat.',
                        ),
                    );
                }
            });

        return () => {
            cancelled = true;
        };
    }, [typeId, readOnly]);

    const current = Object.fromEntries(
        values.map((row) => [row.tipe_atribut_id, row.nilai]),
    );

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}
            {definitions.map((definition) => {
                const config = toFieldConfig(definition);

                return (
                    <DynamicField
                        key={definition.tipe_atribut_id}
                        config={{
                            ...config,
                            name: `receipt-${rowKey}-${definition.tipe_atribut_id}`,
                        }}
                        value={valueFrom(current, config)}
                        readOnly={readOnly}
                        portalContainer={portalContainer}
                        onChange={(next) =>
                            onChange([
                                ...values.filter(
                                    (row) =>
                                        row.tipe_atribut_id !==
                                        definition.tipe_atribut_id,
                                ),
                                {
                                    tipe_atribut_id: definition.tipe_atribut_id,
                                    nilai: payloadValue(config, next),
                                },
                            ])
                        }
                    />
                );
            })}
        </div>
    );
}
