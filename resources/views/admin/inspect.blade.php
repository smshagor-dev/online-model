@extends('admin.layout')
@section('title', 'Model files')
@section('section', 'Chat models')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">CHAT MODELS / IMPORT</span><h1>Repository files</h1><p>Review GGUF weights and their model license before importing.</p></div><div class="heading-actions"><a class="subtle-label" href="/admin/models">← Model library</a></div></div>
<section class="admin-card" style="margin-bottom:21px">
    <h2>{{ $repository }}</h2><dl class="info-list"><div class="info-row"><dt>Pinned revision</dt><dd><code>{{ $revision }}</code></dd></div><div class="info-row"><dt>Model card</dt><dd><a href="https://huggingface.co/{{ $repository }}" target="_blank" rel="noopener noreferrer">Read license ↗</a></dd></div></dl>
</section>
<div class="section-separator"><h2>Available GGUF files</h2><p>Select a weight file and provide the display name and model family.</p></div>
<div class="entity-list">
    @forelse($files as $file)
    <form class="admin-card" action="/admin/models" method="post">
        @csrf
        <input type="hidden" name="revision" value="{{ $revision }}"><input type="hidden" name="repository" value="{{ $repository }}"><input type="hidden" name="filename" value="{{ $file['rfilename'] }}">
        <div class="entity-card-header"><h2>{{ $file['rfilename'] }}</h2><span class="badge">{{ number_format(($file['lfs']['size'] ?? $file['size'] ?? 0) / 1073741824, 2) }} GiB</span></div>
        <div class="form-fields" style="margin-top:18px"><div class="field"><label for="file_name_{{ $loop->index }}">Display name</label><input id="file_name_{{ $loop->index }}" name="name" required maxlength="120" placeholder="Name shown in the admin console"></div>
        <div class="field"><label for="file_family_{{ $loop->index }}">Model family</label><select id="file_family_{{ $loop->index }}" name="family">@foreach(['Nemotron','Qwen3','Llama','Mistral','Gemma','DeepSeek','Other'] as $family)<option value="{{ $family }}">{{ $family }}</option>@endforeach</select></div></div>
        <label class="check-line"><input type="checkbox" name="license_accepted" value="1" required> I reviewed the license and have permission to download and use this model.</label>
        <div class="form-actions"><button type="submit" class="btn-primary-admin">Import verified model</button></div>
    </form>
    @empty<div class="empty-block">No GGUF files found. Try another repository compatible with your inference runtime.</div>@endforelse
</div>
@endsection
