@extends('emails.layouts.default', ['locale' => 'en'])

@section('title', 'New Meeting Invitation: ' . ($meeting_title ?? 'Schedule Notice'))

@section('content')
    <h2>New Meeting Invitation</h2>
    <p>Hello {{ $name ?? 'Attendee' }},</p>
    <p><strong>{{ $organizer ?? 'The Organizer' }}</strong> has invited you to attend the following meeting. Please review the schedule and confirm your availability:</p>

    <div class="details-box">
        <table>
            <tr>
                <td class="label">Topic:</td>
                <td><strong style="color: #0f172a;">{{ $meeting_title ?? 'Project Meeting' }}</strong></td>
            </tr>
            <tr>
                <td class="label">Date & Time:</td>
                <td><span style="color: #4f46e5; font-weight: 600;">{{ $start_time ?? 'To be scheduled' }}</span></td>
            </tr>
            <tr>
                <td class="label">Duration:</td>
                <td>{{ $duration ?? '45 minutes' }}</td>
            </tr>
            <tr>
                <td class="label">Agenda:</td>
                <td>{{ $description ?? 'Please review the project board or attachments.' }}</td>
            </tr>
        </table>
    </div>

    <p style="font-weight: 600; margin-bottom: 8px;">Please respond to this invitation:</p>
    <div style="text-align: center; margin: 20px 0;">
        <a href="{{ $rsvp_accept_url ?? '#' }}" class="btn btn-success" style="margin: 0 4px;">Accept</a>
        <a href="{{ $rsvp_tentative_url ?? '#' }}" class="btn btn-secondary" style="margin: 0 4px;">Tentative</a>
        <a href="{{ $rsvp_decline_url ?? '#' }}" class="btn btn-danger" style="margin: 0 4px;">Decline</a>
    </div>

    <p style="font-size: 13px; color: #64748b;">
        Direct link to meeting room: <a href="{{ $meeting_url ?? '#' }}" style="color: #4f46e5;">{{ $meeting_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', 'All rights reserved.')
@section('footer_disclaimer', 'This is an automated meeting invitation email.')
