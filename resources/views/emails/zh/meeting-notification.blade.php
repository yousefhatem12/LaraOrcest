@extends('emails.layouts.default', ['locale' => 'zh'])

@section('title', '新会议邀请：' . ($meeting_title ?? '日程通知'))

@section('content')
    <h2>新会议日程邀请</h2>
    <p>尊敬的 {{ $name ?? '与会人员' }}，您好：</p>
    <p><strong>{{ $organizer ?? '组织者' }}</strong> 邀请您参加以下会议，请查看日程安排并反馈您的参会意向：</p>

    <div class="details-box">
        <table>
            <tr>
                <td class="label">会议主题：</td>
                <td><strong style="color: #0f172a;">{{ $meeting_title ?? '项目日程安排' }}</strong></td>
            </tr>
            <tr>
                <td class="label">日程时间：</td>
                <td><span style="color: #4f46e5; font-weight: 600;">{{ $start_time ?? '待定' }}</span></td>
            </tr>
            <tr>
                <td class="label">预期时长：</td>
                <td>{{ $duration ?? '45 分钟' }}</td>
            </tr>
            <tr>
                <td class="label">会议议程：</td>
                <td>{{ $description ?? '请参阅项目看板或附件材料。' }}</td>
            </tr>
        </table>
    </div>

    <p style="font-weight: 600; margin-bottom: 8px;">请确认您的参会状态：</p>
    <div style="text-align: center; margin: 20px 0;">
        <a href="{{ $rsvp_accept_url ?? '#' }}" class="btn btn-success" style="margin: 0 4px;">接受邀请</a>
        <a href="{{ $rsvp_tentative_url ?? '#' }}" class="btn btn-secondary" style="margin: 0 4px;">待定</a>
        <a href="{{ $rsvp_decline_url ?? '#' }}" class="btn btn-danger" style="margin: 0 4px;">谢绝邀请</a>
    </div>

    <p style="font-size: 13px; color: #64748b;">
        如需直接进入会议室，请访问：<a href="{{ $meeting_url ?? '#' }}" style="color: #4f46e5;">{{ $meeting_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', '保留所有权利。')
@section('footer_disclaimer', '这是一封系统自动发送的会议邀请邮件。')
