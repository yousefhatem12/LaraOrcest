@extends('emails.layouts.default', ['locale' => 'en'])

@section('title', 'Welcome to ' . config('app.name', 'LaraOrcest'))

@section('content')
    <h2>Welcome to {{ config('app.name', 'LaraOrcest') }}!</h2>
    <p>Hello {{ $name ?? 'there' }},</p>
    <p>Welcome to our community! Your account has been successfully created and is ready to use. We are excited to support your orchestration and development workflows.</p>

    <div class="details-box">
        <p style="margin-top: 0; font-weight: 600; color: #1e293b;">Here are key capabilities you can explore:</p>
        <ul style="margin-bottom: 0; padding-left: 20px; color: #475569;">
            <li>🤖 <strong>Multi-Agent Automation Orchestration:</strong> Seamlessly plan, architect, implement, and review code.</li>
            <li>🔒 <strong>Enterprise-Grade Security:</strong> Built-in 2FA support and path-guarded sandboxed execution.</li>
            <li>📅 <strong>Schedules & Notifications:</strong> Instant meeting alerts and multi-language team collaboration.</li>
        </ul>
    </div>

    <div class="btn-wrapper">
        <a href="{{ $login_url ?? url('/login') }}" class="btn">Go to Dashboard</a>
    </div>

    <p>If you have any questions, feel free to contact our support team at any time.</p>
@endsection

@section('footer_rights', 'All rights reserved.')
@section('footer_disclaimer', 'This is an automated system email, please do not reply directly.')
