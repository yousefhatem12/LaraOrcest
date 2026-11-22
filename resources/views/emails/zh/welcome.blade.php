@extends('emails.layouts.default', ['locale' => 'zh'])

@section('title', '欢迎加入 ' . config('app.name', 'LaraOrcest'))

@section('content')
    <h2>欢迎加入 {{ config('app.name', 'LaraOrcest') }}！</h2>
    <p>尊敬的 {{ $name ?? '朋友' }}，您好：</p>
    <p>欢迎成为我们社区的一员！您的账户已成功创建并就绪。我们致力于为您提供卓越的自动化、协作和智能系统支持。</p>

    <div class="details-box">
        <p style="margin-top: 0; font-weight: 600; color: #1e293b;">您现在可以体验以下核心能力：</p>
        <ul style="margin-bottom: 0; padding-left: 20px; color: #475569;">
            <li>🤖 <strong>多智能体自动化编排：</strong> 无缝管理项目需求、架构与代码实现。</li>
            <li>🔒 <strong>企业级安全防护：</strong> 支持双重认证 (2FA) 与沙箱化安全工具保障。</li>
            <li>📅 <strong>日程与协作管理：</strong> 即时会议提醒与多语言团队协同。</li>
        </ul>
    </div>

    <div class="btn-wrapper">
        <a href="{{ $login_url ?? url('/login') }}" class="btn">立即登录控制台</a>
    </div>

    <p>如果您在上手过程中有任何疑问，请随时联系我们的客户支持团队。</p>
@endsection

@section('footer_rights', '保留所有权利。')
@section('footer_disclaimer', '这是一封系统自动发送的邮件，请勿直接回复。')
