export const title = "Copy sandbox / gagal pelucutan dan pilihan retry";
export const state = v => {
 const source = v.sourceModules !== 1 ? " / module sumber berubah" : "";
 if(v.phase === "start") return "1. Sebelum restore";
 if(v.phase === "degraded") return `4. Gagal sesudah ${v.nextStep-1} bagian pelucutan${source}`;
 if(v.phase === "active") return (Object.values(v.clean).every(Boolean) ? "6. Sandbox aktif dan terlucuti" : "6. BUG sandbox aktif dengan pelucutan belum lengkap")+source;
 if(v.phase === "rejected") return "5. BUG snapshot lengkap dianggap restore rusak";
 return `3.${v.nextStep}. Pelucutan ${Math.min(v.nextStep-1,7)} dari 7 bagian selesai${source}`;
};
export const step = (action,from,to) => ({Restore:"2 / A. Restore selesai",Disarm:`3.${from.nextStep} / A. Lucuti bagian ${from.nextStep}`,Fail:"4 / A. Proses gagal",RetryCopy:"3.1 / Operator. Lanjutkan copy dari pelucutan",ClickProvision:"6 / Operator. Klik Coba siapkan lagi",SourceChanges:"B. Module di production bertambah / dapat bersamaan",Health:"5 / A. Periksa kesehatan",Done:"Selesai"}[action] ?? action);
