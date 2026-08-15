import { useEffect, useRef, useState } from "react";
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
import { Field, FieldDescription } from "@apperp/ui/field";
import { Input } from "@apperp/ui/input";
import { Select } from "@apperp/ui/select";
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@apperp/ui/sheet";
import { api, errorMessage } from "../../api";
import { optionLabel, useMasterOptions } from "../../master/useMasterOptions";
type Asset = { id: string; kode: string; lifecycle_state: string };
export default function MutationPage() {
  const [assets, setAssets] = useState<Asset[]>([]);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [assetId, setAssetId] = useState("");
  const [locationId, setLocationId] = useState("");
  const locations = useMasterOptions("lokasi-aset");
  const sheetContentRef = useRef<HTMLDivElement>(null);
  const load = () =>
    api<{ data: Asset[] }>("/aset")
      .then((x) => setAssets(x.data))
      .catch((e) => setError(errorMessage(e, "Aset belum dapat dimuat.")));
  useEffect(() => {
    void load();
  }, []);
  async function save(form: HTMLFormElement) {
    const data = new FormData(form);
    if (!assetId) {
      setError("Pilih aset yang dimutasi terlebih dahulu.");
      return;
    }
    setSaving(true);
    try {
      await api("/aset/" + assetId + "/penempatan", {
        method: "POST",
        body: JSON.stringify({
          effective_on: data.get("effective_on"),
          reason: data.get("reason"),
          usage_org_unit_id: data.get("usage_org_unit_id"),
          custodian_user_id: data.get("custodian_user_id") || null,
          // Memindahkan aset ke lokasi yang dipetakan ke unit lain ikut memindahkan
          // dimensi keuangannya, jadi lokasi dipilih dari master, bukan diketik.
          asset_location_id: locationId || null,
        }),
      });
      setOpen(false);
      form.reset();
      setAssetId("");
      setLocationId("");
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
        <SheetContent ref={sheetContentRef} side="right">
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
              <Select
                label="Aset"
                required
                items={assets.map((asset) => asset.kode)}
                value={assets.find((asset) => asset.id === assetId)?.kode}
                placeholder="Pilih aset"
                searchPlaceholder="Cari kode aset"
                emptyMessage="Aset tidak ditemukan."
                ariaLabel="Pilih aset yang dimutasi"
                portalContainer={sheetContentRef}
                onValueChange={(item) =>
                  setAssetId(assets.find((asset) => asset.kode === item)?.id ?? "")
                }
              />
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
              <Input name="usage_org_unit_id" label="ID unit pengguna" required />
            </Field>
            <Field>
              <Input name="custodian_user_id" label="ID PIC aset" />
            </Field>
            <Field>
              <Select
                label="Lokasi aset"
                items={locations.options.map(optionLabel)}
                value={
                  locations.options
                    .filter((option) => option.id === locationId)
                    .map(optionLabel)[0]
                }
                placeholder="Tidak berubah"
                searchPlaceholder="Cari lokasi aset"
                emptyMessage="Lokasi aset tidak ditemukan."
                ariaLabel="Pilih lokasi aset"
                portalContainer={sheetContentRef}
                onValueChange={(item) =>
                  setLocationId(
                    locations.options.find(
                      (option) => optionLabel(option) === item,
                    )?.id ?? "",
                  )
                }
              />
              <FieldDescription>
                {locations.error ||
                  "Lokasi yang dipetakan ke unit organisasi ikut memindahkan dimensi keuangan aset."}
              </FieldDescription>
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
