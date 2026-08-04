import { createRoot } from "react-dom/client";
import { Button } from "@apperp/ui/button";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@apperp/ui/card";
import { Input } from "@apperp/ui/input";
import { NativeSelect } from "@apperp/ui/native-select";
import { applyCoreErpTheme, type CoreErpTheme } from "@apperp/ui/theme";
import { type Dispatch, type SetStateAction, useEffect, useState } from "react";
import "./styles.css";

type AppContext = { token: string; theme: CoreErpTheme };
type ViewId = "workers" | "jobs" | "positions" | "worker-position-assignments";
type Worker = {
  id: string;
  name: string;
  personnel_number: string;
  email?: string;
};
type Job = { id: string; name: string; code: string };
type Position = {
  id: string;
  name: string;
  code: string;
  operating_unit_id: string;
};
type Assignment = {
  id: string;
  worker_id: string;
  position_id: string;
  valid_from: string;
};
type OperatingUnit = { id: string; name: string };
type CoreMember = { membership_id: string; name: string; email: string };
type Record = Worker | Job | Position | Assignment;
type FormProps = {
  view: ViewId;
  name: string;
  setName: Dispatch<SetStateAction<string>>;
  members: CoreMember[];
  memberId: string;
  setMemberId: Dispatch<SetStateAction<string>>;
  jobs: Job[];
  jobId: string;
  setJobId: Dispatch<SetStateAction<string>>;
  units: OperatingUnit[];
  unitId: string;
  setUnitId: Dispatch<SetStateAction<string>>;
  workers: Worker[];
  workerId: string;
  setWorkerId: Dispatch<SetStateAction<string>>;
  positions: Position[];
  positionId: string;
  setPositionId: Dispatch<SetStateAction<string>>;
  validFrom: string;
  setValidFrom: Dispatch<SetStateAction<string>>;
  validUntil: string;
  setValidUntil: Dispatch<SetStateAction<string>>;
};

const views: { id: ViewId; label: string }[] = [
  { id: "workers", label: "Pekerja" },
  { id: "jobs", label: "Jabatan" },
  { id: "positions", label: "Posisi" },
  { id: "worker-position-assignments", label: "Penugasan posisi" },
];

function selectedView(): ViewId {
  return (
    views.find((view) => `#/${view.id}` === window.location.hash)?.id ??
    "workers"
  );
}

function App() {
  const [context, setContext] = useState<AppContext | null>(null);
  const [view, setView] = useState<ViewId>(selectedView);
  const [records, setRecords] = useState<Record[]>([]);
  const [workers, setWorkers] = useState<Worker[]>([]);
  const [jobs, setJobs] = useState<Job[]>([]);
  const [positions, setPositions] = useState<Position[]>([]);
  const [units, setUnits] = useState<OperatingUnit[]>([]);
  const [members, setMembers] = useState<CoreMember[]>([]);
  const [name, setName] = useState("");
  const [jobId, setJobId] = useState("");
  const [unitId, setUnitId] = useState("");
  const [workerId, setWorkerId] = useState("");
  const [positionId, setPositionId] = useState("");
  const [memberId, setMemberId] = useState("");
  const [validFrom, setValidFrom] = useState(
    new Date().toISOString().slice(0, 10),
  );
  const [validUntil, setValidUntil] = useState("");
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const currentView = views.find((item) => item.id === view)!;

  useEffect(() => {
    const parentOrigin = document.referrer
      ? new URL(document.referrer).origin
      : "";
    const receiveContext = (event: MessageEvent) => {
      if (event.source !== window.parent || event.origin !== parentOrigin)
        return;
      if (event.data?.type !== "coreerp.context") return;

      applyCoreErpTheme(event.data.theme as CoreErpTheme);
      setContext({ token: event.data.contextToken, theme: event.data.theme });
    };
    const receiveNavigation = () => setView(selectedView());

    window.addEventListener("message", receiveContext);
    window.addEventListener("hashchange", receiveNavigation);
    window.parent.postMessage({ type: "coreerp.ready" }, parentOrigin || "*");

    return () => {
      window.removeEventListener("message", receiveContext);
      window.removeEventListener("hashchange", receiveNavigation);
    };
  }, []);

  const request = async (path: string) => {
    const response = await fetch(`/api/v1/${path}`, {
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${context?.token}`,
      },
    });
    if (!response.ok) throw new Error("Data belum dapat dimuat.");
    return response.json();
  };

  const refresh = async () => {
    if (!context) return;
    try {
      const payload = await request(view);
      setRecords(payload.data ?? []);
    } catch {
      setRecords([]);
      setMessage("Data belum dapat dimuat. Periksa akses Anda.");
    }
  };

  const loadLookups = async () => {
    if (!context) return;

    const [workerData, jobData, positionData, unitData, memberData] =
      await Promise.all([
        request("workers"),
        request("jobs"),
        request("positions"),
        request("operating-units"),
        request("core-members").catch(() => ({ data: [] })),
      ]);

    setWorkers(workerData.data ?? []);
    setJobs(jobData.data ?? []);
    setPositions(positionData.data ?? []);
    setUnits(unitData.data ?? []);
    setMembers(memberData.data ?? []);
  };

  useEffect(() => {
    void refresh();
  }, [context, view]);

  useEffect(() => {
    if (!context) return;
    void loadLookups().catch(() => undefined);
  }, [context]);

  const create = async () => {
    if (!context || (!name.trim() && view !== "worker-position-assignments"))
      return;

    const payload =
      view === "workers"
        ? { name, core_membership_id: memberId || undefined }
        : view === "jobs"
          ? { name }
          : view === "positions"
            ? {
                name,
                job_id: jobId,
                operating_unit_id: unitId,
                valid_from: validFrom,
                valid_until: validUntil || undefined,
              }
            : {
                worker_id: workerId,
                position_id: positionId,
                valid_from: validFrom,
                valid_until: validUntil || undefined,
                is_primary: true,
              };

    setSaving(true);
    setMessage("");
    try {
      const response = await fetch(`/api/v1/${view}`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${context.token}`,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          ...payload,
          idempotency_key: `${view}:${crypto.randomUUID()}`,
        }),
      });
      const result = await response.json();
      if (!response.ok)
        throw new Error(result.message ?? "Data belum dapat disimpan.");

      setName("");
      setMemberId("");
      setValidUntil("");
      setMessage("Data tersimpan.");
      await refresh();
      await loadLookups();
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Data belum dapat disimpan.",
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <main className="hr-page">
      <Card>
        <CardHeader>
          <CardTitle>{currentView.label}</CardTitle>
          <CardDescription>{description(view)}</CardDescription>
          <CardAction>
            <Button onClick={create} disabled={saving}>
              {saving ? "Menyimpan..." : "Simpan"}
            </Button>
          </CardAction>
        </CardHeader>
        <CardContent className="space-y-5">
          {!context && <p>Menyiapkan sesi aplikasi...</p>}
          {context && (
            <CreateForm
              {...{
                view,
                name,
                setName,
                members,
                memberId,
                setMemberId,
                jobs,
                jobId,
                setJobId,
                units,
                unitId,
                setUnitId,
                workers,
                workerId,
                setWorkerId,
                positions,
                positionId,
                setPositionId,
                validFrom,
                setValidFrom,
                validUntil,
                setValidUntil,
              }}
            />
          )}
          {message && (
            <p className="hr-message" role="status">
              {message}
            </p>
          )}
          {context && records.length === 0 && (
            <p>Belum ada data untuk ditampilkan.</p>
          )}
          {records.length > 0 && (
            <RecordsTable
              records={records}
              workers={workers}
              positions={positions}
              units={units}
            />
          )}
        </CardContent>
      </Card>
    </main>
  );
}

function CreateForm(props: FormProps) {
  const { view } = props;
  if (view === "worker-position-assignments")
    return (
      <div className="hr-create">
        <NativeSelect
          label="Pekerja"
          value={props.workerId}
          onChange={(event) => props.setWorkerId(event.target.value)}
        >
          <option value="">Pilih pekerja</option>
          {props.workers.map((worker: Worker) => (
            <option key={worker.id} value={worker.id}>
              {worker.name}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect
          label="Posisi"
          value={props.positionId}
          onChange={(event) => props.setPositionId(event.target.value)}
        >
          <option value="">Pilih posisi</option>
          {props.positions.map((position: Position) => (
            <option key={position.id} value={position.id}>
              {position.name}
            </option>
          ))}
        </NativeSelect>
        <Dates {...props} />
      </div>
    );
  if (view === "positions")
    return (
      <div className="hr-create">
        <Input
          label="Nama posisi"
          value={props.name}
          onChange={(event) => props.setName(event.target.value)}
        />
        <NativeSelect
          label="Jabatan"
          value={props.jobId}
          onChange={(event) => props.setJobId(event.target.value)}
        >
          <option value="">Pilih jabatan</option>
          {props.jobs.map((job: Job) => (
            <option key={job.id} value={job.id}>
              {job.name}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect
          label="Unit kerja"
          value={props.unitId}
          onChange={(event) => props.setUnitId(event.target.value)}
        >
          <option value="">Pilih unit kerja</option>
          {props.units.map((unit: OperatingUnit) => (
            <option key={unit.id} value={unit.id}>
              {unit.name}
            </option>
          ))}
        </NativeSelect>
        <Dates {...props} />
      </div>
    );
  return (
    <div className="hr-create">
      <Input
        label={`Nama ${view === "workers" ? "pekerja" : "jabatan"}`}
        value={props.name}
        onChange={(event) => props.setName(event.target.value)}
      />
      {view === "workers" && (
        <NativeSelect
          label="Anggota Core (opsional)"
          value={props.memberId}
          onChange={(event) => props.setMemberId(event.target.value)}
        >
          <option value="">Belum ditautkan</option>
          {props.members.map((member: CoreMember) => (
            <option key={member.membership_id} value={member.membership_id}>
              {member.name} ({member.email})
            </option>
          ))}
        </NativeSelect>
      )}
    </div>
  );
}

function Dates({
  validFrom,
  setValidFrom,
  validUntil,
  setValidUntil,
}: Pick<
  FormProps,
  "validFrom" | "setValidFrom" | "validUntil" | "setValidUntil"
>) {
  return (
    <>
      <Input
        label="Mulai berlaku"
        type="date"
        value={validFrom}
        onChange={(event) => setValidFrom(event.target.value)}
      />
      <Input
        label="Berakhir (opsional)"
        type="date"
        value={validUntil}
        onChange={(event) => setValidUntil(event.target.value)}
      />
    </>
  );
}

function RecordsTable({
  records,
  workers,
  positions,
  units,
}: {
  records: Record[];
  workers: Worker[];
  positions: Position[];
  units: OperatingUnit[];
}) {
  const workerName = (id: string) =>
    workers.find((worker) => worker.id === id)?.name ?? id;
  const position = (id: string) => positions.find((item) => item.id === id);
  const unitName = (id: string) =>
    units.find((unit) => unit.id === id)?.name ?? id;
  return (
    <table className="hr-table">
      <thead>
        <tr>
          <th>Nama / kode</th>
          <th>Informasi</th>
        </tr>
      </thead>
      <tbody>
        {records.map((record) => (
          <tr key={record.id}>
            <td>
              {"worker_id" in record
                ? workerName(record.worker_id)
                : record.name}
            </td>
            <td>
              {"worker_id" in record
                ? `${position(record.position_id)?.name ?? record.position_id} · ${record.valid_from}`
                : "operating_unit_id" in record
                  ? `${record.code} · ${unitName(record.operating_unit_id)}`
                  : "personnel_number" in record
                    ? record.personnel_number
                    : record.code}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function description(view: ViewId) {
  if (view === "workers")
    return "Pilih anggota Core bila pekerja membutuhkan akses aplikasi. Email hanya informasi, bukan kunci hubungan.";
  if (view === "positions")
    return "Posisi selalu ditempatkan pada satu unit kerja dari pengaturan Organisasi Core.";
  if (view === "worker-position-assignments")
    return "Satu pekerja dapat memiliki posisi aktif di lebih dari satu unit kerja.";
  return "Jabatan menjadi dasar untuk membuat posisi kerja.";
}

createRoot(document.getElementById("root")!).render(<App />);
