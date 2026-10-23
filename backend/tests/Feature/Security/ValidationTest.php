<?php

namespace Tests\Feature\Security;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidationTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        Member::create(['nama' => 'bd1', 'peran' => 'bd', 'password' => Hash::make('pw123456')]);

        return $this->postJson('/api/login', ['nama' => 'bd1', 'pw' => 'pw123456'])->json('token');
    }

    private function base(): array
    {
        return ['mode' => 'tender', 'nama' => 'Dinas X', 'industri' => 'Pemerintahan',
            'durasi_minggu' => 12, 'diskon_persen' => 0, 'pagu' => 20000000,
            'dokumen_text' => 'Pengembangan backend API', 'legal' => ['nib' => '1', 'npwp' => '2', 'akta' => '3'],
            'komponen' => [['kode' => 'DEV-BE', 'qty' => 10]]];
    }

    public function test_draft_valid_lolos_gate(): void
    {
        $t = $this->token();
        $b = $this->base();
        $b['dokumen_text'] = "Pengembangan backend API\nPengujian aplikasi\nPelatihan pengguna";
        $b['komponen'] = [['kode' => 'DEV-BE', 'qty' => 10], ['kode' => 'QA', 'qty' => 5], ['kode' => 'TRAIN', 'qty' => 2]];
        $r = $this->postJson('/api/draft', $b, ['Authorization' => "Bearer {$t}"]);
        $r->assertOk();
        $this->assertSame(17750000, $r->json('meta.total'));
        $this->assertTrue($r->json('korporat.gate.passed'));
    }

    #[DataProvider('invalidCases')]
    public function test_draft_invalid_400_per_field(array $patch, string $field): void
    {
        $t = $this->token();
        $b = array_merge($this->base(), $patch);
        $r = $this->postJson('/api/draft', $b, ['Authorization' => "Bearer {$t}"]);
        $r->assertStatus(400)->assertJsonPath('field', $field);
    }

    public static function invalidCases(): array
    {
        return [
            [['nama' => 'A'], 'nama'],
            [['industri' => 'Antariksa'], 'ind'],
            [['durasi_minggu' => 99], 'dur'],
            [['diskon_persen' => 99], 'disk'],
            [['pagu' => -5], 'pagu'],
            [['komponen' => []], 'komp'],
            [['komponen' => [['kode' => 'XX', 'qty' => 1]]], 'komp'],
            [['mode' => 'website'], 'paket'],
        ];
    }

    public function test_injeksi_sql_netral(): void
    {
        $t = $this->token();
        $b = $this->base();
        $b['nama'] = "' OR '1'='1";
        $b['legal'] = ['nib' => '1', 'npwp' => '2', 'akta' => '3'];
        $r = $this->postJson('/api/draft', $b, ['Authorization' => "Bearer {$t}"]);
        $r->assertOk();
        $this->assertDatabaseCount('members', 1);
    }

    public function test_dokumen_melebihi_200kb_ditolak(): void
    {
        $t = $this->token();
        $b = $this->base();
        $b['dokumen_text'] = str_repeat('a', 210 * 1024);
        $r = $this->postJson('/api/draft', $b, ['Authorization' => "Bearer {$t}"]);
        $r->assertStatus(400)->assertJsonPath('field', 'dok');
    }

    public function test_export_format_salah_ditolak(): void
    {
        $t = $this->token();
        $this->postJson('/api/export', ['format' => 'pdf', 'markdown' => '# x'],
            ['Authorization' => "Bearer {$t}"])->assertStatus(400);
    }
}
