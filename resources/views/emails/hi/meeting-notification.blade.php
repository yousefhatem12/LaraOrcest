@extends('emails.layouts.default', ['locale' => 'hi'])

@section('title', 'नई बैठक आमंत्रण: ' . ($meeting_title ?? 'अनुसूची सूचना'))

@section('content')
    <h2>नई बैठक अनुसूची आमंत्रण</h2>
    <p>नमस्ते {{ $name ?? 'प्रतिभागी' }},</p>
    <p><strong>{{ $organizer ?? 'आयोजक' }}</strong> ने आपको निम्नलिखित बैठक में भाग लेने के लिए आमंत्रित किया है। कृपया विवरण देखें और अपनी प्रतिक्रिया दें:</p>

    <div class="details-box">
        <table>
            <tr>
                <td class="label">बैठक का शीर्षक:</td>
                <td><strong style="color: #0f172a;">{{ $meeting_title ?? 'परियोजना अनुसूची बैठक' }}</strong></td>
            </tr>
            <tr>
                <td class="label">दिनांक और समय:</td>
                <td><span style="color: #4f46e5; font-weight: 600;">{{ $start_time ?? 'निर्धारित होना बाकी' }}</span></td>
            </tr>
            <tr>
                <td class="label">अपेक्षित अवधि:</td>
                <td>{{ $duration ?? '45 मिनट' }}</td>
            </tr>
            <tr>
                <td class="label">एजेंडा:</td>
                <td>{{ $description ?? 'कृपया परियोजना बोर्ड या संलग्न सामग्री देखें।' }}</td>
            </tr>
        </table>
    </div>

    <p style="font-weight: 600; margin-bottom: 8px;">कृपया अपनी उपस्थिति स्थिति की पुष्टि करें:</p>
    <div style="text-align: center; margin: 20px 0;">
        <a href="{{ $rsvp_accept_url ?? '#' }}" class="btn btn-success" style="margin: 0 4px;">स्वीकार करें</a>
        <a href="{{ $rsvp_tentative_url ?? '#' }}" class="btn btn-secondary" style="margin: 0 4px;">अनंतिम</a>
        <a href="{{ $rsvp_decline_url ?? '#' }}" class="btn btn-danger" style="margin: 0 4px;">अस्वीकार करें</a>
    </div>

    <p style="font-size: 13px; color: #64748b;">
        सीधे बैठक कक्ष में जाने के लिए लिंक: <a href="{{ $meeting_url ?? '#' }}" style="color: #4f46e5;">{{ $meeting_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', 'सर्वाधिकार सुरक्षित।')
@section('footer_disclaimer', 'यह एक स्वचालित बैठक आमंत्रण ईमेल है।')
