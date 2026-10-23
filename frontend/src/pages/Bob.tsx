import { ArrowLeft } from "lucide-react";
import { useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { ChatBob } from "./Agent";
import { Button } from "../components/ui/controls";
import { T } from "../ui-text";
import { api, authHeaders } from "../lib/utils";

export default function Bob() {
  const [params] = useSearchParams();
  const idStr = params.get("proposal");
  const propId = idStr ? parseInt(idStr, 10) : null;
  const [instansi, setInstansi] = useState(params.get("instansi") ?? "");

  useEffect(() => {
    if (propId && !instansi) {
      api<{ proposal: { instansi: string } }>(`/api/proposals/${propId}`, { headers: authHeaders() })
        .then((j) => setInstansi(j.proposal.instansi))
        .catch(() => {});
    }
  }, [propId, instansi]);

  const context = propId ? { id: propId, instansi } : undefined;

  return (
    <main className="max-w-6xl mx-auto px-4 py-5 pb-16">
      <div className="flex items-center gap-3 mb-4 flex-wrap">
        <Link to={propId ? `/app/${propId}` : "/app"} className="no-underline">
          <Button variant="secondary" size="sm"><ArrowLeft size={15} aria-hidden /> {T.agen.tutupHalaman}</Button>
        </Link>
        <div className="min-w-0">
          <h1 className="font-extrabold text-lg text-[#0F1B3D] leading-tight">{T.agen.halamanJudul}</h1>
          <p className="text-xs text-slate-500 truncate">{T.agen.halamanSub}</p>
        </div>
      </div>
      <ChatBob variant="full" context={context} />
    </main>
  );
}
