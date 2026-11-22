@extends('emails.layouts.default', ['locale' => 'en'])

@section('title', 'Your Security One-Time Password (OTP)')

@section('content')
    <h2>One-Time Security Verification Code (OTP)</h2>
    <p>Hello {{ $name ?? 'User' }},</p>
    <p>You are performing a sensitive operation that requires security verification. Please use the following 6-digit code to complete authentication:</p>

    <div class="code-box">
        {{ $code ?? '123456' }}
    </div>

    <p style="text-align: center; color: #64748b; font-size: 13px;">
        This code is valid for <strong>{{ $expire_minutes ?? 10 }} minutes</strong>. Do not share this code with anyone.
    </p>

    <div class="details-box" style="background-color: #fffbeb; border-color: #fde68a;">
        <p style="margin: 0; color: #b45309; font-size: 13px;">
            ⚠️ <strong>Important Security Notice:</strong> The {{ config('app.name', 'LaraOrcest') }} team will never ask for this code via phone, message, or email. If you did not initiate this request, secure your account immediately.
        </p>
    </div>
@endsection

@section('footer_rights', 'All rights reserved.')
@section('footer_disclaimer', 'This is an automated system email, please do not reply directly.')
