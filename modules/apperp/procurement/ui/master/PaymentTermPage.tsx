import PaymentTermsPage from "@/pages/foundation/payment-term/payment-terms";
import type { ModulePageProps } from "../App";

export default function PaymentTermPage({
    permissions = [],
}: Partial<ModulePageProps>) {
    const canManage =
        permissions.includes("procurement.termin-pembayaran.update") ||
        permissions.includes("procurement.termin-pembayaran.create");

    return <PaymentTermsPage canManage={canManage} />;
}
