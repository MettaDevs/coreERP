import PaymentMethodsPage from '@/pages/foundation/payment-method/payment-methods';
import type { ModulePageProps } from '../App';

export default function PaymentMethodPage({
    permissions = [],
}: Partial<ModulePageProps>) {
    const canManage =
        permissions.includes('procurement.metode-pembayaran.update') ||
        permissions.includes('procurement.metode-pembayaran.create');

    return <PaymentMethodsPage canManage={canManage} />;
}
