import { Eye, EyeOff, LogIn, ShieldCheck } from "lucide-react";
import { useEffect, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../auth";
import { Alert, Button, Card, FieldError, Input, Label } from "../components/ui/controls";
import { toast } from "../components/ui/toast";
import { T } from "../ui-text";

export default function Login() {
  const nav = useNavigate();
  const loc = useLocation();
  const from = (loc.state as { from?: { pathname?: string } })?.from?.pathname || "/app";
  const { login, user, ready } = useAuth();
  const [nama, setNama] = useState("");
  const [pw, setPw] = useState("");
  const [show, setShow] = useState(false);
  const [err, setErr] = useState("");
  const [tried, setTried] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => { if (ready && user) nav(from, { replace: true }); }, [ready, user, nav, from]);

  const namaErr = tried && nama.trim().length < 2 ? T.validasi.wajib : "";
  const pwErr = tried && pw === "" ? T.validasi.wajib : "";

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setTried(true);
    setErr("");
    if (nama.trim().length < 2 || pw === "") { toast(T.auth.kosong, "warn"); return; }
    setBusy(true);
    try {
      await login(nama.trim(), pw);
      toast("Berhasil masuk.", "ok");
      nav(from, { replace: true });
    } catch (e) {
      const s = (e as { status?: number }).status;
      const m =
        s === 401 ? T.auth.salah
        : s === 429 ? T.auth.sibuk
        : s === 0 || s == null ? T.auth.jaringan
        : s >= 500 ? T.auth.server
        : T.auth.jaringan;
      setErr(m); toast(m, "error");
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="min-h-[calc(100dvh-8rem)] flex items-center justify-center p-4">
      <Card className="anim-fade-up w-full max-w-md p-8 shadow-[0_20px_60px_rgba(15,27,61,0.14)]">
        <div className="flex items-center gap-3 mb-5">
          <img src="/BidMaster-Ikon-1024.png" alt="" className="h-11 w-11 rounded-xl" aria-hidden />
          <img src="/BidMaster-Logo.png" alt="BidMaster AI" className="h-9 w-auto" />
        </div>
        <h1 className="text-2xl font-extrabold text-[#0F1B3D]">{T.auth.judul}</h1>
        <p className="text-base text-slate-600 mt-1 mb-5">{T.auth.sub}</p>
        <form onSubmit={submit} noValidate>
          <Label htmlFor="user">{T.auth.nama}</Label>
          <Input id="user" autoFocus autoComplete="username" placeholder="nama.pengguna"
            value={nama} onChange={(e) => setNama(e.target.value)}
            aria-invalid={namaErr !== ""} />
          <FieldError>{namaErr}</FieldError>
          <Label htmlFor="pw">{T.auth.sandi}</Label>
          <div className="relative">
            <Input id="pw" type={show ? "text" : "password"} autoComplete="current-password"
              value={pw} onChange={(e) => setPw(e.target.value)}
              aria-invalid={pwErr !== ""} className="pr-12" />
            <button type="button" onClick={() => setShow(!show)}
              aria-label={show ? T.auth.sembunyi : T.auth.lihat} aria-pressed={show}
              className="absolute right-1 top-1/2 -translate-y-1/2 p-2.5 min-w-11 min-h-11 text-slate-500 hover:text-[#0F1B3D] rounded-lg">
              {show ? <EyeOff size={18} aria-hidden /> : <Eye size={18} aria-hidden />}
            </button>
          </div>
          <FieldError>{pwErr}</FieldError>
          {err !== "" && <div className="mt-3" role="alert"><Alert kind="error">{err}</Alert></div>}
          <div className="grid mt-6">
            <Button type="submit" size="lg" disabled={busy}>
              {busy ? T.auth.memeriksa : <><LogIn size={17} aria-hidden /> {T.auth.tombol}</>}
            </Button>
          </div>
        </form>
        <p className="text-xs text-slate-500 mt-4 flex items-center gap-1.5">
          <ShieldCheck size={13} aria-hidden /> Semua aktivitas tercatat di jejak audit.
        </p>
      </Card>
    </main>
  );
}
