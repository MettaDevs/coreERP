export const title = "Copy sandbox / recovery sesudah perbaikan";
export const state = v => {
 const changed = v.sourceModules !== 1 ? " / production sudah berubah" : "";
 const complete = Object.values(v.clean).every(Boolean);
 if (v.phase === "start") return "Sebelum restore";
 if (v.phase === "degraded") return (complete ? "Terhenti setelah pelucutan lengkap" : "Terhenti saat pelucutan belum lengkap") + changed;
 if (v.phase === "active") return "Sandbox aktif dan terlucuti" + changed;
 if (v.phase === "rejected") return "Hasil salinan ditolak";
 if (complete) return "Pelucutan lengkap / pemeriksaan atau pengulangan" + changed;
 return (Object.values(v.clean).some(Boolean) ? "Pelucutan sebagian sudah tersimpan" : "Restore lengkap / mulai pelucutan") + changed;
};
export const step = action => ({Restore:"Restore selesai",Disarm:"Lucuti bagian berikutnya",Fail:"Pelucutan gagal",RetryCopy:"Operator melanjutkan copy dari pelucutan",ClickProvision:"Operator mencoba Siapkan / ditolak tanpa perubahan",SourceChanges:"Production bertambah module selama copy / urutan bebas",Health:"Periksa hasil terhadap snapshot restore",Done:"Selesai"}[action] ?? action);

