@extends('admin.layout')
@section('title', 'Dashboard')
@section('section', 'Overview')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">PLATFORM OVERVIEW</span><h1>Dashboard</h1><p>{{ $settings['dashboard_note'] ?: 'Your AI workspace at a glance.' }}</p></div><div class="heading-actions"><span>{{ now()->format('D, d M Y') }}</span><a href="/admin/settings" class="btn-primary-admin">Manage settings ↗</a></div></div>
<div class="kpi-grid">
<div class="kpi-card"><div class="kpi-title">Total users <span class="kpi-symbol violet">♙</span></div><div class="kpi-number">{{ number_format($stats['users']) }}</div><div class="kpi-caption"><b>+{{ $stats['new_users'] }}</b> new accounts in 30 days</div></div>
<div class="kpi-card"><div class="kpi-title">Conversations <span class="kpi-symbol blue">☷</span></div><div class="kpi-number">{{ number_format($stats['conversations']) }}</div><div class="kpi-caption">{{ number_format($stats['messages']) }} total messages</div></div>
<div class="kpi-card"><div class="kpi-title">Models ready <span class="kpi-symbol green">✳</span></div><div class="kpi-number">{{ number_format($stats['ready_models']) }} <small>/ {{ $stats['models'] }}</small></div><div class="kpi-caption">{{ $stats['enabled_models'] }} enabled for chat</div></div>
<div class="kpi-card"><div class="kpi-title">Active users <span class="kpi-symbol orange">◉</span></div><div class="kpi-number">{{ number_format($stats['active_users']) }}</div><div class="kpi-caption">{{ $stats['admins'] }} admins · {{ $stats['suspended_users'] }} suspended</div></div>
</div>
<div class="dashboard-grid">
<section class="card"><div class="panel-heading"><div><h2>New registrations</h2><p>User growth during the last 7 days</p></div><span class="subtle-label">Last 7 days</span></div>
@php($maximum = max(1, max(array_column($registrations, 'count'))))
<div class="registration-chart" role="img" aria-label="New user registrations over the last seven days: @foreach($registrations as $point){{ $point['day'] }} {{ $point['count'] }}; @endforeach">
@foreach($registrations as $point)
<div class="chart-column"><small>{{ $point['count'] }}</small><div class="chart-track"><div class="chart-bar" style="height:{{ $point['count'] ? max(6, round(100 * $point['count'] / $maximum)) : 3 }}%" title="{{ $point['count'] }} users"></div></div><span>{{ $point['day'] }}</span></div>
@endforeach
</div><div class="panel-bottom"><span>{{ array_sum(array_column($registrations, 'count')) }} users this week</span><a href="/admin/users">View users →</a></div>
</section>
<section class="card"><div class="panel-heading"><div><h2>Operations</h2><p>Based on current database records</p></div><span class="subtle-label">Current</span></div>
@foreach([['Ready models', $stats['ready_models'], 'success'],['Models downloading', $stats['pending_models'], 'warning'],['Failed downloads', $stats['failed_models'], 'danger'],['Active media jobs', $stats['running_media'], 'info'],['Background runs', $stats['running_tasks'], 'info']] as $row)
<div class="operation-row"><span><i class="health-dot {{ $row[2] }}"></i>{{ $row[0] }}</span><strong>{{ $row[1] }}</strong></div>
@endforeach
<div class="panel-bottom"><span>Not a remote runtime health check</span><a href="/admin/models">Manage models →</a></div></section>
</div>
@include('admin.credit-overview')
<div class="dashboard-grid two-tables">
<section class="card"><div class="panel-heading"><div><h2>Recent users</h2><p>Newest registered accounts</p></div><a href="/admin/users" class="view-all">View all →</a></div>
<div class="scroll"><table><thead><tr><th>Account</th><th>Joined</th><th>Status</th></tr></thead><tbody>
@forelse($recentUsers as $user)
<tr><td><a class="table-emphasis" href="/admin/users/{{ $user->id }}">{{ $user->name }}</a><small class="table-subtitle">{{ $user->email }}</small></td><td>{{ $user->created_at->format('M d, Y') }}</td><td><span class="status-pill {{ $user->is_active ? 'success' : 'warning' }}">{{ $user->is_active ? 'Active' : 'Suspended' }}</span></td></tr>
@empty<tr><td colspan="3" class="empty-state">No users yet.</td></tr>@endforelse
</tbody></table></div></section>
<section class="card"><div class="panel-heading"><div><h2>Recently added models</h2><p>Latest entries in the model library</p></div><a href="/admin/models" class="view-all">View all →</a></div>
<div class="scroll"><table><thead><tr><th>Model</th><th>Family</th><th>Status</th></tr></thead><tbody>
@forelse($recentModels as $model)
<tr><td><span class="table-emphasis">{{ $model->name }}</span><small class="table-subtitle">{{ \Illuminate\Support\Str::limit($model->repository, 34) }}</small></td><td>{{ $model->family }}</td><td><span class="status-pill {{ $model->status === 'ready' ? 'success' : ($model->status === 'failed' ? 'danger' : 'warning') }}">{{ ucfirst($model->status) }}</span></td></tr>
@empty<tr><td colspan="3" class="empty-state">No models added yet.</td></tr>@endforelse
</tbody></table></div></section>
</div>
@endsection
