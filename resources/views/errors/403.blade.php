@extends('errors::minimal')

@section('title', 'Akses Ditolak')
@section('code', '403')
@section('message', __($exception->getMessage() ?: 'Akunmu tidak memiliki izin untuk membuka halaman atau tindakan ini.'))
