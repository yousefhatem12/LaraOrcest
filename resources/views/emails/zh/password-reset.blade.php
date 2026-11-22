@extends('emails.layouts.default', ['locale' => 'zh'])

@section('title', '重置您的账户密码')

@section('content')
    <h2>重置密码请求</h2>
    <p>尊敬的 {{ $name ?? '用户' }}，您好：</p>
    <p>我们收到了重置您 {{ config('app.name', 'LaraOrcest') }} 账户密码的请求。请点击下方按钮完成密码重置：</p>

    <div class="btn-wrapper">
        <a href="{{ $reset_url ?? '#' }}" class="btn">重置我的密码</a>
    </div>

    <p style="color: #64748b; font-size: 13px;">此密码重置链接将在 <strong>{{ $expire_minutes ?? 60 }} 分钟</strong>后失效。</p>
    <p style="color: #dc2626; font-size: 13px;">⚠️ 安全提示：如果您并未提交过重置密码请求，请立即忽略本邮件，您的当前密码不会发生任何改变。</p>

    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
    <p style="font-size: 12px; color: #94a3b8; word-break: break-all;">
        如果上方按钮无法点击，请将以下链接复制到浏览器地址栏访问：<br>
        <a href="{{ $reset_url ?? '#' }}" style="color: #4f46e5;">{{ $reset_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', '保留所有权利。')
@section('footer_disclaimer', '这是一封系统自动发送的邮件，请勿直接回复。')
