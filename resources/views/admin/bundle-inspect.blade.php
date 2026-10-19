@extends('admin.layout')
@section('content')
<h1>Review media snapshot</h1><p><a href="https://huggingface.co/{{$repository}}" target="_blank" rel="noopener noreferrer">{{$repository}} model card and license</a></p>
<p>Pinned revision <code>{{$manifest['revision']}}</code><br>{{count($manifest['files'])}} files · {{number_format($manifest['bytes']/1073741824,2)}} GiB · {{$manifest['license']}}</p>
<p>Check that this repository is compatible with the {{$purpose}} runtime. The importer checks integrity and file types; it does not prove model quality, license suitability or runtime compatibility.</p>
<form class="panel" method="post" action="/admin/model-bundles">@csrf
<input type="hidden" name="repository" value="{{$repository}}"><input type="hidden" name="revision" value="{{$manifest['revision']}}"><input type="hidden" name="purpose" value="{{$purpose}}">
<label>Display name<input name="name" required maxlength="120" value="{{$repository}}"></label>
<label><input type="checkbox" name="license_accepted" value="1" required> I reviewed and accept the model license and access conditions.</label><button>Import verified manifest</button>
</form>
<div class="scroll"><table><thead><tr><th>File</th><th>Bytes</th><th>Checksum</th></tr></thead><tbody>@foreach($manifest['files'] as $file)<tr><td>{{$file['filename']}}</td><td>{{number_format($file['bytes'])}}</td><td>{{$file['algorithm']}} · <code>{{$file['checksum']}}</code></td></tr>@endforeach</tbody></table></div>
@endsection
