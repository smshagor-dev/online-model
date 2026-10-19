<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadModelBundle;
use App\Models\ModelBundle;
use App\Services\MediaModelManifest;
use Illuminate\Http\Request;

class ModelBundleController
{
    public function index()
    {
        return view('admin.model-bundles', ['bundles' => ModelBundle::latest()->paginate(20)]);
    }

    public function inspect(Request $r, MediaModelManifest $manifest)
    {
        $d = $r->validate(['repository' => 'required|string|max:160', 'purpose' => 'required|in:image,vision,transcription,speech,video']);

        return view('admin.bundle-inspect', [...$d, 'manifest' => $manifest->inspect($d['repository'])]);
    }

    public function import(Request $r, MediaModelManifest $manifest)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'repository' => 'required|string|max:160', 'revision' => 'required|regex:/^[a-f0-9]{40}$/D', 'purpose' => 'required|in:image,vision,transcription,speech,video', 'license_accepted' => 'required|accepted']);
        $manifest->import($d);

        return redirect('/admin/model-bundles')->with('status', 'Verified snapshot manifest imported. Download when ready.');
    }

    public function download(ModelBundle $bundle)
    {
        abort_unless(ModelBundle::whereKey($bundle->id)->whereIn('status', ['available', 'failed'])->update(['status' => 'queued', 'download_error' => null]), 409, 'Snapshot is already queued, downloading or ready.');
        try {
            DownloadModelBundle::dispatch($bundle->id);
        } catch (\Throwable $e) {
            $bundle->update(['status' => 'failed']);
            throw $e;
        }

        return back()->with('status', 'Snapshot download queued.');
    }
}
