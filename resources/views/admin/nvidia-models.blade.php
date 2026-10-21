@extends('admin.layout')
@section('title', 'NVIDIA free models')
@section('section', 'NVIDIA models')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">NVIDIA BUILD</span><h1>Free endpoint model catalog</h1><p>Configure the hosted model registry before exposing it to the frontend. Published model availability is not a guarantee of account access.</p></div></div>
<div class="card-grid" style="margin-bottom:22px">
 <div class="admin-card"><h2>{{ $total }}</h2><p class="entity-meta">Catalog models</p></div>
 <div class="admin-card"><h2>{{ $enabled }}</h2><p class="entity-meta">Always listed in the API</p></div>
 <div class="admin-card"><h2>{{ $configured }}</h2><p class="entity-meta">Inference configuration approved</p></div>
</div>
<div class="muted-note" style="margin-bottom:22px"><strong>NVIDIA server credentials:</strong> {{ $nvidiaReady ? 'Configured' : 'Missing NVIDIA_API_KEY in Laravel .env' }}. All 42 models are permanently listed in the developer model catalog. Chat models use the shared chat completion route. Specialized models need a verified NVIDIA API path and request schema before inference can work. Free endpoints can still have rate limits, access restrictions and non-commercial trial conditions.</div>
<section class="admin-card" style="margin-bottom:22px">
 <h2>Frontend model API</h2>
 <div class="field"><label>Base URL</label><input readonly value="{{ $apiBase }}"></div>
 <p class="entity-meta"><code>GET /catalog</code> — all catalog models, categories and integration readiness. <code>GET /models</code> — every registered model with availability metadata. <code>POST /chat/completions</code> — chat inference. <code>POST /models/{slug}/invoke</code> — configured JSON-based specialized models.</p>
 <p class="entity-meta">These APIs require a developer Bearer token. Specialized model invocation requires verified integration settings, but models are never hidden from the catalog. File uploads, binary and long-running asynchronous model operations are not supported by this JSON gateway.</p>
</section>
<form method="get" action="/admin/nvidia-models" class="admin-card admin-filter-form">
 <div class="admin-filter-fields">
  <div class="field"><label for="nvidia-q">Search models</label><input id="nvidia-q" name="q" value="{{ $search }}" placeholder="Model name or NVIDIA ID"></div>
  <div class="field"><label for="nvidia-category">Category</label><select id="nvidia-category" name="category"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category }}" @selected($selectedCategory === $category)>{{ $category }}</option>@endforeach</select></div>
 </div>
 <div class="admin-filter-actions"><button type="submit" class="btn-primary-admin">Filter models</button><a class="admin-reset-filter" href="/admin/nvidia-models">Reset filters</a></div>
</form>
@forelse($grouped as $category => $models)
<div class="section-separator"><h2>{{ $category }}</h2><p>{{ $models->count() }} model(s)</p></div>
<div class="card-grid" style="margin-bottom:26px">
@foreach($models as $model)
<section class="admin-card">
 <div class="entity-card-header"><div><h2 style="word-break:break-word">{{ $model->name }}</h2><p class="entity-meta">{{ $model->api_protocol === 'chat' ? 'Chat completions' : 'Specialized JSON request' }}</p></div><span class="status-pill {{ $model->api_ready ? 'success' : 'warning' }}">{{ $model->api_ready ? 'API configured' : 'Integration pending' }}</span></div>
 <p class="entity-meta">{{ $model->summary }}</p>
 <p class="entity-meta"><a href="{{ $model->catalog_url }}" target="_blank" rel="noopener noreferrer">NVIDIA model card</a> · Free Endpoint (catalog)</p>
 <form method="post" action="/admin/nvidia-models/{{ $model->slug }}">
  @csrf @method('PATCH')
  <div class="field"><label for="name-{{ $model->id }}">Display name</label><input id="name-{{ $model->id }}" name="name" value="{{ $model->name }}" required maxlength="120"></div>
  <div class="field"><label for="provider-{{ $model->id }}">NVIDIA API model ID</label><input id="provider-{{ $model->id }}" name="provider_model" value="{{ $model->provider_model }}" required maxlength="190"></div>
  <div class="field"><label for="path-{{ $model->id }}">Verified NVIDIA API path</label><input id="path-{{ $model->id }}" name="api_path" value="{{ $model->api_path }}" placeholder="/v1/..." maxlength="190"><small>Only paths on integrate.api.nvidia.com are allowed.</small></div>
  <div class="field"><label for="protocol-{{ $model->id }}">API protocol</label><select id="protocol-{{ $model->id }}" name="api_protocol"><option value="chat" @selected($model->api_protocol === 'chat')>Chat completions</option><option value="json" @selected($model->api_protocol === 'json')>Specialized JSON</option></select></div>
  <div class="field"><label for="ready-{{ $model->id }}">Inference integration</label><select id="ready-{{ $model->id }}" name="api_ready"><option value="0" @selected(!$model->api_ready)>Not verified</option><option value="1" @selected($model->api_ready)>Verified (JSON API)</option></select></div>
  <div class="field"><label for="model-field-{{ $model->id }}">Add model field to requests</label><select id="model-field-{{ $model->id }}" name="include_model"><option value="1" @selected($model->include_model)>Yes</option><option value="0" @selected(!$model->include_model)>No</option></select></div>
  <p class="entity-meta">Always visible to authenticated API clients. Provider-side access is verified separately.</p>
  <div class="form-actions"><button type="submit" class="btn-primary-admin">Save model</button></div>
 </form>
</section>
@endforeach
</div>
@empty
<section class="admin-card"><p>No models match your search.</p></section>
@endforelse
@endsection
