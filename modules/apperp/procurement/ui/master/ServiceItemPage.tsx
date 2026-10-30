import ServiceItemsPage from '@/pages/foundation/item/service-items';
import type { ModulePageProps } from '../App';

export default function ServiceItemPage({
    permissions = [],
}: Partial<ModulePageProps>) {
    const canManage =
        permissions.includes('procurement.item-jasa.update') ||
        permissions.includes('procurement.item-jasa.create');

    return <ServiceItemsPage canManage={canManage} />;
}
