@extends('emails.layouts.default', ['locale' => 'en'])

@section('title', 'Two-Factor Authentication (2FA) Recovery Code Notice')

@section('content')
    <h2 style="color: #b45309;">Two-Factor Authentication Security Alert</h2>
    <p>Hello {{ $name ?? 'User' }},</p>
    <p>Your {{ config('app.name', 'LaraOrcest') }} account was just accessed using a <strong>two-factor authentication (2FA) backup recovery code</strong>.</p>

    <div class="details-box">
        <table>
            <tr>
                <td class="label">Login Time:</td>
                <td>{{ $login_time ?? date('Y-m-d H:i:s') }}</td>
            </tr>
            <tr>
                <td class="label">IP Address:</td>
                <td>{{ $ip_address ?? 'Confidential' }}</td>
            </tr>
            <tr>
                <td class="label">Remaining Codes:</td>
                <td><strong style="color: {{ ($remaining_codes ?? 0) <= 2 ? '#dc2626' : '#16a34a' }};">{{ $remaining_codes ?? 0 }} codes</strong></td>
            </tr>
        </table>
    </div>

    @if(($remaining_codes ?? 0) <= 2)
        <div style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; margin: 16px 0; color: #991b1b; font-size: 13px;">
            ⚠️ You have few recovery codes remaining. We strongly recommend logging in to generate a fresh set of backup codes so you are never locked out.
        </div>
    @endif

    <div class="btn-wrapper">
        <a href="{{ $security_settings_url ?? url('/settings/security') }}" class="btn btn-secondary">Review Security Settings</a>
    </div>

    <p style="color: #dc2626; font-size: 13px;">🚨 If you did not initiate this login, your account credentials may be compromised. Please reset your password immediately and contact support.</p>
@endsection

@section('footer_rights', 'All rights reserved.')
@section('footer_disclaimer', 'This is an automated security alert email.')
