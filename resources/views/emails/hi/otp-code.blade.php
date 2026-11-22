@extends('emails.layouts.default', ['locale' => 'hi'])

@section('title', 'आपका सुरक्षा वन-टाइम पासवर्ड (OTP)')

@section('content')
    <h2>वन-टाइम सुरक्षा सत्यापन कोड (OTP)</h2>
    <p>नमस्ते {{ $name ?? 'उपयोगकर्ता' }},</p>
    <p>आप एक संवेदनशील ऑपरेशन कर रहे हैं जिसके लिए सुरक्षा सत्यापन आवश्यक है। पहचान की पुष्टि करने के लिए कृपया निम्नलिखित 6-अंकीय कोड का उपयोग करें:</p>

    <div class="code-box">
        {{ $code ?? '123456' }}
    </div>

    <p style="text-align: center; color: #64748b; font-size: 13px;">
        यह कोड <strong>{{ $expire_minutes ?? 10 }} मिनट</strong> के लिए मान्य है। इस कोड को किसी के साथ साझा न करें।
    </p>

    <div class="details-box" style="background-color: #fffbeb; border-color: #fde68a;">
        <p style="margin: 0; color: #b45309; font-size: 13px;">
            ⚠️ <strong>महत्वपूर्ण सुरक्षा चेतावनी:</strong> {{ config('app.name', 'LaraOrcest') }} की टीम कभी भी आपसे फोन, संदेश या ईमेल के माध्यम से यह कोड नहीं मांगेगी। यदि आपने इसका अनुरोध नहीं किया है, तो तुरंत अपना खाता सुरक्षित करें।
        </p>
    </div>
@endsection

@section('footer_rights', 'सर्वाधिकार सुरक्षित।')
@section('footer_disclaimer', 'यह एक स्वचालित सिस्टम ईमेल है, कृपया सीधे उत्तर न दें।')
