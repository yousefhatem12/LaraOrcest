@extends('emails.layouts.default', ['locale' => 'zh'])

@section('title', '您的安全验证码 (OTP)')

@section('content')
    <h2>一次性安全验证码 (OTP)</h2>
    <p>尊敬的 {{ $name ?? '用户' }}，您好：</p>
    <p>您正在进行需要安全验证的敏感操作。请使用以下 6 位一次性验证码完成身份确认：</p>

    <div class="code-box">
        {{ $code ?? '123456' }}
    </div>

    <p style="text-align: center; color: #64748b; font-size: 13px;">
        该验证码有效时间为 <strong>{{ $expire_minutes ?? 10 }} 分钟</strong>。请勿将此代码分享给他人。
    </p>

    <div class="details-box" style="background-color: #fffbeb; border-color: #fde68a;">
        <p style="margin: 0; color: #b45309; font-size: 13px;">
            ⚠️ <strong>重要安全提示：</strong> {{ config('app.name', 'LaraOrcest') }} 的工作人员绝不会通过电话、短信或邮件向您索取此验证码。如非本人操作，请立刻检查账户安全。
        </p>
    </div>
@endsection

@section('footer_rights', '保留所有权利。')
@section('footer_disclaimer', '这是一封系统自动发送的邮件，请勿直接回复。')
