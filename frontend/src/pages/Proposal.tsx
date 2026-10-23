import { ArrowLeft, ArrowRight, FileText } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Steps } from "../components/Steps";
import { Alert, Button, Card, Skeleton } from "../components/ui/controls";
import { ProposalApi, type Proposal as P } from "../lib/proposals";
import { T } from "../ui-text";
import Agent from "./Agent";
import Step1 from "./Step1";
import Step3 from "./Step3";
import Step2 from "./Step2";

export default function Proposal() {
  const { id } = useParams();
  const nav = useNavigate();
  const [p, setP] = useState<P | null>(null);
  const [step, setStep] = useState(1);
  const [err, setErr] = useState("");
  const [savedAt, setSavedAt] = useState("");
  const [dirty, setDirty] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pendingPatchRef = useRef<Record<string, unknown>>({});
  const creatingRef = useRef(false);

  const load = useCallback(async (pid: number, syncStep = false) => {
    try {
      const j = await ProposalApi.get(pid);
      setP(j.proposal);
      if (syncStep) setStep(j.proposal.items.length > 0 ? 2 : 1);
    } catch (e) {
      setErr(e instanceof Error ? e.message : T.umum.cobaLagi);
    }
  }, []);

  useEffect(() => {
    if (id) {
      const numId = parseInt(id, 10);
      if (Number.isNaN(numId) || numId <= 0) {
        setErr("ID proposal tidak valid.");
        return;
      }
      load(numId, true);
    } else {
      if (creatingRef.current) return;
      creatingRef.current = true;
      ProposalApi.create({}).then((j) => {
        setP(j.proposal);
        nav(`/app/${j.proposal.id}`, { replace: true });
      }).catch((e) => setErr(e instanceof Error ? e.message : T.umum.cobaLagi));
    }
  }, [id, load, nav]);

  const autosave = useCallback((patch: object) => {
    if (!p) return;
    setDirty(true);
    pendingPatchRef.current = { ...pendingPatchRef.current, ...patch };
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(async () => {
      const toSend = pendingPatchRef.current;
      pendingPatchRef.current = {};
      try {
        const j = await ProposalApi.patch(p.id, toSend);
        setP(j.proposal);
        setSavedAt(j.tersimpan_pukul);
        setDirty(false);
      } catch {
        /* tetap dirty; coba lagi saat patch berikutnya */
      }
    }, 800);
  }, [p]);

  useEffect(() => {
    const fn = (e: BeforeUnloadEvent) => {
      if (dirty) {
        e.preventDefault();
        e.returnValue = T.unggah.tinggalkan;
      }
    };
    window.addEventListener("beforeunload", fn);
    return () => window.removeEventListener("beforeunload", fn);
  }, [dirty]);

  const can: [boolean, boolean, boolean] = [
    true,
    !!p && p.req_teks.trim() !== "",
    !!p && p.items.length > 0,
  ];
  const reasons: [string, string, string] = [
    "",
    T.unggah.perluFile,
    "Jalankan analisis di Langkah 2 dulu.",
  ];

  return (
    <main className="max-w-4xl mx-auto px-4 py-5 pb-20">
      <div className="mb-4"><Steps current={step} can={can} reasons={reasons} /></div>

      {err !== "" && <div className="mb-3" role="alert"><Alert kind="error">{err}</Alert></div>}

      {!p && (
        <Card className="space-y-4">
          <Skeleton className="h-6 w-48" />
          <Skeleton className="h-40 w-full rounded-2xl" />
          <div className="grid sm:grid-cols-2 gap-4">
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />
          </div>
        </Card>
      )}

      {p && (
        <div className="flex items-center gap-3 mb-3 text-xs text-slate-600 bg-white/70 border border-[#C9DDEE] rounded-xl px-3.5 py-2" role="status">
          <FileText size={14} className="text-brand shrink-0" aria-hidden />
          <span className="truncate font-semibold text-slate-700">{p.instansi || "(tanpa nama)"}</span>
          <span className="text-slate-400">· #{p.id}</span>
          <span className="ml-auto inline-flex items-center gap-1.5 shrink-0">
            {dirty ? (
              <><span className="w-2 h-2 rounded-full bg-amber-500 animate-pulse" aria-hidden /> Menyimpan…</>
            ) : savedAt !== "" ? (
              <><span className="w-2 h-2 rounded-full bg-green-500" aria-hidden /> {T.unggah.tersimpan} {savedAt}</>
            ) : ""}
          </span>
        </div>
      )}

      {step === 1 && p && <Step1 p={p} setP={setP} autosave={autosave} reload={() => load(p.id)} />}
      {step === 2 && p && (
        <Step2 p={p} setP={setP} goHarga={() => setStep(3)} agent={<Agent compact context={{ id: p.id, instansi: p.instansi }} />} />
      )}
      {step === 3 && p && <Step3 p={p} setP={setP} refresh={() => load(p.id)} goStep={(n) => { setStep(n); window.scrollTo({ top: 0 }); }} />}

      {p && (
        <div className="flex gap-2 mt-5">
          {step > 1 && <Button variant="secondary" onClick={() => setStep(step - 1)}><ArrowLeft size={15} aria-hidden /> {T.umum.kembali}</Button>}
          {step < 3 && can[step] && (
            <Button onClick={() => setStep(step + 1)}>{step === 1 ? "Ke Tinjau" : "Ke Harga"} <ArrowRight size={15} aria-hidden /></Button>
          )}
        </div>
      )}
    </main>
  );
}
