<?php
// app/Http/Controllers/Api/V1/ScannerCollectorController.php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
            // Finite app-level cap on manifest entries so an authenticated-but-compromised
            // client can't push an unbounded manifest payload.
            'manifest' => 'required|array|max:100000',
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

    public function files(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            // Bounds mirror the plugin-side contract: batches of up to 500 files,
            // each file capped at ~2.8M base64 chars (~2MB decoded), matching the
            // 2MB max_file_size / 2MB batch_bytes advertised in session().
            'files' => 'required|array|max:500',
            'files.*.path' => 'required|string',
            'files.*.content_b64' => 'required|string|max:2800000',
        ]);

        $session = $this->requireSession($request, $data['token']);
        if ($session instanceof JsonResponse) return $session;

        $engine = new ScannerEngine(config('scanner_signatures'));
        $scanned = 0;
        foreach ($data['files'] as $file) {
            $content = base64_decode($file['content_b64'], true);
            if ($content === false) continue;
            $session->addFindings('malware_signatures', $engine->scanContent($file['path'], $content));
            $session->addFindings('entropy_analysis', $engine->entropyFindings($file['path'], $content));
            $scanned++;
        }
        $session->incrementFilesScanned($scanned);

        return response()->json(['success' => true, 'scanned' => $scanned]);
    }

    public function finalize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'home_host' => 'required|string',
            'htaccess_files' => 'array',
            'htaccess_files.*.path' => 'required_with:htaccess_files|string',
            'htaccess_files.*.content_b64' => 'required_with:htaccess_files|string',
            'database' => 'array',
            'suspicious_files' => 'array',
            'permissions' => 'array',
        ]);

        $session = $this->requireSession($request, $data['token']);
        if ($session instanceof JsonResponse) return $session;

        $engine = new ScannerEngine(config('scanner_signatures'));

        foreach ($data['htaccess_files'] ?? [] as $ht) {
            $content = base64_decode($ht['content_b64'], true);
            if ($content === false) continue;
            $session->addFindings('htaccess', $engine->scanHtaccess($ht['path'], $content, $data['home_host']));
        }

        if (!empty($data['database'])) {
            $session->addFindings('database', $engine->analyzeDatabase($data['database']));
        }
        if (!empty($data['suspicious_files'])) {
            $session->addFindings('suspicious_files', $data['suspicious_files']);
        }
        if (!empty($data['permissions'])) {
            $session->addFindings('permissions', $data['permissions']);
        }

        $results = $session->assembleResults();
        $session->forget();

        return response()->json(['success' => true, 'results' => $results]);
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
