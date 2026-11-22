@extends('emails.layouts.default', ['locale' => 'zh'])

@section('title', '验证您的电子邮件地址')

@section('content')
    <h2>验证您的电子邮件地址</h2>
    <p>尊敬的 {{ $name ?? '用户' }}，您好：</p>
    <p>感谢您注册 {{ config('app.name', 'LaraOrcest') }}！为了确保您的账户安全并激活全部功能，请点击下方按钮验证您的电子邮件地址：</p>
    
    <div class="btn-wrapper">
        <a href="{{ $verification_url ?? '#' }}" class="btn">验证我的账户</a>
    </div>

    <p style="color: #64748b; font-size: 13px;">此验证链接将在 <strong>{{ $expire_minutes ?? 60 }} 分钟</strong>后失效。</p>
    <p style="color: #64748b; font-size: 13px;">如果您并未注册过本平台账户，请忽略此邮件，无需采取任何操作。</p>

    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
    <p style="font-size: 12px; color: #94a3b8; word-break: break-all;">
        如果上方按钮无法点击，请将以下链接复制到浏览器地址栏访问：<br>
        <a href="{{ $verification_url ?? '#' }}" style="color: #4f46e5;">{{ $verification_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', '保留所有权利。')
@section('footer_disclaimer', '这是一封系统自动发送的邮件，请勿直接回复。')
