<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use JsonException;

final class CollectorUpdateManifestController extends Controller
{
    private const SUPPORTED = [
        'windows' => ['x86_64'],
        'linux' => ['x86_64'],
        'darwin' => ['x86_64', 'aarch64'],
    ];

    /**
     * Implements the dynamic signed-update protocol used by Tauri v2.
     * It does not distribute unsigned binaries or perform remote installation.
     */
    public function __invoke(string $target, string $arch, string $currentVersion): JsonResponse|Response
    {
        $noStore = ['Cache-Control' => 'no-store'];

        if (! in_array($arch, self::SUPPORTED[$target] ?? [], true)
            || preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/D', $currentVersion) !== 1) {
            return response()->json(['message' => 'Unsupported platform or version.'], 422, $noStore);
        }

        $raw = config('nexa.collector_updates.manifest_json');
        if (! is_string($raw) || trim($raw) === '') {
            return response()->noContent(204, $noStore);
        }

        try {
            $manifest = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::error('Collector updater manifest is not valid JSON.');
            return response()->json(['message' => 'Update feed unavailable.'], 503, $noStore);
        }

        $version = is_array($manifest) ? ($manifest['version'] ?? null) : null;
        if (! is_string($version) || preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
            Log::error('Collector updater manifest has an invalid release version.');
            return response()->json(['message' => 'Update feed unavailable.'], 503, $noStore);
        }

        if (version_compare($version, $currentVersion, '<=')) {
            return response()->noContent(204, $noStore);
        }

        $platform = $manifest['platforms'][$target.'-'.$arch] ?? null;
        if ($platform === null) {
            return response()->noContent(204, $noStore);
        }
        $url = is_array($platform) ? ($platform['url'] ?? null) : null;
        $signature = is_array($platform) ? ($platform['signature'] ?? null) : null;
        // The URL is never fetched by the API. Clients still MUST verify its
        // artifact signature against the public key embedded in their installer.
        if (! is_string($url) || strlen($url) > 2048
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! is_string($signature) || strlen($signature) < 80 || strlen($signature) > 4096
            || ! str_contains($signature, 'untrusted comment:')) {
            Log::error('Collector updater manifest has no usable signed HTTPS artifact.', [
                'platform' => $target.'-'.$arch,
            ]);
            return response()->json(['message' => 'Update feed unavailable.'], 503, $noStore);
        }

        $result = ['version' => $version, 'url' => $url, 'signature' => $signature];
        foreach (['notes', 'pub_date'] as $optional) {
            if (isset($manifest[$optional]) && is_string($manifest[$optional])) {
                $result[$optional] = $manifest[$optional];
            }
        }

        return response()->json($result, 200, $noStore);
    }
}
