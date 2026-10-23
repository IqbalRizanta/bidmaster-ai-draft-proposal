import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { CalendarDays, FileClock, History, Plus, Trash2 } from "lucide-react";
import { Alert, Button, Card, SkeletonRows } from "../components/ui/controls";
import { toast } from "../components/ui/toast";
import { ProposalApi } from "../lib/proposals";
import { T } from "../ui-text";
import { api, authHeaders } from "../lib/utils";

type Row = { id: number; instansi: string; status: string; updated_at?: string; dibuat_oleh: number; current_version: number };

const STATUS_TONE: Record<string, string> = {
  draf: "bg-slate-100 text-slate-600 border-slate-200",
  dianalisis: "bg-blue-50 text-blue-700 border-blue-200",
  disetujui: "bg-green-50 text-green-700 border-green-200",
  diunduh: "bg-emerald-50 text-emerald-800 border-emerald-300",
};

export default function Riwayat() {
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState("");
  const [confirm, setConfirm] = useState<number | null>(null);
  const [audit, setAudit] = useState<{ ts: number; aksi: string; detail: string }[]>([]);
  const [showAudit, setShowAudit] = useState(false);
  const [vers, setVers] = useState<Record<number, { versi: number }[]>>({});
  const [openV, setOpenV] = useState<number | null>(null);

  async function load() {
    try {
      setRows((await ProposalApi.list()).proposals);
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => { load(); }, []);

  async function hapus(id: number) {
    try {
      await ProposalApi.remove(id);
      setConfirm(null);
      toast("Proposal dihapus.", "ok");
      load();
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
    }
  }

  async function lihatAudit() {
    setShowAudit(true);
    try {
      setAudit((await api<{ audit: { ts: number; aksi: string; detail: string }[] }>("/api/audit", { headers: authHeaders() })).audit.slice(0, 30));
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
    }
  }

  async function toggleVersi(id: number) {
    if (openV === id) { setOpenV(null); return; }
    try {
      const j = await api<{ versions: { versi: number }[] }>(`/api/proposals/${id}/versions`, { headers: authHeaders() });
      setVers((m) => ({ ...m, [id]: j.versions }));
      setOpenV(id);
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
    }
  }

  async function unduhVersi(id: number, v: number, format: string) {
    setErr("");
    try {
      const r = await fetch(`/api/proposals/${id}/versions/${v}/file?format=${format}`, { headers: authHeaders() });
      if (!r.ok) { toast(T.umum.cobaLagi, "error"); return; }
      const nama = (r.headers.get("Content-Disposition") ?? "").match(/filename="(.+?)"/)?.[1] ?? `proposal-v${v}.${format}`;
      const a = document.createElement("a");
      a.href = URL.createObjectURL(await r.blob());
      a.download = nama;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 4000);
      toast(`Versi ${v} berhasil diunduh.`, "ok");
    } catch {
      toast(T.umum.cobaLagi, "error");
    }
  }

  return (
    <main className="max-w-4xl mx-auto px-4 py-6">
      <div className="flex items-center gap-3 mb-4 flex-wrap">
        <span className="w-11 h-11 rounded-xl grad-brand flex items-center justify-center text-white shrink-0"><History size={20} aria-hidden /></span>
        <div className="min-w-0">
          <h1 className="font-extrabold text-xl text-[#0F1B3D]">{T.riwayat.judul}</h1>
          <p className="text-sm text-slate-500">Semua proposal tim beserta status dan versinya.</p>
        </div>
        <Link to="/app" className="ml-auto no-underline">
          <Button size="sm"><Plus size={15} aria-hidden /> {T.nav.baru}</Button>
        </Link>
      </div>

      <Card>
        {err !== "" && <div className="mb-3" role="alert"><Alert kind="error">{err}</Alert></div>}
        {loading ? (
          <SkeletonRows rows={4} />
        ) : rows.length === 0 ? (
          <div className="text-center py-10">
            <span className="w-14 h-14 rounded-2xl bg-slate-100 mx-auto flex items-center justify-center text-slate-400"><FileClock size={26} aria-hidden /></span>
            <p className="text-base text-slate-600 mt-3">{T.riwayat.kosong}</p>
            <Link to="/app" className="no-underline"><Button size="sm" className="mt-3"><Plus size={15} aria-hidden /> {T.nav.baru}</Button></Link>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[600px]">
              <thead><tr className="text-left text-xs uppercase text-slate-600 bg-[#EEF0F8]">
                <th className="py-2.5 px-2 rounded-l-lg">{T.riwayat.kolomTanggal}</th>
                <th className="py-2.5 px-2">{T.riwayat.kolomInstansi}</th>
                <th className="py-2.5 px-2">{T.riwayat.kolomStatus}</th>
                <th className="py-2.5 px-2 rounded-r-lg">{T.riwayat.buka}</th>
              </tr></thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} className="border-t border-slate-200 text-slate-700 hover:bg-slate-50/70 transition-colors">
                    <td className="py-3 px-2 whitespace-nowrap text-slate-500">
                      <span className="inline-flex items-center gap-1.5"><CalendarDays size={14} aria-hidden />{r.updated_at ? new Date(r.updated_at).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) : "—"}</span>
                    </td>
                    <td className="py-3 px-2 font-semibold text-[#0F1B3D]">{r.instansi || "(tanpa nama)"}</td>
                    <td className="py-3 px-2">
                      <span className={`inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold ${STATUS_TONE[r.status] ?? "bg-slate-100 text-slate-600 border-slate-200"}`}>{r.status}</span>
                    </td>
                    <td className="py-3 px-2">
                      <div className="flex gap-1 flex-wrap items-center">
                        <Link to={`/app/${r.id}`} className="text-brand font-semibold text-sm px-2.5 py-2 rounded-lg hover:bg-brand-light no-underline inline-flex items-center min-h-11">{T.riwayat.buka}</Link>
                        <button onClick={() => toggleVersi(r.id)} className="text-slate-600 text-sm hover:text-[#0F1B3D] hover:bg-slate-100 px-2.5 py-2 rounded-lg min-h-11">
                          {T.harga.versi} ({(vers[r.id] ?? []).length || r.current_version || 0})</button>
                        <button onClick={() => setConfirm(r.id)} aria-label={`${T.riwayat.hapus} ${r.instansi}`}
                          className="text-slate-500 hover:text-red-600 hover:bg-red-50 p-2.5 rounded-lg min-w-11 min-h-11 inline-flex items-center justify-center">
                          <Trash2 size={15} aria-hidden />
                        </button>
                      </div>
                      {openV === r.id && (
                        <div className="text-xs text-slate-600 mt-2 bg-[#F7F9FE] border border-slate-200 rounded-lg px-3 py-2">
                          {(vers[r.id] ?? []).map((v) => (
                            <span key={v.versi} className="mr-3 inline-flex items-center gap-1">v{v.versi}
                              <button onClick={() => unduhVersi(r.id, v.versi, "docx")} className="text-brand underline">.docx</button>
                              <button onClick={() => unduhVersi(r.id, v.versi, "md")} className="text-brand underline">.md</button>
                            </span>
                          ))}
                          {(vers[r.id] ?? []).length === 0 && <span className="text-slate-500">Belum ada versi tersimpan.</span>}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="mt-4">
          <Button variant="secondary" size="sm" onClick={() => (showAudit ? setShowAudit(false) : lihatAudit())}>
            <History size={14} aria-hidden /> {showAudit ? T.umum.tutup : T.riwayat.audit}
          </Button>
          {showAudit && (
            <div className="mt-3 border border-slate-200 rounded-xl divide-y divide-slate-100 max-h-72 overflow-y-auto">
              {audit.length === 0 && <div className="p-4 text-sm text-slate-500">Belum ada aktivitas.</div>}
              {audit.map((a, i) => (
                <div key={i} className="text-xs text-slate-600 px-3 py-2 flex gap-3">
                  <span className="text-slate-400 whitespace-nowrap shrink-0">{new Date(a.ts * 1000).toLocaleString("id-ID", { dateStyle: "short", timeStyle: "short" })}</span>
                  <span><b className="text-slate-700">{a.aksi}</b> — {a.detail}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </Card>

      {confirm !== null && (
        <div className="fixed inset-0 z-[80] bg-black/60 flex items-center justify-center p-4" onClick={() => setConfirm(null)}
          onKeyDown={(e) => { if (e.key === "Escape") setConfirm(null); }}>
          <div role="dialog" aria-modal="true" aria-labelledby="hapus-title" className="bg-white border border-[#C9DDEE] rounded-2xl p-6 max-w-sm w-full anim-pop shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center gap-3">
              <span className="w-10 h-10 rounded-xl bg-red-100 text-red-700 flex items-center justify-center shrink-0"><Trash2 size={18} aria-hidden /></span>
              <p id="hapus-title" className="text-base text-slate-800 font-semibold">{T.riwayat.hapusTanya}</p>
            </div>
            <div className="mt-5 flex gap-2 justify-end">
              <Button variant="secondary" size="sm" onClick={() => setConfirm(null)}>{T.riwayat.batal}</Button>
              <Button size="sm" className="bg-red-600 hover:bg-red-700 text-white" onClick={() => hapus(confirm)}>{T.riwayat.ya}</Button>
            </div>
          </div>
        </div>
      )}
    </main>
  );
}
