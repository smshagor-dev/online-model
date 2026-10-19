@extends('admin.layout')
@section('content')
<h1>Media model snapshots</h1>
<p>Download revision-pinned Hugging Face snapshots for the private image, vision, transcription, speech or video runtime. Safetensors and verified configuration/tokenizer files are supported. Python and pickle weight files are excluded. A stored snapshot still needs a compatible runtime and hardware.</p>
<p><a href="/admin/models">Chat model library</a></p>
<form class="panel" method="get" action="/admin/model-bundles/inspect">
<label>Hugging Face repository<input name="repository" placeholder="owner/repository" required maxlength="160"></label>
<label>Runtime purpose<select name="purpose">@foreach(['image','vision','transcription','speech','video'] as $kind)<option>{{$kind}}</option>@endforeach</select></label>
<button>Inspect snapshot</button>
</form>
@foreach($bundles as $bundle)
<div class="card"><h2>{{$bundle->name}}</h2><p><span class="badge">{{$bundle->purpose}}</span> <span class="badge">{{$bundle->status}}</span></p>
<p>{{$bundle->repository}} · {{count($bundle->files)}} verified files · {{number_format($bundle->bytes/1073741824,2)}} GiB<br>Revision <code>{{$bundle->revision}}</code><br>License {{$bundle->license}}</p>
@if($bundle->download_error)<p role="alert">{{$bundle->download_error}}</p>@endif
@if(in_array($bundle->status,['available','failed']))<form method="post" action="/admin/model-bundles/{{$bundle->id}}/download">@csrf<button>Download snapshot</button></form>@endif
@if($bundle->status==='ready')<p>Private runtime setting:<br><code>MEDIA_{{strtoupper($bundle->purpose)}}_PATH={{storage_path('app/models/bundles/'.$bundle->id)}}</code></p><p>If the GPU runtime is on another machine, transfer this complete verified folder there, set its local path and restart the runtime. No inference engine is installed or enabled by downloading.</p>@endif
</div>
@endforeach
{{$bundles->links()}}
@endsection
