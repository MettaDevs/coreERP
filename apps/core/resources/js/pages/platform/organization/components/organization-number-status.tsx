import { Badge } from '@apperp/ui/badge';
import { CircleAlert } from 'lucide-react';
import type { OperatingUnit } from './organization-types';

const NUMBERED_UNIT_TYPES = ['business_unit', 'department'];

export function needsNumber(unit: OperatingUnit | null | undefined): boolean {
    return (
        unit !== null &&
        unit !== undefined &&
        NUMBERED_UNIT_TYPES.includes(unit.type) &&
        !unit.number
    );
}

export function MissingNumberBadge() {
    return (
        <Badge
            variant="outline"
            className="border-warning/40 text-warning"
            title="Posting finance untuk unit ini akan tertahan sampai nomornya diisi."
        >
            <CircleAlert />
            Belum bernomor
        </Badge>
    );
}
