@extends('admin.layout')
@section('content')
<h1>Public assistant templates</h1><p>Review public instructions and block unsuitable publications. User account suspension also hides their public templates.</p>
@foreach($publications as $a)<article class="card"><h2>{{$a->name}}</h2><p>{{$a->user->name}} · {{$a->blocked?'Blocked':'Public'}}</p><p>{{$a->description}}</p><details><summary>Published instructions</summary><pre style="white-space:pre-wrap">{{$a->instructions}}</pre></details><form method="post" action="/admin/assistants/{{$a->id}}">@csrf @method('PATCH')<input type="hidden" name="blocked" value="{{$a->blocked?0:1}}"><button>{{$a->blocked?'Unblock':'Block publication'}}</button></form></article>@endforeach
{{$publications->links()}}
@endsection
