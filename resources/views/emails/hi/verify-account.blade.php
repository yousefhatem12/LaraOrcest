@extends('emails.layouts.default', ['locale' => 'hi'])

@section('title', 'अपना ईमेल पता सत्यापित करें')

@section('content')
    <h2>अपना ईमेल पता सत्यापित करें</h2>
    <p>नमस्ते {{ $name ?? 'उपयोगकर्ता' }},</p>
    <p>{{ config('app.name', 'LaraOrcest') }} में पंजीकरण करने के लिए धन्यवाद! अपने खाते की सुरक्षा सुनिश्चित करने और सभी सुविधाओं को सक्रिय करने के लिए, कृपया नीचे दिए गए बटन पर क्लिक करके अपना ईमेल पता सत्यापित करें:</p>
    
    <div class="btn-wrapper">
        <a href="{{ $verification_url ?? '#' }}" class="btn">मेरा खाता सत्यापित करें</a>
    </div>

    <p style="color: #64748b; font-size: 13px;">यह सत्यापन लिंक <strong>{{ $expire_minutes ?? 60 }} मिनट</strong> में समाप्त हो जाएगा।</p>
    <p style="color: #64748b; font-size: 13px;">यदि आपने कोई खाता नहीं बनाया है, तो किसी और कार्रवाई की आवश्यकता नहीं है।</p>

    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
    <p style="font-size: 12px; color: #94a3b8; word-break: break-all;">
        यदि ऊपर दिया गया बटन काम नहीं करता है, तो कृपया निम्न URL को अपने ब्राउज़र में कॉपी और पेस्ट करें:<br>
        <a href="{{ $verification_url ?? '#' }}" style="color: #4f46e5;">{{ $verification_url ?? '#' }}</a>
    </p>
@endsection

@section('footer_rights', 'सर्वाधिकार सुरक्षित।')
@section('footer_disclaimer', 'यह एक स्वचालित सिस्टम ईमेल है, कृपया सीधे उत्तर न दें।')
