import { useEffect, useRef, useState } from "react";
import { Badge } from "@apperp/ui/badge";
import { Button } from "@apperp/ui/button";
import {
  Card,
  CardAction,
  CardContent,
  CardHeader,
  CardTitle,
} from "@apperp/ui/card";
import { DataTable, type DataTableColumn } from "@apperp/ui/data-table";
import {
  Empty,
  EmptyDescription,
  EmptyHeader,
  EmptyTitle,
} from "@apperp/ui/empty";
import { Field, FieldError, FieldGroup, FieldLabel } from "@apperp/ui/field";
import { Input } from "@apperp/ui/input";
import { Select } from "@apperp/ui/select";
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@apperp/ui/sheet";
import { Switch } from "@apperp/ui/switch";
import { Textarea } from "@apperp/ui/textarea";
import { api, errorMessage } from "../../api";

type Profile = {
  id: string;
  kode: string;
  nama: string;
  method: string;
  frequency: string;
  useful_life_periods: number | null;
  aktif: boolean;
};
const methodLabels: Record<string, string> = {
  straight_line: "Garis lurus",
  reducing_balance: "Saldo menurun",
  manual: "Jadwal manual",
  consumption: "Berdasarkan pemakaian",
};
const frequencyLabels: Record<string, string> = {
  monthly: "Bulanan",
  quarterly: "Triwulanan",
  half_yearly: "Semesteran",
  yearly: "Tahunan",
};
const initialForm = {
  nama: "",
  method: "straight_line",
  frequency: "monthly",
  year_basis: "calendar",
  useful_life_periods: "",
  rate_percent: "",
  manual_schedule: "",
  aktif: true,
};

export default function DepreciationProfilePage({
  canCreate,
}: {
  canCreate: boolean;
}) {
  const [items, setItems] = useState<Profile[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(initialForm);
  const key = useRef(crypto.randomUUID());
  const load = async () => {
    setLoading(true);
    try {
      const result = await api<{ data: Profile[] }>("/profil-penyusutan");
      setItems(result.data);
      setError("");
    } catch (caught) {
      setError(errorMessage(caught, "Profil penyusutan belum dapat dimuat."));
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    void load();
  }, []);
  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setError("");
    const schedule = form.manual_schedule
      .split("\n")
      .map((value) => value.trim())
      .filter(Boolean);
    if (
      form.method === "manual" &&
      (!schedule.length ||
        schedule.some(
          (value) => !Number.isFinite(Number(value)) || Number(value) < 0,
        ))
    ) {
      setError(
        "Isi jadwal manual dengan satu nilai penyusutan nol atau lebih pada setiap baris.",
      );
      return;
    }
    try {
      await api("/profil-penyusutan", {
        method: "POST",
        headers: { "Idempotency-Key": key.current },
        body: JSON.stringify({
          ...form,
          useful_life_periods: form.useful_life_periods
            ? Number(form.useful_life_periods)
            : null,
          rate_percent: form.rate_percent ? Number(form.rate_percent) : null,
          manual_schedule:
            form.method === "manual"
              ? schedule.map((amount) => ({ amount: Number(amount) }))
              : null,
        }),
      });
      setOpen(false);
      key.current = crypto.randomUUID();
      setForm(initialForm);
      await load();
    } catch (caught) {
      setError(errorMessage(caught, "Profil penyusutan belum dapat disimpan."));
    }
  };
  const columns: DataTableColumn<Profile>[] = [
    { id: "kode", header: "Kode", cell: (item) => item.kode, width: 150 },
    {
      id: "nama",
      header: "Nama profil",
      cell: (item) => item.nama,
      width: 250,
    },
    {
      id: "method",
      header: "Metode",
      cell: (item) => methodLabels[item.method] ?? item.method,
      width: 200,
    },
    {
      id: "frequency",
      header: "Frekuensi",
      cell: (item) => frequencyLabels[item.frequency] ?? item.frequency,
      width: 150,
    },
    {
      id: "life",
      header: "Masa manfaat",
      cell: (item) =>
        item.useful_life_periods ? `${item.useful_life_periods} periode` : "—",
      width: 150,
    },
    {
      id: "status",
      header: "Status",
      cell: (item) => (
        <Badge variant={item.aktif ? "default" : "secondary"}>
          {item.aktif ? "Aktif" : "Tidak aktif"}
        </Badge>
      ),
      width: 110,
    },
  ];
  return (
    <div>
      <Card className="min-h-full rounded-none border-0 shadow-none">
        <CardHeader className="min-h-0 border-b px-5 py-3">
          <CardTitle className="text-base">Profil penyusutan</CardTitle>
          {canCreate && (
            <CardAction>
              <Button onClick={() => setOpen(true)}>＋ Tambah profil</Button>
            </CardAction>
          )}
        </CardHeader>
        <CardContent className="px-0">
          {error ? (
            <Empty>
              <EmptyHeader>
                <EmptyTitle>Profil belum dapat ditampilkan</EmptyTitle>
                <EmptyDescription>{error}</EmptyDescription>
              </EmptyHeader>
              <Button variant="outline" onClick={load}>
                Coba lagi
              </Button>
            </Empty>
          ) : loading ? (
            <Empty>
              <EmptyDescription>Memuat profil penyusutan…</EmptyDescription>
            </Empty>
          ) : items.length === 0 ? (
            <Empty>
              <EmptyHeader>
                <EmptyTitle>Belum ada profil penyusutan</EmptyTitle>
                <EmptyDescription>
                  Buat profil sebelum aset memakai buku penyusutan.
                </EmptyDescription>
              </EmptyHeader>
            </Empty>
          ) : (
            <DataTable
              columns={columns}
              data={items}
              getRowKey={(item) => item.id}
              getRowLabel={(item) => item.nama}
            />
          )}
        </CardContent>
      </Card>
      {open && (
        <Sheet open onOpenChange={(value) => !value && setOpen(false)}>
          <SheetContent side="right" className="w-full p-0 sm:max-w-xl">
            <SheetHeader className="border-b px-6 py-5 pr-12">
              <SheetTitle>Tambah profil penyusutan</SheetTitle>
            </SheetHeader>
            <form className="flex min-h-0 flex-1 flex-col" onSubmit={save}>
              <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                <FieldGroup>
                  <Field>
                    <Input
                      label="Nama profil *"
                      required
                      value={form.nama}
                      onChange={(event) =>
                        setForm({ ...form, nama: event.target.value })
                      }
                    />
                  </Field>
                  <Field>
                    <FieldLabel>Metode *</FieldLabel>
                    <Select
                      items={Object.values(methodLabels)}
                      value={methodLabels[form.method]}
                      ariaLabel="Metode penyusutan"
                      onValueChange={(value) =>
                        setForm({
                          ...form,
                          method:
                            Object.entries(methodLabels).find(
                              ([, label]) => label === value,
                            )?.[0] ?? "straight_line",
                        })
                      }
                    />
                  </Field>
                  <Field>
                    <FieldLabel>Frekuensi *</FieldLabel>
                    <Select
                      items={Object.values(frequencyLabels)}
                      value={frequencyLabels[form.frequency]}
                      ariaLabel="Frekuensi penyusutan"
                      onValueChange={(value) =>
                        setForm({
                          ...form,
                          frequency:
                            Object.entries(frequencyLabels).find(
                              ([, label]) => label === value,
                            )?.[0] ?? "monthly",
                        })
                      }
                    />
                  </Field>
                  <Field>
                    <FieldLabel>Dasar tahun *</FieldLabel>
                    <Select
                      items={["Kalender", "Fiskal"]}
                      value={
                        form.year_basis === "fiscal" ? "Fiskal" : "Kalender"
                      }
                      ariaLabel="Dasar tahun"
                      onValueChange={(value) =>
                        setForm({
                          ...form,
                          year_basis:
                            value === "Fiskal" ? "fiscal" : "calendar",
                        })
                      }
                    />
                  </Field>
                  {form.method !== "consumption" && (
                    <Field>
                      <Input
                        label="Masa manfaat (periode)"
                        type="number"
                        min="1"
                        value={form.useful_life_periods}
                        onChange={(event) =>
                          setForm({
                            ...form,
                            useful_life_periods: event.target.value,
                          })
                        }
                      />
                    </Field>
                  )}
                  {form.method === "reducing_balance" && (
                    <Field>
                      <Input
                        label="Persentase per tahun *"
                        type="number"
                        min="0.0001"
                        step="0.0001"
                        value={form.rate_percent}
                        onChange={(event) =>
                          setForm({ ...form, rate_percent: event.target.value })
                        }
                      />
                    </Field>
                  )}
                  {form.method === "manual" && (
                    <Field>
                      <FieldLabel>Jadwal manual *</FieldLabel>
                      <Textarea
                        value={form.manual_schedule}
                        onChange={(event) =>
                          setForm({
                            ...form,
                            manual_schedule: event.target.value,
                          })
                        }
                        placeholder={
                          "Satu nilai per periode, satu baris per nilai.\nContoh:\n100000\n100000"
                        }
                      />
                    </Field>
                  )}
                  <Field orientation="horizontal">
                    <Switch
                      id="profile-active"
                      checked={form.aktif}
                      onCheckedChange={(aktif) => setForm({ ...form, aktif })}
                    />
                    <FieldLabel htmlFor="profile-active">
                      Profil aktif dan dapat dipilih
                    </FieldLabel>
                  </Field>
                  {error && <FieldError>{error}</FieldError>}
                </FieldGroup>
              </div>
              <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                <Button
                  variant="outline"
                  type="button"
                  onClick={() => setOpen(false)}
                >
                  Batal
                </Button>
                <Button>Simpan</Button>
              </SheetFooter>
            </form>
          </SheetContent>
        </Sheet>
      )}
    </div>
  );
}
