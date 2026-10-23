<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $peran = 'bd'): string
    {
        Member::create(['nama' => 'ag', 'peran' => $peran, 'password' => Hash::make('pw123456')]);

        return $this->postJson('/api/login', ['nama' => 'ag', 'pw' => 'pw123456'])->json('token');
    }

    private function fakeLangflow(string $text = 'Draft siap.'): void
    {
        config(['bidmaster.langflow_url' => 'http://lf.test', 'bidmaster.langflow_key' => 'k']);
        Http::fake(['*' => Http::response([
            'outputs' => [['outputs' => [['results' => ['message' => ['text' => $text]]]]]],
        ], 200)]);
    }

    public function test_agent_tanpa_konfigurasi_503(): void
    {
        config(['bidmaster.langflow_url' => '', 'bidmaster.langflow_key' => '']);
        $t = $this->token();
        $this->postJson('/api/agent', ['message' => 'halo'], ['Authorization' => "Bearer {$t}"])
            ->assertStatus(503)->assertJsonPath('code', 'LF_NOT_CONFIGURED');
    }

    public function test_agent_pesan_kosong_dan_tanpa_lampiran_ditolak(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $this->postJson('/api/agent', ['message' => '   '], ['Authorization' => "Bearer {$t}"])
            ->assertStatus(400);
    }

    public function test_agent_lampiran_dokumen_menjadi_konteks(): void
    {
        $this->fakeLangflow('Analisis dokumen.');
        $t = $this->token();
        $f = UploadedFile::fake()->createWithContent('brief.txt', "Butuh web reservasi\nBudget 10jt");
        $r = $this->post('/api/agent', [
            'message' => 'Tinjau ini', 'flow' => 'bidmaster_proposal_generator', 'file' => $f,
        ], ['Authorization' => "Bearer {$t}"]);
        $r->assertOk()->assertJsonPath('lampiran', 'brief.txt');
        Http::assertSent(function ($req) {
            $body = $req->data();

            return str_contains($body['input_value'] ?? '', 'DOKUMEN TERLAMPIR')
                && str_contains($body['input_value'] ?? '', 'Butuh web reservasi')
                && str_contains($body['input_value'] ?? '', 'Tinjau ini');
        });
    }

    public function test_agent_lampiran_tanpa_pesan_tetap_jalan(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $f = UploadedFile::fake()->createWithContent('kak.txt', 'Satu baris kebutuhan');
        $this->post('/api/agent', ['file' => $f], ['Authorization' => "Bearer {$t}"])
            ->assertOk()->assertJsonPath('lampiran', 'kak.txt');
    }

    public function test_agent_lampiran_format_salah_ditolak(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $bad = UploadedFile::fake()->createWithContent('x.exe', 'abc');
        $this->post('/api/agent', ['message' => 'halo', 'file' => $bad], ['Authorization' => "Bearer {$t}"])
            ->assertStatus(422)->assertJsonPath('field', 'file');
    }

    public function test_agent_lampiran_terlalu_besar_ditolak(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $big = UploadedFile::fake()->createWithContent('x.txt', str_repeat('a', 210 * 1024));
        $this->post('/api/agent', ['message' => 'halo', 'file' => $big], ['Authorization' => "Bearer {$t}"])
            ->assertStatus(422);
    }

    public function test_agent_flow_tidak_dikenal_ditolak(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $this->postJson('/api/agent', ['message' => 'halo', 'flow' => 'flow_asing'],
            ['Authorization' => "Bearer {$t}"])->assertStatus(400);
    }

    public function test_agent_mode_proposal_tanpa_tweak(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $this->postJson('/api/agent', ['message' => 'halo'], ['Authorization' => "Bearer {$t}"])
            ->assertOk()->assertJsonPath('mode', 'proposal');
        Http::assertSent(fn ($req) => ! array_key_exists('tweaks', $req->data()));
    }

    public function test_agent_konteks_proposal_dilampirkan_ke_prompt(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $m = Member::where('nama', 'ag')->first();
        $prop = Proposal::create([
            'member_id' => $m->id,
            'instansi' => 'RSUD Sehat',
            'jenis_instansi' => 'Kesehatan',
            'pagu' => 500000000,
            'durasi_minggu' => 8,
            'req_nama' => 'kak_rs.txt',
            'req_satuan' => 'baris',
            'req_jumlah' => 15,
        ]);

        $this->postJson('/api/agent', [
            'message' => 'Bantu buat ringkasan',
            'proposal_id' => $prop->id,
        ], ['Authorization' => "Bearer {$t}"])->assertOk();

        Http::assertSent(function ($req) {
            $val = $req->data()['input_value'] ?? '';

            return str_contains($val, 'KONTEKS PROPOSAL AKTIF')
                && str_contains($val, 'RSUD Sehat')
                && str_contains($val, '500.000.000')
                && str_contains($val, 'Bantu buat ringkasan');
        });
    }

    public function test_agent_mode_umum_mengirim_override_prompt(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $this->postJson('/api/agent', ['message' => 'apa itu cloud computing?', 'mode' => 'umum'],
            ['Authorization' => "Bearer {$t}"])->assertOk()->assertJsonPath('mode', 'umum');
        Http::assertSent(function ($req) {
            $b = $req->data();
            $node = config('bidmaster.langflow_prompt_node');

            return isset($b['tweaks'][$node]['template'])
                && str_contains($b['tweaks'][$node]['template'], 'Pertanyaan umum')
                && str_contains($b['tweaks'][$node]['template'], '{context}')
                && str_contains($b['tweaks'][$node]['template'], '{tender}');
        });
    }

    public function test_agent_saran_file_benar_saat_minta_file(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $this->postJson('/api/agent', ['message' => 'Buatkan file Word siap unduh dari draft ini'],
            ['Authorization' => "Bearer {$t}"])->assertOk()->assertJsonPath('saran_file', true);
        $this->postJson('/api/agent', ['message' => 'Jelaskan singkat saja'],
            ['Authorization' => "Bearer {$t}"])->assertOk()->assertJsonPath('saran_file', false);
    }

    public function test_agent_file_docx_menghasilkan_unduhan(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $r = $this->postJson('/api/agent/file',
            ['markdown' => "# Judul\n\nIsi **tebal** dan tabel:\n\n| Komponen | Qty |\n|---|---|\n| DEV-BE | 2 |\n\n- satu\n- dua",
                'format' => 'docx', 'nama' => 'Draft Kafe'],
            ['Authorization' => "Bearer {$t}"]);
        $r->assertOk();
        $this->assertStringStartsWith('PK', $r->getContent());
        $this->assertStringContainsString('draft_kafe.docx', (string) $r->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audits', ['aksi' => 'agent-file']);
    }

    public function test_agent_file_docx_bercover_logo_dan_header(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $r = $this->postJson('/api/agent/file',
            ['markdown' => "# Ringkasan\n\nIsi proposal.", 'format' => 'docx', 'judul' => 'Penawaran Dinas X'],
            ['Authorization' => "Bearer {$t}"]);
        $r->assertOk();
        $zip = $this->unzipDocx($r->getContent());
        $this->assertContains('word/document.xml', array_keys($zip));
        // Cover berlogo + header berlogo (2 gambar tertanam).
        $images = array_filter(array_keys($zip), fn ($k) => str_starts_with($k, 'word/media/'));
        $this->assertGreaterThanOrEqual(2, count($images), 'Cover + header harus memuat logo');
        $this->assertNotEmpty(array_filter(array_keys($zip), fn ($k) => (bool) preg_match('#^word/header\d*\.xml$#', $k)), 'Header hilang');
        $this->assertNotEmpty(array_filter(array_keys($zip), fn ($k) => (bool) preg_match('#^word/footer\d*\.xml$#', $k)), 'Footer hilang');
        $this->assertStringContainsString('Penawaran Dinas X', $zip['word/document.xml']);
    }

    /** Baca isi file docx (zip) menjadi map nama => teks. */
    private function unzipDocx(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bmz').'.docx';
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $out[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        return $out;
    }

    public function test_agent_file_md_menghasilkan_markdown(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $r = $this->postJson('/api/agent/file', ['markdown' => '# Halo Bob', 'format' => 'md', 'nama' => 'catatan'],
            ['Authorization' => "Bearer {$t}"]);
        $r->assertOk();
        $this->assertSame('# Halo Bob', $r->getContent());
        $this->assertStringContainsString('catatan.md', (string) $r->headers->get('Content-Disposition'));
    }

    public function test_agent_file_tolak_isi_kosong_dan_terlalu_besar(): void
    {
        $this->fakeLangflow();
        $t = $this->token();
        $this->postJson('/api/agent/file', ['markdown' => ''], ['Authorization' => "Bearer {$t}"])
            ->assertStatus(422)->assertJsonPath('field', 'markdown');
        $this->postJson('/api/agent/file', ['markdown' => str_repeat('a', 201 * 1024)], ['Authorization' => "Bearer {$t}"])
            ->assertStatus(422);
    }
}
