import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { BadgeCheck, Check, Circle, Download, FileCheck2, Landmark, Percent, Sparkles, Tag, Trash2 } from "lucide-react";
import { Alert, Badge, Button, Card, FieldError, Input, Label, Select, SkeletonRows } from "../components/ui/controls";
import { toast } from "../components/ui/toast";
import type { Proposal } from "../lib/proposals";
import { T } from "../ui-text";
import { api, authHeaders, fmtRp } from "../lib/utils";
import { mdToHtml, printMarkdown } from "../lib/print";

type Row = {
  kode: string; nama: string; satuan: string; qty: number;
  harga_acuan: number; harga_proposal: number; alasan: string; alasan_catatan: string;
  subtotal?: number; deviasi?: number; lencana?: string;
};
type Ringkas = {
  rows: Row[]; subtotal: number; diskon_rp: number; total: number;
  perlu_persetujuan: boolean; sebab: string[]; pagu_ok: boolean | null;
};

const ALASAN = ["kontrak lanjutan", "volume besar", "tingkat kesulitan", "negosiasi klien", "lainnya"];

export default function Step3({ p, setP, refresh, goStep }: {
  p: Proposal; setP: (p: Proposal) => void; refresh: () => void; goStep: (n: number) => void;
}) {
  const nav = useNavigate();
  const [rows, setRows] = useState<Row[]>([]);
  const [ring, setRing] = useState<Ringkas | null>(null);
  const [tipe, setTipe] = useState("persen");
  const [nilai, setNilai] = useState(0);
  const [alasanD, setAlasanD] = useState("");
  const [oleh, setOleh] = useState("");
  const [centang, setCentang] = useState(false);
  const [kat, setKat] = useState<{ kode: string; nama: string; satuan: string; harga_dasar: number }[]>([]);
  const [cari, setCari] = useState("");
  const [err, setErr] = useState("");
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [role, setRole] = useState("");
  const [legal, setLegal] = useState({ nib: "", npwp: "", akta: "" });
  const [companyName, setCompanyName] = useState("PT Solusi Teknologi Nusantara");
  const [kurang, setKurang] = useState<string[]>([]);
  const [preview, setPreview] = useState("");
  const [lampS, setLampS] = useState(true);
  const [lampK, setLampK] = useState(true);
  const [sukses, setSukses] = useState("");
  const [versions, setVersions] = useState<{ versi: number; pada?: string }[]>([]);
  const [tried, setTried] = useState(false);
  const [savedSnapshot, setSavedSnapshot] = useState("");

  useEffect(() => {
    setLoading(true);
    api<{ tender: { komponen: { kode: string; nama: string; satuan: string; harga_dasar: number }[] } }>("/api/catalog")
      .then((c) => setKat(c.tender.komponen)).catch(() => setErr(T.umum.cobaLagi));
    api<{ nama: string; peran: string }>("/api/me").then((u) => setRole(u.peran)).catch(() => {});
    api<{ nama_resmi?: string; legalitas: { nib: string; npwp: string; akta: string } }>("/api/company")
      .then((c) => {
        if (c.nama_resmi) setCompanyName(c.nama_resmi);
        setLegal(c.legalitas);
      }).catch(() => {});
    api<{ ringkasan: Ringkas; proposal: Proposal }>(`/api/proposals/${p.id}/prices`, { headers: authHeaders() })
      .then((j) => {
        const loadedRows = (j.ringkasan.rows ?? []).map((r) => ({
          ...r,
          alasan: r.alasan ?? "",
          alasan_catatan: r.alasan_catatan ?? "",
        }));
        setRows(loadedRows);
        setRing(j.ringkasan); setP(j.proposal);
        const d = (j.proposal as unknown as { discount?: { tipe?: string; nilai?: number; alasan?: string } }).discount;
        let lTipe = "persen";
        let lNilai = 0;
        let lAlasan = "";
        if (d && typeof d === "object" && !Array.isArray(d)) {
          lTipe = d.tipe === "nominal" ? "nominal" : "persen";
          lNilai = Number(d.nilai) || 0;
          lAlasan = typeof d.alasan === "string" ? d.alasan : "";
          setTipe(lTipe);
          setNilai(lNilai);
          setAlasanD(lAlasan);
        }
        setSavedSnapshot(JSON.stringify({ rows: loadedRows, tipe: lTipe, nilai: lNilai, alasanD: lAlasan }));
      }).catch((e) => setErr(e instanceof Error ? e.message : T.umum.cobaLagi))
      .finally(() => setLoading(false));
    api<{ ok: boolean; kurang: string[] }>(`/api/proposals/${p.id}/download-check`, { headers: authHeaders() })
      .then((j) => setKurang(j.ok ? [] : j.kurang)).catch(() => {});
    api<{ versions: { versi: number; pada?: string }[] }>(`/api/proposals/${p.id}/versions`, { headers: authHeaders() })
      .then((j) => setVersions(j.versions)).catch(() => {});
  }, [p.id, setP]);

  function rpParse(s: string): number {
    const n = parseInt(s.replace(/[^0-9]/g, ""), 10);
    return Number.isNaN(n) ? 0 : n;
  }

  const liveSubtotal = useMemo(() => rows.reduce((acc, r) => acc + (r.harga_proposal * r.qty), 0), [rows]);
  const liveDiskonRp = useMemo(() => {
    if (nilai <= 0) return 0;
    if (tipe === "persen") return Math.round(liveSubtotal * (Math.min(100, Math.max(0, nilai)) / 100));
    return Math.min(liveSubtotal, Math.max(0, nilai));
  }, [liveSubtotal, tipe, nilai]);
  const liveTotal = useMemo(() => Math.max(0, liveSubtotal - liveDiskonRp), [liveSubtotal, liveDiskonRp]);
  const livePaguOk = p.pagu == null ? true : liveTotal <= p.pagu;

  const currentSnapshot = JSON.stringify({ rows, tipe, nilai, alasanD });
  const isDirty = savedSnapshot !== "" && savedSnapshot !== currentSnapshot;

  const adaDiskon = nilai > 0;
  const alasanRusak = rows.some((r) => r.harga_proposal !== r.harga_acuan && r.alasan.trim() === "");
  const diskonErr = adaDiskon && alasanD.trim() === "" ? T.validasi.diskonAlasanWajib : "";
  const setujuErr = tried && (!centang || oleh.trim() === "") ? T.validasi.setujuWajib : "";

  async function simpan(): Promise<boolean> {
    setTried(true);
    if (alasanRusak) { setErr(T.validasi.alasanWajib); toast(T.validasi.alasanWajib, "warn"); return false; }
    if (diskonErr !== "") { setErr(diskonErr); toast(diskonErr, "warn"); return false; }
    setErr("");
    setBusy(true);
    try {
      const j = await api<{ proposal: Proposal; ringkasan: Ringkas }>(`/api/proposals/${p.id}/prices`, {
        method: "PATCH", headers: authHeaders({ "Content-Type": "application/json" }),
        body: JSON.stringify({
          rows: rows.map((r) => ({ kode: r.kode, qty: r.qty, harga_proposal: r.harga_proposal, alasan: r.alasan, alasan_catatan: r.alasan_catatan })),
          diskon: { tipe, nilai, alasan: alasanD },
        }),
      });
      setP(j.proposal);
      setRing(j.ringkasan);
      setRows(j.ringkasan.rows);
      setSavedSnapshot(JSON.stringify({ rows: j.ringkasan.rows, tipe, nilai, alasanD }));
      toast(T.harga.simpan, "ok");
      const c = await api<{ ok: boolean; kurang: string[] }>(`/api/proposals/${p.id}/download-check`, { headers: authHeaders() });
      setKurang(c.ok ? [] : c.kurang);
      return true;
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function ensureSaved(): Promise<boolean> {
    if (!isDirty) return true;
    return await simpan();
  }

  function tambah(kode: string) {
    const k = kat.find((x) => x.kode === kode);
    if (!k || rows.some((r) => r.kode === kode)) return;
    setRows([...rows, { kode: k.kode, nama: k.nama, satuan: k.satuan, qty: 1, harga_acuan: k.harga_dasar, harga_proposal: k.harga_dasar, alasan: "", alasan_catatan: "" }]);
  }

  function hapusRow(kode: string) {
    setRows(rows.filter((r) => r.kode !== kode));
  }

  async function lihatPreview() {
    setErr("");
    if (!await ensureSaved()) return;
    try {
      const j = await api<{ markdown: string }>(`/api/proposals/${p.id}/preview`, {
        method: "POST", headers: authHeaders({ "Content-Type": "application/json" }),
        body: JSON.stringify({ lampiran_sumber: lampS, lampiran_klarifikasi: lampK }),
      });
      setPreview(j.markdown);
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
    }
  }

  /** Cetak lewat portal tersembunyi agar tidak blank. */
  async function cetak() {
    setErr("");
    if (!await ensureSaved()) return;
    try {
      const j = await api<{ markdown: string }>(`/api/proposals/${p.id}/preview`, {
        method: "POST", headers: authHeaders({ "Content-Type": "application/json" }),
        body: JSON.stringify({ lampiran_sumber: lampS, lampiran_klarifikasi: lampK }),
      });
      printMarkdown(j.markdown, `${p.instansi || "Proposal"} · #${p.id}`, {
        logo: "/NusaTech-Logo.png", company: companyName,
      });
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
    }
  }

  async function unduh(format: "docx" | "md") {
    setErr("");
    if (!await ensureSaved()) return;
    try {
      const r = await fetch(`/api/proposals/${p.id}/export`, {
        method: "POST", headers: authHeaders({ "Content-Type": "application/json" }),
        body: JSON.stringify({ format, lampiran_sumber: lampS, lampiran_klarifikasi: lampK }),
      });
      if (!r.ok) {
        const j = await r.json().catch(() => ({}));
        setKurang(j.kurang ?? []);
        throw new Error(j.error ?? T.umum.cobaLagi);
      }
      const nama = (r.headers.get("Content-Disposition") ?? "").match(/filename="(.+?)"/)?.[1] ?? `proposal.${format}`;
      const a = document.createElement("a");
      a.href = URL.createObjectURL(await r.blob());
      a.download = nama;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 4000);
      setSukses(nama);
      toast("Unduhan berhasil.", "ok");
      refresh();
      api<{ versions: { versi: number; pada?: string }[] }>(`/api/proposals/${p.id}/versions`, { headers: authHeaders() })
        .then((j) => setVersions(j.versions)).catch(() => {});
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
    }
  }

  async function unduhVersi(v: number, format: "docx" | "md") {
    setErr("");
    try {
      const r = await fetch(`/api/proposals/${p.id}/versions/${v}/file?format=${format}`, { headers: authHeaders() });
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

  const approvals = (p as unknown as { approvals?: { oleh: string }[] }).approvals ?? [];

  // Pulihkan nama penyetuju terakhir agar tidak hilang saat proposal dimuat ulang.
  useEffect(() => {
    const last = approvals[approvals.length - 1];
    if (last?.oleh) setOleh((cur) => (cur === "" ? last.oleh : cur));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [p.id, approvals.length]);

  const risikoKurang = (p.items ?? []).some(
    (it) => it.status === "berisiko" && (!p.decisions?.[it.id] || p.decisions[it.id].putusan === "tanya" || p.decisions[it.id].status_akhir === "berisiko")
  );

  const syarat: { ok: boolean; aksi?: () => void }[] = [
    { ok: !risikoKurang, aksi: () => goStep(2) },
    { ok: livePaguOk, aksi: () => goStep(1) },
    { ok: legal.nib !== "" && legal.npwp !== "" && legal.akta !== "" },
    { ok: !ring?.perlu_persetujuan || approvals.length > 0 },
  ];

  return (
    <div className="anim-fade-up space-y-4">
      {/* Header */}
      <div className="grad-brand rounded-2xl p-5 sm:p-6 text-white relative overflow-hidden">
        <div className="absolute right-0 top-0 w-40 h-40 rounded-full bg-white/5 -mr-10 -mt-10" aria-hidden />
        <div className="relative">
          <span className="inline-flex items-center gap-1.5 text-xs font-semibold bg-white/15 rounded-full px-3 py-1">
            <Sparkles size={13} aria-hidden /> Langkah 3 dari 3
          </span>
          <h2 className="text-xl sm:text-2xl font-extrabold mt-2">{T.harga.judul}</h2>
          <p className="text-white/80 text-sm mt-1">{T.harga.estimasi}</p>
        </div>
      </div>

      <div className="grid lg:grid-cols-[1fr_320px] gap-4 items-start">
        <div className="space-y-4">
          <Card className="space-y-3">
            <div className="flex items-center gap-2">
              <Tag size={17} className="text-brand" aria-hidden />
              <h3 className="font-bold text-[#0F1B3D]">Komponen harga</h3>
            </div>
            <Input placeholder={T.harga.cari} value={cari} onChange={(e) => setCari(e.target.value)} aria-label={T.harga.tambah} />
            {cari !== "" && (
              <div className="border border-[#C9DDEE] rounded-xl max-h-48 overflow-y-auto">
                {kat.filter((k) => (k.kode + " " + k.nama).toLowerCase().includes(cari.toLowerCase())).slice(0, 8).map((k) => (
                  <button key={k.kode} onClick={() => { tambah(k.kode); setCari(""); }}
                    className="w-full text-left px-3 py-3 text-sm text-slate-700 hover:bg-brand-light border-b border-slate-100 last:border-0 min-h-11">
                    <b className="text-brand">{k.kode}</b> — {k.nama} <span className="text-slate-500">({fmtRp(k.harga_dasar)})</span></button>
                ))}
              </div>
            )}

            {loading ? (
              <SkeletonRows rows={3} />
            ) : rows.length === 0 ? (
              <div className="border border-dashed border-slate-300 rounded-xl p-6 text-center text-sm text-slate-500">
                Belum ada komponen. Cari dan tambah dari katalog di atas.
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm min-w-[660px]">
                  <thead><tr className="text-left text-xs uppercase text-slate-600 bg-[#EEF0F8]">
                    <th className="py-2.5 px-2 rounded-l-lg">{T.harga.kolomKomponen}</th>
                    <th className="py-2.5 px-2">{T.harga.kolomQty}</th>
                    <th className="py-2.5 px-2">{T.harga.kolomAcuan}</th>
                    <th className="py-2.5 px-2">{T.harga.kolomProposal}</th>
                    <th className="py-2.5 px-2">{T.harga.kolomSubtotal}</th>
                    <th className="py-2.5 px-2 rounded-r-lg"></th>
                  </tr></thead>
                  <tbody>
                    {rows.map((r, i) => (
                      <tr key={r.kode} className="border-t border-slate-200 align-top">
                        <td className="py-3 px-2 text-slate-700">{r.nama}<br /><span className="text-xs text-slate-500">{r.kode} / {r.satuan}</span>
                          {r.lencana !== "" && <><br /><span className="text-xs font-bold text-amber-700">{r.lencana}</span></>}
                        </td>
                        <td className="py-3 px-2"><Input type="number" min={1} max={1000} className="max-w-24 py-1.5" aria-label={`Jumlah ${r.kode}`}
                          value={r.qty} onChange={(e) => { const n = [...rows]; n[i] = { ...r, qty: Math.max(1, Math.min(1000, parseInt(e.target.value || "1", 10))) }; setRows(n); }} /></td>
                        <td className="py-3 px-2 text-slate-500 whitespace-nowrap">{fmtRp(r.harga_acuan)}</td>
                        <td className="py-3 px-2">
                          <Input inputMode="numeric" aria-label={`Harga proposal ${r.kode}`} className="max-w-36 py-1.5"
                            aria-invalid={r.harga_proposal !== r.harga_acuan && r.alasan.trim() === ""}
                            value={fmtRp(r.harga_proposal)}
                            onChange={(e) => { const n = [...rows]; n[i] = { ...r, harga_proposal: rpParse(e.target.value) }; setRows(n); }} />
                          {r.harga_proposal !== r.harga_acuan && (
                            <div className="mt-1.5 space-y-1.5">
                              <Select aria-label={`${T.harga.alasan} ${r.kode}`} value={r.alasan}
                                aria-invalid={r.alasan.trim() === ""}
                                onChange={(e) => { const n = [...rows]; n[i] = { ...r, alasan: e.target.value }; setRows(n); }}
                                className="py-1.5 text-xs">
                                <option value="">{T.harga.alasanPilih}</option>
                                {ALASAN.map((a) => <option key={a} value={a}>{a}</option>)}
                              </Select>
                              <Input aria-label={`${T.harga.catatan} ${r.kode}`} placeholder={T.harga.catatan}
                                value={r.alasan_catatan} onChange={(e) => { const n = [...rows]; n[i] = { ...r, alasan_catatan: e.target.value }; setRows(n); }}
                                className="py-1.5 text-xs" maxLength={200} />
                            </div>
                          )}
                        </td>
                        <td className="py-3 px-2 whitespace-nowrap text-slate-800 font-semibold">{fmtRp(r.harga_proposal * r.qty)}</td>
                        <td className="py-3 px-2">
                          <button onClick={() => hapusRow(r.kode)} aria-label={`Hapus ${r.kode}`}
                            className="text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg p-2.5 min-w-11 min-h-11 inline-flex items-center justify-center">
                            <Trash2 size={15} aria-hidden />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {alasanRusak && <FieldError>{T.validasi.alasanWajib}</FieldError>}
          </Card>

          <Card className="space-y-3">
            <div className="flex items-center gap-2">
              <Percent size={17} className="text-brand" aria-hidden />
              <h3 className="font-bold text-[#0F1B3D]">{T.harga.diskonJudul}</h3>
            </div>
            <div className="flex gap-2 flex-wrap items-center">
              {(["persen", "nominal"] as const).map((t) => (
                <button key={t} onClick={() => setTipe(t)} aria-pressed={tipe === t}
                  className={`rounded-xl px-3.5 py-2.5 text-sm font-semibold border min-h-11 ${tipe === t ? "border-brand text-brand bg-brand-light" : "border-slate-300 text-slate-600"}`}>
                  {t === "persen" ? T.harga.diskonPersen : T.harga.diskonNominal}</button>
              ))}
              <Input type="number" min={0} max={tipe === "persen" ? 100 : undefined} aria-label="Nilai diskon" value={nilai}
                onChange={(e) => setNilai(tipe === "persen" ? Math.min(100, Math.max(0, parseInt(e.target.value || "0", 10))) : Math.max(0, parseInt(e.target.value || "0", 10)))} className="max-w-32 py-1.5" />
            </div>
            <div>
              <Label htmlFor="diskon-alasan">{T.harga.diskonAlasan}</Label>
              <Input id="diskon-alasan" value={alasanD} onChange={(e) => setAlasanD(e.target.value)} maxLength={40}
                aria-invalid={diskonErr !== ""} placeholder="cth negosiasi klien" />
              <FieldError>{diskonErr}</FieldError>
            </div>
          </Card>

          {err !== "" && <div role="alert"><Alert kind="error">{err}</Alert></div>}
          <div className="flex items-center gap-3 flex-wrap">
            <Button onClick={simpan} disabled={busy} size="lg" className="w-full sm:w-auto">
              {busy ? T.harga.menyimpan : T.harga.simpan}
            </Button>
            {isDirty && (
              <span className="text-xs text-amber-700 font-semibold bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-1.5 animate-pulse">
                Perubahan harga belum disimpan
              </span>
            )}
          </div>

          <Card className="space-y-3">
            <div className="flex items-center gap-2">
              <Landmark size={17} className="text-brand" aria-hidden />
              <h3 className="font-bold text-[#0F1B3D]">{T.harga.legalJudul}</h3>
            </div>
            <div className="grid sm:grid-cols-3 gap-3">
              {[["NIB", legal.nib], ["NPWP", legal.npwp], ["Akta", legal.akta]].map(([l, v]) => (
                <div key={l} className={`rounded-xl border p-3 ${v !== "" ? "border-green-200 bg-green-50/50" : "border-amber-200 bg-amber-50/50"}`}>
                  <div className="text-xs font-semibold text-slate-500">{l}</div>
                  <div className="text-sm text-slate-800 mt-0.5">{v !== "" ? v : <span className="text-amber-700">{T.harga.legalHubungi}</span>}</div>
                </div>
              ))}
            </div>
          </Card>

          <Card className="space-y-3">
            <div className="flex items-center gap-2">
              <FileCheck2 size={17} className="text-brand" aria-hidden />
              <h3 className="font-bold text-[#0F1B3D]">{T.harga.pratinjau}</h3>
            </div>
            <div className="flex gap-5 flex-wrap text-sm text-slate-700">
              <label className="flex items-center gap-2 cursor-pointer min-h-11">
                <input type="checkbox" checked={lampS} onChange={(e) => setLampS(e.target.checked)} className="w-5 h-5 accent-amber-500" />
                {T.harga.lampiranSumber}</label>
              <label className="flex items-center gap-2 cursor-pointer min-h-11">
                <input type="checkbox" checked={lampK} onChange={(e) => setLampK(e.target.checked)} className="w-5 h-5 accent-amber-500" />
                {T.harga.lampiranKlarifikasi}</label>
            </div>
            <div className="flex gap-2 flex-wrap">
              <Button variant="secondary" onClick={lihatPreview}>{T.harga.pratinjauLihat}</Button>
              <Button variant="accent" onClick={() => unduh("docx")} disabled={kurang.length > 0}><Download size={16} aria-hidden /> {T.harga.unduhDocx}</Button>
              <Button variant="secondary" onClick={() => unduh("md")} disabled={kurang.length > 0}>{T.harga.unduhMd}</Button>
              <Button variant="secondary" onClick={cetak}>{T.harga.unduhCetak}</Button>
            </div>
            {kurang.length > 0 && (
              <div className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                Belum terpenuhi: {kurang.join("; ")}
              </div>
            )}
            {sukses !== "" && (
              <div className="border border-green-300 bg-green-50 rounded-xl p-4 anim-pop">
                <p className="font-bold text-green-800 flex items-center gap-2"><BadgeCheck size={17} aria-hidden /> {T.harga.sukses}: {sukses}</p>
                <div className="mt-2 flex gap-2 flex-wrap">
                  <Button size="sm" variant="secondary" onClick={() => unduh("docx")}>{T.harga.suksesUlang}</Button>
                  <Button size="sm" variant="secondary" onClick={() => nav0("/app")}>{T.harga.suksesBaru}</Button>
                  <Button size="sm" variant="secondary" onClick={() => nav0("/riwayat")}>{T.harga.suksesRiwayat}</Button>
                </div>
              </div>
            )}
            {versions.length > 0 && (
              <div className="text-sm text-slate-600">
                {T.harga.versi}: {versions.map((v) => (
                  <span key={v.versi} className="inline-flex items-center gap-1 mr-3">
                    <span className="text-slate-700 font-semibold">v{v.versi}</span>
                    <button onClick={() => unduhVersi(v.versi, "docx")} className="text-brand underline inline-flex items-center min-h-11 px-1.5">.docx</button>
                    <button onClick={() => unduhVersi(v.versi, "md")} className="text-brand underline inline-flex items-center min-h-11 px-1.5">.md</button>
                  </span>
                ))}
              </div>
            )}
          </Card>
        </div>

        {/* Sidebar ringkasan */}
        <aside className="space-y-4 lg:sticky lg:top-24 self-start">
          <Card className="space-y-3">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{T.harga.total}</div>
            <div className="text-3xl font-extrabold text-[#0F1B3D] leading-none">{fmtRp(liveTotal)}</div>
            <div className="text-xs text-slate-600 space-y-1">
              <div className="flex justify-between"><span>{T.harga.subtotal}</span><span className="font-semibold text-slate-700">{fmtRp(liveSubtotal)}</span></div>
              <div className="flex justify-between"><span>{T.harga.diskon}</span><span className="font-semibold text-slate-700">{fmtRp(liveDiskonRp)}</span></div>
              <div className="flex justify-between"><span>Pagu</span><span className="font-semibold text-slate-700">{p.pagu ? fmtRp(p.pagu) : T.harga.tanpaPagu}</span></div>
            </div>
            {p.pagu !== null && p.pagu !== undefined && (
              <div className={`text-xs font-semibold rounded-lg px-3 py-2 ${livePaguOk ? "text-green-700 bg-green-50 border border-green-200" : "text-red-700 bg-red-50 border border-red-200"}`}>
                {livePaguOk ? T.harga.paguDiBawah : T.harga.paguLewat}
              </div>
            )}
            <div>
              {ring && (ring.perlu_persetujuan && approvals.length === 0
                ? <Badge ok={false}>{T.harga.perluSetuju}</Badge>
                : <Badge ok={true}>{approvals.length > 0 ? `Disetujui ${approvals.length}x` : T.harga.siap}</Badge>)}
            </div>
            {ring?.sebab.map((s) => <div key={s} className="text-xs text-amber-700">• {s}</div>)}
          </Card>

          <Card className="space-y-2">
            <h3 className="font-bold text-sm text-[#0F1B3D]">{T.harga.setujuOleh}</h3>
            <Input id="oleh" value={oleh} onChange={(e) => setOleh(e.target.value)} maxLength={80} placeholder="Nama penyetuju"
              aria-invalid={setujuErr !== ""} />
            <label className="flex items-start gap-2 text-sm text-slate-700 cursor-pointer min-h-11">
              <input type="checkbox" checked={centang} onChange={(e) => setCentang(e.target.checked)} className="mt-1 w-5 h-5 accent-amber-500" />
              {T.harga.centang}
            </label>
            <FieldError>{setujuErr}</FieldError>
            <Button size="sm" className="w-full" onClick={setujui}>{T.harga.setujuOleh}</Button>
            {role !== "" && role !== "admin" && <p className="text-xs text-slate-500">{T.harga.butuhApprover}</p>}
          </Card>

          <Card>
            <h3 className="font-bold text-sm text-[#0F1B3D]">{T.harga.syaratJudul}</h3>
            <ul className="mt-2 space-y-2 text-sm">
              {T.harga.syarat.map((s, i) => (
                <li key={s} className={`flex items-start gap-2 ${syarat[i].ok ? "text-green-700" : "text-slate-500"}`}>
                  <span aria-hidden className="mt-0.5">{syarat[i].ok ? <Check size={15} /> : <Circle size={13} />}</span>
                  {syarat[i].aksi && !syarat[i].ok
                    ? <button onClick={syarat[i].aksi} className="underline text-left min-h-11 inline-flex items-center">{s}</button>
                    : <span>{s}</span>}
                </li>
              ))}
            </ul>
          </Card>
        </aside>
      </div>
      {preview !== "" && <PreviewDoc md={preview} onClose={() => setPreview("")} companyName={companyName} />}
    </div>
  );

  function nav0(to: string) { nav(to); }

  async function setujui() {
    setTried(true);
    if (!centang || oleh.trim() === "") { setErr(T.validasi.setujuWajib); toast(T.validasi.setujuWajib, "warn"); return; }
    if (!await ensureSaved()) return;
    setErr("");
    try {
      const j = await api<{ proposal: Proposal }>(`/api/proposals/${p.id}/approve`, {
        method: "POST", headers: authHeaders({ "Content-Type": "application/json" }),
        body: JSON.stringify({ disetujui_oleh: oleh, centang }),
      });
      setP(j.proposal);
      refresh();
      toast("Persetujuan tersimpan.", "ok");
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
    }
  }
}

function PreviewDoc({ md: markdown, onClose, companyName }: { md: string; onClose: () => void; companyName: string }) {
  useEffect(() => {
    const handleEsc = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    window.addEventListener("keydown", handleEsc);
    return () => window.removeEventListener("keydown", handleEsc);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-[80] bg-black/70 overflow-y-auto p-4" onClick={onClose}
      role="dialog" aria-modal="true" aria-label={T.harga.pratinjau}>
      <div className="bg-white text-slate-900 max-w-3xl mx-auto rounded-xl my-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center gap-2 p-4 border-b sticky top-0 bg-white rounded-t-xl z-10">
          <h2 className="font-bold flex-1">{T.harga.pratinjau}</h2>
          <Button size="sm" variant="secondary" onClick={onClose}>{T.harga.kembaliPerbaiki}</Button>
          <Button size="sm" variant="secondary" onClick={() => printMarkdown(markdown, T.harga.pratinjau, { logo: "/NusaTech-Logo.png", company: companyName })}>{T.harga.unduhCetak}</Button>
        </div>
        <div className="p-8 text-[0.95rem] leading-relaxed chat-md" dangerouslySetInnerHTML={{ __html: mdToHtml(markdown) }} />
      </div>
    </div>
  );
}
