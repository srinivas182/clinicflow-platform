@extends('errors.layout')

@section('code', '403')
@section('title', 'You don’t have access')
@php($reason = $exception?->getMessage())
@section('message', $reason && ! in_array($reason, ['This action is unauthorized.', 'Forbidden', 'Unauthorized.'], true) ? $reason : 'Your account can’t open this page. If you think you should have access, ask your practice owner or administrator.')
