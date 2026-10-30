import ServiceItemsPage from '@/pages/foundation/item/service-items';
import type { ModulePageProps } from '../App';

export default function SubcontractItemPage({
    permissions = [],
}: Partial<ModulePageProps>) {
    const canManage =
        permissions.includes('procurement.item-subkont.update') ||
        permissions.includes('procurement.item-subkont.create');

    return <ServiceItemsPage canManage={canManage} />;
}
