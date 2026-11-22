@extends('emails.layouts.default', ['locale' => 'hi'])

@section('title', config('app.name', 'LaraOrcest') . ' में आपका स्वागत है')

@section('content')
    <h2>{{ config('app.name', 'LaraOrcest') }} में आपका स्वागत है!</h2>
    <p>नमस्ते {{ $name ?? 'मित्र' }},</p>
    <p>हमारे समुदाय का हिस्सा बनने के लिए धन्यवाद! आपका खाता सफलतापूर्वक बना दिया गया है और उपयोग के लिए तैयार है। हम आपको बेहतरीन सेवाएं प्रदान करने के लिए प्रतिबद्ध हैं।</p>

    <div class="details-box">
        <p style="margin-top: 0; font-weight: 600; color: #1e293b;">अब आप निम्नलिखित मुख्य क्षमताओं का अनुभव कर सकते हैं:</p>
        <ul style="margin-bottom: 0; padding-left: 20px; color: #475569;">
            <li>🤖 <strong>मल्टी-एजेंट स्वचालन ऑर्केस्ट्रेशन:</strong> परियोजना आवश्यकताओं, वास्तुकला और कोड निर्माण का निर्बाध प्रबंधन।</li>
            <li>🔒 <strong>उद्यम-स्तरीय सुरक्षा:</strong> दो-चरणीय प्रमाणीकरण (2FA) और सैंडबॉक्स्ड सुरक्षित टूल सुरक्षा।</li>
            <li>📅 <strong>अनुसूची और सहयोग प्रबंधन:</strong> त्वरित बैठक अनुस्मारक और बहुभाषी टीम सहयोग।</li>
        </ul>
    </div>

    <div class="btn-wrapper">
        <a href="{{ $login_url ?? url('/login') }}" class="btn">डैशबोर्ड में लॉग इन करें</a>
    </div>

    <p>यदि आपके पास शुरुआत करने के दौरान कोई प्रश्न हैं, तो कृपया हमारी सहायता टीम से संपर्क करें।</p>
@endsection

@section('footer_rights', 'सर्वाधिकार सुरक्षित।')
@section('footer_disclaimer', 'यह एक स्वचालित सिस्टम ईमेल है, कृपया सीधे उत्तर न दें।')
