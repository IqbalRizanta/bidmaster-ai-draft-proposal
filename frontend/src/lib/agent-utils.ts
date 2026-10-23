import { authHeaders } from "./utils";
import { T } from "../ui-text";

export function slug(s: string): string {
  const t = s.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
  return t || "jawaban-bob";
}

export function judulDari(text: string, fallback: string): string {
  const h = text.match(/^\s*#{1,3}\s+(.+)$/m)?.[1]?.trim();
  const t = (h || fallback || "Dokumen Bob").replace(/[*_`#]/g, "").replace(/\s+/g, " ").trim();
  return t.slice(0, 120) || "Dokumen Bob";
}

export async function unduhJawaban(text: string, format: "docx" | "md", judul: string): Promise<void> {
  const nama = slug(judul);
  if (format === "md") {
    const blob = new Blob([text], { type: "text/markdown;charset=utf-8" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = `${nama}.md`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
    return;
  }
  const r = await fetch("/api/agent/file", {
    method: "POST",
    headers: authHeaders({ "Content-Type": "application/json" }),
    body: JSON.stringify({ markdown: text, format: "docx", judul, nama }),
  });
  if (!r.ok) {
    const j = (await r.json().catch(() => ({}))) as { error?: string };
    throw new Error(j.error ?? T.agen.unduhGagal);
  }
  const blob = await r.blob();
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = `${nama}.docx`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 4000);
}

export function formatTime(): string {
  return new Date().toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
}

export function renderAgentMarkdown(text: string): string {
  let s = text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  s = s.replace(/```[\w]*\n?([\s\S]*?)```/g, (_, c: string) => `<pre><code>${c.replace(/\n$/, "")}</code></pre>`);
  s = s.replace(/`([^`]+)`/g, "<code>$1</code>");
  s = s.replace(/^### (.+)$/gm, "<h3>$1</h3>").replace(/^## (.+)$/gm, "<h2>$1</h2>").replace(/^# (.+)$/gm, "<h1>$1</h1>");
  s = s.replace(/^&gt; (.+)$/gm, "<blockquote>$1</blockquote>");
  s = s.replace(/^\s*(?:---|\*\*\*)\s*$/gm, "<hr>");
  s = s.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
  s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, "$1<em>$2</em>");
  s = s.replace(/\|(.+)\|\n\|[-| :]+\|\n((?:\|.+\|\n?)*)/g, (_, h: string, rows: string) => {
    const th = h.split("|").map((c) => c.trim()).filter(Boolean).map((c) => `<th>${c}</th>`).join("");
    const tr = rows.trim().split("\n").map((r) =>
      `<tr>${r.split("|").map((c) => c.trim()).filter(Boolean).map((c) => `<td>${c}</td>`).join("")}</tr>`).join("");
    return `<table><thead><tr>${th}</tr></thead><tbody>${tr}</tbody></table>`;
  });
  return s.split(/\n{2,}/).map((b) => {
    const t = b.trim();
    if (!t) return "";
    if (/^<(h[1-6]|table|pre|ul|ol|blockquote|hr)/.test(t)) return t;
    const lines = t.split("\n");
    if (lines.every((l) => /^\s*[-*+] /.test(l))) return `<ul>${lines.map((l) => `<li>${l.replace(/^\s*[-*+] /, "")}</li>`).join("")}</ul>`;
    if (lines.every((l) => /^\s*\d+[.)] /.test(l))) return `<ol>${lines.map((l) => `<li>${l.replace(/^\s*\d+[.)] /, "")}</li>`).join("")}</ol>`;
    return `<p>${lines.map((l) => l.trim()).filter(Boolean).join("<br>")}</p>`;
  }).filter(Boolean).join("\n");
}
