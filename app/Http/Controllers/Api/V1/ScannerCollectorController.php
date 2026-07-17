<?php
// app/Http/Controllers/Api/V1/ScannerCollectorController.php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Scanner\ChecksumService;
use App\Services\Scanner\ManifestStore;
use App\Services\Scanner\ScannerEngine;
use App\Services\Scanner\ScanSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScannerCollectorController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        $project = $request->attributes->get('lsm_project');
        $data = $request->validate([
            'scan_id' => 'required|integer',
            'scan_type' => 'required|in:quick,standard,full',
            'wp_version' => 'required|string',
            'locale' => 'nullable|string',
        ]);

        $session = ScanSession::create($project->id, $data['scan_id'], $data['scan_type']);

        return response()->json([
            'success' => true,
            'token' => $session->token(),
            'spam_keywords' => config('scanner_signatures.spam_keywords'),
            'config' => ['max_file_size' => 2097152, 'batch_bytes' => 2097152],
        ]);
    }

    public function manifest(Request $request, ChecksumService $checksums, ManifestStore $manifests): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'wp_version' => 'required|string',
            'locale' => 'nullable|string',
            'manifest' => 'required|array',
            'manifest.*.path' => 'required|string',
            'manifest.*.md5' => 'required|string',
            'manifest.*.size' => 'required|integer',
        ]);

        $session = $this->requireSession($request, $data['token']);
        if ($session instanceof JsonResponse) return $session;

        $core = $checksums->coreChecksums($data['wp_version'], $data['locale'] ?? 'en_US');
        $previous = $manifests->load($session->projectId());
        $needed = $checksums->needsContent($data['manifest'], $core, $previous);

        $session->setNeededPaths($needed);
        $manifests->save($session->projectId(), $data['manifest']);

        return response()->json(['success' => true, 'needed_paths' => $needed]);
    }

    private function requireSession(Request $request, string $token): ScanSession|JsonResponse
    {
        $session = ScanSession::load($token);
        $project = $request->attributes->get('lsm_project');
        if (!$session || $session->projectId() !== $project->id) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired scan session'], 422);
        }
        return $session;
    }
}
