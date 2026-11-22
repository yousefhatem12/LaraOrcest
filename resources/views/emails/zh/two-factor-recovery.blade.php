@extends('emails.layouts.default', ['locale' => 'zh'])

@section('title', '双重认证 (2FA) 备用恢复代码使用提醒')

@section('content')
    <h2 style="color: #b45309;">双重认证 (2FA) 安全警报</h2>
    <p>尊敬的 {{ $name ?? '用户' }}，您好：</p>
    <p>您的 {{ config('app.name', 'LaraOrcest') }} 账户刚刚通过<strong>双重认证 (2FA) 备用恢复代码</strong>成功登录。</p>

    <div class="details-box">
        <table>
            <tr>
                <td class="label">登录时间：</td>
                <td>{{ $login_time ?? date('Y-m-d H:i:s') }}</td>
            </tr>
            <tr>
                <td class="label">IP 地址：</td>
                <td>{{ $ip_address ?? '保密' }}</td>
            </tr>
            <tr>
                <td class="label">剩余代码数：</td>
                <td><strong style="color: {{ ($remaining_codes ?? 0) <= 2 ? '#dc2626' : '#16a34a' }};">{{ $remaining_codes ?? 0 }} 个</strong></td>
            </tr>
        </table>
    </div>

    @if(($remaining_codes ?? 0) <= 2)
        <div style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; margin: 16px 0; color: #991b1b; font-size: 13px;">
            ⚠️ 您的可用恢复代码数量已不足，强烈建议您登录账户重新生成一套新的备用恢复代码，以避免意外被锁在账户之外。
        </div>
    @endif

    <div class="btn-wrapper">
        <a href="{{ $security_settings_url ?? url('/settings/security') }}" class="btn btn-secondary">检查安全设置</a>
    </div>

    <p style="color: #dc2626; font-size: 13px;">🚨 如果此次操作不是您本人执行，您的账户可能面临安全风险，请立即重置密码并联系系统管理员。</p>
@endsection

@section('footer_rights', '保留所有权利。')
@section('footer_disclaimer', '这是一封系统自动发送的安全警报邮件。')
