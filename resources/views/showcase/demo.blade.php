@extends('layouts.showcase')
@section('content')
<div class="app-layout" data-demo-shell="workspace" @if($demoView === 'passport') hidden @endif>
    @include('showcase.partials.sidebar')
    <div class="app-main">
        @include('showcase.partials.topbar')
        <p class="demo-notice" role="status" aria-live="polite" data-demo-notice></p>
        <main>
            @foreach(['dashboard', 'catalogue', 'product', 'readiness'] as $view)
                <div data-demo-panel="{{ $view }}" @if($demoView !== $view && !($demoView === 'import' && $view === 'dashboard')) hidden @endif>
                    @include('showcase.partials.'.$view)
                </div>
            @endforeach
        </main>
        <p class="app-session-note">{{ $copy['demo_notice'] }} · {{ $copy['demo_detail'] }} <button type="button" class="demo-reset" data-demo-action="reset">{{ $copy['reset'] }}</button></p>
    </div>
</div>
<main data-demo-panel="passport" @if($demoView !== 'passport') hidden @endif>
    @include('showcase.partials.passport')
</main>
<dialog class="np-dialog import-wizard" data-demo-import-dialog aria-labelledby="demo-import-heading">
    @include('showcase.partials.import')
</dialog>
<template data-demo-row-template>@include('showcase.partials.catalogue-row')</template>
@endsection
