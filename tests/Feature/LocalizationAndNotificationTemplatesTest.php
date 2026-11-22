<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Feature;

use Illuminate\Support\Facades\View;
use Yousef\LaraOrcest\Mail\MeetingNotificationMail;
use Yousef\LaraOrcest\Mail\MeetingReminderMail;
use Yousef\LaraOrcest\Mail\OtpCodeMail;
use Yousef\LaraOrcest\Mail\PasswordResetMail;
use Yousef\LaraOrcest\Mail\TwoFactorRecoveryMail;
use Yousef\LaraOrcest\Mail\VerifyAccountMail;
use Yousef\LaraOrcest\Mail\WelcomeMail;
use Yousef\LaraOrcest\Tests\TestCase;

class LocalizationAndNotificationTemplatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['view']->addLocation(__DIR__ . '/../../resources/views');
    }

    public function test_chinese_and_hindi_language_resource_files_exist_and_are_valid_json(): void
    {
        $zhPath = __DIR__ . '/../../resources/lang/zh.json';
        $hiPath = __DIR__ . '/../../resources/lang/hi.json';
        $enPath = __DIR__ . '/../../resources/lang/en.json';

        $this->assertFileExists($zhPath);
        $this->assertFileExists($hiPath);
        $this->assertFileExists($enPath);

        $zhJson = json_decode((string) file_get_contents($zhPath), true);
        $hiJson = json_decode((string) file_get_contents($hiPath), true);
        $enJson = json_decode((string) file_get_contents($enPath), true);

        $this->assertIsArray($zhJson, 'zh.json must be valid JSON');
        $this->assertIsArray($hiJson, 'hi.json must be valid JSON');
        $this->assertIsArray($enJson, 'en.json must be valid JSON');

        $this->assertNotEmpty($zhJson);
        $this->assertNotEmpty($hiJson);
        $this->assertNotEmpty($enJson);
    }

    public function test_authentication_error_translations_in_chinese_and_hindi(): void
    {
        $zhJson = json_decode((string) file_get_contents(__DIR__ . '/../../resources/lang/zh.json'), true);
        $hiJson = json_decode((string) file_get_contents(__DIR__ . '/../../resources/lang/hi.json'), true);

        $authKeys = [
            'Unauthenticated.',
            'Unauthorized.',
            'These credentials do not match our records.',
            'The provided password is incorrect.',
            'Your account is locked due to too many failed attempts.',
            'Session expired. Please log in again.',
            'Invalid or expired token.',
            'CSRF token mismatch.',
            'Two-factor authentication code is invalid.',
            'Invalid recovery code.',
        ];

        foreach ($authKeys as $key) {
            $this->assertArrayHasKey($key, $zhJson, "zh.json missing key: {$key}");
            $this->assertArrayHasKey($key, $hiJson, "hi.json missing key: {$key}");
            $this->assertNotEmpty($zhJson[$key]);
            $this->assertNotEmpty($hiJson[$key]);
        }

        // Verify representative Chinese characters and Devanagari Hindi characters
        $this->assertEquals('未认证。', $zhJson['Unauthenticated.']);
        $this->assertEquals('अप्रमाणित।', $hiJson['Unauthenticated.']);

        $this->assertEquals('双重认证代码无效。', $zhJson['Two-factor authentication code is invalid.']);
        $this->assertEquals('दो-चरणीय प्रमाणीकरण कोड अमान्य है।', $hiJson['Two-factor authentication code is invalid.']);
    }

    public function test_validation_messages_translations_in_chinese_and_hindi(): void
    {
        $zhJson = json_decode((string) file_get_contents(__DIR__ . '/../../resources/lang/zh.json'), true);
        $hiJson = json_decode((string) file_get_contents(__DIR__ . '/../../resources/lang/hi.json'), true);

        $validationKeys = [
            'The :attribute field is required.',
            'This field is required.',
            'The email field is required.',
            'The password field is required.',
            'The password format is invalid.',
            'The password must be at least :min characters.',
            'The password must contain at least one uppercase and one lowercase letter.',
            'The password must contain at least one number.',
            'The password must contain at least one special character.',
            'The password confirmation does not match.',
            'Too many attempts. Please try again in :seconds seconds.',
            'Too many requests. Please try again later.',
            'Rate limit exceeded. Try again in :seconds seconds.',
            'Too many login attempts. Please try again in :minutes minutes.',
        ];

        foreach ($validationKeys as $key) {
            $this->assertArrayHasKey($key, $zhJson, "zh.json missing key: {$key}");
            $this->assertArrayHasKey($key, $hiJson, "hi.json missing key: {$key}");
            $this->assertNotEmpty($zhJson[$key]);
            $this->assertNotEmpty($hiJson[$key]);
        }

        $this->assertStringContainsString(':attribute', $zhJson['The :attribute field is required.']);
        $this->assertStringContainsString(':attribute', $hiJson['The :attribute field is required.']);
        $this->assertStringContainsString(':seconds', $zhJson['Rate limit exceeded. Try again in :seconds seconds.']);
        $this->assertStringContainsString(':seconds', $hiJson['Rate limit exceeded. Try again in :seconds seconds.']);
    }

    public function test_http_status_codes_and_api_error_responses_in_chinese_and_hindi(): void
    {
        $zhJson = json_decode((string) file_get_contents(__DIR__ . '/../../resources/lang/zh.json'), true);
        $hiJson = json_decode((string) file_get_contents(__DIR__ . '/../../resources/lang/hi.json'), true);

        $statusKeys = [
            'Bad Request',
            'Unauthorized',
            'Forbidden',
            'Not Found',
            'Method Not Allowed',
            'Conflict',
            'Unprocessable Entity',
            'Too Many Requests',
            'Internal Server Error',
            'Bad Gateway',
            'Service Unavailable',
            'Gateway Timeout',
        ];

        foreach ($statusKeys as $key) {
            $this->assertArrayHasKey($key, $zhJson, "zh.json missing status key: {$key}");
            $this->assertArrayHasKey($key, $hiJson, "hi.json missing status key: {$key}");
            $this->assertNotEmpty($zhJson[$key]);
            $this->assertNotEmpty($hiJson[$key]);
        }

        $this->assertEquals('未找到', $zhJson['Not Found']);
        $this->assertEquals('नहीं मिला', $hiJson['Not Found']);
        $this->assertEquals('服务器内部错误', $zhJson['Internal Server Error']);
        $this->assertEquals('आंतरिक सर्वर त्रुटि', $hiJson['Internal Server Error']);
    }

    public function test_all_chinese_email_templates_render_successfully(): void
    {
        $templates = [
            'emails.zh.verify-account' => [
                'name' => '张伟',
                'verification_url' => 'https://example.com/verify?token=abc',
                'expire_minutes' => 60,
            ],
            'emails.zh.welcome' => [
                'name' => '张伟',
                'login_url' => 'https://example.com/login',
            ],
            'emails.zh.password-reset' => [
                'name' => '张伟',
                'reset_url' => 'https://example.com/reset-password?token=xyz',
                'expire_minutes' => 60,
            ],
            'emails.zh.otp-code' => [
                'name' => '张伟',
                'code' => '889922',
                'expire_minutes' => 10,
            ],
            'emails.zh.two-factor-recovery' => [
                'name' => '张伟',
                'remaining_codes' => 3,
                'login_time' => '2026-11-22 13:00:00',
                'ip_address' => '192.168.1.1',
                'security_settings_url' => 'https://example.com/settings/security',
            ],
            'emails.zh.meeting-reminder' => [
                'name' => '张伟',
                'meeting_title' => '系统架构与编排研讨会',
                'start_time' => '2026-11-22 14:00',
                'duration' => '45 分钟',
                'organizer' => '技术负责人',
                'location' => '腾讯会议 / 线上会议室',
                'meeting_url' => 'https://meet.example.com/room/123',
            ],
            'emails.zh.meeting-notification' => [
                'name' => '张伟',
                'meeting_title' => '产品路线图规划会',
                'start_time' => '2026-11-23 10:00',
                'duration' => '60 分钟',
                'organizer' => '产品经理',
                'description' => '评审第四季度功能与交付清单',
                'rsvp_accept_url' => 'https://example.com/rsvp/accept',
                'rsvp_decline_url' => 'https://example.com/rsvp/decline',
                'rsvp_tentative_url' => 'https://example.com/rsvp/tentative',
                'meeting_url' => 'https://meet.example.com/room/456',
            ],
        ];

        foreach ($templates as $viewName => $data) {
            $html = View::make($viewName, $data)->render();
            $this->assertNotEmpty($html, "View {$viewName} must not render empty");
            $this->assertStringContainsString('<!DOCTYPE html>', $html);
            $this->assertStringContainsString('张伟', $html);
        }
    }

    public function test_all_hindi_email_templates_render_successfully(): void
    {
        $templates = [
            'emails.hi.verify-account' => [
                'name' => 'राहुल शर्मा',
                'verification_url' => 'https://example.com/verify?token=abc',
                'expire_minutes' => 60,
            ],
            'emails.hi.welcome' => [
                'name' => 'राहुल शर्मा',
                'login_url' => 'https://example.com/login',
            ],
            'emails.hi.password-reset' => [
                'name' => 'राहुल शर्मा',
                'reset_url' => 'https://example.com/reset-password?token=xyz',
                'expire_minutes' => 60,
            ],
            'emails.hi.otp-code' => [
                'name' => 'राहुल शर्मा',
                'code' => '554433',
                'expire_minutes' => 10,
            ],
            'emails.hi.two-factor-recovery' => [
                'name' => 'राहुल शर्मा',
                'remaining_codes' => 2,
                'login_time' => '2026-11-22 13:00:00',
                'ip_address' => '10.0.0.1',
                'security_settings_url' => 'https://example.com/settings/security',
            ],
            'emails.hi.meeting-reminder' => [
                'name' => 'राहुल शर्मा',
                'meeting_title' => 'वास्तुकला समीक्षा बैठक',
                'start_time' => '2026-11-22 15:30',
                'duration' => '30 मिनट',
                'organizer' => 'अमित कुमार',
                'location' => 'ऑनलाइन वर्चुअल रूम',
                'meeting_url' => 'https://meet.example.com/room/789',
            ],
            'emails.hi.meeting-notification' => [
                'name' => 'राहुल शर्मा',
                'meeting_title' => 'साप्ताहिक स्प्रिंट योजना',
                'start_time' => '2026-11-23 11:00',
                'duration' => '45 मिनट',
                'organizer' => 'प्रिया वर्मा',
                'description' => 'नए फीचर्स और बग फिक्स की समीक्षा',
                'rsvp_accept_url' => 'https://example.com/rsvp/accept',
                'rsvp_decline_url' => 'https://example.com/rsvp/decline',
                'rsvp_tentative_url' => 'https://example.com/rsvp/tentative',
                'meeting_url' => 'https://meet.example.com/room/999',
            ],
        ];

        foreach ($templates as $viewName => $data) {
            $html = View::make($viewName, $data)->render();
            $this->assertNotEmpty($html, "View {$viewName} must not render empty");
            $this->assertStringContainsString('<!DOCTYPE html>', $html);
            $this->assertStringContainsString('राहुल शर्मा', $html);
        }
    }

    public function test_mailable_classes_build_chinese_and_hindi_emails(): void
    {
        // 1. VerifyAccountMail
        $mailZh = new VerifyAccountMail(['name' => '李明', 'verification_url' => 'https://test.com/v'], 'zh');
        $mailZh->build();
        $this->assertEquals('验证您的电子邮件地址', $mailZh->subject);
        $this->assertEquals('emails.zh.verify-account', $mailZh->view);

        $mailHi = new VerifyAccountMail(['name' => 'विजय', 'verification_url' => 'https://test.com/v'], 'hi');
        $mailHi->build();
        $this->assertEquals('अपना ईमेल पता सत्यापित करें', $mailHi->subject);
        $this->assertEquals('emails.hi.verify-account', $mailHi->view);

        // 2. WelcomeMail
        $welcomeZh = new WelcomeMail(['name' => '李明'], 'zh');
        $welcomeZh->build();
        $this->assertStringContainsString('欢迎加入', $welcomeZh->subject);

        $welcomeHi = new WelcomeMail(['name' => 'विजय'], 'hi');
        $welcomeHi->build();
        $this->assertStringContainsString('में आपका स्वागत है', $welcomeHi->subject);

        // 3. PasswordResetMail
        $resetZh = new PasswordResetMail(['reset_url' => 'https://test.com/r'], 'zh');
        $resetZh->build();
        $this->assertEquals('重置您的账户密码', $resetZh->subject);

        $resetHi = new PasswordResetMail(['reset_url' => 'https://test.com/r'], 'hi');
        $resetHi->build();
        $this->assertEquals('अपना पासवर्ड रीसेट करें', $resetHi->subject);

        // 4. OtpCodeMail
        $otpZh = new OtpCodeMail(['code' => '998877'], 'zh');
        $otpZh->build();
        $this->assertEquals('您的一次性安全验证码 (OTP)', $otpZh->subject);

        $otpHi = new OtpCodeMail(['code' => '998877'], 'hi');
        $otpHi->build();
        $this->assertEquals('आपका सुरक्षा वन-टाइम पासवर्ड (OTP)', $otpHi->subject);

        // 5. TwoFactorRecoveryMail
        $twoFaZh = new TwoFactorRecoveryMail(['remaining_codes' => 4], 'zh');
        $twoFaZh->build();
        $this->assertEquals('双重认证 (2FA) 备用恢复代码使用提醒', $twoFaZh->subject);

        $twoFaHi = new TwoFactorRecoveryMail(['remaining_codes' => 4], 'hi');
        $twoFaHi->build();
        $this->assertEquals('दो-चरणीय प्रमाणीकरण (2FA) पुनर्प्राप्ति कोड सूचना', $twoFaHi->subject);

        // 6. MeetingReminderMail
        $reminderZh = new MeetingReminderMail(['meeting_title' => '系统评审'], 'zh');
        $reminderZh->build();
        $this->assertEquals('会议日程提醒：系统评审', $reminderZh->subject);

        $reminderHi = new MeetingReminderMail(['meeting_title' => 'सिस्टम समीक्षा'], 'hi');
        $reminderHi->build();
        $this->assertEquals('बैठक अनुस्मारक: सिस्टम समीक्षा', $reminderHi->subject);

        // 7. MeetingNotificationMail
        $notifZh = new MeetingNotificationMail(['meeting_title' => '技术研讨'], 'zh');
        $notifZh->build();
        $this->assertEquals('新会议邀请：技术研讨', $notifZh->subject);

        $notifHi = new MeetingNotificationMail(['meeting_title' => 'तकनीकी कार्यशाला'], 'hi');
        $notifHi->build();
        $this->assertEquals('नई बैठक आमंत्रण: तकनीकी कार्यशाला', $notifHi->subject);
    }
}
