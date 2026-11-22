<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name', 'LaraOrcest'))</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f6f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #334155;
            -webkit-font-smoothing: antialiased;
        }
        .wrapper {
            width: 100%;
            background-color: #f4f6f9;
            padding: 40px 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .header {
            background: #1e293b;
            color: #ffffff;
            padding: 24px 32px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .content {
            padding: 32px;
            line-height: 1.6;
            font-size: 15px;
        }
        .content h2 {
            color: #0f172a;
            font-size: 18px;
            margin-top: 0;
            margin-bottom: 16px;
        }
        .btn-wrapper {
            text-align: center;
            margin: 28px 0;
        }
        .btn {
            display: inline-block;
            background-color: #4f46e5;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
        }
        .btn-success { background-color: #10b981; }
        .btn-danger { background-color: #ef4444; }
        .btn-secondary { background-color: #64748b; }
        .code-box {
            background-color: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 6px;
            padding: 16px;
            text-align: center;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 6px;
            color: #1e293b;
            margin: 20px 0;
            font-family: monospace;
        }
        .details-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 16px;
            margin: 20px 0;
        }
        .details-box table {
            width: 100%;
            border-collapse: collapse;
        }
        .details-box td {
            padding: 8px 4px;
            font-size: 14px;
            vertical-align: top;
        }
        .details-box td.label {
            font-weight: 600;
            color: #475569;
            width: 120px;
        }
        .footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 20px 32px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
        }
        .footer p {
            margin: 4px 0;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <h1>{{ config('app.name', 'LaraOrcest') }}</h1>
            </div>
            <div class="content">
                @yield('content')
            </div>
            <div class="footer">
                <p>&copy; {{ date('Y') }} {{ config('app.name', 'LaraOrcest') }}. @yield('footer_rights', 'All rights reserved.')</p>
                <p>@yield('footer_disclaimer', 'This is an automated system email, please do not reply directly.')</p>
            </div>
        </div>
    </div>
</body>
</html>
