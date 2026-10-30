export const title = "Pengambilalihan lease penyiapan environment";
export const state = v => {
 const downgraded = v.opB === "succeeded" && v.failedCallback && v.env === "degraded";
 if (v.stolen && downgraded) return "Lease baru direbut dan hasil pengganti diturunkan";
 if (v.stolen) return "Lease baru tetap direbut";
 if (downgraded) return "Environment aktif diturunkan pekerja lama";
 if (v.opB === "succeeded") return "Pengganti berhasil; environment aktif";
 if (v.retiredA && v.failedCallback) return "Pekerja lama gagal; pengganti masih berjalan";
 if (v.retiredA) return "Pengganti berjalan; operasi lama dicabut";
 if (v.observedExpired && v.leaseValid) return "Lease diperpanjang; pembacaan lama masih tersimpan";
 if (v.observedExpired) return "Pengambil alih membaca lease kedaluwarsa";
 if (v.opA === "running" && !v.leaseValid) return "Lease habis; pekerja lama masih hidup";
 return v.opA === "running" ? "Pekerja A berjalan" : "Belum ada penyiapan";
};
export const step = action => ({StartA:"Penyiapan dimulai",ExpireLease:"Lease habis",ReadExpired:"Percobaan lain membaca lease",RenewA:"Pekerja memperpanjang lease",TakeOver:"Percobaan lain mengambil alih",FinishB:"Pekerja pengganti berhasil",OldWorkerFails:"Pekerja lama kembali melaporkan gagal",Done:"Operasi selesai"}[action] ?? action);
