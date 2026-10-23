<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Services\DocumentParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class ProposalsTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $peran = 'bd'): string
    {
        Member::create(['nama' => 'pre', 'peran' => $peran, 'password' => Hash::make('pw123456')]);

        return $this->postJson('/api/login', ['nama' => 'pre', 'pw' => 'pw123456'])->json('token');
    }

    private function buat(string $t): array
    {
        $h = ['Authorization' => "Bearer {$t}"];

        return [$this->postJson('/api/proposals', ['instansi' => 'Dinas X'], $h)->json('proposal'), $h];
    }

    public function test_buat_autosave_riwayat_hapus(): void
    {
        [$p, $h] = $this->buat($this->token());
        $this->assertSame('Dinas X', $p['instansi']);
        $r = $this->patchJson("/api/proposals/{$p['id']}", ['pagu' => 50000000], $h);
        $r->assertOk()->assertJsonPath('proposal.pagu', 50000000);
        $this->assertMatchesRegularExpression('/^\d{2}\.\d{2}$/', $r->json('tersimpan_pukul'));
        $list = $this->getJson('/api/proposals', $h)->json('proposals');
        $this->assertCount(1, $list);
        $this->deleteJson("/api/proposals/{$p['id']}", [], $h)->assertOk();
        $this->assertCount(0, $this->getJson('/api/proposals', $h)->json('proposals'));
        $this->assertDatabaseHas('audits', ['aksi' => 'proposal_buat']);
        $this->assertDatabaseHas('audits', ['aksi' => 'proposal_hapus']);
    }

    public function test_upload_txt_12_baris(): void
    {
        [$p, $h] = $this->buat($this->token());
        $txt = implode("\n", array_map(fn ($i) => "Kebutuhan {$i}", range(1, 12)));
        $f = UploadedFile::fake()->createWithContent('requirement_uji.txt', $txt);
        $r = $this->post("/api/proposals/{$p['id']}/upload", ['kind' => 'requirement', 'file' => $f], $h);
        $r->assertOk();
        $this->assertSame(12, $r->json('proposal.req_jumlah'));
        $this->assertSame('baris', $r->json('proposal.req_satuan'));
        $this->assertSame('berhasil dibaca', $r->json('file.status'));
    }

    public function test_upload_docx_penanda_paragraf_stabil(): void
    {
        $svc = app(DocumentParserService::class);
        $tmp = tempnam(sys_get_temp_dir(), 't').'.docx';
        $w = new PhpWord;
        $s = $w->addSection();
        $s->addText('Paragraf pertama');
        $s->addText('Paragraf kedua');
        $w->save($tmp, 'Word2007');
        $a = $svc->parse('uji.docx', file_get_contents($tmp));
        $b = $svc->parse('uji.docx', file_get_contents($tmp));
        @unlink($tmp);
        $this->assertSame('paragraf', $a['satuan']);
        $this->assertSame(['par. 1', 'par. 2'], array_column($a['units'], 'label'));
        $this->assertSame($a['units'], $b['units']);
    }

    public function test_upload_pdf_halaman_paragraf(): void
    {
        $svc = app(DocumentParserService::class);
        $pdf = $this->miniPdf(['Baris pdf satu', 'Baris pdf dua']);
        $r = $svc->parse('uji.pdf', $pdf);
        $this->assertSame('halaman', $r['satuan']);
        $this->assertSame(2, $r['jumlah']);
        $this->assertSame('hal. 1, par. 1', $r['units'][0]['label']);
        $this->assertSame('hal. 2, par. 2', $r['units'][1]['label']);
        $this->assertStringContainsString('Baris pdf satu', $r['units'][0]['teks']);
    }

    private function miniPdf(array $lines): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $n = 3;
        foreach ($lines as $i => $text) {
            $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
            $objs[$n + 1] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
            $objs[$n] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.($n + 1).' 0 R /Resources << /Font << /F1 7 0 R >> >> >>';
            $kids[] = "{$n} 0 R";
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($lines).' >>';
        $objs[7] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $out = "%PDF-1.4\n";
        $off = [];
        for ($i = 1; $i <= 7; $i++) {
            $off[$i] = strlen($out);
            $out .= "{$i} 0 obj\n{$objs[$i]}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 8\n0000000000 65535 f \n";
        for ($i = 1; $i <= 7; $i++) {
            $out .= sprintf("%010d 00000 n \n", $off[$i]);
        }

        return $out."trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    public function test_upload_ditolak_ukuran_dan_format(): void
    {
        [$p, $h] = $this->buat($this->token());
        $big = UploadedFile::fake()->createWithContent('x.txt', str_repeat('a', 210 * 1024));
        $this->post("/api/proposals/{$p['id']}/upload", ['kind' => 'requirement', 'file' => $big], $h)
            ->assertStatus(422);
        $bad = UploadedFile::fake()->createWithContent('x.exe', 'abc');
        $this->post("/api/proposals/{$p['id']}/upload", ['kind' => 'requirement', 'file' => $bad], $h)
            ->assertStatus(422);
    }

    public function test_hapus_requirement_mengosongkan_hasil_analisis(): void
    {
        [$p, $h] = $this->buat($this->token());
        $f = UploadedFile::fake()->createWithContent('req.txt', "Baris satu\nBaris dua");
        $this->post("/api/proposals/{$p['id']}/upload", ['kind' => 'requirement', 'file' => $f], $h)->assertOk();

        $r = $this->deleteJson("/api/proposals/{$p['id']}/file?kind=requirement", [], $h);
        $r->assertOk();
        $this->assertSame('', $r->json('proposal.req_nama'));
        $this->assertSame('', $r->json('proposal.req_teks'));
        $this->assertSame(0, $r->json('proposal.req_jumlah'));
        $this->assertSame([], $r->json('proposal.items'));
        $this->assertSame('draf', $r->json('proposal.status'));
        $this->assertDatabaseHas('audits', ['aksi' => 'proposal_ubah']);
    }

    public function test_hapus_pendukung_berdasarkan_index(): void
    {
        [$p, $h] = $this->buat($this->token());
        $a = UploadedFile::fake()->createWithContent('dukungan_a.txt', 'Dokumen A');
        $b = UploadedFile::fake()->createWithContent('dukungan_b.txt', 'Dokumen B');
        $this->post("/api/proposals/{$p['id']}/upload", ['kind' => 'pendukung', 'file' => $a], $h)->assertOk();
        $this->post("/api/proposals/{$p['id']}/upload", ['kind' => 'pendukung', 'file' => $b], $h)->assertOk();

        $r = $this->deleteJson("/api/proposals/{$p['id']}/file?kind=pendukung&index=0", [], $h);
        $r->assertOk();
        $pend = $r->json('proposal.pendukung');
        $this->assertCount(1, $pend);
        $this->assertSame('dukungan_b.txt', $pend[0]['nama']);

        $this->deleteJson("/api/proposals/{$p['id']}/file?kind=pendukung&index=5", [], $h)
            ->assertStatus(404);
    }

    public function test_analyze_validasi_sumber(): void
    {
        [$p, $h] = $this->buat($this->token());
        $txt = "Pengembangan backend API\nPenyedia wajib memiliki sertifikasi ISO/IEC 27001";
        $f = UploadedFile::fake()->createWithContent('req.txt', $txt);
        $this->post("/api/proposals/{$p['id']}/upload", ['kind' => 'requirement', 'file' => $f], $h)->assertOk();
        $items = [
            ['id' => 'B1', 'kategori' => 'Teknis', 'requirement' => 'Backend API',
                'kutipan' => 'Pengembangan backend API', 'sumber_klien' => ['baris' => 1],
                'sumber_knowledge' => ['K11'], 'status' => 'sesuai', 'alasan' => 'Cocok.',
                'keyakinan' => 'tinggi', 'komponen_usulan' => [['kode' => 'DEV-BE', 'qty' => 5]]],
            ['id' => 'B2', 'kategori' => 'Keamanan', 'requirement' => 'ISO',
                'kutipan' => 'Kutipan yang tidak ada di dokumen', 'sumber_klien' => ['baris' => 99],
                'sumber_knowledge' => ['K01'], 'status' => 'sesuai', 'alasan' => 'Dugaan.',
                'keyakinan' => 'tinggi', 'komponen_usulan' => []],
        ];
        $text = '```json'.json_encode($items).'```';
        $payload = ['outputs' => [['outputs' => [['results' => ['message' => ['text' => $text]]]]]]];
        Http::fake(['*' => Http::response($payload, 200)]);
        $r = $this->postJson("/api/proposals/{$p['id']}/analyze", [], $h);
        $r->assertOk();
        $items = $r->json('proposal.items');
        $this->assertCount(2, $items);
        $this->assertFalse($items[0]['perlu_konfirmasi']);
        $this->assertTrue($items[1]['perlu_konfirmasi']);
        $this->assertSame(['K11'], $items[0]['sumber_knowledge']);
        $this->assertSame(1, $r->json('ringkasan.valid'));
        $this->assertSame(1, $r->json('ringkasan.perlu_konfirmasi'));
        $this->assertSame('dianalisis', $r->json('proposal.status'));
        $this->assertDatabaseHas('audits', ['aksi' => 'proposal_analisis']);
    }

    public function test_knowledge_tidak_terepos_ui(): void
    {
        $t = $this->token();
        $h = ['Authorization' => "Bearer {$t}"];
        $joined = $this->getJson('/api/catalog')->getContent()
            .$this->getJson('/api/me', $h)->getContent()
            .$this->getJson('/api/agent/status', $h)->getContent()
            .$this->getJson('/api/company')->getContent();
        $this->assertStringNotContainsString('K01', $joined);
        $this->assertStringNotContainsString('ISO/IEC 27001', $joined);
        $this->assertStringContainsString('NusaTech', $this->getJson('/api/company')->getContent());
    }

    public function test_script_dokumen_tidak_diekseskusi_backend(): void
    {
        [$p, $h] = $this->buat($this->token());
        $xss = '<script>alert(1)</script>';
        $f = UploadedFile::fake()->createWithContent('req.txt', "Baris aman\n{$xss}");
        $r = $this->post("/api/proposals/{$p['id']}/upload", ['kind' => 'requirement', 'file' => $f], $h);
        $r->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString($xss, $r->json('proposal.req_teks'));
    }

    /** Siapkan proposal dengan requirement terunggah + Langflow dikonfigurasi. */
    private function siapAnalisis(string $txt = "Baris satu\nBaris dua"): array
    {
        [$p, $h] = $this->buat($this->token());
        $f = UploadedFile::fake()->createWithContent('req.txt', $txt);
        $this->post("/api/proposals/{$p['id']}/upload", ['kind' => 'requirement', 'file' => $f], $h)->assertOk();
        config(['bidmaster.langflow_url' => 'http://lf.test', 'bidmaster.langflow_key' => 'k']);

        return [$p, $h];
    }

    public function test_analyze_kuota_429_pesan_ramah_bukan_json_mentah(): void
    {
        [$p, $h] = $this->siapAnalisis();
        $body = '{"message":"Error running graph: RESOURCE_EXHAUSTED 429 ... https://cloud.google.com/vertex-ai"}';
        Http::fake(['*' => Http::response($body, 500)]);
        $r = $this->postJson("/api/proposals/{$p['id']}/analyze", [], $h);
        $r->assertStatus(502)->assertJsonPath('code', 'LF_QUOTA');
        $err = $r->json('error');
        $this->assertStringContainsString('Kuota', $err);
        $this->assertStringNotContainsString('RESOURCE_EXHAUSTED', $err);
        $this->assertStringNotContainsString('cloud.google.com', $err);
        $this->assertTrue($r->json('retryable'));
        $this->assertTrue($r->json('bisa_fallback'));
    }

    public function test_analyze_gagal_bisa_lanjut_tanpa_ai(): void
    {
        [$p, $h] = $this->siapAnalisis("Butuh backend API\nButuh sertifikasi ISO");
        Http::fake(['*' => Http::response('{"message":"boom"}', 500)]);
        $r = $this->postJson("/api/proposals/{$p['id']}/analyze", ['fallback' => true], $h);
        $r->assertOk()->assertJsonPath('ringkasan.fallback', true);
        $items = $r->json('proposal.items');
        $this->assertCount(2, $items);
        foreach ($items as $it) {
            $this->assertSame('berisiko', $it['status']);
            $this->assertSame('rendah', $it['keyakinan']);
            $this->assertTrue($it['perlu_konfirmasi']);
            $this->assertSame([], $it['komponen_usulan']);
        }
        $this->assertSame(0, $r->json('ringkasan.valid'));
        $this->assertSame('dianalisis', $r->json('proposal.status'));
    }

    public function test_analyze_sukses_tidak_menandai_fallback(): void
    {
        [$p, $h] = $this->siapAnalisis();
        $items = [['id' => 'B1', 'kategori' => 'T', 'requirement' => 'Baris satu',
            'kutipan' => 'Baris satu', 'sumber_klien' => ['baris' => 1], 'sumber_knowledge' => [],
            'status' => 'sesuai', 'alasan' => '', 'keyakinan' => 'tinggi', 'komponen_usulan' => []]];
        $text = json_encode($items);
        Http::fake(['*' => Http::response(['outputs' => [['outputs' => [['results' => ['message' => ['text' => $text]]]]]]], 200)]);
        $this->postJson("/api/proposals/{$p['id']}/analyze", [], $h)
            ->assertOk()->assertJsonPath('ringkasan.fallback', false);
    }

    public function test_analyze_galat_5xx_biasa_bukan_kuota(): void
    {
        [$p, $h] = $this->siapAnalisis();
        Http::fake(['*' => Http::response('{"message":"internal"}', 500)]);
        $r = $this->postJson("/api/proposals/{$p['id']}/analyze", [], $h);
        $r->assertStatus(502)->assertJsonPath('code', 'LF_SERVER');
        $this->assertStringNotContainsString('internal', $r->json('error'));
    }
}
