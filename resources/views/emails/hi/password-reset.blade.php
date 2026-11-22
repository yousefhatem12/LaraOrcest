@extends('emails.layouts.default', ['locale' => 'hi'])

@section('title', 'अपना पासवर्ड रीसेट करें')

@section('content')
    <h2>पासवर्ड रीसेट अनुरोध</h2>
    <p>नमस्ते {{ $name ?? 'उपयोगकर्ता' }},</p>
    <p>हमें आपके {{ config('app.name', 'LaraOrcest') }} खाते के लिए पासवर्ड रीसेट करने का अनुरोध प्राप्त हुआ है। नया पासवर्ड सेट करने के लिए नीचे दिए गए बटन पर क्लिक करें:</p>

    <div class="btn-wrapper">
        <a href="{{ $reset_url ?? '#' }}" class="btn">मेरा पासवर्ड रीसेट करें</a>
    </div>

    <p style="color: #64748b; font-size: 13px;">यह पासवर्ड रीसेट लिंक <strong>{{ $expire_minutes ?? 60 }} मिनट</strong> में समाप्त हो जाएगा।</p>
    <p style="color: #dc2626; font-size: 13px;">⚠️ सुरक्षा चेतावनी: यदि आपने पासवर्ड रीसेट का अनुरोध नहीं किया है, तो कृपया इस ईमेल को अनदेखा करें। आपका पासवर्ड पूरी तरह सुरक्षित रहेगा।</p>

    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
    <p style="font-size: 12px; color: #94a3b8; word-break: break-all;">
        यदि ऊपर दिया गया बटन काम नहीं करता है, तो कृपया निम्न URL को अपने ब्राउज़र में कॉपी और पेस्ट करें:<br>
        <a href="{{ $reset_url ?? '#' }}" style="color: #4f46e5;">{{ $reset_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', 'सर्वाधिकार सुरक्षित।')
@section('footer_disclaimer', 'यह एक स्वचालित सिस्टम ईमेल है, कृपया सीधे उत्तर न दें।')
