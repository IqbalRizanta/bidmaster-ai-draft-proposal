<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Member;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DecisionsTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): array
    {
        Member::create(['nama' => 'rv', 'peran' => 'bd', 'password' => Hash::make('pw123456')]);
        $t = $this->postJson('/api/login', ['nama' => 'rv', 'pw' => 'pw123456'])->json('token');
        $h = ['Authorization' => "Bearer {$t}"];
        $p = $this->postJson('/api/proposals', ['instansi' => 'Dinas Y'], $h)->json('proposal');
        $items = [
            ['id' => 'B9', 'kategori' => 'Keamanan', 'requirement' => 'ISO 27001',
                'kutipan' => 'wajib ISO/IEC 27001', 'sumber_klien' => ['baris' => 9],
                'sumber_knowledge' => ['K01'], 'status' => 'berisiko', 'alasan' => 'Ragu.',
                'keyakinan' => 'rendah', 'komponen_usulan' => [], 'perlu_konfirmasi' => false],
            ['id' => 'B1', 'kategori' => 'Teknis', 'requirement' => 'Frontend',
                'kutipan' => 'frontend', 'sumber_klien' => ['baris' => 1],
                'sumber_knowledge' => [], 'status' => 'sesuai', 'alasan' => '',
                'keyakinan' => 'tinggi', 'komponen_usulan' => [], 'perlu_konfirmasi' => false],
        ];
        Proposal::where('id', $p['id'])->update(['items' => json_encode($items)]);

        return [$p['id'], $h];
    }

    public function test_putusan_benar_tercatat_audit(): void
    {
        [$id, $h] = $this->boot();
        $r = $this->patchJson("/api/proposals/{$id}/items/B9/decision",
            ['putusan' => 'benar', 'catatan' => 'Cek internal'], $h);
        $r->assertOk();
        $dec = $r->json('proposal.decisions.B9');
        $this->assertSame('benar', $dec['putusan']);
        $this->assertSame('berisiko', $dec['status_awal']);
        $this->assertSame('Cek internal', $dec['catatan']);
        $this->assertDatabaseHas('audits', ['aksi' => 'keputusan']);
        $audit = Audit::where('aksi', 'keputusan')->first();
        $this->assertStringContainsString('B9', $audit->detail);
        $this->assertStringContainsString('berisiko', $audit->detail);
    }

    public function test_ubah_tanpa_alasan_ditolak(): void
    {
        [$id, $h] = $this->boot();
        $this->patchJson("/api/proposals/{$id}/items/B9/decision",
            ['putusan' => 'ubah', 'status' => 'sesuai', 'alasan' => ''], $h)
            ->assertStatus(400)->assertJsonPath('field', 'alasan');
    }

    public function test_ubah_dengan_alasan(): void
    {
        [$id, $h] = $this->boot();
        $r = $this->patchJson("/api/proposals/{$id}/items/B9/decision",
            ['putusan' => 'ubah', 'status' => 'sesuai', 'alasan' => 'Sertifikat ada, berlaku.'], $h);
        $r->assertOk();
        $this->assertSame('sesuai', $r->json('proposal.decisions.B9.status_akhir'));
    }

    public function test_tanya_masuk_klarifikasi(): void
    {
        [$id, $h] = $this->boot();
        $r = $this->patchJson("/api/proposals/{$id}/items/B9/decision",
            ['putusan' => 'tanya', 'pertanyaan' => 'Apakah SOC 2 boleh menyusul?'], $h);
        $r->assertOk();
        $dec = $r->json('proposal.decisions.B9');
        $this->assertSame('tanya', $dec['putusan']);
        $this->assertSame('Apakah SOC 2 boleh menyusul?', $dec['pertanyaan']);
    }

    public function test_approve_massal_dan_reset(): void
    {
        [$id, $h] = $this->boot();
        $r = $this->postJson("/api/proposals/{$id}/approve-sesuai", ['konfirmasi' => true], $h);
        $r->assertOk()->assertJsonPath('disetujui', 1);
        $this->assertDatabaseHas('audits', ['aksi' => 'keputusan_massal']);
        // tanpa konfirmasi ditolak
        $this->postJson("/api/proposals/{$id}/approve-sesuai", ['konfirmasi' => false], $h)->assertStatus(400);
        // ubah keputusan setelah ada approval (simulasikan) mereset approvals
        Proposal::where('id', $id)->update(['approvals' => json_encode([['oleh' => 'x']])]);
        $this->patchJson("/api/proposals/{$id}/items/B1/decision", ['putusan' => 'benar'], $h)->assertOk();
        $this->assertSame([], $this->getJson("/api/proposals/{$id}", $h)->json('proposal.approvals'));
    }

    public function test_quotes_knowledge_readonly(): void
    {
        $t = $this->token();
        $h = ['Authorization' => "Bearer {$t}"];
        $r = $this->getJson('/api/knowledge/quotes?codes=K01,K99,XX', $h);
        $r->assertOk();
        $q = $r->json('quotes');
        $this->assertCount(1, $q);
        $this->assertSame('K01', $q[0]['kode']);
        $this->assertStringContainsString('ISO/IEC 27001', $q[0]['teks']);
    }

    public function test_source_klien_konteks(): void
    {
        [$id, $h] = $this->boot();
        $txt = implode("\n", array_map(fn ($i) => "Baris kebutuhan {$i}", range(1, 12)));
        $f = UploadedFile::fake()->createWithContent('req.txt', $txt);
        $this->post("/api/proposals/{$id}/upload", ['kind' => 'requirement', 'file' => $f], $h)->assertOk();
        $r = $this->getJson("/api/proposals/{$id}/source?baris=9&kutipan=Baris+kebutuhan+9", $h);
        $r->assertOk();
        $this->assertSame('baris 9', $r->json('label'));
        $this->assertSame('Baris kebutuhan 9', $r->json('target'));
        $this->assertSame('Baris kebutuhan 7', $r->json('atas')[0]['teks']);
        $this->assertSame('Baris kebutuhan 10', $r->json('bawah')[0]['teks']);
        $this->assertSame('Baris kebutuhan 11', $r->json('bawah')[1]['teks']);
        $this->getJson("/api/proposals/{$id}/source?baris=99", $h)->assertStatus(404);
    }

    public function test_source_docx_dan_pendukung(): void
    {
        [$id, $h] = $this->boot();
        $p = Proposal::find($id);
        $p->req_units = [
            ['label' => 'par. 1', 'teks' => 'Paragraf satu', 'paragraf' => 1, 'halaman' => null],
            ['label' => 'par. 2', 'teks' => 'Paragraf dua', 'paragraf' => 2, 'halaman' => null],
        ];
        $p->pendukung = [
            ['nama' => 'lampiran.txt', 'units' => [
                ['label' => 'baris 1', 'teks' => 'Kebutuhan lampiran 1'],
            ]],
        ];
        $p->save();

        $r1 = $this->getJson("/api/proposals/{$id}/source?paragraf=2", $h)->assertOk();
        $this->assertSame('par. 2', $r1->json('label'));
        $this->assertSame('Paragraf dua', $r1->json('target'));

        $r2 = $this->getJson("/api/proposals/{$id}/source?baris=1", $h)->assertOk();
        $this->assertSame('lampiran.txt: baris 1', $r2->json('label'));
        $this->assertSame('Kebutuhan lampiran 1', $r2->json('target'));
    }

    private function token(): string
    {
        Member::create(['nama' => 'q', 'peran' => 'bd', 'password' => Hash::make('pw123456')]);

        return $this->postJson('/api/login', ['nama' => 'q', 'pw' => 'pw123456'])->json('token');
    }
}
