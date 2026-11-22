@extends('emails.layouts.default', ['locale' => 'zh'])

@section('title', '会议日程提醒：' . ($meeting_title ?? '项目会议'))

@section('content')
    <h2>会议日程即将开始</h2>
    <p>尊敬的 {{ $name ?? '与会人员' }}，您好：</p>
    <p>这是一封温馨提醒，您参与的会议即将开始，请提前做好准备并准时接入：</p>

    <div class="details-box">
        <table>
            <tr>
                <td class="label">会议主题：</td>
                <td><strong style="color: #0f172a;">{{ $meeting_title ?? '未命名会议' }}</strong></td>
            </tr>
            <tr>
                <td class="label">开始时间：</td>
                <td><span style="color: #4f46e5; font-weight: 600;">{{ $start_time ?? '即刻' }}</span></td>
            </tr>
            <tr>
                <td class="label">会议时长：</td>
                <td>{{ $duration ?? '30 分钟' }}</td>
            </tr>
            <tr>
                <td class="label">组织者：</td>
                <td>{{ $organizer ?? '项目负责人' }}</td>
            </tr>
            <tr>
                <td class="label">会议地点：</td>
                <td>{{ $location ?? '线上虚拟会议室' }}</td>
            </tr>
        </table>
    </div>

    <div class="btn-wrapper">
        <a href="{{ $meeting_url ?? '#' }}" class="btn">立即加入会议室</a>
    </div>

    <p style="font-size: 13px; color: #64748b; word-break: break-all;">
        会议直接访问链接：<a href="{{ $meeting_url ?? '#' }}" style="color: #4f46e5;">{{ $meeting_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', '保留所有权利。')
@section('footer_disclaimer', '这是一封系统自动发送的会议通知邮件。')
