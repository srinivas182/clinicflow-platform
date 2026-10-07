@extends('errors.layout')

@section('code', '419')
@section('title', 'Your session expired')
@section('message', 'For your security, the page timed out. Go back to the page and try again — anything you hadn’t saved may need to be entered again.')
@section('primary')<a class="btn primary" href="{{ url()->previous() }}">Back to the page</a>@endsection
