<?php

namespace App\Services;

/** Susun dokumen proposal dari snapshot (bukan live) agar versi stabil. */
class DocumentService
{
    public static function slug(string $s): string
    {
        $s = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);

        return trim($s, '_') ?: 'proposal';
    }

    public static function formatSumberKlien(?array $s): string
    {
        if (! is_array($s)) {
            return '';
        }
        if (isset($s['baris'])) {
            return 'baris '.$s['baris'];
        }
        if (isset($s['halaman'], $s['paragraf'])) {
            return 'hal. '.$s['halaman'].', par. '.$s['paragraf'];
        }
        if (isset($s['paragraf'])) {
            return 'par. '.$s['paragraf'];
        }

        return (string) ($s['label'] ?? '');
    }

    /**
     * @return array{markdown:string,nama_file:string}
     */
    public function build(array $snap): array
    {
        $c = $snap['company'];
        $p = $snap['proposal'];
        $ring = $snap['ringkasan'];
        $tgl = date('d F Y');
        $md = [];
        $md[] = '# '.$c['nama_resmi'];
        $md[] = '*'.$c['tagline'].'*';
        $md[] = '';
        $md[] = '# Penawaran '.$p['instansi'];
        $md[] = '';
        $md[] = 'Disiapkan untuk: **'.$p['instansi'].'**';
        $md[] = 'Tanggal: '.$tgl;
        $md[] = '**RAHASIA**';
        $md[] = '';
        $md[] = $c['alamat'].' | '.$c['telepon'].' | '.$c['email'].' | '.$c['website'];
        $md[] = '';
        $md[] = '---';
        $md[] = '';
        $md[] = '## Ringkasan Eksekutif';
        $md[] = "Penawaran untuk {$p['instansi']} senilai ".PriceService::fmt($ring['total'])
            .", durasi {$p['durasi_minggu']} minggu, mencakup ".count($snap['items']).' butir kebutuhan.';
        $md[] = '';
        $md[] = '## Profil Perusahaan';
        $md[] = mb_substr($snap['profil'], 0, 1200);
        $md[] = '';
        $md[] = '## Matriks Kepatuhan';
        $md[] = '| No | Kebutuhan | Status | Sumber |';
        $md[] = '|---|---|---|---|';
        foreach ($snap['items'] as $it) {
            $st = $snap['decisions'][$it['id']]['status_akhir'] ?? $it['status'];
            $src = [];
            if ($it['sumber_klien']) {
                $fmt = self::formatSumberKlien($it['sumber_klien']);
                if ($fmt !== '') {
                    $src[] = 'Klien '.$fmt;
                }
            }
            foreach ($it['sumber_knowledge'] as $k) {
                $src[] = $k;
            }
            $md[] = '| '.$it['id'].' | '.str_replace('|', '/', $it['requirement']).' | '.$st.' | '.implode('; ', $src).' |';
        }
        $md[] = '';
        $md[] = '## Rincian Biaya';
        $md[] = '| Komponen | Qty | Harga satuan | Subtotal |';
        $md[] = '|---|---|---|---|';
        foreach ($ring['rows'] as $r) {
            $md[] = '| '.$r['nama'].' | '.$r['qty'].' '.$r['satuan'].' | '
                .PriceService::fmt($r['harga_proposal']).' | '.PriceService::fmt($r['subtotal']).' |';
        }
        if ($ring['diskon_rp'] > 0) {
            $md[] = '| Diskon |  |  | '.PriceService::fmt($ring['diskon_rp']).' |';
        }
        $md[] = '| **Total** |  |  | **'.PriceService::fmt($ring['total']).'** |';
        $md[] = '';
        $md[] = '## Jadwal';
        $dur = (int) $p['durasi_minggu'];
        $b = intdiv($dur, 3);
        $md[] = "- Tahap 1 (desain & analisis): {$b} minggu";
        $md[] = '- Tahap 2 (pengembangan): '.($dur - 2 * $b).' minggu';
        $md[] = "- Tahap 3 (pengujian & serah terima): {$b} minggu";
        $md[] = '';
        if (($snap['options']['lampiran_sumber'] ?? true) && $snap['items'] !== []) {
            $md[] = '## Lampiran A — Jejak Sumber';
            $md[] = '| No | Butir | Sumber | Lokasi |';
            $md[] = '|---|---|---|---|';
            $n = 0;
            foreach ($snap['items'] as $it) {
                if ($it['sumber_klien']) {
                    $n++;
                    $lok = self::formatSumberKlien($it['sumber_klien']);
                    $md[] = "| {$n} | {$it['id']} | requirement klien | {$lok} |";
                }
                foreach ($it['sumber_knowledge'] as $k) {
                    $n++;
                    $md[] = "| {$n} | {$it['id']} | knowledge | {$k} |";
                }
            }
            $md[] = '';
        }
        if (($snap['options']['lampiran_klarifikasi'] ?? true) && $snap['klarifikasi'] !== []) {
            $md[] = '## Lampiran B — Daftar Klarifikasi';
            foreach ($snap['klarifikasi'] as $k) {
                $md[] = '- '.$k['id'].': '.$k['pertanyaan'];
            }
            $md[] = '';
        }
        $md[] = '## Pengesahan';
        $md[] = 'Diperiksa dan disetujui oleh '.$snap['approved_by'].' pada '.$tgl.'.';
        $md[] = 'Analisis awal dibantu AI; setiap pernyataan telah diperiksa terhadap dokumen sumber oleh penyusun.';

        $markdown = implode("\n", $md)."\n";
        $nama = 'Proposal_'.self::slug($p['instansi']).'_'.date('Ymd').'.md';

        return ['markdown' => $markdown, 'nama_file' => $nama];
    }
}
