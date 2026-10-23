<?php

namespace App\Services;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

/** Cover + header/footer + logo di DOCX. Hanya harga proposal, tanpa data internal. */
class ProposalDocxService
{
    public function __construct(private DocumentService $docs) {}

    public function toBytes(array $snap, string $logoPath): string
    {
        Settings::setOutputEscapingEnabled(true);

        $built = $this->docs->build($snap);
        $c = $snap['company'];
        $warna = ltrim($c['warna_utama'] ?? '#1F3864', '#');
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');

        // Halaman 1: cover (tanpa header/footer)
        $cover = $phpWord->addSection();
        if (is_file($logoPath)) {
            $cover->addImage($logoPath, ['width' => 220, 'alignment' => Jc::CENTER]);
        }
        $cover->addText($c['nama_resmi'], ['bold' => true, 'size' => 20], ['alignment' => Jc::CENTER]);
        $cover->addText($c['tagline'], ['size' => 11, 'color' => '666666'], ['alignment' => Jc::CENTER]);
        $cover->addTextBreak(1);
        $cover->addText('Penawaran '.$snap['proposal']['instansi'],
            ['bold' => true, 'size' => 24, 'color' => $warna], ['alignment' => Jc::CENTER]);
        $cover->addText('Disiapkan untuk: '.$snap['proposal']['instansi'], ['size' => 12], ['alignment' => Jc::CENTER]);
        $cover->addText('Tanggal: '.date('d F Y'), ['size' => 12], ['alignment' => Jc::CENTER]);
        $cover->addText('RAHASIA', ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER]);
        $cover->addTextBreak(2);
        $cover->addText($c['alamat'].' | '.$c['telepon'].' | '.$c['email'].' | '.$c['website'],
            ['size' => 9, 'color' => '666666'], ['alignment' => Jc::CENTER]);

        // Halaman 2+: header logo kecil + garis, footer nama + halaman
        $body = $phpWord->addSection();
        $header = $body->addHeader();
        if (is_file($logoPath)) {
            $header->addImage($logoPath, ['width' => 70]);
        }
        $header->addText($c['nama_singkat'],
            ['bold' => true, 'size' => 9],
            ['alignment' => Jc::END, 'borderBottomSize' => 6, 'borderBottomColor' => $warna]);
        $footer = $body->addFooter();
        $footer->addPreserveText($c['nama_singkat'].' | Halaman {PAGE}', ['size' => 9, 'color' => '666666']);

        // Isi dari markdown (lewati blok cover: sampai garis --- pertama)
        $lines = explode("\n", $built['markdown']);
        $start = 0;
        foreach ($lines as $i => $line) {
            if (trim($line) === '---') {
                $start = $i + 1;
                break;
            }
        }
        $table = [];
        $flush = function () use (&$table, $body) {
            if ($table === []) {
                return;
            }
            $rows = $table;
            $table = [];
            $hdr = null;
            if (count($rows) >= 2 && isset($rows[1][0]) && preg_match('/^[\s:|\-]+$/', $rows[1][0])) {
                $hdr = array_shift($rows);
                array_shift($rows);
            }
            $t = $body->addTable(['borderSize' => 4, 'borderColor' => '999999']);
            if ($hdr) {
                $t->addRow();
                foreach ($hdr as $cell) {
                    $t->addCell(2500)->addText(trim($cell, ' *'), ['bold' => true]);
                }
            }
            foreach ($rows as $row) {
                $t->addRow();
                foreach ($row as $cell) {
                    $t->addCell(2500)->addText(trim($cell, ' *'));
                }
            }
        };
        foreach (array_slice($lines, $start) as $rawLine) {
            $line = rtrim(mb_substr($rawLine, 0, 2000));
            if (str_starts_with($line, '|') && str_ends_with($line, '|')) {
                $table[] = array_map('trim', explode('|', trim($line, '|')));

                continue;
            }
            $flush();
            if (str_starts_with($line, '## ')) {
                $body->addTitle(mb_substr($line, 3), 2);
            } elseif (str_starts_with($line, '# ')) {
                $body->addTitle(mb_substr($line, 2), 1);
            } elseif (str_starts_with($line, '- ')) {
                $body->addListItem('• '.mb_substr($line, 2));
            } elseif (trim($line) !== '') {
                $body->addText($line);
            }
        }
        $flush();

        $base = tempnam(sys_get_temp_dir(), 'bm');
        $tmp = $base.'.docx';
        try {
            $phpWord->save($tmp, 'Word2007');

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($base);
            @unlink($tmp);
        }
    }
}
