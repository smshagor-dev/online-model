@extends('admin.layout')
@section('title', 'Inspect media snapshot')
@section('section', 'Media models')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">MEDIA MODELS / IMPORT</span><h1>Review media snapshot</h1><p>Verify the downloaded file manifest and license requirements before importing.</p></div><div class="heading-actions"><a class="subtle-label" href="/admin/model-bundles">← Media models</a></div></div>
<div class="admin-form-grid">
    <div class="admin-form-stack">
        <section class="admin-card">
            <h2>{{ $repository }}</h2>
            <dl class="info-list"><div class="info-row"><dt>Revision</dt><dd><code>{{ $manifest['revision'] }}</code></dd></div><div class="info-row"><dt>Verified files</dt><dd>{{ count($manifest['files']) }}</dd></div><div class="info-row"><dt>Total size</dt><dd>{{ number_format($manifest['bytes'] / 1073741824, 2) }} GiB</dd></div><div class="info-row"><dt>License</dt><dd>{{ $manifest['license'] }}</dd></div><div class="info-row"><dt>Purpose</dt><dd>{{ ucfirst($purpose) }}</dd></div></dl>
            <div class="form-actions"><a href="https://huggingface.co/{{ $repository }}" target="_blank" rel="noopener noreferrer" class="pill-link">Read model card and license ↗</a></div>
        </section>
        <form class="admin-card" method="post" action="/admin/model-bundles">
            @csrf
            <input type="hidden" name="repository" value="{{ $repository }}"><input type="hidden" name="revision" value="{{ $manifest['revision'] }}"><input type="hidden" name="purpose" value="{{ $purpose }}">
            <div class="section-separator"><h2>Import snapshot</h2><p>Import the signed-off manifest into the workspace.</p></div>
            <div class="field"><label for="bundle_display_name">Display name</label><input id="bundle_display_name" name="name" required maxlength="120" value="{{ old('name', $repository) }}"></div>
            <label class="check-line"><input type="checkbox" name="license_accepted" value="1" required> I reviewed and accept the model license and access conditions.</label>
            <div class="form-actions"><button class="btn-primary-admin" type="submit">Import verified manifest</button></div>
        </form>
    </div>
    <aside class="admin-form-stack"><div class="muted-note"><strong>Verify runtime compatibility</strong><div>File integrity and allowed file types are checked. This does not certify model quality, license suitability, hardware capacity or inference compatibility.</div></div></aside>
</div>
<section class="admin-card table-card" style="margin-top:23px">
    <div class="table-heading"><h2>Verified files</h2><p class="section-description">Checksums for the snapshot's pinned revision.</p></div>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>File</th><th>Bytes</th><th>Checksum</th></tr></thead><tbody>@foreach($manifest['files'] as $file)<tr><td>{{ $file['filename'] }}</td><td>{{ number_format($file['bytes']) }}</td><td>{{ $file['algorithm'] }} · <code>{{ $file['checksum'] }}</code></td></tr>@endforeach</tbody></table></div>
</section>
@endsection
