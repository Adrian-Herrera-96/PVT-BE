@extends('layouts.app')

@section('title', 'Afiliados')

@section('content')
<div class="row wrapper border-bottom white-bg page-heading">
    <div class="col-lg-9">
        {{ Breadcrumbs::render('show_affiliate', $affiliate) }}
    </div>
</div>
<div class="wrapper wrapper-content animated fadeInRight">
    <div class="row">
        <div class="col-lg-12">
            <div class="text-center m-t-lg">
                <h1>
                    {{ $affiliate->first_name }}
                </h1>
                <small>
                    It is an application skeleton for a typical web app. You can use it to quickly bootstrap your webapp projects.
                </small>
            </div>
        </div>
    </div>
</div>
@endsection
