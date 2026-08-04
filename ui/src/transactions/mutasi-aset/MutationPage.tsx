import { useEffect, useState } from "react";
import { Button } from "@apperp/ui/button";
import {
  Card,
  CardAction,
  CardContent,
  CardHeader,
  CardTitle,
} from "@apperp/ui/card";
import {
  Empty,
  EmptyDescription,
  EmptyHeader,
  EmptyTitle,
} from "@apperp/ui/empty";
import { Field } from "@apperp/ui/field";
import { Input } from "@apperp/ui/input";
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@apperp/ui/sheet";
import { api, errorMessage } from "../../api";
type Asset = { id: string; kode: string; lifecycle_state: string };
export default function MutationPage() {
  const [assets, setAssets] = useState<Asset[]>([]);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const load = () =>
    api<{ data: Asset[] }>("/aset")
      .then((x) => setAssets(x.data))
      .catch((e) => setError(errorMessage(e, "Aset belum dapat dimuat.")));
  useEffect(() => {
    void load();
  }, []);
  async function save(form: HTMLFormElement) {
    const data = new FormData(form);
    setSaving(true);
    try {
      await api("/aset/" + data.get("asset_id") + "/penempatan", {
        method: "POST",
        body: JSON.stringify({
          effective_on: data.get("effective_on"),
          reason: data.get("reason"),
          usage_org_unit_id: data.get("usage_org_unit_id") || null,
          custodian_user_id: data.get("custodian_user_id") || null,
          asset_location_id: data.get("asset_location_id") || null,
        }),
      });
      setOpen(false);
      form.reset();
      await load();
    } catch (e) {
      setError(errorMessage(e, "Mutasi belum dapat disimpan."));
    } finally {
      setSaving(false);
    }
  }
  return (
    <Card className="min-h-full rounded-none border-0 shadow-none">
      <CardHeader className="border-b px-5 py-3">
        <CardTitle>Mutasi aset</CardTitle>
        <CardAction>
          <Button onClick={() => setOpen(true)}>Catat mutasi</Button>
        </CardAction>
      </CardHeader>
      <CardContent className="px-0">
        {error && <p className="px-5 py-3 text-sm text-destructive">{error}</p>}
        {!assets.length ? (
          <Empty>
            <EmptyHeader>
              <EmptyTitle>Belum ada aset</EmptyTitle>
              <EmptyDescription>
                Terima aset terlebih dahulu sebelum memindahkan penggunaan, PIC,
                atau lokasi.
              </EmptyDescription>
            </EmptyHeader>
          </Empty>
        ) : (
          <div className="divide-y">
            {assets.map((asset) => (
              <div key={asset.id} className="flex justify-between px-5 py-3">
                <span>{asset.kode}</span>
                <span className="text-sm text-muted-foreground">
                  {asset.lifecycle_state}
                </span>
              </div>
            ))}
          </div>
        )}
      </CardContent>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="right">
          <SheetHeader>
            <SheetTitle>Catat mutasi aset</SheetTitle>
          </SheetHeader>
          <form
            className="space-y-4 p-4"
            onSubmit={(e) => {
              e.preventDefault();
              void save(e.currentTarget);
            }}
          >
            <Field>
              <Input name="asset_id" label="ID aset" required />
            </Field>
            <Field>
              <Input
                name="effective_on"
                label="Berlaku sejak"
                type="date"
                required
              />
            </Field>
            <Field>
              <Input name="reason" label="Alasan mutasi" required />
            </Field>
            <Field>
              <Input name="usage_org_unit_id" label="ID unit pengguna" />
            </Field>
            <Field>
              <Input name="custodian_user_id" label="ID PIC aset" />
            </Field>
            <Field>
              <Input name="asset_location_id" label="ID lokasi aset" />
            </Field>
            <SheetFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(false)}
              >
                Batal
              </Button>
              <Button type="submit" disabled={saving}>
                {saving ? "Menyimpan…" : "Simpan mutasi"}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </Card>
  );
}
