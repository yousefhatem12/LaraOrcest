@extends('emails.layouts.default', ['locale' => 'en'])

@section('title', 'Reset Your Password')

@section('content')
    <h2>Password Reset Request</h2>
    <p>Hello {{ $name ?? 'User' }},</p>
    <p>We received a request to reset your password for your {{ config('app.name', 'LaraOrcest') }} account. Click the button below to set a new password:</p>

    <div class="btn-wrapper">
        <a href="{{ $reset_url ?? '#' }}" class="btn">Reset Password</a>
    </div>

    <p style="color: #64748b; font-size: 13px;">This password reset link will expire in <strong>{{ $expire_minutes ?? 60 }} minutes</strong>.</p>
    <p style="color: #dc2626; font-size: 13px;">⚠️ Security notice: If you did not request a password reset, please ignore this email. Your password will remain completely safe.</p>

    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
    <p style="font-size: 12px; color: #94a3b8; word-break: break-all;">
        If you are having trouble clicking the button, copy and paste the URL below into your web browser:<br>
        <a href="{{ $reset_url ?? '#' }}" style="color: #4f46e5;">{{ $reset_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', 'All rights reserved.')
@section('footer_disclaimer', 'This is an automated system email, please do not reply directly.')
