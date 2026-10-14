import { AdHocAnalysis } from "@/components/analytics/ad-hoc-analysis";

export default function FixedAssetLedgerPage() {
    return (
        <AdHocAnalysis
            datasetCode="management-aset.fixed-asset-ledger"
            title="Transaksi nilai aset"
            defaults={{
                columns: [
                    "asset_code",
                    "asset_name",
                    "posting_date",
                    "document_number",
                    "posting_type",
                    "group_aset_id",
                    "book_code",
                    "amount",
                    "is_reconstructed",
                ],
                groups: ["group_aset_id", "asset_code"],
                measures: ["sum_amount"],
            }}
        />
    );
}
