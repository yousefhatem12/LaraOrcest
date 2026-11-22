@extends('emails.layouts.default', ['locale' => 'en'])

@section('title', 'Verify Your Email Address')

@section('content')
    <h2>Verify Your Email Address</h2>
    <p>Hello {{ $name ?? 'User' }},</p>
    <p>Thank you for registering with {{ config('app.name', 'LaraOrcest') }}! To ensure account security and activate all features, please verify your email address by clicking the button below:</p>
    
    <div class="btn-wrapper">
        <a href="{{ $verification_url ?? '#' }}" class="btn">Verify My Account</a>
    </div>

    <p style="color: #64748b; font-size: 13px;">This verification link will expire in <strong>{{ $expire_minutes ?? 60 }} minutes</strong>.</p>
    <p style="color: #64748b; font-size: 13px;">If you did not create an account, no further action is required.</p>

    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
    <p style="font-size: 12px; color: #94a3b8; word-break: break-all;">
        If you are having trouble clicking the button, copy and paste the URL below into your web browser:<br>
        <a href="{{ $verification_url ?? '#' }}" style="color: #4f46e5;">{{ $verification_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', 'All rights reserved.')
@section('footer_disclaimer', 'This is an automated system email, please do not reply directly.')
