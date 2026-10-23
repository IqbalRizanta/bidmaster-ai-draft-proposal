import { ArrowUpRight, Check, Copy, Download, FileText, Lock, Paperclip, RefreshCw, Send, TriangleAlert, X } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../auth";
import { Button } from "../components/ui/controls";
import { toast } from "../components/ui/toast";
import { api, agentStatus, authHeaders } from "../lib/utils";
import { formatTime, judulDari, renderAgentMarkdown, unduhJawaban } from "../lib/agent-utils";
import { T } from "../ui-text";

export type Msg = { role: "user" | "bot"; text: string; err?: boolean; lampiran?: string; mode?: Mode; saranFile?: boolean; time?: string };
type Lampiran = { file: File; nama: string };
type Mode = "proposal" | "umum";

const HINTS = [
  "Buatkan draft proposal untuk kafe di Surabaya, butuh web + reservasi, budget 10jt",
  "Revisi proposal: tambahkan fitur galeri menu",
  "Buatkan PRD dari brief website tadi",
  "Tender dinas pemerintah, pagu 50 juta, durasi 3 bulan",
  "Buatkan file Word siap unduh dari draft di atas",
];

const ACCEPT = [".txt", ".md", ".csv", ".json", ".pdf", ".docx"];
const MAX_BYTES = 200 * 1024;

/** Indikator "berpikir" bergaya LLM: titik mengetik + shimmer teks. */
function Thinking() {
  return (
    <div className="flex gap-3 anim-msg" aria-live="polite">
      <img src="/BidMaster-Ikon-1024.png" alt="" className="w-8 h-8 rounded-lg shrink-0" aria-hidden />
      <div className="bg-white border border-slate-200 rounded-2xl rounded-bl-md px-4 py-3 flex items-center gap-2.5 shadow-[0_2px_14px_rgba(15,27,61,0.06)]">
        <span className="flex gap-1" aria-hidden>
          <span className="typing-dot" /><span className="typing-dot" /><span className="typing-dot" />
        </span>
        <span className="text-sm text-slate-500">{T.agen.berpikir}</span>
      </div>
    </div>
  );
}

function CopyButton({ text }: { text: string }) {
  const [done, setDone] = useState(false);
  return (
    <button
      onClick={() => { navigator.clipboard?.writeText(text).then(() => { setDone(true); toast(T.agen.tersalin, "ok"); setTimeout(() => setDone(false), 1600); }).catch(() => {}); }}
      aria-label={T.agen.salin}
      className="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity text-slate-500 hover:text-brand p-1.5 rounded-md hover:bg-slate-100 inline-flex items-center gap-1">
      {done ? <Check size={13} aria-hidden /> : <Copy size={13} aria-hidden />}
      <span className="text-[0.68rem] font-semibold">{done ? T.agen.tersalin : T.agen.salin}</span>
    </button>
  );
}

/** Tombol unduh jawaban Bob sebagai .docx / .md — selalu terlihat, tanpa dropdown
 *  (dropdown sebelumnya terpotong oleh kontainer chat yang bisa di-scroll). */
function DownloadButton({ text, nama, saran }: { text: string; nama: string; saran?: boolean }) {
  const [busy, setBusy] = useState<"docx" | "md" | null>(null);
  const [done, setDone] = useState<"docx" | "md" | null>(null);
  async function go(format: "docx" | "md") {
    if (busy) return;
    setBusy(format);
    try {
      await unduhJawaban(text, format, judulDari(text, nama));
      setDone(format);
      toast(T.agen.unduhSukses, "ok");
      setTimeout(() => setDone(null), 2600);
    } catch (e) {
      toast(e instanceof Error ? e.message : T.agen.unduhGagal, "warn");
    } finally {
      setBusy(null);
    }
  }
  const chip = (format: "docx" | "md", label: string) => {
    const ok = done === format;
    return (
      <button
        onClick={() => go(format)} disabled={busy !== null}
        aria-label={`${T.agen.unduhJawaban} ${label}`}
        className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 min-h-9 text-[0.7rem] font-semibold transition-all cursor-pointer disabled:opacity-50 ${
          ok ? "border-green-300 bg-green-50 text-green-700"
            : "border-slate-300 bg-white text-slate-600 hover:text-brand hover:border-brand hover:bg-brand-light hover:shadow-sm active:scale-95"}`}>
        {ok ? <Check size={13} aria-hidden /> : busy === format ? <RefreshCw size={13} className="animate-spin" aria-hidden /> : <Download size={13} aria-hidden />}
        {ok ? T.agen.unduhSelesai : busy === format ? "…" : label}
      </button>
    );
  };
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-xl border px-1.5 py-1 ${saran ? "border-brand/35 bg-brand-light" : "border-slate-200 bg-slate-50"}`}>
      <span className="text-[0.68rem] font-semibold text-slate-500 pl-1">{T.agen.unduhJawaban}</span>
      {chip("docx", T.agen.unduhDocx)}
      {chip("md", T.agen.unduhMd)}
    </span>
  );
}

export function ChatBob({ variant = "compact", context }: {
  variant?: "compact" | "full";
  context?: { id: number; instansi: string };
}) {
  const full = variant === "full";
  const [msgs, setMsgs] = useState<Msg[]>([]);
  const [input, setInput] = useState("");
  const [busy, setBusy] = useState(false);
  const [ready, setReady] = useState<boolean | null>(null);
  const [lamp, setLamp] = useState<Lampiran | null>(null);
  const [mode, setMode] = useState<Mode>("proposal");
  const { user } = useAuth();
  const logged = user !== null;
  const box = useRef<HTMLDivElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const [sessionId, setSessionId] = useState(() => "bm-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 7));

  useEffect(() => {
    agentStatus().then((ok) => setReady(ok));
  }, []);
  useEffect(() => {
    const el = box.current;
    if (el) el.scrollTo({ top: el.scrollHeight, behavior: "smooth" });
  }, [msgs, busy]);

  function pilihFile(f: File) {
    const ext = "." + (f.name.split(".").pop() ?? "").toLowerCase();
    if (!ACCEPT.includes(ext)) { toast(T.validasi.fileFormat, "warn"); return; }
    if (f.size > MAX_BYTES) { toast(T.validasi.fileBesar, "warn"); return; }
    setLamp({ file: f, nama: f.name });
  }

  async function send(text: string, retry = false) {
    const t = text.trim();
    if (busy) return;
    if (t === "" && !lamp) { toast(T.agen.butuhSatu, "warn"); return; }
    if (!logged) { toast(T.agen.belumLogin, "warn"); return; }
    const l = lamp;
    const nowTime = formatTime();
    if (!retry) setMsgs((m) => [...m, { role: "user", text: t, lampiran: l?.nama, time: nowTime }]);
    setInput("");
    setLamp(null);
    setBusy(true);
    try {
      let j: { text: string; saran_file?: boolean };
      if (l) {
        const fd = new FormData();
        fd.append("message", t);
        fd.append("flow", "bidmaster_proposal_generator");
        fd.append("session_id", sessionId);
        fd.append("mode", mode);
        if (context) fd.append("konteks_proposal", String(context.id));
        fd.append("file", l.file);
        j = await api<{ text: string }>("/api/agent", { method: "POST", headers: authHeaders(), body: fd });
      } else {
        j = await api<{ text: string }>("/api/agent", {
          method: "POST", headers: authHeaders({ "Content-Type": "application/json" }),
          body: JSON.stringify({
            message: t,
            flow: "bidmaster_proposal_generator",
            session_id: sessionId,
            mode,
            ...(context ? { proposal_id: context.id, konteks_proposal: context.id } : {}),
          }),
        });
      }
      setMsgs((m) => [...m, { role: "bot", text: j.text, mode, saranFile: j.saran_file === true, time: formatTime() }]);
    } catch (e) {
      setMsgs((m) => [...m, { role: "bot", text: `**${T.agen.gagal}**\n\n${e instanceof Error ? e.message : ""}`.trim(), err: true, time: formatTime() }]);
    } finally {
      setBusy(false);
    }
  }

  const canSend = (input.trim() !== "" || lamp !== null) && !busy && ready === true;

  return (
    <div className={`flex overflow-hidden bg-white border border-[#C9DDEE] shadow-[0_10px_36px_rgba(15,27,61,0.10)] rounded-2xl ${
      full ? "h-[calc(100dvh-11rem)] min-h-[32rem]" : "h-[74vh] supports-[height:100dvh]:h-[74dvh]"}`}>
      {/* Sidebar (desktop, full) */}
      {full && (
        <aside className="w-72 shrink-0 bg-[#F7F9FE] border-r border-slate-200 hidden lg:flex flex-col">
          <div className="p-4 border-b border-slate-200 flex items-center gap-2 text-xs text-slate-600">
            <span className={`w-2 h-2 rounded-full ${ready ? "bg-green-500 animate-pulse" : ready === false ? "bg-red-500" : "bg-slate-400"}`} />
            {ready === null ? T.agen.memeriksa : ready ? T.agen.terhubung : T.agen.tidakTerhubung}
          </div>
          <div className="p-4 border-b border-slate-200">
            <h3 className="text-xs font-bold uppercase tracking-wide mb-2 text-slate-500">{T.agen.flowAktif}</h3>
            <div className="bg-brand-light text-brand font-semibold text-xs rounded-lg px-3 py-2.5 flex items-center gap-2 border border-brand/20">
              <FileText size={15} aria-hidden /> BidMaster Proposal Generator
            </div>
          </div>
          {context && (
            <div className="p-4 border-b border-slate-200">
              <h3 className="text-xs font-bold uppercase tracking-wide mb-2 text-slate-500">{T.agen.konteks}</h3>
              <div className="text-xs text-slate-600 bg-white border border-slate-200 rounded-lg px-3 py-2">
                <b className="text-[#0F1B3D]">{context.instansi || "(tanpa nama)"}</b>
                <div className="text-slate-400">#{context.id}</div>
              </div>
            </div>
          )}
          <div className="p-4">
            <h3 className="text-xs font-bold uppercase tracking-wide mb-2 text-slate-500">{T.agen.panduan}</h3>
            <ul className="space-y-1.5 text-xs text-slate-600">
              {T.agen.panduanIsi.map((p) => <li key={p} className="flex gap-1.5"><span className="text-brand">•</span>{p}</li>)}
            </ul>
          </div>
          <div className="p-4 border-t border-slate-200 mt-auto text-xs text-slate-500">
            {T.agen.sesi} <code className="bg-slate-200/70 px-1.5 py-0.5 rounded">{sessionId.slice(0, 16)}…</code>
          </div>
        </aside>
      )}

      <div className="flex-1 flex flex-col min-w-0 min-h-0">
        {/* Header */}
        <div className="shrink-0 flex items-center gap-3 px-4 py-3 border-b border-slate-200 bg-white/80 backdrop-blur">
          <img src="/BidMaster-Ikon-1024.png" alt="" className="w-9 h-9 rounded-xl" aria-hidden />
          <div className="min-w-0">
            <p className="font-bold text-[#0F1B3D] leading-tight">{full ? T.agen.halamanJudul : T.agen.judul}</p>
            <p className="text-xs text-slate-500 truncate">{full ? T.agen.halamanSub : T.agen.sub}</p>
          </div>
          <div className="ml-auto flex items-center gap-2 shrink-0">
            <span className={`hidden sm:inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full ${ready ? "bg-green-50 text-green-700" : ready === false ? "bg-red-50 text-red-700" : "bg-slate-100 text-slate-600"}`}>
              <span className={`w-1.5 h-1.5 rounded-full ${ready ? "bg-green-500" : ready === false ? "bg-red-500" : "bg-slate-400"}`} />
              {ready === null ? T.agen.memeriksa : ready ? T.agen.terhubung : T.agen.tidakTerhubung}
            </span>
            {!full && (
              <Link to={context ? `/bob?proposal=${context.id}` : "/bob"} className="no-underline">
                <Button size="sm" variant="secondary"><ArrowUpRight size={14} aria-hidden /><span className="hidden sm:inline">{T.agen.bukaHalaman}</span></Button>
              </Link>
            )}
            {full && msgs.length > 0 && (
              <Button size="sm" variant="secondary" onClick={() => { setMsgs([]); setSessionId("bm-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 7)); }}>
                <RefreshCw size={14} aria-hidden /><span className="hidden sm:inline">{T.agen.bersihkan}</span>
              </Button>
            )}
          </div>
        </div>

        {!logged && (
          <div className="shrink-0 mx-3 mt-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800 flex items-start gap-2">
            <Lock size={16} className="mt-0.5 shrink-0" aria-hidden />
            <span>Belum login — <a href="/login" className="text-brand font-semibold underline">{T.agen.masuk}</a> untuk bertanya ke Bob.</span>
          </div>
        )}
        {ready === false && (
          <div className="shrink-0 mx-3 mt-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800 flex items-start gap-2">
            <TriangleAlert size={16} className="mt-0.5 shrink-0" aria-hidden />
            <span>{T.agen.mesinMati}</span>
          </div>
        )}

        {/* Area pesan — hanya ini yang scroll */}
        <div ref={box} className="flex-1 min-h-0 overflow-y-auto overscroll-contain px-4 py-5 flex flex-col gap-5">
          {msgs.length === 0 && !busy && (
            <div className="flex flex-col items-center text-center my-auto gap-3 anim-fade-up">
              <img src="/BidMaster-Ikon-1024.png" alt="Bob" className="w-[72px] h-[72px] rounded-2xl anim-pulse-ring" />
              <h3 className="text-xl font-bold text-[#0F1B3D]">{T.agen.sapaJudul}</h3>
              <p className="text-slate-600 text-sm max-w-md">{T.agen.sapaIsi}</p>
            </div>
          )}

          {msgs.map((m, i) => (
            <div key={i} className={`anim-msg group flex gap-3 ${m.role === "user" ? "flex-row-reverse" : ""}`}>
              <div className="w-8 h-8 shrink-0 flex items-center justify-center" aria-hidden>
                {m.role === "user"
                  ? <span className="w-8 h-8 rounded-full bg-slate-600 flex items-center justify-center text-xs font-bold text-white">K</span>
                  : <img src="/BidMaster-Ikon-1024.png" alt="Bob" className="w-8 h-8 rounded-lg" />}
              </div>
              <div className="max-w-[85%] sm:max-w-[74%] min-w-0">
                {m.role === "user"
                  ? <div className="bg-brand text-white rounded-2xl rounded-br-md px-4 py-2.5 text-sm whitespace-pre-wrap break-words shadow-[0_4px_20px_rgba(15,27,61,0.22)]">
                      {m.lampiran && <span className="flex items-center gap-1.5 text-xs text-white/80 mb-1"><Paperclip size={12} aria-hidden />{m.lampiran}</span>}
                      {m.text}
                    </div>
                  : <div className={`bg-white border rounded-2xl rounded-bl-md px-4 py-3 shadow-[0_2px_14px_rgba(15,27,61,0.06)] ${m.err ? "border-red-300 bg-red-50" : "border-slate-200"}`}>
                      <div className={`chat-md ${m.err ? "text-red-800" : ""}`} dangerouslySetInnerHTML={{ __html: renderAgentMarkdown(m.text) }} />
                      {m.err && (
                        <button onClick={() => send(msgs[i - 1]?.text ?? "", true)} disabled={busy}
                          className="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-brand hover:underline disabled:opacity-50">
                          <RefreshCw size={13} aria-hidden /> {T.agen.ulangi}
                        </button>
                      )}
                      {m.saranFile && !m.err && (
                        <div className="mt-3 flex items-start gap-2 bg-brand-light border border-brand/25 rounded-xl px-3 py-2 text-xs text-[#0F1B3D]">
                          <Download size={14} className="text-brand mt-0.5 shrink-0" aria-hidden />
                          <span>{T.agen.saranFile}</span>
                        </div>
                      )}
                    </div>}
                <div className="flex items-center gap-2 mt-1.5 px-1 flex-wrap">
                  {m.time && <span className="text-[0.7rem] text-slate-400">{m.time}</span>}
                  {m.role === "bot" && m.mode === "umum" && (
                    <span className="text-[0.65rem] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-2 py-0.5">{T.agen.umumBadge}</span>
                  )}
                  {m.role === "bot" && !m.err && <CopyButton text={m.text} />}
                  {m.role === "bot" && !m.err && (
                    <DownloadButton text={m.text} nama={context?.instansi || "jawaban-bob"} saran={m.saranFile} />
                  )}
                </div>
              </div>
            </div>
          ))}
          {busy && <Thinking />}
        </div>

        {/* Komposer */}
        <div className="shrink-0 border-t border-slate-200 bg-white/90 backdrop-blur p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <div className="max-w-3xl mx-auto mb-2 flex items-center gap-2 flex-wrap">
            <span className="text-xs font-semibold text-slate-500" id="bob-mode-label">{T.agen.mode}</span>
            <div role="group" aria-labelledby="bob-mode-label" className="inline-flex bg-slate-100 border border-slate-200 rounded-full p-0.5">
              {(["proposal", "umum"] as Mode[]).map((mo) => (
                <button key={mo} type="button" onClick={() => setMode(mo)} disabled={busy}
                  title={mo === "proposal" ? T.agen.modeProposalKet : T.agen.modeUmumKet}
                  aria-pressed={mode === mo}
                  className={`px-3.5 py-1.5 rounded-full text-xs font-semibold transition-colors min-h-8 ${mode === mo ? "bg-brand text-white shadow-[0_2px_10px_rgba(15,27,61,0.25)]" : "text-slate-600 hover:text-brand"}`}>
                  {mo === "proposal" ? T.agen.modeProposal : T.agen.modeUmum}
                </button>
              ))}
            </div>
            <span className="text-xs text-slate-500 truncate">{mode === "proposal" ? T.agen.modeProposalKet : T.agen.modeUmumKet}</span>
          </div>

          {msgs.length === 0 && (
            <div className="flex gap-1.5 flex-wrap mb-2 max-w-3xl mx-auto">
              {HINTS.map((h) => (
                <button key={h} onClick={() => setInput(h)} disabled={busy}
                  className="bg-slate-100 border border-slate-200 rounded-full px-3.5 py-2 text-xs text-slate-600 hover:border-brand/60 hover:text-brand transition-colors min-h-11 inline-flex items-center max-w-full">
                  <span className="truncate">{h.length > 48 ? h.slice(0, 48) + "…" : h}</span></button>
              ))}
            </div>
          )}

          {lamp && (
            <div className="max-w-3xl mx-auto mb-2 anim-slide-up">
              <div className="inline-flex items-center gap-2 bg-brand-light border border-brand/25 text-[#0F1B3D] rounded-xl px-3 py-2 text-sm">
                <FileText size={15} className="text-brand" aria-hidden />
                <span className="max-w-[16rem] truncate font-medium">{lamp.nama}</span>
                <span className="text-xs text-slate-500 hidden sm:inline">{T.agen.lampiranKet}</span>
                <button onClick={() => setLamp(null)} aria-label={T.agen.lampiranBuang} className="text-slate-500 hover:text-red-600 p-1 rounded-md">
                  <X size={15} aria-hidden />
                </button>
              </div>
            </div>
          )}

          <form className="flex gap-2 max-w-3xl mx-auto items-end" onSubmit={(e) => { e.preventDefault(); send(input); }}>
            <button type="button" onClick={() => fileRef.current?.click()} disabled={busy}
              aria-label={T.agen.lampirkan} title={T.agen.lampirkan}
              className="w-11 h-11 shrink-0 rounded-xl border border-slate-300 text-slate-600 hover:text-brand hover:border-brand/60 hover:bg-slate-50 flex items-center justify-center disabled:opacity-40 transition-colors">
              <Paperclip size={18} aria-hidden />
            </button>
            <input ref={fileRef} type="file" hidden accept={ACCEPT.join(",")}
              onChange={(e) => { const f = e.target.files?.[0]; if (f) pilihFile(f); e.target.value = ""; }} />
            <textarea rows={1} value={input} onChange={(e) => setInput(e.target.value)}
              onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); if (canSend) send(input); } }}
              placeholder={T.agen.placeholder} maxLength={4000} disabled={busy}
              className="flex-1 resize-none border-[1.5px] border-slate-200 bg-white rounded-xl px-3.5 py-2.5 text-base text-slate-800 placeholder:text-slate-500 focus:border-brand focus:outline-none focus:ring-3 focus:ring-brand/20 disabled:bg-slate-50" />
            <button type="submit" disabled={!canSend} aria-label={T.agen.kirim} title={T.agen.kirim}
              className="w-11 h-11 shrink-0 rounded-xl bg-brand hover:bg-brand-dark text-white flex items-center justify-center disabled:opacity-40 transition-all hover:scale-105 active:scale-95 shadow-[0_4px_20px_rgba(15,27,61,0.25)]">
              <Send size={18} aria-hidden />
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}

/** Komponen kompat: dipakai di Langkah 2 sebagai tab "Tanya Bob". */
export default function Agent({ compact = false, context }: { compact?: boolean; context?: { id: number; instansi: string } }) {
  return <ChatBob variant={compact ? "compact" : "full"} context={context} />;
}
