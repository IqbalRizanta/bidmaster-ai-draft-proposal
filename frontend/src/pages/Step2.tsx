import { useMemo, useState } from "react";
import { AlertTriangle, CheckCircle2, ClipboardCheck, HelpCircle, Layers, Sparkles } from "lucide-react";
import { SourcePanel, type SourceRef } from "../components/SourcePanel";
import { CiteMarker } from "../components/CiteMarker";
import { Alert, Badge, Button, Card, FieldError } from "../components/ui/controls";import { toast } from "../components/ui/toast";
import { type Decision, type Item, type Proposal } from "../lib/proposals";
import { T } from "../ui-text";
import { api, authHeaders } from "../lib/utils";

const STATUS_LABEL = { sesuai: "Sesuai", sebagian: "Sebagian", berisiko: "Berisiko" } as const;

export default function Step2({ p, setP, goHarga, agent }: {
  p: Proposal;
  setP: (p: Proposal) => void;
  goHarga: () => void;
  agent: React.ReactNode;
}) {
  const [tab, setTab] = useState<"butir" | "bob">("butir");
  const [filter, setFilter] = useState(0);
  const [panel, setPanel] = useState<{ s: SourceRef; anchor: HTMLElement } | null>(null);
  const [dec, setDec] = useState<Record<string, { putusan: string; status: string; alasan: string; catatan: string; pertanyaan: string }>>({});
  const [massal, setMassal] = useState(false);
  const [err, setErr] = useState("");
  const [busy, setBusy] = useState("");
  const [touched, setTouched] = useState<Record<string, boolean>>({});

  const decisions: Record<string, Decision> = useMemo(() => p.decisions ?? {}, [p.decisions]);
  const items: Item[] = useMemo(() => p.items ?? [], [p.items]);

  const counts = useMemo(() => {
    const c = { sesuai: 0, sebagian: 0, berisiko: 0 };
    for (const it of items) c[it.status]++;
    return c;
  }, [items]);
  const diputus = Object.keys(decisions).length;

  const shown = useMemo(() => {
    const arr = [...items].sort((a, b) =>
      (a.status === "berisiko" ? 0 : 1) - (b.status === "berisiko" ? 0 : 1));
    switch (filter) {
      case 1: return arr.filter((i) => i.status === "berisiko");
      case 2: return arr.filter((i) => i.status === "sebagian");
      case 3: return arr.filter((i) => i.status === "sesuai");
      case 4: return arr.filter((i) => i.perlu_konfirmasi);
      case 5: return arr.filter((i) => !decisions[i.id]);
      default: return arr;
    }
  }, [items, filter, decisions]);

  const sisa = items.filter((i) => i.status === "berisiko" && !decisions[i.id]).length;
  const kandidatMassal = items.filter((i) => i.status === "sesuai" && i.keyakinan === "tinggi" && !i.perlu_konfirmasi && !decisions[i.id]).length;
  const nextRisk = items.find((i) => i.status === "berisiko" && !decisions[i.id]);

  function srcKlien(it: Item): SourceRef | null {
    const s = it.sumber_klien;
    if (!s) return null;
    if (s.baris) return { kind: "klien", baris: s.baris, kutipan: it.kutipan };
    if (s.halaman && s.paragraf) return { kind: "klien", halaman: s.halaman, paragraf: s.paragraf, kutipan: it.kutipan };
    return null;
  }

  function alasanErr(id: string): string {
    const d = dec[id];
    if (!d || !touched[id]) return "";
    if (d.putusan === "ubah" && d.alasan.trim() === "") return T.validasi.alasanWajib;
    if (d.putusan === "tanya" && d.pertanyaan.trim() === "") return T.validasi.wajib;
    return "";
  }

  async function simpan(id: string) {
    const d = dec[id];
    if (!d) return;
    setTouched((m) => ({ ...m, [id]: true }));
    if (d.putusan === "ubah" && d.alasan.trim() === "") { setErr(T.validasi.alasanWajib); toast(T.validasi.alasanWajib, "warn"); return; }
    if (d.putusan === "tanya" && d.pertanyaan.trim() === "") { setErr(T.validasi.wajib); toast(T.validasi.wajib, "warn"); return; }
    setErr("");
    setBusy(id);
    try {
      const j = await api<{ proposal: Proposal }>(`/api/proposals/${p.id}/items/${id}/decision`, {
        method: "PATCH", headers: authHeaders({ "Content-Type": "application/json" }),
        body: JSON.stringify({ putusan: d.putusan, status: d.status || undefined, alasan: d.alasan, catatan: d.catatan, pertanyaan: d.pertanyaan }),
      });
      setP(j.proposal);
      setDec((m) => { const n = { ...m }; delete n[id]; return n; });
      toast(`Keputusan ${id} tersimpan.`, "ok");
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
    } finally {
      setBusy("");
    }
  }

  async function massalGo() {
    setErr("");
    try {
      const j = await api<{ proposal: Proposal; disetujui: number }>(`/api/proposals/${p.id}/approve-sesuai`, {
        method: "POST", headers: authHeaders({ "Content-Type": "application/json" }),
        body: JSON.stringify({ konfirmasi: true }),
      });
      setP(j.proposal);
      setMassal(false);
      toast(`${j.disetujui ?? kandidatMassal} butir disetujui.`, "ok");
    } catch (e) {
      const m = e instanceof Error ? e.message : T.umum.cobaLagi;
      setErr(m); toast(m, "error");
    }
  }

  function startDec(id: string, putusan: string) {
    const existing = decisions[id];
    setDec((m) => ({
      ...m,
      [id]: {
        putusan,
        status: existing?.status_akhir ?? "sesuai",
        alasan: existing?.alasan ?? "",
        catatan: existing?.catatan ?? "",
        pertanyaan: existing?.pertanyaan ?? "",
      },
    }));
  }

  const stat = [
    { label: "Total butir", value: items.length, tone: "text-[#0F1B3D]", icon: Layers },
    { label: "Sesuai", value: counts.sesuai, tone: "text-green-700", icon: CheckCircle2 },
    { label: "Sebagian", value: counts.sebagian, tone: "text-amber-700", icon: HelpCircle },
    { label: "Berisiko", value: counts.berisiko, tone: "text-red-700", icon: AlertTriangle },
  ];

  return (
    <div className="anim-fade-up space-y-4">
      {/* Header */}
      <div className="grad-brand rounded-2xl p-5 sm:p-6 text-white relative overflow-hidden">
        <div className="absolute right-0 top-0 w-40 h-40 rounded-full bg-white/5 -mr-10 -mt-10" aria-hidden />
        <div className="relative">
          <span className="inline-flex items-center gap-1.5 text-xs font-semibold bg-white/15 rounded-full px-3 py-1">
            <Sparkles size={13} aria-hidden /> Langkah 2 dari 3
          </span>
          <h2 className="text-xl sm:text-2xl font-extrabold mt-2">{T.tinjau.judul}</h2>
          <p className="text-white/80 text-sm mt-1">{T.tinjau.risikoAtas}</p>
        </div>
      </div>

      <Card className="space-y-4">
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          {stat.map((s) => {
            const Icon = s.icon;
            return (
              <div key={s.label} className="border border-[#C9DDEE] rounded-xl p-3 bg-[#F7F9FE] card-hover">
                <div className="flex items-center gap-1.5 text-xs text-slate-500 font-semibold">
                  <Icon size={13} aria-hidden /> {s.label}
                </div>
                <div className={`text-2xl font-extrabold mt-1 ${s.tone}`}>{s.value}</div>
              </div>
            );
          })}
        </div>

        <div className="flex items-center gap-3 flex-wrap text-sm text-slate-600">
          <span className="inline-flex items-center gap-1.5"><ClipboardCheck size={15} className="text-brand" aria-hidden />
            {T.tinjau.progresDiputus}: <b className="text-[#0F1B3D]">{diputus}</b> {T.tinjau.dariButir} {items.length}
          </span>
          <div className="flex-1 min-w-32 h-2 rounded-full bg-slate-200 overflow-hidden">
            <div className="h-full grad-amber transition-all" style={{ width: `${items.length ? (diputus / items.length) * 100 : 0}%` }} />
          </div>
        </div>

        <div className="flex gap-1.5 flex-wrap" role="group" aria-label="Filter butir">
          {T.tinjau.filter.map((f, i) => (
            <button key={f} onClick={() => setFilter(i)} aria-pressed={filter === i}
              className={`rounded-full px-3.5 py-2 text-xs font-semibold border-[1.5px] min-h-11 transition-colors ${filter === i ? "bg-brand text-white border-brand" : "border-slate-300 text-slate-600 hover:border-brand/60 hover:bg-slate-50"}`}>
              {f}</button>
          ))}
        </div>

        {kandidatMassal > 0 && (
          <Button variant="accent" size="sm" onClick={() => setMassal(true)}>
            <CheckCircle2 size={15} aria-hidden /> {T.tinjau.massal} ({kandidatMassal})
          </Button>
        )}
      </Card>

      {massal && (
        <div className="fixed inset-0 z-[80] bg-black/60 flex items-center justify-center p-4" onClick={() => setMassal(false)}
          onKeyDown={(e) => { if (e.key === "Escape") setMassal(false); }}>
          <div role="dialog" aria-modal="true" aria-labelledby="massal-title" className="bg-white border border-[#C9DDEE] rounded-2xl p-6 max-w-sm w-full max-h-[90dvh] overflow-y-auto anim-pop shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <p id="massal-title" className="text-base text-slate-800">{kandidatMassal} {T.tinjau.massalTanya}</p>
            <div className="mt-4 flex gap-2 justify-end">
              <Button variant="secondary" size="sm" onClick={() => setMassal(false)}>{T.umum.kembali}</Button>
              <Button size="sm" onClick={massalGo}>{T.tinjau.massalYa}</Button>
            </div>
          </div>
        </div>
      )}

      <div className="flex gap-2" role="tablist" aria-label="Tinjau">
        {(["butir", "bob"] as const).map((t) => (
          <button key={t} role="tab" aria-selected={tab === t} onClick={() => setTab(t)}
            className={`rounded-xl px-4 py-2.5 text-sm font-semibold min-h-11 transition-colors ${tab === t ? "grad-brand text-white shadow-[0_4px_14px_rgba(15,27,61,0.25)]" : "bg-white border border-slate-300 text-slate-600 hover:text-[#0F1B3D] hover:bg-slate-50"}`}>
            {t === "butir" ? T.tinjau.tabButir : T.tinjau.tabBob}</button>
        ))}
      </div>

      {tab === "butir" && (
        <div className="space-y-3">
          {items.length === 0 && <Alert kind="warn">{T.tinjau.kosong}</Alert>}
          {err !== "" && <div role="alert"><Alert kind="error">{err}</Alert></div>}
          {nextRisk && (
            <p className="text-xs text-slate-600 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
              {T.tinjau.butirBerikut}: <b>{nextRisk.id}</b> — {nextRisk.requirement.slice(0, 80)}
            </p>
          )}
          <div className="space-y-3 stagger">
          {shown.map((it) => {
            const dc = decisions[it.id];
            const form = dec[it.id];
            const sk = srcKlien(it);
            const sources: SourceRef[] = [
              ...(sk ? [sk] : []),
              ...it.sumber_knowledge.map((k) => ({ kind: "knowledge", kode: k } as SourceRef)),
            ];
            return (
              <article key={it.id} className={`bg-white border-2 rounded-2xl p-5 card-hover ${it.status === "berisiko" ? "border-red-300" : "border-[#C9DDEE]"}`}>
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-[0.72rem] font-bold uppercase tracking-wide text-brand bg-brand-light rounded-full px-2.5 py-1">{it.kategori} · {it.id}</span>
                  <Badge ok={it.status === "sesuai"}>{STATUS_LABEL[it.status]}</Badge>
                  {it.perlu_konfirmasi && <Badge ok={false}>{T.tinjau.perluKonfirmasi}</Badge>}
                  <span className="text-xs text-slate-500 ml-auto">{T.tinjau.keyakinan}: <b className="text-slate-700">{it.keyakinan}</b></span>
                </div>
                <p className="text-base text-slate-800 mt-2 leading-relaxed">
                  {it.requirement || "(tanpa teks)"}
                  {sources.map((s, i) => (
                    <CiteMarker key={i} n={i + 1} s={s} proposalId={p.id} dokumen={p.req_nama}
                      onFull={(ss, a) => setPanel({ s: ss, anchor: a })} />
                  ))}
                </p>
                {it.alasan !== "" && <p className="text-sm text-slate-600 mt-1.5"><span className="font-semibold">{T.tinjau.alasan}:</span> {it.alasan}</p>}
                {sources.length > 0 && (
                  <p className="text-xs text-slate-500 mt-2">{sources.length} {T.tinjau.sumber.toLowerCase()} · {T.umum.sitasiKet}</p>
                )}

                <div className="mt-4 border-t border-slate-200 pt-4">
                  <div className="flex items-center gap-2 flex-wrap">
                    <p className="text-sm font-bold text-[#0F1B3D]">{T.tinjau.putusanJudul}</p>
                    {dc
                      ? <span className="text-xs font-semibold text-green-700 inline-flex items-center gap-1"><CheckCircle2 size={13} aria-hidden /> {T.tinjau.sudahDiputus} ({dc.putusan}{dc.putusan === "ubah" ? ` → ${STATUS_LABEL[dc.status_akhir as keyof typeof STATUS_LABEL] ?? dc.status_akhir}` : ""})</span>
                      : <span className="text-xs text-slate-500">{T.tinjau.belumDiputus}</span>}
                  </div>
                  <div className="flex gap-2 flex-wrap mt-2" role="group" aria-label={T.tinjau.putusanJudul}>
                    {([["benar", T.tinjau.putusanBenar], ["ubah", T.tinjau.putusanUbah], ["tanya", T.tinjau.putusanTanya]] as const).map(([v, label]) => (
                      <button key={v} onClick={() => startDec(it.id, v)} aria-pressed={form?.putusan === v}
                        className={`rounded-xl px-4 py-2.5 text-sm font-semibold border-[1.5px] min-h-11 transition-colors ${form?.putusan === v ? "border-brand text-brand bg-brand-light" : "border-slate-300 text-slate-600 hover:border-brand/60"}`}>
                        {label}</button>
                    ))}
                  </div>
                  {form && (
                    <div className="mt-3 space-y-2.5 bg-[#F7F9FE] border border-slate-200 rounded-xl p-3">
                      {form.putusan === "ubah" && (
                        <div className="flex gap-1.5 flex-wrap" role="group" aria-label="Status baru">
                          {(["sesuai", "sebagian", "berisiko"] as const).map((s) => (
                            <button key={s} onClick={() => setDec((m) => ({ ...m, [it.id]: { ...m[it.id], status: s } }))}
                              aria-pressed={form.status === s}
                              className={`rounded-full px-3.5 py-2 text-xs font-semibold border min-h-11 ${form.status === s ? "border-brand text-brand bg-white" : "border-slate-300 text-slate-600 bg-white"}`}>
                              {STATUS_LABEL[s]}</button>
                          ))}
                        </div>
                      )}
                      {(form.putusan === "ubah" || form.putusan === "tanya") && (
                        <>
                          <input aria-label={form.putusan === "ubah" ? T.tinjau.putusanAlasan : T.tinjau.putusanTanyaKet}
                            aria-invalid={alasanErr(it.id) !== ""}
                            placeholder={form.putusan === "ubah" ? T.tinjau.putusanAlasan : T.tinjau.putusanTanyaKet}
                            value={form.putusan === "ubah" ? form.alasan : form.pertanyaan}
                            onChange={(e) => setDec((m) => ({ ...m, [it.id]: { ...m[it.id], [form.putusan === "ubah" ? "alasan" : "pertanyaan"]: e.target.value } }))}
                            className="w-full px-3 py-2.5 border-[1.5px] border-slate-300 rounded-lg text-base bg-white text-ink placeholder:text-slate-400 aria-[invalid=true]:border-red-500" />
                          <FieldError>{alasanErr(it.id)}</FieldError>
                        </>
                      )}
                      <input aria-label={T.tinjau.putusanCatatan} placeholder={T.tinjau.putusanCatatan}
                        value={form.catatan}
                        onChange={(e) => setDec((m) => ({ ...m, [it.id]: { ...m[it.id], catatan: e.target.value } }))}
                        className="w-full px-3 py-2.5 border-[1.5px] border-slate-300 rounded-lg text-base bg-white text-ink placeholder:text-slate-400" />
                      <Button size="sm" disabled={busy === it.id} onClick={() => simpan(it.id)}>
                        {busy === it.id ? T.umum.memuat : T.tinjau.putusanSimpan}</Button>
                    </div>
                  )}
                  {dc && dc.alasan !== "" && <p className="text-xs text-slate-600 mt-2">Alasan: {dc.alasan}{dc.catatan !== "" ? ` · Catatan: ${dc.catatan}` : ""}</p>}
                </div>
              </article>
            );
          })}
          </div>
        </div>
      )}
      {tab === "bob" && <div>{agent}</div>}

      <div className="flex items-center gap-3 flex-wrap">
        <Button onClick={goHarga} disabled={sisa > 0} aria-describedby="alasan-harga" size="lg">
          {T.tinjau.lanjut}
        </Button>
        {sisa > 0 && <span id="alasan-harga" className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{sisa}{T.tinjau.sisaRisiko}</span>}
      </div>
      {panel && (
        <SourcePanel proposalId={p.id} s={panel.s} returnTo={panel.anchor} onClose={() => setPanel(null)} />
      )}
    </div>
  );
}
