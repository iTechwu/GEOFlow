@extends('theme.geoflow-workspace.layout')

@section('theme_content')
    @php($signedIn = auth('admin')->check())

    <section class="geoflow-entry" aria-labelledby="geoflow-entry-title">
        <div class="geoflow-entry-mark" aria-hidden="true"><span></span><span></span><span></span></div>
        <h1 id="geoflow-entry-title">GeoFlow</h1>
        <div class="geoflow-entry-action">
            @if($signedIn)
                <a href="{{ route('admin.dashboard') }}">进入工作台</a>
            @else
                <a href="{{ route('sso.login') }}">登录</a>
            @endif
        </div>
    </section>
@endsection
