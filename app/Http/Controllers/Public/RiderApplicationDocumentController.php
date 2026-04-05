<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\RiderApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RiderApplicationDocumentController extends Controller
{
    public function show(Request $request, RiderApplication $riderApplication, string $type)
    {
        abort_unless(in_array($type, ['id_document', 'license_document'], true), 404);

        $path = $type === 'id_document'
            ? ($riderApplication->id_document_path ?: null)
            : ($riderApplication->license_document_path ?: null);

        if (! $path && $type === 'id_document') {
            $path = $this->legacyPathFromUrl($riderApplication->id_document_url);
        }
        if (! $path && $type === 'license_document') {
            $path = $this->legacyPathFromUrl($riderApplication->license_document_url);
        }

        abort_if(! $path || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    private function legacyPathFromUrl(?string $url): ?string
    {
        if (! $url || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return null;
        }

        return ltrim($url, '/');
    }
}
