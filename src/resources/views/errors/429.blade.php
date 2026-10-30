@extends('errors.layout')

@php
    $retry = (int) ($exception->getHeaders()['Retry-After'] ?? 0);
    $minutes = max(1, (int) ceil($retry / 60));
@endphp

@section('code', '429')
@section('title', __('Slow down a little'))
@section('message')
    {{ $retry > 0
        ? trans_choice('You have hit the limit for now. Please try again in :n minute.|You have hit the limit for now. Please try again in :n minutes.', $minutes, ['n' => $minutes])
        : __('You have hit the limit for now. Please try again shortly.') }}
    {{ __('Uploads and trade imports are limited per hour.') }}
@endsection
