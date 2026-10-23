<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Repositories\Contracts\AuditRepository;
use App\Services\AnalysisService;
use App\Services\DocumentParserService;
use App\Services\DocumentService;
use App\Services\DocxExportService;
use App\Services\KnowledgeService;
use App\Services\LangflowService;
use App\Services\PriceService;
use App\Services\ProposalDocxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProposalsController
{
    private ?array $cachedCompanyFull = null;

    public function __construct(
        private AuditRepository $audits,
        private DocumentParserService $parser,
        private AnalysisService $analysis,
        private LangflowService $lf,
        private KnowledgeService $knowledge,
    ) {}

    public function company()
    {
        $data = $this->knowledge->json('perusahaan.json');

        return response()->json([
            'nama_resmi' => $data['nama_resmi'] ?? '',
            'nama_singkat' => $data['nama_singkat'] ?? '',
            'tagline' => $data['tagline'] ?? '',
            'warna_utama' => $data['warna_utama'] ?? '#1F3864',
            'legalitas' => [
                'nib' => $data['nib'] ?? '',
                'npwp' => $data['npwp'] ?? '',
                'akta' => $data['akta_pendirian'] ?? '',
            ],
        ]);
    }

    private function own(Request $request, Proposal $proposal): bool
    {
        $u = $request->attributes->get('member');

        return $u['peran'] === 'admin' || (int) $proposal->member_id === (int) $u['id'];
    }

    public function index(Request $request)
    {
        $u = $request->attributes->get('member');
        $q = Proposal::select(['id', 'instansi', 'status', 'updated_at', 'member_id', 'current_version'])
            ->orderByDesc('updated_at');
        if ($u['peran'] !== 'admin') {
            $q->where('member_id', $u['id']);
        }

        return response()->json(['proposals' => $q->get()
            ->map(fn ($row) => $this->summary($row))->all()]);
    }

    private function summary(Proposal $proposal): array
    {
        return ['id' => $proposal->id, 'instansi' => $proposal->instansi, 'status' => $proposal->status,
            'updated_at' => $proposal->updated_at?->toIso8601String(), 'dibuat_oleh' => $proposal->member_id,
            'current_version' => $proposal->current_version];
    }

    public function show(Request $request, Proposal $proposal)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }

        return response()->json(['proposal' => $this->full($proposal)]);
    }

    private function full(Proposal $proposal): array
    {
        return ['id' => $proposal->id, 'instansi' => $proposal->instansi, 'jenis_instansi' => $proposal->jenis_instansi,
            'pagu' => $proposal->pagu, 'durasi_minggu' => $proposal->durasi_minggu,
            'req_nama' => $proposal->req_nama, 'req_satuan' => $proposal->req_satuan, 'req_jumlah' => $proposal->req_jumlah,
            'req_teks' => $proposal->req_teks, 'pendukung' => $proposal->pendukung ?? [],
            'req_units' => $proposal->req_units ?? [],
            'items' => $proposal->items ?? [], 'decisions' => $proposal->decisions ?? [],
            'approvals' => $proposal->approvals ?? [], 'status' => $proposal->status,
            'prices' => $proposal->prices ?? [], 'discount' => $this->discountOf($proposal),
            'current_version' => $proposal->current_version,
            'updated_at' => $proposal->updated_at?->toIso8601String()];
    }

    /** Normalisasi diskon agar selalu objek (hindari [] yang truthy di JS). */
    private function discountOf(Proposal $proposal): array
    {
        $d = $proposal->discount;
        if (! is_array($d) || $d === [] || array_is_list($d)) {
            return ['tipe' => 'persen', 'nilai' => 0, 'alasan' => ''];
        }

        return ['tipe' => in_array($d['tipe'] ?? 'persen', ['persen', 'nominal'], true) ? $d['tipe'] : 'persen',
            'nilai' => (int) ($d['nilai'] ?? 0), 'alasan' => (string) ($d['alasan'] ?? '')];
    }

    public function store(Request $request)
    {
        $u = $request->attributes->get('member');
        $d = $request->isJson() ? $request->json()->all() : [];
        $pagu = $d['pagu'] ?? null;
        if ($pagu !== null && $pagu !== '') {
            $pagu = filter_var($pagu, FILTER_VALIDATE_INT);
            if ($pagu === false || $pagu < 0 || $pagu > 10_000_000_000) {
                return response()->json(['error' => 'Pagu 0-10Miliar.', 'field' => 'pagu'], 400);
            }
        } else {
            $pagu = null;
        }
        $dur = filter_var($d['durasi_minggu'] ?? 12, FILTER_VALIDATE_INT);
        if ($dur === false || $dur < 1 || $dur > 52) {
            return response()->json(['error' => 'Durasi 1-52 minggu.', 'field' => 'durasi'], 400);
        }
        $proposal = Proposal::create(['member_id' => $u['id'],
            'instansi' => mb_substr(trim((string) ($d['instansi'] ?? '')), 0, 80),
            'jenis_instansi' => mb_substr((string) ($d['jenis_instansi'] ?? 'Pemerintahan'), 0, 40),
            'pagu' => $pagu, 'durasi_minggu' => $dur]);
        $this->audits->log($u['id'], 'proposal_buat', "#{$proposal->id} {$proposal->instansi}");

        return response()->json(['proposal' => $this->full($proposal->fresh())], 201);
    }

    public function update(Request $request, Proposal $proposal)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $u = $request->attributes->get('member');
        $d = $request->isJson() ? $request->json()->all() : [];
        $patch = [];
        if (array_key_exists('instansi', $d)) {
            $patch['instansi'] = mb_substr(trim((string) $d['instansi']), 0, 80);
        }
        if (array_key_exists('jenis_instansi', $d)) {
            $patch['jenis_instansi'] = mb_substr((string) $d['jenis_instansi'], 0, 40);
        }
        $paguChanged = false;
        if (array_key_exists('pagu', $d)) {
            $pg = $d['pagu'];
            if ($pg === null || $pg === '') {
                $newPagu = null;
            } else {
                $pg = filter_var($pg, FILTER_VALIDATE_INT);
                if ($pg === false || $pg < 0 || $pg > 10_000_000_000) {
                    return response()->json(['error' => 'Pagu 0-10Miliar.', 'field' => 'pagu'], 400);
                }
                $newPagu = $pg;
            }
            if ($newPagu !== $proposal->pagu) {
                $patch['pagu'] = $newPagu;
                $paguChanged = true;
            }
        }
        if (array_key_exists('durasi_minggu', $d)) {
            $dur = filter_var($d['durasi_minggu'], FILTER_VALIDATE_INT);
            if ($dur === false || $dur < 1 || $dur > 52) {
                return response()->json(['error' => 'Durasi 1-52 minggu.', 'field' => 'durasi'], 400);
            }
            $patch['durasi_minggu'] = $dur;
        }
        if ($paguChanged) {
            $patch['approvals'] = [];
        }
        if ($patch !== []) {
            $proposal->fill($patch)->save();
            $this->audits->log($u['id'], 'proposal_ubah', "#{$proposal->id} ".implode(',', array_keys($patch)));
        }

        return response()->json(['proposal' => $this->full($proposal->fresh()),
            'tersimpan_pukul' => date('H.i')]);
    }

    public function destroy(Request $request, Proposal $proposal)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $u = $request->attributes->get('member');
        $this->audits->log($u['id'], 'proposal_hapus', "#{$proposal->id} {$proposal->instansi}");
        $proposal->delete();

        return response()->json(['ok' => true]);
    }

    public function upload(Request $request, Proposal $proposal)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $kind = $request->input('kind', 'requirement');
        if (! in_array($kind, ['requirement', 'pendukung'], true)) {
            return response()->json(['error' => 'Jenis harus requirement/pendukung.'], 400);
        }
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['error' => 'File tidak valid.', 'field' => 'file'], 400);
        }
        try {
            $parsed = $this->parser->parse($file->getClientOriginalName(), file_get_contents($file->getRealPath()));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage(), 'field' => 'file'], 422);
        }

        return DB::transaction(function () use ($proposal, $kind, $parsed, $request) {
            $locked = Proposal::where('id', $proposal->id)->lockForUpdate()->first();
            if ($kind === 'requirement') {
                $locked->req_nama = $parsed['nama'];
                $locked->req_teks = implode("\n", array_map(fn ($unit) => $unit['teks'], $parsed['units']));
                $locked->req_satuan = $parsed['satuan'];
                $locked->req_jumlah = $parsed['jumlah'];
                $locked->req_units = array_map(fn ($unit) => [
                    'label' => $unit['label'], 'teks' => $unit['teks'],
                    'halaman' => $unit['halaman'] ?? null, 'paragraf' => $unit['paragraf'] ?? null,
                ], $parsed['units']);
                $locked->items = [];
                $locked->decisions = [];
                $locked->approvals = [];
                $locked->status = 'draf';
            } else {
                $pend = $locked->pendukung ?? [];
                $pend[] = ['nama' => $parsed['nama'], 'satuan' => $parsed['satuan'],
                    'jumlah' => $parsed['jumlah'], 'units' => $parsed['units']];
                $locked->pendukung = array_slice($pend, -5);
            }
            $locked->save();
            $u = $request->attributes->get('member');
            $this->audits->log($u['id'], 'proposal_ubah', "#{$locked->id} upload {$kind} {$parsed['nama']}");

            return response()->json(['proposal' => $this->full($locked->fresh()),
                'file' => ['nama' => $parsed['nama'], 'satuan' => $parsed['satuan'],
                    'jumlah' => $parsed['jumlah'], 'status' => 'berhasil dibaca']]);
        });
    }

    public function deleteFile(Request $request, Proposal $proposal)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $kind = $request->input('kind', 'requirement');
        if (! in_array($kind, ['requirement', 'pendukung'], true)) {
            return response()->json(['error' => 'Jenis harus requirement/pendukung.'], 400);
        }
        $u = $request->attributes->get('member');

        if ($kind === 'requirement') {
            $nama = $proposal->req_nama;
            $proposal->req_nama = '';
            $proposal->req_teks = '';
            $proposal->req_satuan = 'baris';
            $proposal->req_jumlah = 0;
            $proposal->req_units = [];
            // Hasil analisis lama tidak lagi sahih tanpa dokumen sumber.
            $proposal->items = [];
            $proposal->decisions = [];
            $proposal->approvals = [];
            $proposal->status = 'draf';
            $proposal->save();
            $this->audits->log($u['id'], 'proposal_ubah', "#{$proposal->id} hapus requirement {$nama}");
        } else {
            $pend = $proposal->pendukung ?? [];
            $idx = (int) $request->input('index', -1);
            if ($idx < 0 || $idx >= count($pend)) {
                return response()->json(['error' => 'Dokumen pendukung tidak ditemukan.'], 404);
            }
            $nama = $pend[$idx]['nama'] ?? '';
            array_splice($pend, $idx, 1);
            $proposal->pendukung = array_values($pend);
            $proposal->save();
            $this->audits->log($u['id'], 'proposal_ubah', "#{$proposal->id} hapus pendukung {$nama}");
        }

        return response()->json(['proposal' => $this->full($proposal->fresh()), 'ok' => true]);
    }

    public function quotes(Request $request)
    {
        $codes = [];
        foreach (explode(',', (string) $request->query('codes', '')) as $c) {
            $c = strtoupper(trim($c));
            if (preg_match('/^K\d{2}$/', $c)) {
                $codes[] = $c;
            }
        }
        $codes = array_values(array_unique(array_slice($codes, 0, 10)));
        $map = [];
        foreach (explode("\n", $this->knowledge->read('knowledge_perusahaan.md')) as $line) {
            if (preg_match('/^(K\d{2})\s*\|\s*(.+)$/', trim($line), $m)) {
                $map[$m[1]] = trim($m[2]);
            }
        }
        $out = [];
        foreach ($codes as $c) {
            if (isset($map[$c])) {
                $out[] = ['kode' => $c, 'teks' => $map[$c]];
            }
        }

        return response()->json(['quotes' => $out]);
    }

    public function source(Request $request, Proposal $proposal)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $units = $proposal->req_units ?? [];
        if ($units === []) {
            foreach (preg_split('/\R/', $proposal->req_teks) as $i => $line) {
                $line = trim($line);
                if ($line !== '') {
                    $units[] = ['label' => 'baris '.($i + 1), 'teks' => $line];
                }
            }
        }
        $baris = $request->query('baris');
        $halaman = $request->query('halaman');
        $paragraf = $request->query('paragraf');
        $kutipan = mb_substr(trim((string) $request->query('kutipan', '')), 0, 500);
        $idx = null;
        $label = '';
        foreach ($units as $i => $u) {
            if ($baris !== null && ($u['label'] ?? '') === 'baris '.(int) $baris) {
                $idx = $i;
                $label = $u['label'];
                break;
            }
            if ($halaman !== null && ($u['halaman'] ?? null) == (int) $halaman
                && ($u['paragraf'] ?? null) == (int) $paragraf) {
                $idx = $i;
                $label = $u['label'];
                break;
            }
            if ($halaman === null && $paragraf !== null && ($u['paragraf'] ?? null) == (int) $paragraf && ($u['halaman'] ?? null) === null) {
                $idx = $i;
                $label = $u['label'];
                break;
            }
        }
        if ($idx === null && ! empty($proposal->pendukung)) {
            foreach ($proposal->pendukung as $dok) {
                $dokUnits = $dok['units'] ?? [];
                foreach ($dokUnits as $i => $u) {
                    if ($baris !== null && ($u['label'] ?? '') === 'baris '.(int) $baris) {
                        $idx = $i;
                        $label = ($dok['nama'] ?? 'Pendukung').': '.$u['label'];
                        $units = $dokUnits;
                        break 2;
                    }
                    if ($halaman !== null && ($u['halaman'] ?? null) == (int) $halaman
                        && ($u['paragraf'] ?? null) == (int) $paragraf) {
                        $idx = $i;
                        $label = ($dok['nama'] ?? 'Pendukung').': '.$u['label'];
                        $units = $dokUnits;
                        break 2;
                    }
                    if ($halaman === null && $paragraf !== null && ($u['paragraf'] ?? null) == (int) $paragraf && ($u['halaman'] ?? null) === null) {
                        $idx = $i;
                        $label = ($dok['nama'] ?? 'Pendukung').': '.$u['label'];
                        $units = $dokUnits;
                        break 2;
                    }
                }
            }
        }
        if ($idx === null) {
            return response()->json(['error' => 'Sumber tidak ditemukan di dokumen.'], 404);
        }
        $pick = fn ($i) => isset($units[$i]) ? ['label' => $units[$i]['label'], 'teks' => $units[$i]['teks']] : null;

        return response()->json(['label' => $label,
            'target' => $units[$idx]['teks'], 'kutipan' => $kutipan,
            'atas' => array_values(array_filter([$pick($idx - 2), $pick($idx - 1)])),
            'bawah' => array_values(array_filter([$pick($idx + 1), $pick($idx + 2)]))]);
    }

    public function decide(Request $request, Proposal $proposal, string $item)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $u = $request->attributes->get('member');
        $d = $request->isJson() ? $request->json()->all() : [];
        $putusan = $d['putusan'] ?? '';
        if (! in_array($putusan, ['benar', 'ubah', 'tanya'], true)) {
            return response()->json(['error' => 'Putusan harus benar/ubah/tanya.', 'field' => 'putusan'], 400);
        }
        $alasan = mb_substr(trim((string) ($d['alasan'] ?? '')), 0, 300);

        return DB::transaction(function () use ($proposal, $item, $putusan, $d, $alasan, $u) {
            $locked = Proposal::where('id', $proposal->id)->lockForUpdate()->first();
            $items = $locked->items ?? [];
            $found = null;
            foreach ($items as $it) {
                if (($it['id'] ?? '') === $item) {
                    $found = $it;
                    break;
                }
            }
            if (! $found) {
                return response()->json(['error' => 'Butir tidak ditemukan.'], 404);
            }
            if ($putusan === 'ubah') {
                $status = $d['status'] ?? '';
                if (! in_array($status, ['sesuai', 'sebagian', 'berisiko'], true)) {
                    return response()->json(['error' => 'Status harus Sesuai/Sebagian/Berisiko.', 'field' => 'status'], 400);
                }
                if ($alasan === '') {
                    return response()->json(['error' => 'Alasan wajib diisi saat mengubah status.', 'field' => 'alasan'], 400);
                }
            } else {
                $status = $found['status'];
            }
            $decisions = $locked->decisions ?? [];
            $decisions[$item] = ['putusan' => $putusan, 'status_awal' => $found['status'],
                'status_akhir' => $putusan === 'tanya' ? $found['status'] : $status,
                'alasan' => $alasan,
                'catatan' => mb_substr(trim((string) ($d['catatan'] ?? '')), 0, 500),
                'pertanyaan' => $putusan === 'tanya' ? mb_substr(trim((string) ($d['pertanyaan'] ?? $alasan)), 0, 300) : '',
                'oleh' => $u['nama'], 'pada' => date('c')];
            $locked->decisions = $decisions;
            $locked->approvals = [];
            $locked->save();
            $this->audits->log($u['id'], 'keputusan',
                "#{$locked->id} {$item}: {$found['status']} → {$decisions[$item]['status_akhir']} ({$putusan}) {$alasan}");

            return response()->json(['proposal' => $this->full($locked->fresh())]);
        });
    }

    public function approveSesuai(Request $request, Proposal $proposal)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $u = $request->attributes->get('member');
        $d = $request->isJson() ? $request->json()->all() : [];
        if (($d['konfirmasi'] ?? false) !== true) {
            return response()->json(['error' => 'Konfirmasi wajib dicentang.', 'field' => 'konfirmasi'], 400);
        }
        $items = $proposal->items ?? [];
        $decisions = $proposal->decisions ?? [];
        $n = 0;
        foreach ($items as $it) {
            $id = $it['id'] ?? '';
            if ($id === '' || isset($decisions[$id])) {
                continue;
            }
            if ($it['status'] === 'sesuai' && $it['keyakinan'] === 'tinggi' && ! $it['perlu_konfirmasi']) {
                $decisions[$id] = ['putusan' => 'benar', 'status_awal' => 'sesuai',
                    'status_akhir' => 'sesuai', 'alasan' => 'Disetujui massal (Sesuai, keyakinan Tinggi).',
                    'catatan' => '', 'pertanyaan' => '', 'oleh' => $u['nama'], 'pada' => date('c'),
                    'massal' => true];
                $n++;
            }
        }
        if ($n === 0) {
            return response()->json(['error' => 'Tidak ada butir yang memenuhi syarat.'], 400);
        }
        $proposal->decisions = $decisions;
        $proposal->approvals = [];
        $proposal->save();
        $this->audits->log($u['id'], 'keputusan_massal', "#{$proposal->id} {$n} butir Sesuai-Tinggi disetujui");

        return response()->json(['proposal' => $this->full($proposal->fresh()), 'disetujui' => $n]);
    }

    public function analyze(Request $request, Proposal $proposal)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        set_time_limit(300);
        if (trim($proposal->req_teks) === '') {
            return response()->json(['error' => 'Unggah dokumen requirement dulu.', 'field' => 'file'], 400);
        }
        if (! $this->lf->configured()) {
            return response()->json(['error' => 'Mesin analisis belum dikonfigurasi.', 'code' => 'LF_NOT_CONFIGURED'], 503);
        }
        $started = microtime(true);
        $sessionId = 'proposal-'.$proposal->id.'-'.Str::uuid();
        $result = $this->lf->run($this->analysis->prompt($proposal), $this->lf->flow(),
            $sessionId, $this->analysis->templateOverride());
        $fallback = false;
        if (! $result['ok']) {
            // Mode darurat: pengguna memilih lanjut tanpa AI saat mesin mati.
            if (($request->input('fallback') === true || $request->input('fallback') === 'true')
                && ($result['retryable'] ?? true)) {
                $checked = $this->analysis->fallbackItems($proposal);
                $fallback = true;
            } else {
                return response()->json(['error' => $result['error'],
                    'code' => $result['code'] ?? 'LF_ERROR',
                    'retryable' => $result['retryable'] ?? true,
                    'bisa_fallback' => true], 502);
            }
        } else {
            $raw = AnalysisService::extractJson($result['text']);
            if ($raw === null) {
                return response()->json(['error' => 'Respons mesin tidak terstruktur. Coba lagi.',
                    'code' => 'LF_FORMAT', 'retryable' => true, 'bisa_fallback' => true], 502);
            }
            $checked = $this->analysis->validateItems($proposal, $raw);
        }
        $proposal->items = $checked['items'];
        $proposal->decisions = [];
        $proposal->approvals = [];
        $proposal->prices = [];
        $proposal->status = 'dianalisis';
        $proposal->save();
        $u = $request->attributes->get('member');
        $this->audits->log($u['id'], 'proposal_analisis',
            "#{$proposal->id} ".($fallback ? 'MODE DARURAT tanpa AI, ' : '')
            ."{$checked['valid']} valid, {$checked['invalid']} perlu konfirmasi, ".round(microtime(true) - $started).' dtk');

        return response()->json(['proposal' => $this->full($proposal->fresh()),
            'ringkasan' => ['butir' => count($checked['items']), 'valid' => $checked['valid'],
                'perlu_konfirmasi' => $checked['invalid'], 'fallback' => $fallback,
                'waktu_detik' => (int) round(microtime(true) - $started)]]);
    }

    public function prices(Request $request, Proposal $proposal, PriceService $harga)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        if (($proposal->prices ?? []) === []) {
            $proposal->prices = $harga->initRows($proposal);
            $proposal->save();
        }

        return response()->json(['proposal' => $this->full($proposal->fresh()),
            'ringkasan' => $harga->ringkasan($proposal->fresh()),
            'alasan_valid' => PriceService::ALASAN]);
    }

    public function pricesUpdate(Request $request, Proposal $proposal, PriceService $harga)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $u = $request->attributes->get('member');
        $d = $request->isJson() ? $request->json()->all() : [];
        $kat = $harga->katalog();
        $inputRows = (array) ($d['rows'] ?? []);
        if (count($inputRows) > 20) {
            return response()->json(['error' => 'Maksimal 20 baris komponen.', 'field' => 'rows'], 400);
        }
        $rows = [];
        $seen = [];
        foreach ($inputRows as $r) {
            if (! is_array($r) || ! isset($kat[$r['kode'] ?? ''])) {
                return response()->json(['error' => 'Kode komponen tidak dikenal.', 'field' => 'rows'], 400);
            }
            $k = $kat[$r['kode']];
            if (isset($seen[$k['kode']])) {
                return response()->json(['error' => "Komponen {$k['kode']} duplikat.", 'field' => 'rows'], 400);
            }
            $seen[$k['kode']] = true;
            $qty = filter_var($r['qty'] ?? 0, FILTER_VALIDATE_INT);
            if ($qty === false || $qty < 1 || $qty > 1000) {
                return response()->json(['error' => "Qty {$k['kode']} 1-1000.", 'field' => 'qty'], 400);
            }
            $hp = $r['harga_proposal'] ?? null;
            if (! is_numeric($hp) || (int) $hp < 0 || (int) $hp > PriceService::MAX_BARIS) {
                return response()->json(['error' => "Harga {$k['kode']} harus bilangan bulat 0-10M.", 'field' => 'harga'], 400);
            }
            $hp = (int) $hp;
            $alasan = mb_substr(trim((string) ($r['alasan'] ?? '')), 0, 40);
            if ($hp !== $k['harga_acuan']) {
                if (! in_array($alasan, PriceService::ALASAN, true)) {
                    return response()->json(['error' => "Alasan wajib untuk {$k['kode']} (beda dari acuan).", 'field' => 'alasan'], 400);
                }
            }
            $rows[] = ['kode' => $k['kode'], 'nama' => $k['nama'], 'satuan' => $k['satuan'],
                'qty' => $qty, 'harga_acuan' => $k['harga_acuan'], 'harga_proposal' => $hp,
                'alasan' => $hp !== $k['harga_acuan'] ? $alasan : '',
                'alasan_catatan' => mb_substr(trim((string) ($r['alasan_catatan'] ?? '')), 0, 200)];
        }
        if ($rows === []) {
            return response()->json(['error' => 'Minimal 1 baris komponen.', 'field' => 'rows'], 400);
        }
        $disc = $d['diskon'] ?? [];
        $tipe = $disc['tipe'] ?? 'persen';
        $nilai = $disc['nilai'] ?? 0;
        if (! in_array($tipe, ['persen', 'nominal'], true) || ! is_numeric($nilai) || (int) $nilai < 0) {
            return response()->json(['error' => 'Diskon tidak valid.', 'field' => 'diskon'], 400);
        }
        $nilai = (int) $nilai;
        if ($tipe === 'persen' && $nilai > 100) {
            return response()->json(['error' => 'Diskon persen 0-100.', 'field' => 'diskon'], 400);
        }
        $alasanD = mb_substr(trim((string) ($disc['alasan'] ?? '')), 0, 40);
        if ($nilai > 0 && $alasanD === '') {
            return response()->json(['error' => 'Alasan diskon wajib diisi.', 'field' => 'diskon_alasan'], 400);
        }
        $lama = collect($proposal->prices ?? [])->mapWithKeys(fn ($r) => [$r['kode'] => $r])->all();
        foreach ($rows as $r) {
            $old = $lama[$r['kode']] ?? null;
            if (! $old || (int) $old['harga_proposal'] !== $r['harga_proposal'] || (int) $old['qty'] !== $r['qty']) {
                $this->audits->log($u['id'], 'harga_ubah',
                    "#{$proposal->id} {$r['kode']}: Rp ".number_format($old['harga_proposal'] ?? $r['harga_acuan'], 0, ',', '.')
                    .' → Rp '.number_format($r['harga_proposal'], 0, ',', '.')." ({$r['alasan']})");
            }
        }
        $proposal->prices = $rows;
        $proposal->discount = ['tipe' => $tipe, 'nilai' => $nilai, 'alasan' => $nilai > 0 ? $alasanD : ''];
        $proposal->approvals = [];
        $proposal->save();

        return response()->json(['proposal' => $this->full($proposal->fresh()),
            'ringkasan' => $harga->ringkasan($proposal->fresh())]);
    }

    public function approve(Request $request, Proposal $proposal)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $u = $request->attributes->get('member');
        if ($u['peran'] !== 'admin') {
            return response()->json(['error' => 'Hanya approver (admin) yang boleh menyetujui.', 'field' => 'persetujuan'], 403);
        }
        $d = $request->isJson() ? $request->json()->all() : [];
        $oleh = mb_substr(trim((string) ($d['disetujui_oleh'] ?? '')), 0, 80);
        if ($oleh === '' || ($d['centang'] ?? false) !== true) {
            return response()->json(['error' => 'Isi nama penyetuju dan centang persetujuan.', 'field' => 'persetujuan'], 400);
        }
        $appr = $proposal->approvals ?? [];
        $appr[] = ['oleh' => $oleh, 'pada' => date('c'), 'admin' => $u['nama']];
        $proposal->approvals = $appr;
        $proposal->save();
        $this->audits->log($u['id'], 'persetujuan', "#{$proposal->id} disetujui oleh {$oleh}");

        return response()->json(['proposal' => $this->full($proposal->fresh())]);
    }

    private function companyFull(): array
    {
        if ($this->cachedCompanyFull !== null) {
            return $this->cachedCompanyFull;
        }
        $data = $this->knowledge->json('perusahaan.json');
        $profil = mb_substr(trim($this->knowledge->read('knowledge_perusahaan.md')), 0, 1200);

        return $this->cachedCompanyFull = ['data' => $data, 'profil' => $profil];
    }

    private function logoPath(): string
    {
        return DocxExportService::logoPath();
    }

    /** @return array{ok:bool,kurang:list<string>} */
    private function syaratUnduh(Proposal $proposal, PriceService $harga): array
    {
        $kurang = [];
        $items = $proposal->items ?? [];
        $decisions = $proposal->decisions ?? [];
        if ($items === []) {
            $kurang[] = 'Dokumen requirement belum dianalisis';
        }
        $risiko = 0;
        foreach ($items as $it) {
            $id = $it['id'] ?? '';
            $dec = $decisions[$id] ?? null;
            if (($it['status'] ?? '') === 'berisiko') {
                if (! $dec || ($dec['putusan'] ?? '') === 'tanya' || ($dec['status_akhir'] ?? '') === 'berisiko') {
                    $risiko++;
                }
            }
        }
        if ($items !== [] && $risiko > 0) {
            $kurang[] = "{$risiko} butir Berisiko belum diputuskan atau belum selesai";
        }
        $ring = $harga->ringkasan($proposal);
        if (($proposal->prices ?? []) === [] || ($ring['subtotal'] ?? 0) <= 0) {
            $kurang[] = 'Komponen harga belum ditentukan';
        }
        if ($ring['pagu_ok'] === false) {
            $kurang[] = 'Total melewati pagu';
        }
        $co = $this->companyFull()['data'];
        foreach (['nib' => 'NIB', 'npwp' => 'NPWP', 'akta_pendirian' => 'Akta'] as $k => $label) {
            if (trim((string) ($co[$k] ?? '')) === '') {
                $kurang[] = "Legalitas perusahaan kurang: {$label} (hubungi admin)";
            }
        }
        if ($ring['perlu_persetujuan'] && ($proposal->approvals ?? []) === []) {
            $kurang[] = 'Persetujuan wajib (harga menyimpang >15% atau diskon >10%)';
        }

        return ['ok' => $kurang === [], 'kurang' => $kurang, 'ringkasan' => $ring];
    }

    public function downloadCheck(Request $request, Proposal $proposal, PriceService $harga)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }

        return response()->json($this->syaratUnduh($proposal, $harga));
    }

    public function preview(Request $request, Proposal $proposal,
        PriceService $harga, DocumentService $docs)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $d = $request->isJson() ? $request->json()->all() : [];
        $snap = $this->snapshot($proposal, $harga, $d);

        return response()->json(['markdown' => $docs->build($snap)['markdown']]);
    }

    private function snapshot(Proposal $proposal, PriceService $harga, array $options): array
    {
        $decisions = $proposal->decisions ?? [];
        $klarifikasi = [];
        foreach ($proposal->items ?? [] as $it) {
            $dc = $decisions[$it['id'] ?? ''] ?? null;
            if ($dc && ($dc['putusan'] ?? '') === 'tanya') {
                $klarifikasi[] = ['id' => $it['id'] ?? '', 'pertanyaan' => $dc['pertanyaan'] ?? ''];
            }
        }
        $appr = $proposal->approvals ?? [];
        $last = end($appr);

        return [
            'proposal' => ['instansi' => $proposal->instansi, 'durasi_minggu' => $proposal->durasi_minggu],
            'items' => $proposal->items ?? [],
            'decisions' => $decisions,
            'prices' => $proposal->prices ?? [],
            'discount' => $this->discountOf($proposal),
            'ringkasan' => $harga->ringkasan($proposal),
            'company' => $this->companyFull()['data'],
            'profil' => $this->companyFull()['profil'],
            'klarifikasi' => $klarifikasi,
            'approved_by' => $last['oleh'] ?? '',
            'options' => ['lampiran_sumber' => (bool) ($options['lampiran_sumber'] ?? true),
                'lampiran_klarifikasi' => (bool) ($options['lampiran_klarifikasi'] ?? true)],
        ];
    }

    public function exportProposal(Request $request, Proposal $proposal,
        PriceService $harga, DocumentService $docs,
        ProposalDocxService $docx)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $u = $request->attributes->get('member');
        $d = $request->isJson() ? $request->json()->all() : [];
        $format = $d['format'] ?? 'docx';
        if (! in_array($format, ['docx', 'md'], true)) {
            return response()->json(['error' => 'Format harus docx/md.'], 400);
        }

        return DB::transaction(function () use ($proposal, $harga, $docs, $docx, $u, $d, $format) {
            $locked = Proposal::where('id', $proposal->id)->lockForUpdate()->first();
            $cek = $this->syaratUnduh($locked, $harga);
            if (! $cek['ok']) {
                return response()->json(['error' => 'Syarat belum terpenuhi.', 'kurang' => $cek['kurang'],
                    'field' => 'syarat'], 422);
            }
            $snap = $this->snapshot($locked, $harga, $d);
            $built = $docs->build($snap);
            $versi = $locked->current_version + 1;
            $locked->versions()->create(['versi' => $versi, 'snapshot' => $snap]);
            $locked->current_version = $versi;
            $locked->status = 'diunduh';
            $locked->save();
            $nama = pathinfo($built['nama_file'], PATHINFO_FILENAME).'.'.$format;
            $this->audits->log($u['id'], 'export', "#{$locked->id} v{$versi} {$format} {$nama}");

            if ($format === 'md') {
                return response($built['markdown'], 200, [
                    'Content-Type' => 'text/markdown; charset=utf-8',
                    'Content-Disposition' => "attachment; filename=\"{$nama}\"",
                ]);
            }

            return response($docx->toBytes($snap, $this->logoPath()), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => "attachment; filename=\"{$nama}\"",
            ]);
        });
    }

    public function versions(Request $request, Proposal $proposal)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }

        return response()->json(['versions' => $proposal->versions()->get(['versi', 'created_at'])
            ->map(fn ($v) => ['versi' => $v->versi, 'pada' => $v->created_at?->toIso8601String()])->all()]);
    }

    public function versionFile(Request $request, Proposal $proposal, int $versi,
        DocumentService $docs, ProposalDocxService $docx)
    {
        if (! $this->own($request, $proposal)) {
            return response()->json(['error' => 'Akses ditolak untuk peran ini.'], 403);
        }
        $v = $proposal->versions()->where('versi', $versi)->first();
        if (! $v) {
            return response()->json(['error' => 'Versi tidak ditemukan.'], 404);
        }
        $format = $request->query('format', 'docx');
        if (! in_array($format, ['docx', 'md'], true)) {
            return response()->json(['error' => 'Format harus docx/md.'], 400);
        }
        $u = $request->attributes->get('member');
        $this->audits->log($u['id'], 'export', "#{$proposal->id} unduh ulang v{$versi} {$format}");
        $built = $docs->build($v->snapshot);
        $nama = pathinfo($built['nama_file'], PATHINFO_FILENAME).'.'.$format;
        if ($format === 'md') {
            return response($built['markdown'], 200, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$nama}\"",
            ]);
        }

        return response($docx->toBytes($v->snapshot, $this->logoPath()), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => "attachment; filename=\"{$nama}\"",
        ]);
    }
}
