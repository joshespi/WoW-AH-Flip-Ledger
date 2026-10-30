<?php

namespace App\Http\Controllers;

use App\Services\UploaderBuilds;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
    public function build(UploaderBuilds $builds, string $platform): BinaryFileResponse
    {
        return $this->file($builds->path($platform) ?? abort(404));
    }

    public function addon(UploaderBuilds $builds): BinaryFileResponse
    {
        return $this->file($builds->addonPath() ?? abort(404));
    }

    public function sums(UploaderBuilds $builds): BinaryFileResponse
    {
        $path = $builds->sumsPath() ?? abort(404);

        return response()->download($path, UploaderBuilds::SUMS, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function file(string $path): BinaryFileResponse
    {
        return response()->download($path, basename($path), ['X-Content-Type-Options' => 'nosniff']);
    }
}
