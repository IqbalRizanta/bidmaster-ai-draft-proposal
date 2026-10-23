<?php

namespace App\Services;

use App\Gates\GateEvaluator;
use App\Gates\KakHelper;

/** Port deterministik dari api/server.py: validate() + compose(). */
class DraftComposerService
{
    public const INDUSTRIES = ['Pemerintahan', 'F&B / Kafe', 'Healthcare / Klinik', 'Fashion / UMKM', 'Jasa', 'Lainnya'];

    public const FEATURES = ['Katalog/menu + CMS', 'Reservasi online', 'Pembayaran QRIS', 'Blog + analitik', 'Galeri foto', 'Peta + tombol WA', 'Member/loyalty', 'Multi-cabang/bahasa'];

    private const SITEMAP = ['Katalog/menu + CMS' => 'Katalog', 'Reservasi online' => 'Reservasi', 'Pembayaran QRIS' => 'Pembayaran', 'Blog + analitik' => 'Blog', 'Galeri foto' => 'Galeri', 'Peta + tombol WA' => 'Lokasi & Kontak', 'Member/loyalty' => 'Area Member', 'Multi-cabang/bahasa' => 'Multi-cabang'];

    private const TENDER_SITEMAP = ['Beranda', 'Profil', 'Layanan', 'Jadwal', 'Kontak'];

    public const MAX_DOC = 200 * 1024;

    public function __construct(
        private KnowledgeService $knowledge,
        private GateEvaluator $gates,
    ) {}

    private function err(string $field, string $msg): array
    {
        return ['error' => $msg, 'field' => $field];
    }

    /** @return array{0:?array,1:?array} [validated, problem] */
    public function validate(array $d): array
    {
        $mode = $d['mode'] ?? 'tender';
        if (! in_array($mode, ['website', 'tender'], true)) {
            return [null, $this->err('mode', 'Mode website/tender.')];
        }
        $nama = mb_substr(trim((string) ($d['nama'] ?? '')), 0, 80);
        if (mb_strlen($nama) < 2) {
            return [null, $this->err('nama', 'Nama instansi minimal 2 karakter.')];
        }
        $ind = (string) ($d['industri'] ?? 'Pemerintahan');
        if (! in_array($ind, self::INDUSTRIES, true)) {
            return [null, $this->err('ind', 'Industri tidak dikenal.')];
        }
        $dur = filter_var($d['durasi_minggu'] ?? 0, FILTER_VALIDATE_INT);
        if ($dur === false || $dur < 1 || $dur > 12) {
            return [null, $this->err('dur', 'Durasi 1-12 minggu.')];
        }
        $disc = $d['diskon_persen'] ?? 0;
        if (! is_numeric($disc)) {
            return [null, $this->err('disk', 'Diskon harus angka.')];
        }
        $disc = (float) $disc;
        $maxDisc = $this->knowledge->website()['catalog']['discount_rules']['max_discount_percent'];
        if ($disc < 0 || $disc > $maxDisc) {
            return [null, $this->err('disk', "Diskon maks {$maxDisc}%.")];
        }
        $dokRaw = (string) ($d['dokumen_text'] ?? '');
        if (strlen($dokRaw) > self::MAX_DOC) {
            return [null, $this->err('dok', 'Dokumen maks 200KB.')];
        }
        $pagu = filter_var($d['pagu'] ?? 0, FILTER_VALIDATE_INT);
        if ($pagu === false || $pagu < 0 || $pagu > 10_000_000_000) {
            return [null, $this->err('pagu', 'Pagu 0-10Miliar.')];
        }
        $legalRaw = is_array($d['legal'] ?? null) ? $d['legal'] : [];
        $legal = [];
        foreach (['nib', 'npwp', 'akta'] as $k) {
            $legal[$k] = mb_substr(trim((string) ($legalRaw[$k] ?? '')), 0, 40);
        }
        $v = ['mode' => $mode, 'nama' => $nama, 'industri' => $ind, 'durasi' => $dur,
            'diskon' => $disc, 'dok' => mb_substr($dokRaw, 0, self::MAX_DOC),
            'pagu' => (int) $pagu, 'legal' => $legal,
            'dok_nama' => mb_substr((string) ($d['dokumen_nama'] ?? ''), 0, 80),
            'catatan' => mb_substr(trim((string) ($d['catatan'] ?? '')), 0, 500)];
        if ($mode === 'website') {
            $catalog = $this->knowledge->website()['catalog'];
            $paket = collect($catalog['packages'])->firstWhere('id', (string) ($d['paket'] ?? ''));
            if (! $paket) {
                return [null, $this->err('paket', 'Pilih paket Basic/Standard/Premium.')];
            }
            $feats = $d['fitur'] ?? [];
            if (! is_array($feats) || $feats === []
                || array_diff($feats, self::FEATURES) !== []) {
                return [null, $this->err('fitur', 'Pilih minimal 1 fitur valid.')];
            }
            $v['paket'] = $paket;
            $v['fitur'] = array_slice(array_values(array_unique($feats)), 0, 8);
        } else {
            $tender = $this->knowledge->tender()['tender'];
            $map = [];
            foreach ($tender['komponen'] as $k) {
                $map[$k['kode']] = $k;
            }
            $rows = $d['komponen'] ?? [];
            if (! is_array($rows) || $rows === []) {
                return [null, $this->err('komp', 'Isi qty minimal 1 komponen.')];
            }
            $items = [];
            foreach ($rows as $r) {
                if (! is_array($r)) {
                    return [null, $this->err('komp', 'Baris komponen invalid.')];
                }
                $k = $map[(string) ($r['kode'] ?? '')] ?? null;
                if (! $k) {
                    return [null, $this->err('komp', "Kode {$r['kode']} tidak dikenal.")];
                }
                $qty = filter_var($r['qty'] ?? 0, FILTER_VALIDATE_INT);
                if ($qty === false) {
                    return [null, $this->err('komp', "Qty {$k['kode']} harus angka.")];
                }
                if ($qty < 0 || $qty > 1000) {
                    return [null, $this->err('komp', "Qty {$k['kode']} 0-1000.")];
                }
                if ($qty > 0) {
                    $items[] = [$k, $qty];
                }
            }
            if ($items === []) {
                return [null, $this->err('komp', 'Isi qty minimal 1 komponen.')];
            }
            $v['items'] = array_slice($items, 0, 10);
        }

        return [$v, null];
    }

    private static function fmt(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    /** @return array{markdown:string,compliance:array,meta:array,korporat:array} */
    public function compose(array $v): array
    {
        $tag = KnowledgeService::TAG;
        $dur = $v['durasi'];
        if ($v['mode'] === 'website') {
            ['template' => $template, 'catalog' => $catalog] = $this->knowledge->website();
            $p = $v['paket'];
            $total = (int) round($p['price_idr'] * (100 - $v['diskon']) / 100);
            $raw = $p['price_idr'];
            $feats = $v['fitur'];
            $rab = "| Komponen | Estimasi (Rp) |\n|---|---|\n";
            $n = count($feats);
            $acc = 0;
            foreach ($feats as $i => $lab) {
                $nom = $i < $n - 1 ? intdiv($total, $n) : $total - $acc;
                $acc += $nom;
                $rab .= '| '.$lab.' | '.self::fmt($nom)." |\n";
            }
            $rab .= '| **Total bayar ('.$p['name'].')** | **'.self::fmt($total)."** |\n";
            $tab = "| Paket | Harga | Timeline | Cocok untuk |\n|---|---|---|---|\n";
            foreach ($catalog['packages'] as $q) {
                $tab .= "| {$q['name']} | Rp ".self::fmt($q['price_idr'])." | {$q['timeline_weeks']} minggu | {$q['best_for']} |\n";
            }
            $reason = count($feats)." fitur + durasi {$dur} minggu, total Rp ".self::fmt($total);
            $sitemap = array_merge(['Beranda'], array_map(fn ($f) => self::SITEMAP[$f], $feats));
            $priceProof = self::fmt($p['price_idr']);
        } else {
            $t = $this->knowledge->tender();
            $raw = array_sum(array_map(fn ($it) => $it[0]['harga_dasar'] * $it[1], $v['items']));
            $total = (int) round($raw * (100 - $v['diskon']) / 100);
            $pname = 'Tender';
            $rab = "| Kode | Komponen | Qty × Satuan | Harga satuan | Subtotal |\n|---|---|---|---|---|\n";
            foreach ($v['items'] as [$k, $qty]) {
                $rab .= "| {$k['kode']} | {$k['nama']} | {$qty} {$k['satuan']} | "
                    .self::fmt($k['harga_dasar']).' | '.self::fmt($k['harga_dasar'] * $qty)." |\n";
            }
            $rab .= '|  |  |  | **Total bayar (Tender)** | **'.self::fmt($total)."** |\n";
            if ($v['pagu']) {
                $rab .= "\nPagu: Rp ".self::fmt($v['pagu']).' — '
                    .($total > $v['pagu'] ? 'DI ATAS pagu [perlu konfirmasi: turunkan qty]' : 'di dalam pagu').".\n";
            }
            $reason = count($v['items'])." komponen x qty, durasi {$dur} minggu, total Rp ".self::fmt($total);
            $sitemap = self::TENDER_SITEMAP;
            $feats = array_map(fn ($it) => "{$it[0]['nama']} ({$it[1]} {$it[0]['satuan']})", $v['items']);
            $priceProof = self::fmt($v['items'][0][0]['harga_dasar']);
        }
        $pot = $raw - $total;
        $discTxt = $pot > 0 ? " (diskon {$v['diskon']}%, hemat Rp ".self::fmt($pot).')' : '';
        $b = intdiv($dur, 3);
        $r = $dur % 3;
        $ph = [$b + ($r > 0 ? 1 : 0), $b + ($r > 1 ? 1 : 0), $b];
        $phases = "- M1 Desain & konten: {$ph[0]} minggu\n- M2 Development: {$ph[1]} minggu\n- M3 QA + go-live: {$ph[2]} minggu";

        if ($v['mode'] === 'website') {
            $pname = $p['name'];
            $obj = '- Menyediakan '.$feats[0]."\n- Menerima interaksi/transaksi online";
            $nFeat = count($feats);
            $s = [["Kebutuhan terdefinisi: {$nFeat} item dipilih", 'dari form — deterministik']];
            if ($v['dok'] !== '') {
                $s[] = ['Dokumen requirement tersedia', strlen($v['dok']).' karakter, '.($v['dok_nama'] ?: 'tanpa nama')];
            }
            $s[] = ["Konteks {$v['industri']}", "{$tag}: validasi data eksisting client]"];
            $w = [["Kehadiran digital belum optimal ({$v['industri']})", 'sulit ditemukan & transaksi manual', "paket {$pname}: {$feats[0]}"]];
            $dokLow = mb_strtolower($v['dok']);
            if (str_contains($dokLow, 'manual') || str_contains($dokLow, 'whatsapp')) {
                $w[] = ['Proses masih manual (dari dokumen)', 'calon client bisa pindah', "otomatisasi via {$feats[0]}"];
            }
            $md = str_replace('{client_name}', $v['nama'], $template);
            $md = str_replace('{date}', date('d F Y'), $md);
            $dokref = $v['dok_nama'] !== '' ? " (dokumen {$v['dok_nama']})" : '';
            $md = str_replace('{executive_summary}',
                "{$v['nama']} ({$v['industri']}) membutuhkan: ".implode(', ', array_slice($feats, 0, 3))
                .". Penawaran {$pname} Rp ".self::fmt($total).", {$dur} minggu{$dokref}.", $md);
            $prof = "**{$v['nama']}** — {$v['industri']}.\nItem: ".implode(', ', $feats).".\nDurasi: {$dur} minggu. Diskon: {$v['diskon']}%.";
            if ($v['dok'] !== '') {
                $tail = strlen($v['dok']) > 500 ? '…' : '';
                $prof .= "\nKebutuhan tambahan dari dokumen (".strlen($v['dok']).' karakter): '.mb_substr($v['dok'], 0, 500).$tail;
            }
            if ($v['catatan'] !== '') {
                $prof .= "\nCatatan: ".$v['catatan'];
            }
            $rep = [
                '{client_profile}' => $prof,
                '{strengths}' => implode("\n", array_map(fn ($a) => "- **{$a[0]}** — {$a[1]}", $s)),
                '{weaknesses}' => implode("\n", array_map(fn ($a) => "- **{$a[0]}** (dampak: {$a[1]}) → solusi: {$a[2]}", $w)),
                '{objectives}' => $obj,
                '{target_users}' => "- Pengguna {$v['industri']} [perlu konfirmasi: segmen pasti]",
                '{sitemap}' => implode("\n", array_map(fn ($x) => '- '.$x, $sitemap)),
                '{key_features}' => implode("\n", array_map(fn ($f) => '- '.$f, $feats)),
                '{tech_suggestion}' => "Next.js + CMS ringan + WhatsApp API {$tag}: stack default studio]",
                '{timeline_weeks}' => (string) $dur,
                '{phases}' => $phases,
                '{rab_table}' => $rab,
                '{packages_table}' => $tab,
                '{recommended_package}' => $pname,
                '{recommendation_reason}' => $reason.$discTxt,
                '{terms}' => "DP 50%, pelunasan sebelum serah terima. Maks 2x revisi mayor. Berlaku {$catalog['discount_rules']['validity_days']} hari. Harga belum termasuk domain/hosting.",
                '{cta}' => 'Balas setuju + pilih paket, kami kirim invoice DP dan jadwal kickoff.',
            ];
            $md = str_replace(array_keys($rep), array_values($rep), $md);
            $checks = [
                '10 bab + RAB-lite' => str_contains($md, '## 2. Profil Client') && str_contains($md, '## 7. RAB'),
                'Keunggulan + kekurangan' => str_contains($md, 'Keunggulan Client') && str_contains($md, 'Kekurangan'),
                'Harga = katalog' => str_contains($md, $priceProof),
                'Diskon dalam batas' => $v['diskon'] <= $catalog['discount_rules']['max_discount_percent'],
                'RAB total konsisten' => str_contains($rab, self::fmt($total)),
                'Tag konfirmasi' => str_contains($md, $tag),
                'Klausul DP 50%' => str_contains($md, 'DP 50%'),
            ];
            $extra = ['kak' => [], 'uncovered' => [], 'missing_legal' => [],
                'gate' => ['passed' => true, 'blocks' => []], 'pagu' => $v['pagu']];
        } else {
            $pname = 'Tender';
            $itemsKak = KakHelper::items($v['dok']);
            $cov = KakHelper::coverage($itemsKak, array_map(fn ($it) => $it[0], $v['items']));
            $missing = KakHelper::missingLegal($v['legal']);
            $g = $this->gates->evaluate(['total' => $total, 'pagu' => $v['pagu'],
                'uncovered' => $cov['uncovered'], 'missing_legal' => $missing]);
            $tgl = date('d F Y');
            $ringkas = "{$v['nama']} membutuhkan: ".implode(', ', array_slice($feats, 0, 3))
                .'. Penawaran Rp '.self::fmt($total).", {$dur} minggu{$discTxt}.";
            $profil = mb_substr($t['company'], 0, 1200)."\nLegalitas: NIB "
                .($v['legal']['nib'] ?: '[perlu konfirmasi]').', NPWP '
                .($v['legal']['npwp'] ?: '[perlu konfirmasi]').', Akta '
                .($v['legal']['akta'] ?: '[perlu konfirmasi]').'.';
            $paham = ($v['dok'] !== '' ? mb_substr($v['dok'], 0, 1200) : '[perlu konfirmasi: KAK belum ditempel]')
                .($cov['rows'] !== [] ? "\nKAK terpetakan: ".(count($cov['rows']) - count($cov['uncovered'])).'/'.count($cov['rows']).'.' : '');
            if ($v['catatan'] !== '') {
                $paham .= "\nCatatan internal: ".$v['catatan'];
            }
            $solusi = implode("\n", array_map(fn ($f) => '- '.$f, $feats))."\nSitemap: ".implode(', ', $sitemap).'.';
            $timTxt = implode("\n", array_map(
                fn ($a) => "- {$a['nama']} — {$a['peran']} (Rp ".self::fmt($a['tarif_harian']).'/hari)',
                array_slice($t['team']['anggota'], 0, 6)));
            $rep = [
                '{instansi}' => $v['nama'], '{tanggal}' => $tgl, '{ringkasan}' => $ringkas,
                '{profil}' => $profil, '{pemahaman}' => $paham, '{solusi}' => $solusi,
                '{metodologi}' => 'Mengacu SOP perusahaan: analisis → desain → pengembangan → pengujian → implementasi → pelatihan → pemeliharaan.',
                '{jadwal}' => $phases, '{tim}' => $timTxt,
                '{uji}' => "Unit/integrasi/fungsional + UAT bersama pengguna. Garansi & pemeliharaan sesuai KAK {$tag}: masa dukungan pasti].",
                '{rab}' => $rab,
                '{penutup}' => 'Hormat kami, [Nama Perusahaan]. Lampiran: profil, RAB, jadwal, daftar tim. Status draft — wajib review manusia sebelum dikirim ke panitia.',
            ];
            $md = str_replace(array_keys($rep), array_values($rep), $t['template']);
            $checks = [
                '11 bab + harga' => str_contains($md, '## 10. Penawaran Harga') && str_contains($md, '## 3. Profil Perusahaan'),
                'Harga = katalog' => str_contains($md, $priceProof),
                'RAB total konsisten' => str_contains($rab, self::fmt($total)),
                'KAK terpetakan' => $cov['uncovered'] === [],
                'Pagu OK' => ! $v['pagu'] || $total <= $v['pagu'],
                'Legalitas lengkap' => $missing === [],
                'Tag konfirmasi' => str_contains($md, $tag),
            ];
            $extra = ['kak' => $cov['rows'], 'uncovered' => $cov['uncovered'],
                'missing_legal' => $missing, 'gate' => $g, 'pagu' => $v['pagu']];
        }
        $score = count(array_filter($checks)) / count($checks) * 100;
        $tail = "\n\n---\n**Compliance: ".round($score)."%\n"
            .implode("\n", array_map(fn ($ok, $n) => '- ['.($ok ? 'x' : ' ')."] {$n}",
                $checks, array_keys($checks)));
        if ($v['mode'] === 'tender' && ! $extra['gate']['passed']) {
            $tail .= "\n**Gate korporat: BLOKIR**\n".implode("\n", array_map(fn ($b) => '- '.$b, $extra['gate']['blocks']));
        }
        $tail .= "\n*Status: draft — menunggu persetujuan manusia.*";
        $passed = ! in_array(false, $checks, true) && $extra['gate']['passed'];

        return ['markdown' => $md.$tail,
            'compliance' => ['score' => $score, 'passed' => $passed,
                'checks' => array_map(fn ($ok, $n) => ['name' => $n, 'ok' => $ok],
                    $checks, array_keys($checks))],
            'meta' => ['package' => $pname, 'total' => $total, 'duration_weeks' => $dur, 'mode' => $v['mode']],
            'korporat' => $extra];
    }
}
