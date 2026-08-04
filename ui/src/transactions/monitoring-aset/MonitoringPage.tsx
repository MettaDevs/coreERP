import { useEffect, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@apperp/ui/card";
import {
  Empty,
  EmptyDescription,
  EmptyHeader,
  EmptyTitle,
} from "@apperp/ui/empty";
import { api, errorMessage } from "../../api";
type Asset = {
  id: string;
  kode: string;
  lifecycle_state: string;
  acquisition_value: string;
  currency_code: string;
};
export default function MonitoringPage() {
  const [assets, setAssets] = useState<Asset[]>([]);
  const [error, setError] = useState("");
  useEffect(() => {
    api<{ data: Asset[] }>("/aset")
      .then((x) => setAssets(x.data))
      .catch((e) =>
        setError(errorMessage(e, "Monitoring belum dapat dimuat.")),
      );
  }, []);
  return (
    <Card className="min-h-full rounded-none border-0 shadow-none">
      <CardHeader className="border-b px-5 py-3">
        <CardTitle>Monitoring aset</CardTitle>
      </CardHeader>
      <CardContent className="px-0">
        {error && <p className="px-5 py-3 text-sm text-destructive">{error}</p>}
        {!assets.length ? (
          <Empty>
            <EmptyHeader>
              <EmptyTitle>Belum ada aset untuk dipantau</EmptyTitle>
              <EmptyDescription>
                Monitoring menampilkan status register aset yang tersedia.
              </EmptyDescription>
            </EmptyHeader>
          </Empty>
        ) : (
          <div className="divide-y">
            {assets.map((asset) => (
              <div
                className="flex items-center justify-between px-5 py-3"
                key={asset.id}
              >
                <div>
                  <p className="font-medium">{asset.kode}</p>
                  <p className="text-sm text-muted-foreground">
                    Status: {asset.lifecycle_state}
                  </p>
                </div>
                <span>
                  {asset.currency_code} {asset.acquisition_value}
                </span>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
