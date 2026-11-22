@extends('emails.layouts.default', ['locale' => 'en'])

@section('title', 'Meeting Reminder: ' . ($meeting_title ?? 'Scheduled Meeting'))

@section('content')
    <h2>Meeting Starting Soon</h2>
    <p>Hello {{ $name ?? 'Attendee' }},</p>
    <p>This is a friendly reminder that your scheduled meeting is starting soon. Please join on time:</p>

    <div class="details-box">
        <table>
            <tr>
                <td class="label">Topic:</td>
                <td><strong style="color: #0f172a;">{{ $meeting_title ?? 'Untitled Meeting' }}</strong></td>
            </tr>
            <tr>
                <td class="label">Start Time:</td>
                <td><span style="color: #4f46e5; font-weight: 600;">{{ $start_time ?? 'Shortly' }}</span></td>
            </tr>
            <tr>
                <td class="label">Duration:</td>
                <td>{{ $duration ?? '30 minutes' }}</td>
            </tr>
            <tr>
                <td class="label">Organizer:</td>
                <td>{{ $organizer ?? 'Project Lead' }}</td>
            </tr>
            <tr>
                <td class="label">Location:</td>
                <td>{{ $location ?? 'Online Virtual Meeting Room' }}</td>
            </tr>
        </table>
    </div>

    <div class="btn-wrapper">
        <a href="{{ $meeting_url ?? '#' }}" class="btn">Join Meeting Room</a>
    </div>

    <p style="font-size: 13px; color: #64748b; word-break: break-all;">
        Direct meeting link: <a href="{{ $meeting_url ?? '#' }}" style="color: #4f46e5;">{{ $meeting_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', 'All rights reserved.')
@section('footer_disclaimer', 'This is an automated meeting alert email.')
