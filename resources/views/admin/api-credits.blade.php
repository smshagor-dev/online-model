@extends('admin.layout')
@section('title', 'API credits')
@section('section', 'API credits')
@section('content')
<div class="admin-page-heading">
    <div><span class="eyebrow">CONNECTED API USAGE</span><h1>API credits and connections</h1>
    <p>Tracked successful requests per account and connected model · {{ $monthLabel }}</p></div>
    <div class="heading-actions"><a href="/admin" class="subtle-label">← Overview</a></div>
</div>
<div class="muted-note" style="margin-bottom:22px">
    <strong>Application usage credits, not NVIDIA billing credits.</strong>
    <div>Each successfully completed tracked API request counts as one credit. The frontend server handles provider API keys; this page only reads backend usage records. Remaining amounts are calculated from allocations you set below. They do not represent a provider-issued balance or guaranteed live API access. Earlier calls made before tracking was enabled are not included.</div>
</div>
<section class="admin-card">
    <div class="panel-heading"><div><h2>Models and API endpoints</h2><p>Connection metadata comes from authenticated frontend-to-backend requests. Last successful call does not guarantee the endpoint is currently available.</p></div></div>
    <div class="credit-grid">
        @forelse($creditModels as $model)
        <article class="credit-model-card">
            <div class="entity-card-header"><div><h3>{{ $model['model'] }}</h3><p class="entity-meta">{{ strtoupper($model['provider']) }} · {{ ucfirst(str_replace('_', ' ', $model['kind'])) }}</p></div>
            <span class="status-pill {{ $model['last_success_at'] ? 'success' : 'warning' }}">{{ $model['last_success_at'] ? 'Previously connected' : 'No completed calls' }}</span></div>
            @include('admin.credit-pie', ['used' => $model['used'], 'limit' => $model['limit']])
            <p class="credit-date">Last request: {{ $model['last_request_at'] ?: 'Not recorded' }} · Last success: {{ $model['last_success_at'] ?: 'Never' }}</p>
            <form method="post" action="/admin/api-credits/models/{{ $model['id'] }}" class="credit-limit-form">@csrf @method('PATCH')
                <label>Monthly model allocation (request credits; blank = not set)<input type="number" name="monthly_credit_limit" min="1" max="100000000" value="{{ $model['limit'] }}"></label>
                <label>Current admin password<input type="password" name="current_password" required autocomplete="current-password"></label>
                @if(auth()->user()->two_factor_confirmed_at)<label>Two-factor code<input name="code" maxlength="64" required autocomplete="one-time-code"></label>@endif
                <button class="btn-primary-admin" type="submit">Save allocation</button>
            </form>
        </article>
        @empty
        <div class="empty-block">No hosted model requests have been reported yet. After a frontend API request is made, its connection will appear here.</div>
        @endforelse
    </div>
</section>
<section class="admin-card table-card" style="margin-top:22px">
    <div class="table-heading"><h2>User credits</h2><p class="section-description">Top 20 users by successfully completed API requests during {{ $monthLabel }}.</p></div>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Used</th><th>Remaining</th><th>Monthly allocation</th><th></th></tr></thead><tbody>
        @forelse($creditUsers as $u)
        <tr><td><strong>{{ $u['name'] }}</strong><small class="table-subtitle">{{ $u['email'] }}</small></td>
            <td>{{ number_format($u['used']) }}</td><td>{{ $u['remaining'] === null ? 'Not configured' : number_format($u['remaining']) }}</td>
            <td>{{ $u['limit'] === null ? 'Not configured' : number_format($u['limit']) }}</td><td><a href="/admin/users/{{ $u['id'] }}">View account →</a></td></tr>
        @empty<tr><td colspan="5" class="empty-state">No accounts found.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endsection
