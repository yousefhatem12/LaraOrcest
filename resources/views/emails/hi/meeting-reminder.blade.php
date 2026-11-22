@extends('emails.layouts.default', ['locale' => 'hi'])

@section('title', 'बैठक अनुस्मारक: ' . ($meeting_title ?? 'परियोजना बैठक'))

@section('content')
    <h2>बैठक जल्द ही शुरू होने वाली है</h2>
    <p>नमस्ते {{ $name ?? 'प्रतिभागी' }},</p>
    <p>यह एक विनम्र अनुस्मारक है कि आपकी निर्धारित बैठक जल्द ही शुरू होने वाली है। कृपया समय पर जुड़ें:</p>

    <div class="details-box">
        <table>
            <tr>
                <td class="label">बैठक का शीर्षक:</td>
                <td><strong style="color: #0f172a;">{{ $meeting_title ?? 'अनाम बैठक' }}</strong></td>
            </tr>
            <tr>
                <td class="label">प्रारंभ समय:</td>
                <td><span style="color: #4f46e5; font-weight: 600;">{{ $start_time ?? 'शीघ्र' }}</span></td>
            </tr>
            <tr>
                <td class="label">अवधि:</td>
                <td>{{ $duration ?? '30 मिनट' }}</td>
            </tr>
            <tr>
                <td class="label">आयोजक:</td>
                <td>{{ $organizer ?? 'परियोजना प्रमुख' }}</td>
            </tr>
            <tr>
                <td class="label">स्थान:</td>
                <td>{{ $location ?? 'ऑनलाइन वर्चुअल मीटिंग रूम' }}</td>
            </tr>
        </table>
    </div>

    <div class="btn-wrapper">
        <a href="{{ $meeting_url ?? '#' }}" class="btn">बैठक में शामिल हों</a>
    </div>

    <p style="font-size: 13px; color: #64748b; word-break: break-all;">
        बैठक का सीधा लिंक: <a href="{{ $meeting_url ?? '#' }}" style="color: #4f46e5;">{{ $meeting_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', 'सर्वाधिकार सुरक्षित।')
@section('footer_disclaimer', 'यह एक स्वचालित बैठक सूचना ईमेल है।')
