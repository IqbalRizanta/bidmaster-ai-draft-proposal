<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\AuditRepository;
use App\Services\DocxExportService;
use App\Services\DraftComposerService;
use App\Services\KnowledgeService;
use Illuminate\Http\Request;

class DraftController
{
    public function __construct(
        private DraftComposerService $composer,
        private KnowledgeService $knowledge,
        private AuditRepository $audits,
    ) {}

    public function catalog()
    {
        $catalog = $this->knowledge->website()['catalog'];
        $tender = $this->knowledge->tender()['tender'];

        return response()->json([
            'industries' => DraftComposerService::INDUSTRIES,
            'discount_max' => $catalog['discount_rules']['max_discount_percent'],
            'website' => ['packages' => $catalog['packages'], 'features' => DraftComposerService::FEATURES],
            'tender' => ['komponen' => $tender['komponen']],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->isJson() ? $request->json()->all() : $request->all();
        if (! is_array($data)) {
            return response()->json(['error' => 'Body harus JSON object.', 'field' => 'form'], 400);
        }
        [$v, $problem] = $this->composer->validate($data);
        if ($problem) {
            return response()->json($problem, 400);
        }
        $out = $this->composer->compose($v);
        $u = $request->attributes->get('member');
        $this->audits->log($u['id'], 'draft',
            "{$v['nama']} {$out['meta']['mode']} Rp ".number_format($out['meta']['total'], 0, ',', '.')
            .' skor '.round($out['compliance']['score']));

        return response()->json($out);
    }

    public function export(Request $request, DocxExportService $docx)
    {
        $data = $request->isJson() ? $request->json()->all() : [];
        if (($data['format'] ?? '') !== 'docx') {
            return response()->json(['error' => 'Format harus docx.'], 400);
        }
        $md = $data['markdown'] ?? '';
        if (! is_string($md) || strlen($md) < 1 || strlen($md) > DraftComposerService::MAX_DOC) {
            return response()->json(['error' => 'Markdown 1B-200KB.'], 400);
        }
        $u = $request->attributes->get('member');
        $this->audits->log($u['id'], 'export', 'docx '.strlen($md).' chr');

        return response($docx->toBytes($md), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename=draft-proposal.docx',
        ]);
    }

    public function audit(Request $request)
    {
        $u = $request->attributes->get('member');
        $uid = $u['peran'] === 'admin' ? null : $u['id'];

        return response()->json(['audit' => $this->audits->history($uid)]);
    }
}
