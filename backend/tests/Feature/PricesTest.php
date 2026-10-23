<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PricesTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): array
    {
        Member::create(['nama' => 'hr', 'peran' => 'bd', 'password' => Hash::make('pw123456')]);
        Member::create(['nama' => 'ad', 'peran' => 'admin', 'password' => Hash::make('pw123456')]);
        $t = $this->postJson('/api/login', ['nama' => 'hr', 'pw' => 'pw123456'])->json('token');
        $a = $this->postJson('/api/login', ['nama' => 'ad', 'pw' => 'pw123456'])->json('token');
        $h = ['Authorization' => "Bearer {$t}"];
        $p = $this->postJson('/api/proposals', ['instansi' => 'Dinas H'], $h)->json('proposal');
        Proposal::where('id', $p['id'])->update(['items' => json_encode([
            ['id' => 'B2', 'kategori' => 'T', 'requirement' => 'Backend', 'kutipan' => 'x',
                'sumber_klien' => ['baris' => 1], 'sumber_knowledge' => [], 'status' => 'sesuai',
                'alasan' => '', 'keyakinan' => 'tinggi',
                'komponen_usulan' => [['kode' => 'DEV-BE', 'qty' => 10]]],
        ])]);

        return [$p['id'], $h, ['Authorization' => "Bearer {$a}"]];
    }

    private function rows(): array
    {
        return [['kode' => 'DEV-BE', 'qty' => 10, 'harga_proposal' => 1100000,
            'alasan' => '', 'alasan_catatan' => '']];
    }

    public function test_init_dari_usulan_snapshot(): void
    {
        [$id, $h] = $this->boot();
        $r = $this->getJson("/api/proposals/{$id}/prices", $h)->assertOk();
        $rows = $r->json('ringkasan.rows');
        $this->assertCount(1, $rows);
        $this->assertSame(1100000, $rows[0]['harga_acuan']);
        $this->assertSame(11000000, $r->json('ringkasan.subtotal'));
        $this->assertFalse($r->json('ringkasan.perlu_persetujuan'));
    }

    public function test_ubah_tanpa_alasan_ditolak_dengan_alasan_lencana(): void
    {
        [$id, $h] = $this->boot();
        $rows = $this->rows();
        $rows[0]['harga_proposal'] = 900000;
        $this->patchJson("/api/proposals/{$id}/prices", ['rows' => $rows], $h)
            ->assertStatus(400)->assertJsonPath('field', 'alasan');
        $rows[0]['alasan'] = 'negosiasi klien';
        $r = $this->patchJson("/api/proposals/{$id}/prices", ['rows' => $rows], $h)->assertOk();
        $this->assertSame('turun 18%', $r->json('ringkasan.rows')[0]['lencana']);
        $this->assertTrue($r->json('ringkasan.perlu_persetujuan'));
        $this->assertDatabaseHas('audits', ['aksi' => 'harga_ubah']);
    }

    public function test_diskon_lebih_10_persen_perlu_persetujuan(): void
    {
        [$id, $h] = $this->boot();
        $r = $this->patchJson("/api/proposals/{$id}/prices",
            ['rows' => $this->rows(), 'diskon' => ['tipe' => 'persen', 'nilai' => 15, 'alasan' => 'kontrak lanjutan']], $h)
            ->assertOk();
        $this->assertTrue($r->json('ringkasan.perlu_persetujuan'));
        $this->assertSame(9350000, $r->json('ringkasan.total'));
        // diskon tanpa alasan ditolak
        $this->patchJson("/api/proposals/{$id}/prices",
            ['rows' => $this->rows(), 'diskon' => ['tipe' => 'persen', 'nilai' => 5, 'alasan' => '']], $h)
            ->assertStatus(400)->assertJsonPath('field', 'diskon_alasan');
    }

    public function test_approve_hanya_admin_dan_reset(): void
    {
        [$id, $h, $ha] = $this->boot();
        $this->postJson("/api/proposals/{$id}/approve",
            ['disetujui_oleh' => 'Dir', 'centang' => true], $h)->assertStatus(403);
        $this->postJson("/api/proposals/{$id}/approve",
            ['disetujui_oleh' => '', 'centang' => true], $ha)
            ->assertStatus(400)->assertJsonPath('field', 'persetujuan');
        $this->postJson("/api/proposals/{$id}/approve",
            ['disetujui_oleh' => 'Direktur', 'centang' => true], $ha)->assertOk();
        $this->assertDatabaseHas('audits', ['aksi' => 'persetujuan']);
        // ubah harga mereset approvals
        $rows = $this->rows();
        $rows[0]['harga_proposal'] = 1000000;
        $rows[0]['alasan'] = 'volume besar';
        $this->patchJson("/api/proposals/{$id}/prices", ['rows' => $rows], $h)->assertOk();
        $this->assertSame([], $this->getJson("/api/proposals/{$id}", $ha)->json('proposal.approvals'));
    }

    public function test_snapshot_tahan_perubahan_katalog(): void
    {
        [$id, $h] = $this->boot();
        $this->getJson("/api/proposals/{$id}/prices", $h)->assertOk();
        $row = Proposal::find($id)->prices[0];
        $this->assertSame(1100000, $row['harga_acuan']);
        $this->assertArrayHasKey('harga_acuan', $row);
    }

    public function test_discount_selalu_objek_bukan_array_kosong(): void
    {
        [$id, $h] = $this->boot();
        // Sebelum diskon diisi, API harus mengirim objek (bukan [] yang truthy di JS).
        $r = $this->getJson("/api/proposals/{$id}/prices", $h)->assertOk();
        $this->assertIsArray($r->json('proposal.discount'));
        $this->assertArrayNotHasKey(0, $r->json('proposal.discount'));
        $this->assertSame(0, $r->json('proposal.discount.nilai'));
        $this->assertSame('', $r->json('proposal.discount.alasan'));

        $this->patchJson("/api/proposals/{$id}/prices",
            ['rows' => $this->rows(), 'diskon' => ['tipe' => 'persen', 'nilai' => 15, 'alasan' => 'kontrak lanjutan']], $h)
            ->assertOk();
        $d = $this->getJson("/api/proposals/{$id}", $h)->json('proposal.discount');
        $this->assertArrayNotHasKey(0, $d);
        $this->assertSame(15, $d['nilai']);
        $this->assertSame('kontrak lanjutan', $d['alasan']);
    }

    public function test_pagu_pakai_harga_proposal(): void
    {
        [$id, $h] = $this->boot();
        Proposal::where('id', $id)->update(['pagu' => 9500000]);
        $rows = $this->rows();
        $rows[0]['harga_proposal'] = 900000;
        $rows[0]['alasan'] = 'negosiasi klien';
        $r = $this->patchJson("/api/proposals/{$id}/prices", ['rows' => $rows], $h)->assertOk();
        $this->assertSame(9000000, $r->json('ringkasan.total'));
        $this->assertTrue($r->json('ringkasan.pagu_ok'));
    }
}
