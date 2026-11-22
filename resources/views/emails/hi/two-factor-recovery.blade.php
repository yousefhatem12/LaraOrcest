@extends('emails.layouts.default', ['locale' => 'hi'])

@section('title', 'दो-चरणीय प्रमाणीकरण (2FA) पुनर्प्राप्ति कोड सूचना')

@section('content')
    <h2 style="color: #b45309;">दो-चरणीय प्रमाणीकरण (2FA) सुरक्षा चेतावनी</h2>
    <p>नमस्ते {{ $name ?? 'उपयोगकर्ता' }},</p>
    <p>आपके {{ config('app.name', 'LaraOrcest') }} खाते में <strong>दो-चरणीय प्रमाणीकरण (2FA) बैकअप पुनर्प्राप्ति कोड</strong> का उपयोग करके सफलतापूर्वक लॉगिन किया गया है।</p>

    <div class="details-box">
        <table>
            <tr>
                <td class="label">लॉगिन समय:</td>
                <td>{{ $login_time ?? date('Y-m-d H:i:s') }}</td>
            </tr>
            <tr>
                <td class="label">आईपी पता:</td>
                <td>{{ $ip_address ?? 'गोपनीय' }}</td>
            </tr>
            <tr>
                <td class="label">शेष कोड:</td>
                <td><strong style="color: {{ ($remaining_codes ?? 0) <= 2 ? '#dc2626' : '#16a34a' }};">{{ $remaining_codes ?? 0 }} कोड</strong></td>
            </tr>
        </table>
    </div>

    @if(($remaining_codes ?? 0) <= 2)
        <div style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; margin: 16px 0; color: #991b1b; font-size: 13px;">
            ⚠️ आपके पास बहुत कम पुनर्प्राप्ति कोड शेष हैं। हम दृढ़ता से अनुशंसा करते हैं कि आप अपने खाते में लॉगिन करें और नए कोड जनरेट करें ताकि आप कभी भी अपने खाते से लॉक न हों।
        </div>
    @endif

    <div class="btn-wrapper">
        <a href="{{ $security_settings_url ?? url('/settings/security') }}" class="btn btn-secondary">सुरक्षा सेटिंग्स जांचें</a>
    </div>

    <p style="color: #dc2626; font-size: 13px;">🚨 यदि यह कार्रवाई आपके द्वारा नहीं की गई है, तो आपका खाता खतरे में हो सकता है। कृपया तुरंत अपना पासवर्ड रीसेट करें और हमारे समर्थन से संपर्क करें।</p>
@endsection

@section('footer_rights', 'सर्वाधिकार सुरक्षित।')
@section('footer_disclaimer', 'यह एक स्वचालित सुरक्षा अलर्ट ईमेल है।')
