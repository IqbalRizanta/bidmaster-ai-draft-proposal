/** Markdown ringan → HTML (dipakai untuk pratinjau & cetak). */
export function mdToHtml(text: string): string {
  const esc = (s: string) => s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  let s = esc(text);
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

/**
 * Cetak markdown lewat portal tersembunyi di document.body.
 * Lebih andal daripada mengandalkan elemen yang mungkin belum ter-mount.
 */
export function printMarkdown(markdown: string, title: string, opts: { logo?: string; company?: string } = {}) {
  const host = document.createElement("div");
  host.className = "print-portal";

  const page = document.createElement("div");
  page.className = "print-page";

  if (opts.logo) {
    const cover = document.createElement("div");
    cover.className = "print-cover";
    const img = document.createElement("img");
    img.src = opts.logo;
    img.alt = opts.company ?? "";
    cover.appendChild(img);
    if (opts.company) {
      const nm = document.createElement("p");
      nm.className = "print-cover-name";
      nm.textContent = opts.company;
      cover.appendChild(nm);
    }
    page.appendChild(cover);
  }

  const body = document.createElement("div");
  body.innerHTML = mdToHtml(markdown);
  page.appendChild(body);

  const foot = document.createElement("div");
  foot.className = "print-foot";
  const stamp = new Date().toLocaleString("id-ID", { dateStyle: "long", timeStyle: "short" });
  const left = document.createElement("span");
  left.textContent = title;
  const right = document.createElement("span");
  right.textContent = `BidMaster AI · ${stamp}`;
  foot.append(left, right);

  host.append(page, foot);
  document.body.appendChild(host);

  const cleanup = () => {
    window.removeEventListener("afterprint", cleanup);
    host.remove();
  };
  window.addEventListener("afterprint", cleanup);
  // Fallback bila afterprint tidak dipanggil (beberapa browser).
  setTimeout(() => {
    window.print();
    setTimeout(cleanup, 1500);
  }, 60);
}
