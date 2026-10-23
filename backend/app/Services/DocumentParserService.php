<?php

namespace App\Services;

use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Parse dokumen requirement/pendukung.
 * Penanda lokasi tetap: nomor baris (teks) atau halaman+paragraf (PDF/DOCX).
 */
class DocumentParserService
{
    public const MAX_BYTES = 200 * 1024;

    public const ALLOWED = ['txt', 'md', 'csv', 'json', 'pdf', 'docx'];

    /**
     * @return array{nama:string,satuan:string,units:list<array{label:string,teks:string}>,jumlah:int}
     *
     * @throws \InvalidArgumentException bila format/ukuran tidak valid
     */
    public function parse(string $nama, string $bytes): array
    {
        if (strlen($bytes) === 0 || strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Ukuran dokumen 1B-200KB.');
        }
        $ext = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Format harus: txt, md, csv, json, pdf, docx.');
        }
        if ($ext === 'pdf') {
            return $this->fromPdf($nama, $bytes);
        }
        if ($ext === 'docx') {
            return $this->fromDocx($nama, $bytes);
        }

        return $this->fromLines($nama, $bytes);
    }

    private function fromLines(string $nama, string $bytes): array
    {
        if (str_contains($bytes, "\0")) {
            throw new \InvalidArgumentException('Format file biner tidak didukung.');
        }
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new \InvalidArgumentException('Format teks harus ber-encoding UTF-8 valid.');
        }

        $text = mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        $units = [];
        foreach (preg_split('/\R/', $text) as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $units[] = ['label' => 'baris '.($i + 1), 'teks' => mb_substr($line, 0, 2000), 'baris' => $i + 1];
        }

        return ['nama' => mb_substr($nama, 0, 80), 'satuan' => 'baris',
            'units' => $units, 'jumlah' => count($units)];
    }

    private function fromPdf(string $nama, string $bytes): array
    {
        try {
            $pdf = (new PdfParser)->parseContent($bytes);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('PDF tidak bisa dibaca.');
        }
        $pages = $pdf->getPages();
        if (count($pages) > 100) {
            throw new \InvalidArgumentException('Jumlah halaman PDF maksimal 100.');
        }

        $units = [];
        $no = 0;
        foreach ($pages as $pi => $page) {
            try {
                $text = $page->getText();
            } catch (\Throwable) {
                continue;
            }
            foreach (preg_split('/\R{2,}|\R/', trim($text)) as $par) {
                $par = trim(preg_replace('/\s+/', ' ', $par));
                if ($par === '') {
                    continue;
                }
                $no++;
                $units[] = ['label' => 'hal. '.($pi + 1).', par. '.$no,
                    'teks' => mb_substr($par, 0, 2000), 'halaman' => $pi + 1, 'paragraf' => $no];
            }
        }
        if ($units === []) {
            throw new \InvalidArgumentException('PDF tidak berisi teks yang bisa dibaca.');
        }

        return ['nama' => mb_substr($nama, 0, 80), 'satuan' => 'halaman',
            'units' => $units, 'jumlah' => count($units)];
    }

    private function fromDocx(string $nama, string $bytes): array
    {
        $base = tempnam(sys_get_temp_dir(), 'bm');
        $tmp = $base.'.docx';
        try {
            file_put_contents($tmp, $bytes);
            $zip = new \ZipArchive;
            if ($zip->open($tmp) === true) {
                $stat = $zip->statName('word/document.xml');
                $zip->close();
                if ($stat && ($stat['size'] ?? 0) > 10 * 1024 * 1024) {
                    throw new \InvalidArgumentException('Konten DOCX terlalu besar.');
                }
            }
            $doc = IOFactory::load($tmp);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new \InvalidArgumentException('DOCX tidak bisa dibaca.');
        } finally {
            @unlink($base);
            @unlink($tmp);
        }

        $units = [];
        $no = 0;
        foreach ($doc->getSections() as $section) {
            foreach ($section->getElements() as $el) {
                $t = $this->elementText($el);
                if ($t === '') {
                    continue;
                }
                $no++;
                $units[] = ['label' => 'par. '.$no, 'teks' => mb_substr($t, 0, 2000), 'paragraf' => $no];
            }
        }
        if ($units === []) {
            throw new \InvalidArgumentException('DOCX tidak berisi teks yang bisa dibaca.');
        }

        return ['nama' => mb_substr($nama, 0, 80), 'satuan' => 'paragraf',
            'units' => $units, 'jumlah' => count($units)];
    }

    private function elementText(mixed $el): string
    {
        if ($el instanceof Text) {
            return trim($el->getText());
        }
        if ($el instanceof TextRun
            || $el instanceof ListItem) {
            $out = '';
            foreach ($el->getElements() as $child) {
                $out .= $this->elementText($child).' ';
            }

            return trim($out);
        }
        if ($el instanceof Table) {
            $out = '';
            foreach ($el->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $child) {
                        $out .= $this->elementText($child).' | ';
                    }
                }
            }

            return trim($out, ' |');
        }

        return '';
    }
}
