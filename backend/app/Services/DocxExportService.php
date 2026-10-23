<?php

namespace App\Services;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\ListItem as ListItemStyle;

/**
 * Markdown → DOCX yang rapi (heading, tabel, bullet/nomor, kutipan, kode)
 * dengan cover berlogo opsional + header/footer. Port api/export_docx.py.
 */
class DocxExportService
{
    /** Logo perusahaan: pakai aset terunggah, fallback logo publik frontend. */
    public static function logoPath(): string
    {
        $p = storage_path('app/company-logo.png');
        if (is_file($p)) {
            return $p;
        }
        $dir = rtrim((string) config('bidmaster.knowledge_dir'), '/\\');
        foreach ([$dir.'/../frontend/public/NusaTech-Logo.png', $dir.'/../frontend/public/BidMaster-Logo.png'] as $alt) {
            if (is_file($alt)) {
                return $alt;
            }
        }

        return '';
    }

    /**
     * @param  array{title?:string,subtitle?:string,company?:array,logoPath?:string}  $opts
     */
    public function toBytes(string $markdown, array $opts = []): string
    {
        Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');

        $company = is_array($opts['company'] ?? null) ? $opts['company'] : [];
        $logo = (string) ($opts['logoPath'] ?? self::logoPath());
        $title = trim((string) ($opts['title'] ?? ''));
        $subtitle = trim((string) ($opts['subtitle'] ?? ''));
        $warna = ltrim((string) ($company['warna_utama'] ?? '#1F3864'), '#');

        // ── Halaman cover (opsional, tanpa header/footer) ──
        if ($title !== '') {
            $cover = $phpWord->addSection();
            if ($logo !== '' && is_file($logo)) {
                $cover->addImage($logo, ['width' => 200, 'alignment' => Jc::CENTER]);
            }
            if (! empty($company['nama_resmi'])) {
                $cover->addText($company['nama_resmi'], ['bold' => true, 'size' => 18], ['alignment' => Jc::CENTER]);
            }
            if (! empty($company['tagline'])) {
                $cover->addText($company['tagline'], ['size' => 10, 'color' => '666666'], ['alignment' => Jc::CENTER]);
            }
            $cover->addTextBreak(3);
            $cover->addText($title, ['bold' => true, 'size' => 24, 'color' => $warna],
                ['alignment' => Jc::CENTER, 'spaceAfter' => 200]);
            if ($subtitle !== '') {
                $cover->addText($subtitle, ['size' => 12], ['alignment' => Jc::CENTER]);
            }
            $cover->addText(date('d F Y'), ['size' => 11, 'color' => '666666'], ['alignment' => Jc::CENTER]);
            $cover->addTextBreak(2);
            $cover->addText('RAHASIA', ['bold' => true, 'size' => 13, 'color' => $warna], ['alignment' => Jc::CENTER]);
            $contact = array_values(array_filter([
                (string) ($company['alamat'] ?? ''), (string) ($company['telepon'] ?? ''),
                (string) ($company['email'] ?? ''), (string) ($company['website'] ?? ''),
            ], fn ($v) => trim($v) !== ''));
            if ($contact !== []) {
                $cover->addTextBreak(3);
                $cover->addText(implode(' | ', $contact), ['size' => 9, 'color' => '666666'], ['alignment' => Jc::CENTER]);
            }
        }

        // ── Halaman isi (+ header logo & footer nomor halaman) ──
        $body = $phpWord->addSection();
        if ($logo !== '' && is_file($logo) && ! empty($company['nama_singkat'])) {
            $header = $body->addHeader();
            $header->addImage($logo, ['width' => 60]);
            $header->addText($company['nama_singkat'], ['bold' => true, 'size' => 9],
                ['alignment' => Jc::END, 'borderBottomSize' => 6, 'borderBottomColor' => $warna]);
            $footer = $body->addFooter();
            $footer->addPreserveText($company['nama_singkat'].' | Halaman {PAGE}', ['size' => 9, 'color' => '666666']);
        }

        $this->render($body, $markdown);

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

    /** Tulis markdown ke sebuah section dengan gaya yang rapi. */
    private function render(Section $section, string $markdown): void
    {
        $table = [];
        $flushTable = function () use (&$table, $section) {
            if ($table === []) {
                return;
            }
            $rows = $table;
            $table = [];
            $header = null;
            if (count($rows) > 1 && $this->isSeparatorRow($rows[1])) {
                $header = array_shift($rows);
                array_shift($rows);
            }
            $t = $section->addTable(['borderSize' => 4, 'borderColor' => 'B9CDF0', 'cellMargin' => 80]);
            if ($header !== null) {
                $t->addRow();
                foreach ($header as $cell) {
                    $t->addCell(2600, ['bgColor' => 'EEF0F8'])->addText(trim($cell, ' *'), ['bold' => true]);
                }
            }
            foreach ($rows as $row) {
                $t->addRow();
                foreach ($row as $cell) {
                    $this->para($t->addCell(2600), trim($cell));
                }
            }
        };

        $inCode = false;
        $code = [];
        $flushCode = function () use (&$code, &$inCode, $section) {
            if ($code === []) {
                $inCode = false;

                return;
            }
            foreach ($code as $cl) {
                $section->addText($cl === '' ? ' ' : $cl,
                    ['name' => 'Consolas', 'size' => 9, 'color' => 'E9EDFB'],
                    ['shading' => ['fill' => '0F1B3D'], 'indentation' => ['left' => 200, 'right' => 200]]);
            }
            $code = [];
            $inCode = false;
        };

        foreach (explode("\n", $markdown) as $raw) {
            $line = rtrim(mb_substr($raw, 0, 3000));

            if (str_starts_with(trim($line), '```')) {
                if ($inCode) {
                    $flushCode();
                } else {
                    $flushTable();
                    $inCode = true;
                }

                continue;
            }
            if ($inCode) {
                $code[] = $line;

                continue;
            }
            if (str_starts_with($line, '|') && str_ends_with($line, '|')) {
                $table[] = array_map('trim', explode('|', trim($line, '|')));

                continue;
            }
            $flushTable();

            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $section->addTitle($m[2], min(strlen($m[1]), 3));
            } elseif (preg_match('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $section->addText('', [], ['borderBottomSize' => 6, 'borderBottomColor' => 'D7DDEE', 'spaceAfter' => 120]);
            } elseif (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $this->para($section, $m[1], ['indentation' => ['left' => 360]], ['italic' => true, 'color' => '4A5578']);
            } elseif (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m)) {
                $this->listItem($section, $m[1], ListItemStyle::TYPE_BULLET_FILLED);
            } elseif (preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $m)) {
                $this->listItem($section, $m[1], ListItemStyle::TYPE_NUMBER);
            } elseif (trim($line) !== '') {
                $this->para($section, $line);
            } else {
                $section->addText('');
            }
        }
        $flushCode();
        $flushTable();
    }

    /** Paragraf dengan teks kaya (bold/italic/kode) di dalam TextRun. */
    private function para($container, string $text, array $pStyle = [], array $extra = []): void
    {
        $run = $container->addTextRun($pStyle);
        $this->runs($run, $text, $extra);
    }

    /** Butir daftar dengan teks kaya. */
    private function listItem(Section $section, string $text, int $listType): void
    {
        $run = $section->addListItemRun(0, ['listType' => $listType]);
        $this->runs($run, $text);
    }

    /** Pecah teks menjadi run dengan gaya inline. */
    private function runs($run, string $text, array $extra = []): void
    {
        foreach (preg_split('/(\*\*.+?\*\*|\*[^*\n]+?\*|`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if ($part === '') {
                continue;
            }
            if (str_starts_with($part, '**') && str_ends_with($part, '**') && mb_strlen($part) > 4) {
                $run->addText(mb_substr($part, 2, -2), array_merge($extra, ['bold' => true]));
            } elseif (str_starts_with($part, '*') && str_ends_with($part, '*') && mb_strlen($part) > 2) {
                $run->addText(mb_substr($part, 1, -1), array_merge($extra, ['italic' => true]));
            } elseif (str_starts_with($part, '`') && str_ends_with($part, '`') && mb_strlen($part) > 2) {
                $run->addText(mb_substr($part, 1, -1), ['name' => 'Consolas', 'size' => 9, 'color' => '22306A', 'bgColor' => 'EEF0F8']);
            } else {
                $run->addText($part, $extra);
            }
        }
    }

    /** Baris pemisah tabel Markdown, mis. "|---|:--:|". Aman untuk array sel. */
    private function isSeparatorRow(array $row): bool
    {
        if ($row === []) {
            return false;
        }
        foreach ($row as $cell) {
            if (preg_match('/^[\s:|-]+$/', (string) $cell) !== 1) {
                return false;
            }
        }

        return true;
    }
}
