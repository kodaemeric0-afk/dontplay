<?php
include('../common/includes.php');
include('../firewall.php');
?>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once('../config.php');
if (empty($_SESSION['panel_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['panel_password'])) {
        if (hash_equals($MotDePassePanel, $_POST['panel_password'])) {
            $_SESSION['panel_logged_in'] = true;
            header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
            exit;
        } else {
            $error = "Incorrect password.";
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Panel Login</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="https://unpkg.com/lucide@latest/dist/lucide.css">
        <style>
            body {
                background: #111;
                color: #fff;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                margin: 0;
                overflow: hidden;
            }
            .login-bg-shape {
                position: absolute;
                width: 600px;
                height: 600px;
                background: radial-gradient(circle at 60% 40%, #222 60%, #111 100%);
                border-radius: 50%;
                top: -180px;
                left: -180px;
                z-index: 0;
                animation: floatShape 6s ease-in-out infinite alternate;
                opacity: 0.7;
            }
            .login-bg-shape2 {
                position: absolute;
                width: 400px;
                height: 400px;
                background: linear-gradient(135deg, #333 60%, #111 100%);
                border-radius: 40% 60% 60% 40%/60% 40% 60% 40%;
                bottom: -120px;
                right: -120px;
                z-index: 0;
                opacity: 0.5;
                animation: morphShape 7s ease-in-out infinite alternate;
            }
            @keyframes floatShape {
                0% { transform: translateY(0) scale(1);}
                100% { transform: translateY(40px) scale(1.05);}
            }
            @keyframes morphShape {
                0% { border-radius: 40% 60% 60% 40%/60% 40% 60% 40%; }
                100% { border-radius: 60% 40% 40% 60%/40% 60% 40% 60%; }
            }
            .login-container {
                background: rgba(24,24,24,0.98);
                padding: 2.5rem 2rem 2rem 2rem;
                border-radius: 18px;
                box-shadow: 0 8px 48px 0 rgba(0,0,0,0.28);
                min-width: 320px;
                width: 100%;
                max-width: 370px;
                position: relative;
                z-index: 2;
                overflow: visible;
            }
            .login-icon {
                display: flex;
                justify-content: center;
                align-items: center;
                margin-bottom: 1.2rem;
                animation: popIcon 1.2s cubic-bezier(.68,-0.55,.27,1.55);
            }
            @keyframes popIcon {
                0% { transform: scale(0.2) rotate(-30deg); opacity: 0; }
                60% { transform: scale(1.2) rotate(10deg); opacity: 1; }
                100% { transform: scale(1) rotate(0deg);}
            }
            .login-icon svg {
                width: 80px;
                height: 80px;
                color: #fff;
                background: linear-gradient(135deg, #222 60%, #444 100%);
                border-radius: 50%;
                box-shadow: 0 4px 32px #0008;
                padding: 18px;
                border: 3px solid #333;
            }
            .login-title {
                font-size: 1.5rem;
                font-weight: 700;
                margin-bottom: 1.2rem;
                text-align: center;
                color: #fff;
                letter-spacing: 0.04em;
                text-shadow: 0 2px 8px #0006;
            }
            .login-error {
                color: #e11d48;
                margin-bottom: 1rem;
                text-align: center;
                font-size: 1.1rem;
                animation: shake 0.4s;
            }
            @keyframes shake {
                0% { transform: translateX(0);}
                20% { transform: translateX(-8px);}
                40% { transform: translateX(8px);}
                60% { transform: translateX(-6px);}
                80% { transform: translateX(6px);}
                100% { transform: translateX(0);}
            }
            .login-input {
                width: 100%;
                padding: 1.1rem 1.2rem;
                border: 2px solid #333;
                border-radius: 8px;
                background: #181818;
                color: #fff;
                font-size: 1.15rem;
                margin-bottom: 1.3rem;
                outline: none;
                transition: border 0.2s, box-shadow 0.2s;
                box-sizing: border-box;
                display: block;
                letter-spacing: 0.12em;
                font-family: inherit;
                box-shadow: 0 2px 12px #0002;
            }
            .login-input:focus {
                border-color: #fff;
                box-shadow: 0 0 0 2px #fff2;
                background: #222;
            }
            .login-btn {
                width: 60%;
                padding: 0.9rem 1rem;
                background: linear-gradient(90deg, #fff 60%, #bbb 100%);
                color: #111;
                border: none;
                border-radius: 8px;
                font-size: 1.1rem;
                font-weight: 700;
                cursor: pointer;
                transition: background 0.18s, color 0.18s, transform 0.12s;
                display: block;
                margin: 0 auto;
                box-shadow: 0 2px 8px #0003;
                letter-spacing: 0.08em;
                position: relative;
                overflow: hidden;
            }
            .login-btn:active {
                transform: scale(0.97);
            }
            .login-btn::after {
                content: '';
                position: absolute;
                left: 0; top: 0; right: 0; bottom: 0;
                background: linear-gradient(90deg, #fff2 0%, #fff0 100%);
                opacity: 0;
                transition: opacity 0.2s;
                pointer-events: none;
            }
            .login-btn:hover::after {
                opacity: 1;
            }
            .login-btn .btn-icon {
                vertical-align: middle;
                margin-right: 0.5em;
                font-size: 1.2em;
                display: inline-block;
                position: relative;
                top: 2px;
            }
            .login-footer {
                margin-top: 1.8rem;
                text-align: center;
                color: #888;
                font-size: 0.95em;
                letter-spacing: 0.04em;
                opacity: 0.7;
            }
            @media (max-width: 600px) {
                .login-container { min-width: 0; max-width: 98vw; }
                .login-bg-shape, .login-bg-shape2 { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="login-bg-shape"></div>
        <div class="login-bg-shape2"></div>
        <form class="login-container" method="post" autocomplete="off">
            <div class="login-icon">
                <!-- Huge lock icon SVG (lucide) -->
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <rect width="16" height="10" x="4" y="11" rx="2" />
                    <path d="M8 11V7a4 4 0 1 1 8 0v4" />
                </svg>
            </div>
            <div class="login-title">Panel Login</div>
            <?php if (!empty($error)): ?>
                <div class="login-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <input class="login-input" type="password" name="panel_password" placeholder="Password" required autofocus>
            <button class="login-btn" type="submit">
                <span class="btn-icon">
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
                    <i class="fa fa-sign-in" aria-hidden="true"></i>
                </span>
            </button>
            <div class="login-footer">
                2025 &bull; v1.0.2
            </div>
        </form>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Analytics Panel</title>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0a0a0a;
            color: #e5e5e5;
            line-height: 1.6;
            min-height: 100vh;
        }
        .app-container {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 250px;
            background: #1a1a1a;
            border-right: 1px solid #333333;
            padding: 2rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 0 1.5rem 2rem;
            border-bottom: 1px solid #333333;
            margin-bottom: 2rem;
        }
        .sidebar-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }
        .sidebar-subtitle {
            color: #a3a3a3;
            font-size: 0.875rem;
        }
        .nav-menu {
            list-style: none;
        }
        .nav-item {
            margin-bottom: 0.5rem;
        }
        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #a3a3a3;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .nav-link:hover {
            background: rgba(64, 64, 64, 0.5);
            color: #ffffff;
        }
        .nav-link.active {
            background: #404040;
            color: white;
            border-right: 3px solid #666666;
        }
        .nav-icon {
            width: 20px;
            height: 20px;
            margin-right: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .overview-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
        }
        .clicks-icon { background: #525252; }
        .billings-icon { background: #404040; }
        .cards-icon { background: #595959; }
        .otp-icon { background: #4a4a4a; }
        .overview-icon { background: #525252; }
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }
        .page-header {
            margin-bottom: 2rem;
        }
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }
        .page-subtitle {
            color: #a3a3a3;
            font-size: 1rem;
        }
        .content-section {
            display: none;
        }
        .content-section.active {
            display: block;
        }
        .data-panel {
            background: #1a1a1a;
            border-radius: 12px;
            padding: 2rem;
            border: 1px solid #333333;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.5);
        }
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #333333;
        }
        .panel-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #ffffff;
        }
        .panel-icon {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.875rem;
        }
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }
        .metric-label {
            color: #a3a3a3;
            font-size: 0.875rem;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-active { background: #525252; color: white; }
        .status-pending { background: #737373; color: white; }
        .status-failed { background: #404040; color: white; }
        .status-success { background: #525252; color: white; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            background: #0f0f0f;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }
        .data-table thead {
            background: #1a1a1a;
        }
        .data-table th {
            padding: 1rem 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #f5f5f5;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #333333;
        }
        .data-table td {
            padding: 0.875rem 0.75rem;
            color: #e5e5e5;
            border-bottom: 1px solid #333333;
            font-size: 0.875rem;
        }
        .data-table tbody tr {
            transition: background-color 0.2s ease;
        }
        .data-table tbody tr:hover {
            background: rgba(64, 64, 64, 0.3);
        }
        .data-table tbody tr:last-child td {
            border-bottom: none;
        }
        .data-table tbody tr:nth-child(even) {
            background: rgba(15, 15, 15, 0.5);
        }
        .data-table tbody tr:nth-child(odd) {
            background: rgba(26, 26, 26, 0.2);
        }
        @media (max-width: 768px) {
            .data-table {
                font-size: 0.75rem;
            }
            .data-table th,
            .data-table td {
                padding: 0.5rem 0.375rem;
            }
            .data-table th {
                font-size: 0.75rem;
            }
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            .mobile-menu-btn {
                display: block;
                position: fixed;
                top: 1rem;
                left: 1rem;
                z-index: 1000;
                background: #525252;
                color: white;
                border: none;
                padding: 0.5rem;
                border-radius: 6px;
                cursor: pointer;
            }
        }
        .mobile-menu-btn {
            display: none;
        }
        .overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .overview-card {
            background: #1a1a1a;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #333333;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.5);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .overview-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 12px -1px rgba(0, 0, 0, 0.6);
        }
        .overview-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        .overview-header h3 {
            color: #ffffff;
            font-size: 1.125rem;
            font-weight: 600;
            margin-left: 0.75rem;
        }
        .overview-stats {
            text-align: center;
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #d4d4d4;
            margin-bottom: 0.25rem;
        }
        .stat-label {
            color: #a3a3a3;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .flag-img {
            height: auto;
            width: 45px;
            object-fit: contain;
            vertical-align: middle;
            border: 1px solid #fff;
            box-shadow: 0 2px 8px 0 rgba(255,255,255,0.12);
            background: #181818;
        }
        .cc-card-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem 2.5%;
            justify-content: flex-start;
        }
        .cc-card-preview {
            background: #191919;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.18);
            padding: 1.2rem 1.2rem 1rem 1.2rem;
            margin-bottom: 2rem;
            width: 30%;
            min-width: 220px;
            max-width: 340px;
            display: flex;
            flex-direction: row;
            align-items: stretch;
            cursor: pointer;
            transition: box-shadow 0.18s, transform 0.18s;
            position: relative;
        }
        .cc-card-preview:hover, .cc-card-preview:focus {
            box-shadow: 0 8px 24px rgba(0,0,0,0.32);
            transform: translateY(-2px) scale(1.03);
            outline: 2px solid #444;
        }
        .cc-card-preview-imgbox {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 80px;
            max-width: 80px;
            width: 80px;
            height: 100%;
            margin-right: 1.1rem;
        }
        .cc-card-preview img {
            width: 90px;
            height: 56px;
            object-fit: contain;
            border-radius: 8px;
            background: #222;
            border: 1px solid #444;
            margin-bottom: 0;
            display: block;
        }
        .cc-card-preview-content {
            display: flex;
            flex-direction: column;
            justify-content: center;
            flex: 1;
            min-width: 0;
        }
        .cc-card-preview .cc-line {
            font-family: 'Fira Mono', 'Consolas', monospace;
            font-size: 1.08rem;
            color: #e5e5e5;
            background: none;
            border: none;
            padding: 0;
            margin: 0;
            line-height: 1.2;
            text-align: left;
            width: 100%;
            word-break: break-all;
        }
        .cc-card-preview .cc-line + .cc-line {
            margin-top: 0.2rem;
        }
        .cc-card-preview .scan-label {
            margin-top: 0.7rem;
            color: #b3b3b3;
            font-size: 0.92rem;
            font-weight: 500;
            letter-spacing: 0.04em;
            text-align: left;
        }
        .modal-overlay {
            position: fixed;
            z-index: 9999;
            left: 0; top: 0; right: 0; bottom: 0;
            width: 100vw;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: #181818;
            border-radius: 12px;
            padding: 2rem 1.5rem 1.5rem 1.5rem;
            min-width: 390px;
            max-width: 95vw;
            box-shadow: 0 8px 32px rgba(0,0,0,0.7);
            color: #e5e5e5;
            position: relative;
            max-height: 95%;
        }
        .modal-close-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            color: #aaa;
            font-size: 1.5rem;
            cursor: pointer;
        }
        .modal-content h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.25rem;
            color: #fff;
        }
        .modal-details-list {
            display: flex;
            flex-direction: column;
            gap: 1.1rem;
            margin-top: 1.2rem;
        }
        .modal-details-row {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            background: #232323;
            border-radius: 8px;
            padding: 0.45rem 0.7rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.13);
            min-height: 0;
        }
        .modal-details-icon {
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #222;
            border-radius: 6px;
            color: #b3b3b3;
            font-size: 1.2rem;
            margin-left: 10px;
        }
        .modal-details-label {
            font-size: 0.98rem;
            color: #b3b3b3;
            min-width: 90px;
            font-weight: 500;
            margin-right: 0.5rem;
            letter-spacing: 0.01em;
        }
        .modal-details-value {
            font-size: 1.08rem;
            color: #e5e5e5;
            font-family: 'Fira Mono', 'Consolas', monospace;
            word-break: break-all;
            flex: 1;
        }
        .modal-details-section-title {
            font-size: 1.08rem;
            font-weight: 600;
            margin: 1.2rem 0 0.3rem 0;
            letter-spacing: 0.03em;
        }
        .modal-details-row:not(:last-child) {
            border-bottom: 1px solid #292929;
        }
        @media (max-width: 600px) {
            .modal-content {
                padding: 1.1rem 0.5rem 0.7rem 0.5rem;
                min-width: 0;
            }
            .modal-details-row {
                flex-direction: column;
                gap: 0.5rem;
                padding: 0.7rem 0.6rem;
            }
            .modal-details-label {
                min-width: 0;
            }
            .cc-card-preview {
                flex-direction: column;
                align-items: center;
            }
            .cc-card-preview-imgbox {
                margin-right: 0;
                margin-bottom: 0.7rem;
                min-width: 0;
                max-width: none;
                width: 100%;
                justify-content: center;
            }
            .cc-card-preview-content {
                align-items: center;
                text-align: center;
            }
            .cc-card-preview .cc-line,
            .cc-card-preview .scan-label {
                text-align: center;
            }
        }
        .modal-data-table {
            display: none;
        }
        @media (max-width: 1100px) {
            .cc-card-preview { width: 45%; }
        }
        @media (max-width: 700px) {
            .cc-card-preview { width: 95%; min-width: 0; }
            .cc-card-grid { gap: 1.2rem 0; }
        }
        .bar-chart-container {
            width: 100%;
            max-width: 700px;
            margin: 2rem auto 0 auto;
            padding: 1.5rem 1rem 1.5rem 1rem;
            background: #181818;
            border-radius: 12px;
            border: 1px solid #333333;
            box-shadow: 0 2px 8px rgba(0,0,0,0.18);
        }
        .bar-chart-title {
            font-size: 1.15rem;
            font-weight: 600;
            color: #fff;
            margin-bottom: 1.2rem;
            text-align: center;
            letter-spacing: 0.01em;
        }
        .bar-chart {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 1.1rem;
        }
        .bar-row {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .bar-label {
            min-width: 90px;
            color: #b3b3b3;
            font-size: 1rem;
            font-weight: 500;
            text-align: right;
        }
        .bar-bar {
            flex: 1;
            height: 22px;
            border-radius: 7px;
            background: #232323;
            position: relative;
            overflow: hidden;
        }
        .bar-fill {
            height: 100%;
            border-radius: 7px;
            display: block;
            transition: width 0.5s;
        }
        .bar-value {
            min-width: 60px;
            text-align: left;
            color: #e5e5e5;
            font-size: 1rem;
            font-family: 'Fira Mono', 'Consolas', monospace;
            margin-left: 0.7rem;
        }
        .bar-fill-click { background: linear-gradient(90deg, #6366f1 0%, #818cf8 100%); }
        .bar-fill-billing { background: linear-gradient(90deg, #f59e42 0%, #fbbf24 100%); }
        .bar-fill-cc { background: linear-gradient(90deg, #10b981 0%, #34d399 100%); }
        .bar-fill-pin { background: linear-gradient(90deg, #f43f5e 0%, #fb7185 100%); }
        .bar-fill-sms { background: linear-gradient(90deg, #38bdf8 0%, #0ea5e9 100%); }
        @media (max-width: 900px) {
            .bar-chart-container { max-width: 98vw; }
        }
        @media (max-width: 600px) {
            .bar-label { min-width: 60px; font-size: 0.92rem; }
            .bar-value { min-width: 40px; font-size: 0.92rem; }
            .bar-row { gap: 0.5rem; }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">☰</button>
    <div class="app-container">
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h1 class="sidebar-title">Analytics</h1>
                <p class="sidebar-subtitle">Data Dashboard</p>
            </div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a class="nav-link active" onclick="showPage('overview')">
                        <div class="nav-icon">
                            <i data-lucide="layout-dashboard"></i>
                        </div>
                        Overview
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" onclick="showPage('click')">
                        <div class="nav-icon">
                            <i data-lucide="mouse-pointer-click"></i>
                        </div>
                        Clicks
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" onclick="showPage('info')">
                        <div class="nav-icon">
                            <i data-lucide="user"></i>
                        </div>
                        Infos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" onclick="showPage('billing')">
                        <div class="nav-icon">
                            <i data-lucide="file-text"></i>
                        </div>
                        Billings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" onclick="showPage('cc')">
                        <div class="nav-icon">
                            <i data-lucide="credit-card"></i>
                        </div>
                        Cards
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" onclick="showPage('sms')">
                        <div class="nav-icon">
                            <i data-lucide="message-square"></i>
                        </div>
                        SMS
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="clear.php" onclick="return confirm('Are you sure you want to clear all data?');" style="width:100%;text-align:left;">
                        <div class="nav-icon">
                            <i data-lucide="trash-2"></i>
                        </div>
                        Clear Data
                    </a>
                </li>
            </ul>
            <form method="post" action="" style="position: absolute; bottom: 1.2rem; left: 0; width: 100%; margin: 0; padding: 0 1rem;" id="logout-form">
                <button type="submit" name="logout" class="logout-btn"
                    style="
                        width: 100%;
                        background: linear-gradient(90deg, #232323 60%, #1a1a1a 100%);
                        color: #fff;
                        border: none;
                        border-radius: 8px;
                        padding: 0.55rem 1rem;
                        font-size: 1.05rem;
                        font-weight: 600;
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                        justify-content: flex-end;
                        gap: 0.6em;
                        box-shadow: 0 2px 8px 0 rgba(0,0,0,0.13);
                        transition: background 0.2s, box-shadow 0.2s, transform 0.1s;
                        font-family: 'Segoe UI', 'Inter', 'Arial', 'Helvetica Neue', sans-serif;
                    "
                    onmouseover="this.style.background='#313131'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 16px 0 rgba(0,0,0,0.18)';"
                    onmouseout="this.style.background='linear-gradient(90deg, #232323 60%, #1a1a1a 100%)'; this.style.transform='none'; this.style.boxShadow='0 2px 8px 0 rgba(0,0,0,0.13)';"
                >
                    Sign Out <i data-lucide="log-out" style="font-size:1.1em; margin-left:0.5em;"></i>
                </button>
            </form>
        </nav>
        <?php
        if (isset($_POST['logout'])) {
            session_unset();
            session_destroy();
            echo "<script>window.location.reload();</script>";
            exit;
        }
        ?>
        <main class="main-content">
            <div id="overview-page" class="content-section active">
                <div class="page-header">
                    <h1 class="page-title">Dashboard Overview</h1>
                    <p class="page-subtitle">Summary of all data sections and statistics</p>
                </div>
                <div class="overview-grid">
                    <div class="overview-card">
                        <div class="overview-header">
                            <div class="overview-icon clicks-icon">
                                <i data-lucide="mouse-pointer-click"></i>
                            </div>
                            <h3>Clicks</h3>
                        </div>
                        <div class="overview-stats">
                            <div class="stat-number" id="click-count">-</div>
                            <div class="stat-label">Total Records</div>
                        </div>
                    </div>
                    <div class="overview-card">
                        <div class="overview-header">
                            <div class="overview-icon clicks-icon">
                                <i data-lucide="user"></i>
                            </div>
                            <h3>Infos</h3>
                        </div>
                        <div class="overview-stats">
                            <div class="stat-number" id="info-count">-</div>
                            <div class="stat-label">Total Records</div>
                        </div>
                    </div>
                    <div class="overview-card">
                        <div class="overview-header">
                            <div class="overview-icon billings-icon">
                                <i data-lucide="file-text"></i>
                            </div>
                            <h3>Billings</h3>
                        </div>
                        <div class="overview-stats">
                            <div class="stat-number" id="billing-count">-</div>
                            <div class="stat-label">Total Records</div>
                        </div>
                    </div>
                    <div class="overview-card">
                        <div class="overview-header">
                            <div class="overview-icon cards-icon">
                                <i data-lucide="credit-card"></i>
                            </div>
                            <h3>Cards</h3>
                        </div>
                        <div class="overview-stats">
                            <div class="stat-number" id="cc-count">-</div>
                            <div class="stat-label">Total Records</div>
                        </div>
                    </div>
                    <div class="overview-card">
                        <div class="overview-header">
                            <div class="overview-icon otp-icon">
                                <i data-lucide="message-square"></i>
                            </div>
                            <h3>SMS</h3>
                        </div>
                        <div class="overview-stats">
                            <div class="stat-number" id="sms-count">-</div>
                            <div class="stat-label">Total Records</div>
                        </div>
                    </div>
                </div>
                <div class="bar-chart-container" id="overview-bar-chart-container" style="display:none;">
                    <div class="bar-chart-title" style="text-align:center;">
                        Statistiques de la rez // Ameli Remboursement
                    </div>
                    <div class="bar-chart-update" style="color:#a3a3a3;font-size:0.98em;margin-bottom:1.2rem;text-align:center;">
                        Dernière mise à jour le <span id="bar-chart-update-date"></span>
                    </div>
                    <div class="bar-chart" id="overview-bar-chart"></div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            function formatDateTime(d) {
                                var day = String(d.getDate()).padStart(2, '0');
                                var month = String(d.getMonth() + 1).padStart(2, '0');
                                var year = d.getFullYear();
                                var hour = String(d.getHours()).padStart(2, '0');
                                var min = String(d.getMinutes()).padStart(2, '0');
                                var sec = String(d.getSeconds()).padStart(2, '0');
                                return day + '/' + month + '/' + year + ' à ' + hour + ':' + min;
                            }
                            var now = new Date();
                            var dateElem = document.getElementById('bar-chart-date');
                            var updateElem = document.getElementById('bar-chart-update-date');
                            if (dateElem) {
                                dateElem.textContent = formatDateTime(now);
                            }
                            if (updateElem) {
                                updateElem.textContent = formatDateTime(now);
                            }
                        });
                    </script>
                </div>
            </div>
            <div id="click-page" class="content-section">
                <div class="page-header">
                    <h1 class="page-title">Clicks Analytics</h1>
                    <p class="page-subtitle">Monitor click performance across your platform</p>
                </div>
                <div class="data-panel">
                    <p style="color: #94a3b8; text-align: center; padding: 2rem;">Loading data...</p>
                </div>
            </div>
            <div id="info-page" class="content-section">
                <div class="page-header">
                    <h1 class="page-title">User Infos</h1>
                    <p class="page-subtitle">View all infos submission details and activity</p>
                </div>
                <div class="data-panel">
                    <p style="color: #94a3b8; text-align: center; padding: 2rem;">Loading data...</p>
                </div>
            </div>
            <div id="billing-page" class="content-section">
                <div class="page-header">
                    <h1 class="page-title">Billing Overview</h1>
                    <p class="page-subtitle">Review all billing information collected from your submissions.</p>
                </div>
                <div class="data-panel">
                    <p style="color: #94a3b8; text-align: center; padding: 2rem;">Loading data...</p>
                </div>
            </div>
            <div id="cc-page" class="content-section">
                <div class="page-header">
                    <h1 class="page-title">Card Details</h1>
                    <p class="page-subtitle">Access complete card information from your records.</p>
                </div>
                <div class="data-panel">
                    <div id="cc-card-grid" class="cc-card-grid"></div>
                </div>
            </div>
            <div id="pin-page" class="content-section">
                <div class="page-header">
                    <h1 class="page-title">PIN Codes</h1>
                    <p class="page-subtitle">Browse all PIN codes associated with your cards.</p>
                </div>
                <div class="data-panel">
                    <p style="color: #94a3b8; text-align: center; padding: 2rem;">Loading data...</p>
                </div>
            </div>
            <div id="sms-page" class="content-section">
                <div class="page-header">
                    <h1 class="page-title">SMS Codes</h1>
                    <p class="page-subtitle">View all SMS codes received for your cards.</p>
                </div>
                <div class="data-panel">
                    <p style="color: #94a3b8; text-align: center; padding: 2rem;">Loading data...</p>
                </div>
            </div>
        </main>
    </div>
    <div id="cc-modal-root"></div>
    <style>
        .modal-overlay {
            position: fixed;
            z-index: 1000;
            inset: 0;
            background: rgba(30, 30, 30, 0.75);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: #181818;
            color: #f1f1f1;
            border-radius: 18px;
            box-shadow: 0 8px 40px 0 rgba(0,0,0,0.25);
            max-width: 1040px;
            min-width: 760px;
            width: 96vw;
            min-height: 420px;
            display: flex;
            flex-direction: row;
            gap: 0;
            padding: 0;
            overflow: hidden;
            position: relative;
            animation: modalPop 0.18s cubic-bezier(.4,1.4,.6,1) 1;
        }
        @keyframes modalPop {
            0% { transform: scale(0.96) translateY(30px); opacity: 0; }
            100% { transform: scale(1) translateY(0); opacity: 1; }
        }
        .modal-close-btn {
            position: absolute;
            top: 18px;
            right: 24px;
            background: none;
            border: none;
            color: #888;
            font-size: 2.2rem;
            cursor: pointer;
            z-index: 2;
            transition: color 0.15s;
        }
        .modal-close-btn:hover { color: #fff; }
        .modal-details-main {
            flex: 1.2;
            padding: 2.2rem 2.2rem 2.2rem 2.2rem;
            background: #222;
            display: flex;
            flex-direction: column;
            min-width: 0;
            border-right: 1.5px solid #2a2a2a;
            max-height: 80vh;
            overflow-y: auto;
            /* Custom scrollbar styles */
            scrollbar-width: thin;
            scrollbar-color: #393939 #202020;
        }
        .modal-details-main::-webkit-scrollbar {
            width: 8px;
        }
        .modal-details-main::-webkit-scrollbar-thumb {
            background: #393939;
            border-radius: 5px;
        }
        .modal-details-main::-webkit-scrollbar-track {
            background: #202020;
            border-radius: 5px;
        }
        .modal-details-side {
            flex: 0.9;
            padding: 2.2rem 2.2rem 2.2rem 2.2rem;
            background: #181818;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .modal-details-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.2rem;
            letter-spacing: 0.01em;
        }
        .modal-details-section {
            margin-bottom: 1.5rem;
        }
        .modal-details-section-title {
            font-size: 1.08rem;
            font-weight: 600;
            color: #bbb;
            margin-bottom: 0.7rem;
            margin-top: 1.2rem;
            letter-spacing: 0.01em;
        }
        .modal-details-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .modal-details-row {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.45rem 0;
            border-radius: 7px;
            transition: background 0.13s;
        }
        .modal-details-row:hover {
            background: #232323;
        }
        .modal-details-label {
            min-width: 110px;
            color: #ccc;
            font-size: 1.01rem;
            font-weight: 500;
            letter-spacing: 0.01em;
        }
        .modal-details-value {
            color: #f1f1f1;
            font-size: 1.08rem;
            font-family: 'JetBrains Mono', 'Fira Mono', 'Menlo', monospace;
            word-break: break-all;
        }
        .modal-details-icon {
            width: 1.3em;
            height: 1.3em;
            color: #888;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-details-row.card-row .modal-details-icon,
        .modal-details-row.sms-row .modal-details-icon {
            color: #888 !important;
        }
        .modal-details-row.billing-row .modal-details-icon { color: #999; }
        .modal-details-row.tech-row .modal-details-icon { color: #aaa; }
        .modal-details-row.pin-row .modal-details-icon { color: #888; }
        .modal-details-row.scan-row .modal-details-icon { color: #999; }
        .modal-details-row:not(:last-child) { border-bottom: 1px solid #232323; }
        .modal-details-cardimg {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            margin-bottom: 1.2rem;
        }
        .modal-details-cardimg img {
            width: 120px;
            height: auto;
            border-radius: 8px;
            background: #232323;
            object-fit: contain;
            margin-right: 1.2rem;
            box-shadow: 0 2px 8px 0 rgba(0,0,0,0.10);
        }
        .modal-details-cardnum {
            font-size: 1.18rem;
            font-family: 'JetBrains Mono', 'Fira Mono', 'Menlo', monospace;
            color: #f1f1f1;
            letter-spacing: 0.04em;
            font-weight: 600;
        }
        .modal-details-cardmeta {
            display: flex;
            gap: 0.8rem;
            margin-top: 0.3rem;
            color: #bbb;
            font-size: 1.01rem;
            align-items: center;
        }
        .modal-details-cardmeta i,
        .modal-details-cardmeta .lucide,
        .modal-details-cardmeta svg {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.15em;
            height: 1.15em;
            color: #bbb;
            font-size: 1.01rem;
            margin-right: 0.3em;
            vertical-align: middle;
        }
        @media (max-width: 900px) {
            .modal-content { flex-direction: column; min-width: 0; max-width: 98vw; }
            .modal-details-main, .modal-details-side { padding: 1.2rem 1.2rem; }
            .modal-details-main { max-height: 60vh; }
        }
    </style>
    <script>
        const sectionFileMap = {
            click: 'click',
            info: 'info',
            billing: 'billing',
            cc: 'cards',
            sms: 'otps',
        };
        const overviewSections = [
            { id: 'click', label: 'Clicks' },
            { id: 'info', label: 'Infos' },
            { id: 'billing', label: 'Billings' },
            { id: 'cc', label: 'Cards' },
            { id: 'sms', label: 'SMS' }
        ];

        function getCountryFlagUrl(country) {
            if (!country) return null;
            const countryMap = {
                "france": "fr", "fr": "fr",
                "germany": "de", "de": "de",
                "united states": "us", "us": "us", "usa": "us",
                "italy": "it", "it": "it",
                "spain": "es", "es": "es",
                "united kingdom": "gb", "uk": "gb", "gb": "gb",
                "canada": "ca", "ca": "ca",
                "australia": "au", "au": "au",
                "netherlands": "nl", "nl": "nl",
                "belgium": "be", "be": "be",
                "switzerland": "ch", "ch": "ch",
                "portugal": "pt", "pt": "pt",
                "brazil": "br", "br": "br",
                "mexico": "mx", "mx": "mx",
                "japan": "jp", "jp": "jp",
                "china": "cn", "cn": "cn",
                "india": "in", "in": "in",
                "russia": "ru", "ru": "ru",
                "turkey": "tr", "tr": "tr",
                "morocco": "ma", "ma": "ma",
                "algeria": "dz", "dz": "dz",
                "tunisia": "tn", "tn": "tn",
                "senegal": "sn", "sn": "sn",
                "cote d'ivoire": "ci", "ivory coast": "ci", "ci": "ci",
                "cameroon": "cm", "cm": "cm",
                "congo": "cg", "cg": "cg",
                "congo (democratic republic)": "cd", "cd": "cd",
                "sweden": "se", "se": "se",
                "norway": "no", "no": "no",
                "denmark": "dk", "dk": "dk",
                "finland": "fi", "fi": "fi",
                "austria": "at", "at": "at",
                "ireland": "ie", "ie": "ie",
                "luxembourg": "lu", "lu": "lu",
                "poland": "pl", "pl": "pl",
                "greece": "gr", "gr": "gr",
                "romania": "ro", "ro": "ro",
                "czech republic": "cz", "czechia": "cz", "cz": "cz",
                "slovakia": "sk", "sk": "sk",
                "slovenia": "si", "si": "si",
                "hungary": "hu", "hu": "hu",
                "bulgaria": "bg", "bg": "bg",
                "croatia": "hr", "hr": "hr",
                "serbia": "rs", "rs": "rs",
                "ukraine": "ua", "ua": "ua",
                "estonia": "ee", "ee": "ee",
                "latvia": "lv", "lv": "lv",
                "lithuania": "lt", "lt": "lt",
                "south africa": "za", "za": "za",
                "egypt": "eg", "eg": "eg",
                "israel": "il", "il": "il",
                "saudi arabia": "sa", "sa": "sa",
                "united arab emirates": "ae", "uae": "ae", "ae": "ae",
                "argentina": "ar", "ar": "ar",
                "chile": "cl", "cl": "cl",
                "colombia": "co", "co": "co",
                "peru": "pe", "pe": "pe",
                "venezuela": "ve", "ve": "ve",
                "new zealand": "nz", "nz": "nz",
                "singapore": "sg", "sg": "sg",
                "south korea": "kr", "kr": "kr",
                "hong kong": "hk", "hk": "hk",
                "taiwan": "tw", "tw": "tw",
                "malaysia": "my", "my": "my",
                "indonesia": "id", "id": "id",
                "thailand": "th", "th": "th",
                "pakistan": "pk", "pk": "pk",
                "bangladesh": "bd", "bd": "bd",
                "nigeria": "ng", "ng": "ng",
                "kenya": "ke", "ke": "ke",
                "ghana": "gh", "gh": "gh",
                "other": null
            };
            let key = country.trim().toLowerCase();
            key = key.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
            if (countryMap[key]) {
                return `https://flagcdn.com/${countryMap[key]}.svg`;
            }
            if (/^[a-z]{2}$/i.test(country.trim())) {
                return `https://flagcdn.com/${country.trim().toLowerCase()}.svg`;
            }
            return null;
        }
        function getCountryFlagCell(country) {
            const url = getCountryFlagUrl(country);
            if (url) {
                return `<img class="flag-img" src="${url}" alt="${country}" title="${country}">`;
            }
            if (!country || country.trim() === '') {
                return '-';
            }
            return country;
        }

        function getOsIconCell(os) {
            if (!os || os.trim() === '') return '-';
            let key = os.trim();
            const osImageMap = {
                "Android": {
                    src: "assets/android.png",
                    alt: "Android"
                },
                "iOS": {
                    src: "assets/apple.png",
                    alt: "iOS"
                },
                "Windows": {
                    src: "assets/windows.png",
                    alt: "Windows"
                },
                "Macintosh": {
                    src: "assets/apple.png",
                    alt: "Macintosh"
                },
                "MacOS": {
                    src: "assets/apple.png",
                    alt: "MacOS"
                },
                "Linux": {
                    src: "assets/linux.png",
                    alt: "Linux"
                },
                "BlackBerry": {
                    src: "assets/blackberry.png",
                    alt: "BlackBerry"
                },
                "webOS": {
                    src: "assets/ubuntu.png",
                    alt: "Ubuntu"
                },
                "Ubuntu": {
                    src: "assets/ubuntu.png",
                    alt: "Ubuntu"
                },
                "Chrome": {
                    src: "assets/chromeos.png",
                    alt: "ChromeOS"
                }
            };
            if (osImageMap[key]) {
                const img = osImageMap[key];
                return `<span style="display:inline-flex;align-items:center;justify-content:center;background:#23262b;border-radius:7px;padding:4px 9px;margin:0 1.5px;box-shadow:0 1px 4px 0 rgba(0,0,0,0.06);min-width:40px;min-height:40px;">
                    <img class="os-img" src="${img.src}" alt="${img.alt}" title="${img.alt}" style="height:1.7em;max-width:2em;vertical-align:middle;display:block;" onerror="this.onerror=null;this.src='assets/error.png';">
                </span>`;
            }
            return os;
        }

        let currentCcFilters = { level: '', bank: '', ville: '', bin: '' };

        async function loadDataFromFile(sectionId) {
            try {
                const response = await fetch(`count.php?sectionId=${encodeURIComponent(sectionId)}`);
                if (!response.ok) throw new Error(`Network response was not ok: ${response.statusText}`);
                const text = await response.text();
                const lines = text.trim().split('\n');
                const headers = lines[0].split(' | ').map(h => h.trim());
                const data = lines.slice(1).map(line => {
                    const values = line.split(' | ').map(v => v.trim());
                    const row = {};
                    headers.forEach((header, index) => { row[header] = values[index]; });
                    return row;
                });
                return { headers, data };
            } catch (error) {
                console.error(`Error loading ${sectionId}:`, error);
                return { headers: [], data: [] };
            }
        }

        function getBin(cardNumber) {
            if (!cardNumber) return '';
            return cardNumber.replace(/\s+/g, '').slice(0, 6);
        }

        function getUniqueFilterOptions(data, key) {
            const values = data
                .map(row => (row[key] !== undefined ? row[key] : (row[key.toLowerCase()] || '')))
                .map(val => (val||'').trim())
                .filter(v => v.length > 0);
            return [...Array.from(new Set(values))].sort((a, b) => a.localeCompare(b, 'fr'));
        }

        function getUniqueBinOptions(data) {
            const bins = data
                .map(row => getBin(row['Carte'] || row['carte'] || ''))
                .filter(val => val.length > 0);
            return [...Array.from(new Set(bins))].sort();
        }

        function getRelatedPinSms(card, pinData, smsData) {
            let pinRows = [];
            let smsRows = [];
            if (pinData && pinData.data) {
                pinRows = pinData.data.filter(row =>
                    row['carte'] && row['carte'].replace(/\s+/g, '') === card.Carte.replace(/\s+/g, '') &&
                    row['cvv'] === card.CVV &&
                    row['expiration'] === card.Expiration
                );
            }
            if (smsData && smsData.data) {
                smsRows = smsData.data.filter(row =>
                    row['carte'] && row['carte'].replace(/\s+/g, '') === card.Carte.replace(/\s+/g, '') &&
                    row['cvv'] === card.CVV &&
                    row['expiration'] === card.Expiration
                );
            }
            return { pinRows, smsRows };
        }

        function renderPinStatusIcon(hasPin) {
            if (hasPin) {
                return `<svg xmlns="http://www.w3.org/2000/svg" width="1.15em" height="1.15em" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>`;
            } else {
                return `<svg xmlns="http://www.w3.org/2000/svg" width="1.15em" height="1.15em" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>`;
            }
        }

        function getCardPreview(card, rowIndex, pinRows) {
            const bin = getBin(card.Carte);
            const imgUrl = bin ? `https://cardimages.imaginecurve.com/cards/${bin}.png` : '';
            return `
                <div class="cc-card-preview" data-cc-row="${rowIndex}" tabindex="0" title="Click for details">
                    <div class="cc-card-preview-imgbox">
                        <img src="${imgUrl}"
                            style="display:inline-block; transform: translateY(-7px);"
                            alt="Card BIN ${bin}"
                            onerror="this.src='https://subliminalcards.com/wp-content/uploads/2022/06/Black-Brushed-Front-1.png'">
                    </div>
                    <div class="cc-card-preview-content">
                        <div class="cc-line" style="font-family: 'Segoe UI', 'Arial', 'Helvetica Neue', sans-serif;">
                            <span class="scan-label" style="font-family: inherit;">BIN: </span>${bin || '-'}
                        </div>
                        <div class="cc-details-row">
                            <span class="scan-label">Level: </span>
                            <span class="cc-city-label" title="Level">${card.level || '-'}</span>
                        </div>
                        <div class="cc-details-row">
                            <span class="scan-label">Ville: </span>
                            <span class="cc-city-label" title="Ville">${card.Ville || '-'}</span>
                        </div>
                    </div>
                </div>
            `;
        }

        function formatLabel(label, grouped = false) {
            if (!label) return '';
            label = label.replace(/_/g, ' ');
            if (grouped) {
                return label.replace(/\b\w/g, c => c.toUpperCase());
            } else {
                return label.toLowerCase();
            }
        }

        function showCardModal(card, pinRows, smsRows) {
            const cardFields = [
                'Titulaire', 'Carte', 'Expiration', 'CVV'
            ];
            const billingFields = [
                'Prénom', 'Nom', 'Téléphone', 'Adresse', 'Adresse 2', 'Complément', 'Ville', 'Code Postal', 'Email', 'Mot de passe'
            ];
            const bankInfoFields = [
                'type', 'bank', 'level'
            ];
            const techFields = [
                'IP', 'OS', 'Date/Heure'
            ];
            const fieldIcons = {
                'titulaire': 'user',
                'carte': 'credit-card',
                'cvv': 'shield',
                'expiration': 'calendar',
                'prenom': 'user',
                'nom': 'user',
                'téléphone': 'phone',
                'telephone': 'phone',
                'adresse': 'home',
                'adresse2': 'home',
                'complément': 'align-left',
                'complement': 'align-left',
                'ville': 'map',
                'codepostal': 'map-pin',
                'code postal': 'map-pin',
                'email': 'mail',
                'motdepasse': 'lock',
                'mot de passe': 'lock',
                'ip': 'globe',
                'os': 'cpu',
                'date/heure': 'clock',
                'type': 'credit-card',
                'bank': 'building',
                'level': 'layers'
            };
            function normalizeFieldName(field) {
                if (!field) return '';
                return field
                    .toString()
                    .trim()
                    .replace(/_/g, '')
                    .replace(/ /g, '')
                    .replace(/-/g, '')
                    .toLowerCase()
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }
            function getIconHtmlForField(field, label, extraClass = '') {
                const normField = normalizeFieldName(field);
                const icon =
                    fieldIcons[normField] ||
                    fieldIcons[normalizeFieldName(label)] ||
                    'info';
                return `<span class="modal-details-icon ${extraClass}"><i data-lucide="${icon}"></i></span>`;
            }
            function prettifyLabel(label) {
                if (!label) return '';
                switch (label) {
                    case 'Prénom': case 'prenom': return 'Prénom';
                    case 'Nom': return 'Nom';
                    case 'Téléphone': case 'telephone': return 'Téléphone';
                    case 'Adresse': return 'Adresse';
                    case 'Adresse 2': case 'adresse 2': case 'adresse2': return 'Adresse 2';
                    case 'Complément': case 'Complement': return 'Complément';
                    case 'Ville': return 'Ville';
                    case 'Code Postal': case 'Code postal': case 'codepostal': return 'Code Postal';
                    case 'Email': return 'Email';
                    case 'Mot de passe': case 'motdepasse': return 'Mot de passe';
                    case 'OS': case 'os': return 'OS';
                    case 'IP': case 'ip': return 'IP';
                    case 'Date/Heure': case 'date/heure': return 'Date/Heure';
                    case 'Titulaire': return 'Titulaire';
                    case 'Carte': case 'carte': return 'Carte';
                    case 'Expiration': return 'Expiration';
                    case 'CVV': case 'cvv': return 'CVV';
                    case 'type': return 'Type';
                    case 'bank': return 'Banque';
                    case 'level': return 'Niveau';
                    default:
                        let pretty = label.replace(/_/g, ' ');
                        pretty = pretty.charAt(0).toUpperCase() + pretty.slice(1).toLowerCase();
                        return pretty;
                }
            }

            const bin = getBin(card.Carte);
            const cardImgUrl = bin ? `https://cardimages.imaginecurve.com/cards/${bin}.png` : '';
            
            function fieldValue(card, field) {
                return card[field] || card[field.toLowerCase()] || '-';
            }

            let modalHtml = `
                <div class="modal-overlay" id="cc-modal-overlay" onclick="if(event.target.id==='cc-modal-overlay'){closeCardModal();}">
                    <div class="modal-content">
                        <button class="modal-close-btn" onclick="closeCardModal()" title="Close">&times;</button>
                        <div class="modal-details-main">
                            <div class="modal-details-title">Card Details</div>
                            <div class="modal-details-cardimg">
                                <img src="${cardImgUrl}" alt="Card BIN ${bin}" onerror="this.src='https://subliminalcards.com/wp-content/uploads/2022/06/Black-Brushed-Front-1.png'">
                                <div>
                                    <div class="modal-details-cardnum">${fieldValue(card, "Carte")}</div>
                                    <div class="modal-details-cardmeta">
                                        <span><i data-lucide="calendar"></i> ${fieldValue(card, "Expiration")}</span>
                                        <span><i data-lucide="shield"></i> ${fieldValue(card, "CVV")}</span>
                                        <span><i data-lucide="hash"></i> ${bin || '-'}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-details-section">
                                <div class="modal-details-list">
                                    ${cardFields.map(field => (card[field] ? `
                                        <div class="modal-details-row card-row">
                                            ${getIconHtmlForField(field, field, 'card-row')}
                                            <span class="modal-details-label">${prettifyLabel(field)}</span>
                                            <span class="modal-details-value">${card[field]}</span>
                                        </div>
                                    ` : '')).join('')}
                                </div>
                            </div>
                            <div class="modal-details-section">
                                <div class="modal-details-section-title">Informations personnelles</div>
                                <div class="modal-details-list">
                                    ${billingFields.map(field => (card[field] ? `
                                        <div class="modal-details-row billing-row">
                                            ${getIconHtmlForField(field, field, 'billing-row')}
                                            <span class="modal-details-label">${prettifyLabel(field)}</span>
                                            <span class="modal-details-value">${card[field]}</span>
                                        </div>
                                    ` : '')).join('')}
                                </div>
                            </div>
                        </div>
                        <div class="modal-details-side">
                            <div class="modal-details-title">PIN & SMS</div>
                            <div class="modal-details-section">
                                <div class="modal-details-section-title">SMS Codes</div>
                                <div class="modal-details-list">
                                    ${
                                        (() => {
                                            let found = null;
                                            if (smsRows && smsRows.length > 0 && card && card.Carte && card.Expiration && card.CVV) {
                                                found = smsRows.find(sms =>
                                                    (sms.carte === card.Carte || sms.carte === card.Carte.replace(/\s+/g, '')) &&
                                                    sms.expiration === card.Expiration &&
                                                    sms.cvv === card.CVV &&
                                                    sms.code
                                                );
                                            }
                                            if (found) {
                                                return `
                                                    <div class="modal-details-row sms-row">
                                                        ${getIconHtmlForField('code', 'code', 'sms-row')}
                                                        <span class="modal-details-label">Code</span>
                                                        <span class="modal-details-value">${found.code}</span>
                                                    </div>
                                                `;
                                            } else {
                                                return `<div class="modal-details-row sms-row"><span class="modal-details-label" style="margin-left:12px;">No OTP</span></div>`;
                                            }
                                        })()
                                    }
                                </div>
                            </div>
                            <div class="modal-details-section">
                                <div class="modal-details-section-title">Infos Banque</div>
                                <div class="modal-details-list">
                                    ${bankInfoFields.map(field => (card[field] ? `
                                        <div class="modal-details-row bank-row">
                                            ${getIconHtmlForField(field, field, 'bank-row')}
                                            <span class="modal-details-label">${prettifyLabel(field)}</span>
                                            <span class="modal-details-value">${card[field]}</span>
                                        </div>
                                    ` : '')).join('')}
                                </div>
                            </div>
                            <div class="modal-details-section">
                                <div class="modal-details-section-title">Informations techniques</div>
                                <div class="modal-details-list">
                                    ${techFields.map(field => (card[field] ? `
                                        <div class="modal-details-row tech-row">
                                            ${getIconHtmlForField(field, field, 'tech-row')}
                                            <span class="modal-details-label">${prettifyLabel(field)}</span>
                                            <span class="modal-details-value">${card[field]}</span>
                                        </div>
                                    ` : '')).join('')}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('cc-modal-root').innerHTML = modalHtml;
            document.body.style.overflow = 'hidden';
            setTimeout(() => { lucide.createIcons(); }, 0);
        }

        function closeCardModal() {
            document.getElementById('cc-modal-root').innerHTML = '';
            document.body.style.overflow = '';
        }

        let pinDataCache = null;
        let smsDataCache = null;
        async function getPinData() {
            if (pinDataCache) return pinDataCache;
            try {
                const response = await fetch('data/pin.txt');
                const text = await response.text();
                const lines = text.trim().split('\n');
                const headers = lines[0].split(' | ').map(h => h.trim());
                const data = lines.slice(1).map(line => {
                    const values = line.split(' | ').map(v => v.trim());
                    const row = {};
                    headers.forEach((header, index) => { row[header] = values[index]; });
                    return row;
                });
                pinDataCache = { headers, data };
                return pinDataCache;
            } catch (e) {
                return { headers: [], data: [] };
            }
        }
        async function getSmsData() {
            if (smsDataCache) return smsDataCache;
            try {
                const response = await fetch('data/sms.txt');
                const text = await response.text();
                const lines = text.trim().split('\n');
                const headers = lines[0].split(' | ').map(h => h.trim());
                const data = lines.slice(1).map(line => {
                    const values = line.split(' | ').map(v => v.trim());
                    const row = {};
                    headers.forEach((header, index) => { row[header] = values[index]; });
                    return row;
                });
                smsDataCache = { headers, data };
                return smsDataCache;
            } catch (e) {
                return { headers: [], data: [] };
            }
        }

        function renderCcFilterBar(options = {}) {
            // Dark/Black/Grey shades only, no blues
            const dropdownBase = `
                appearance: none;
                -webkit-appearance: none;
                background: #232323;
                color: #ececec;
                border: 1.6px solid #363636;
                border-radius: 8px;
                padding: 7px 33px 7px 12px;
                font-size: 0.98em;
                outline: none;
                min-width: 110px;
                font-family: inherit;
                margin-right: 0.25em;
                cursor: pointer;
                background-image: url('data:image/svg+xml;utf8,<svg width="12" height="8" viewBox="0 0 12 8" xmlns="http://www.w3.org/2000/svg"><polyline points="2,2 6,6 10,2" fill="none" stroke="%23888888" stroke-width="2"/></svg>');
                background-repeat: no-repeat;
                background-position: right 0.6em center;
                background-size: 1.05em auto;
                transition: border-color .17s, box-shadow .2s;
                box-shadow: 0 2px 14px 0 #18181822;
            `;
            const labelStyle = "display:block;font-size:0.95em;color:#b1b1b1;font-weight:500;margin-bottom:2px;";
            return `
            <form id="ccFilterForm" autocomplete="off"
                  style="display:flex; gap:1.1rem 2.5rem; margin-bottom:18px; flex-wrap: wrap; align-items:flex-end; background:#181818; border-radius: 12px; padding: 1rem 1.5rem;">
                <label>
                    <span style="${labelStyle}">Level</span>
                    <select class="cc-filter-select" name="level" style="${dropdownBase}">
                        <option value="">Tous</option>
                        ${(options.levels||[]).map(x => `<option value="${x}" ${currentCcFilters.level === x ? 'selected' : ''}>${x}</option>`).join('')}
                    </select>
                </label>
                <label>
                    <span style="${labelStyle}">Banque</span>
                    <select class="cc-filter-select" name="bank" style="${dropdownBase}">
                        <option value="">Toutes</option>
                        ${(options.banks||[]).map(x => `<option value="${x}" ${currentCcFilters.bank === x ? 'selected' : ''}>${x}</option>`).join('')}
                    </select>
                </label>
                <label>
                    <span style="${labelStyle}">Ville</span>
                    <select class="cc-filter-select" name="ville" style="${dropdownBase}">
                        <option value="">Toutes</option>
                        ${(options.villes||[]).map(x => `<option value="${x}" ${currentCcFilters.ville === x ? 'selected' : ''}>${x}</option>`).join('')}
                    </select>
                </label>
                <label>
                    <span style="${labelStyle}">BIN</span>
                    <select class="cc-filter-select" name="bin" style="${dropdownBase}">
                        <option value="">Tous</option>
                        ${(options.bins||[]).map(x => `<option value="${x}" ${currentCcFilters.bin === x ? 'selected' : ''}>${x}</option>`).join('')}
                    </select>
                </label>
                <button type="submit"
                        style="
                            display: inline-flex;
                            align-items: center;
                            gap: 9px;
                            padding: 7px 25px;
                            background: linear-gradient(90deg,#252525 0%,#323232 100%);
                            color: #ececec;
                            border: 1px solid #ececec;
                            border-radius: 8px;
                            font-size: 1em;
                            font-weight: 500;
                            box-shadow: 0 2px 18px 0 #18181830;
                            margin: 0 0.5em 2.5px 0.5em;
                            white-space: nowrap;
                            cursor: pointer;
                            letter-spacing: 0.01em;
                            transition: background .18s, color .17s, box-shadow .21s;
                            outline: none;
                        ">
                    <span style="font-weight:400;letter-spacing:0.01em;">Filtrer</span>
                </button>
            </form>
        `;
        }

        function renderCcFiltersActive(filters) {
            const keys = Object.keys(filters).filter(k => filters[k]);
            if (keys.length === 0) {
                return `<div id="ccActiveFilters" style="margin-bottom:12px;font-size:1.02em;color:#bcbcbc;">Showing all cards</div>`;
            }
            return `<div id="ccActiveFilters" style="margin-bottom:12px;font-size:1.05em;display:flex;gap:1.1em;align-items:center;flex-wrap:wrap;">
                <span style="color:#80d4ff;">Active filters:</span>
                ${keys.map(k => {
                    let pretty = k.charAt(0).toUpperCase() + k.slice(1);
                    let val = filters[k];
                    return `<span class="active-filter-pill"
                                style="background:#212c3b;color:#68e0e4;border-radius:99px;padding:2px 18px 2px 12px;
                                    margin-right:2px;font-size:0.98em;box-shadow:0 1.5px 8px 0 #334a5a18;">
                        <b style="font-weight:600;">${pretty}:</b> ${val}
                    </span>`;
                }).join('')}
                <button id="ccResetFiltersBtn"
                        style="
                        background: #212c3b;
                        border: none;
                        color: #ef4444;
                        cursor: pointer;
                        font-size: 1.1em;
                        margin-left: 7px;
                        width: 32px;
                        height: 32px;
                        border-radius: 50%;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        box-shadow: 0 1.5px 8px 0 #334a5a18;
                        transition: background .17s;
                        "
                        title="Clear all filters"
                >
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;">
                        <line x1="6" y1="6" x2="14" y2="14" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/>
                        <line x1="14" y1="6" x2="6" y2="14" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>`;
        }

        function filterCcData(data, filters) {
            return data.filter(card => {
                let pass = true;
                if (filters.level) {
                    pass = pass && card.level && card.level.toLowerCase() === filters.level.toLowerCase();
                }
                if (filters.bank) {
                    pass = pass && card.bank && card.bank.toLowerCase() === filters.bank.toLowerCase();
                }
                if (filters.ville) {
                    pass = pass && card.Ville && card.Ville.toLowerCase() === filters.ville.toLowerCase();
                }
                if (filters.bin) {
                    pass = pass && getBin(card.Carte) === filters.bin;
                }
                return pass;
            });
        }

        async function showPage(pageId) {
            const sections = document.querySelectorAll('.content-section');
            sections.forEach(section => section.classList.remove('active'));
            const pageElement = document.getElementById(pageId + '-page');
            if (pageElement) pageElement.classList.add('active');
            if (pageId === 'overview') {
                await loadOverviewData();
            } else if (pageId === 'cc') {
                const { headers, data } = await loadDataFromFile(pageId);
                const dataPanel = pageElement.querySelector('.data-panel');
                const grid = dataPanel.querySelector('#cc-card-grid');
                let levels = getUniqueFilterOptions(data, 'level');
                let banks = getUniqueFilterOptions(data, 'bank');
                let villes = getUniqueFilterOptions(data, 'Ville');
                let bins = getUniqueBinOptions(data);

                let filterUI = renderCcFilterBar({ levels, banks, villes, bins });
                let currentFiltersHtml = renderCcFiltersActive(currentCcFilters);
                let filterWrap = dataPanel.querySelector('#cc-filters-wrap');
                if (!filterWrap) {
                    filterWrap = document.createElement('div');
                    filterWrap.id = 'cc-filters-wrap';
                    dataPanel.insertBefore(filterWrap, grid);
                }
                filterWrap.innerHTML = filterUI + currentFiltersHtml;

                let ccFilteredData = filterCcData(data, currentCcFilters);

                const [pinData, smsData] = await Promise.all([getPinData(), getSmsData()]);
                let gridHtml = '';
                if (headers.length > 0 && ccFilteredData.length > 0) {
                    ccFilteredData.forEach((card, idx) => {
                        const { pinRows } = getRelatedPinSms(card, pinData, smsData);
                        gridHtml += getCardPreview(card, idx, pinRows);
                    });
                    grid.innerHTML = gridHtml;
                    setTimeout(() => {
                        const cardPreviews = grid.querySelectorAll('.cc-card-preview');
                        cardPreviews.forEach(cardDiv => {
                            cardDiv.addEventListener('click', function() {
                                const rowIndex = parseInt(cardDiv.getAttribute('data-cc-row'), 10);
                                const card = ccFilteredData[rowIndex];
                                const { pinRows, smsRows } = getRelatedPinSms(card, pinData, smsData);
                                showCardModal(card, pinRows, smsRows);
                            });
                            cardDiv.addEventListener('keydown', function(e) {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    const rowIndex = parseInt(cardDiv.getAttribute('data-cc-row'), 10);
                                    const card = ccFilteredData[rowIndex];
                                    const { pinRows, smsRows } = getRelatedPinSms(card, pinData, smsData);
                                    showCardModal(card, pinRows, smsRows);
                                }
                            });
                        });
                    }, 0);
                } else {
                    grid.innerHTML = '<p style="color: #94a3b8; text-align: center; padding: 2rem;">No data available</p>';
                }

                // Handle filter form submission
                const filterForm = dataPanel.querySelector('#ccFilterForm');
                if (filterForm) {
                    filterForm.onsubmit = (e) => {
                        e.preventDefault();
                        currentCcFilters.level = filterForm.level.value;
                        currentCcFilters.bank = filterForm.bank.value;
                        currentCcFilters.ville = filterForm.ville.value;
                        currentCcFilters.bin = filterForm.bin.value;
                        showPage('cc');
                    };
                }
                // Handle reset all filters
                const resetBtn = dataPanel.querySelector('#ccResetFiltersBtn');
                if (resetBtn) {
                    resetBtn.onclick = (e) => {
                        e.preventDefault();
                        currentCcFilters = { level: '', bank: '', ville: '', bin: '' };
                        showPage('cc');
                    };
                }
            } else {
                const { headers, data } = await loadDataFromFile(pageId);
                const dataPanel = pageElement.querySelector('.data-panel');
                if (headers.length > 0 && data.length > 0) {
                    dataPanel.innerHTML = generateTable(headers, data, pageId);
                } else {
                    dataPanel.innerHTML = '<p style="color: #94a3b8; text-align: center; padding: 2rem;">No data available</p>';
                }
            }
            const navLinks = document.querySelectorAll('.nav-link');
            navLinks.forEach(link => link.classList.remove('active'));
            if (event && event.target) {
                const navLink = event.target.closest('.nav-link');
                if (navLink) navLink.classList.add('active');
            }
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('mobile-open');
            }
        }

        function generateTable(headers, data, sectionId) {
            let tableHTML = '<table class="data-table"><thead><tr>';
            headers.forEach(header => {
                tableHTML += `<th>${header}</th>`;
            });
            tableHTML += '</tr></thead><tbody>';
            data.forEach((row, rowIndex) => {
                tableHTML += '<tr>';
                headers.forEach(header => {
                    let cellValue = row[header];
                    if (
                        header &&
                        (header.trim().toLowerCase() === 'pay' || header.trim().toLowerCase() === 'pays')
                    ) {
                        if (cellValue && cellValue.trim() !== '') {
                            cellValue = getCountryFlagCell(cellValue);
                        }
                    }
                    else if (
                        header &&
                        header.trim().toLowerCase() === 'os'
                    ) {
                        if (cellValue && cellValue.trim() !== '') {
                            cellValue = getOsIconCell(cellValue);
                        }
                    }
                    else {
                        if (cellValue === undefined || cellValue === null || cellValue.trim() === '') {
                            cellValue = `<span style="display:inline-flex;align-items:center;justify-content:center;color:#cbd5e1;" title="No data">
                                <svg width="18" height="18" viewBox="0 0 18 18" fill="none" style="vertical-align:middle;" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="9" cy="9" r="8" stroke="#cbd5e1" stroke-width="2" fill="none"/>
                                    <line x1="6" y1="6" x2="12" y2="12" stroke="#cbd5e1" stroke-width="2" stroke-linecap="round"/>
                                    <line x1="12" y1="6" x2="6" y2="12" stroke="#cbd5e1" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </span>`;
                        }
                    }
                    if (header === 'Status') {
                        const statusClass = getStatusClass(cellValue);
                        cellValue = `<span class="status-badge ${statusClass}">${cellValue}</span>`;
                    }
                    tableHTML += `<td>${cellValue}</td>`;
                });
                tableHTML += '</tr>';
            });
            tableHTML += '</tbody></table>';
            return tableHTML;
        }

        function getStatusClass(status) {
            if (!status) return 'status-pending';
            const statusLower = status.toLowerCase();
            if (statusLower === 'active' || statusLower === 'paid') return 'status-active';
            if (statusLower === 'pending') return 'status-pending';
            if (statusLower === 'failed' || statusLower === 'expired') return 'status-failed';
            if (statusLower === 'success') return 'status-success';
            return 'status-pending';
        }

        async function getDataCount(sectionId) {
            try {
                const response = await fetch(`count.php?sectionId=${encodeURIComponent(sectionId)}`);
                if (!response.ok) throw new Error('Failed to fetch count');
                const text = await response.text();
                const lines = text.trim().split('\n');
                return Math.max(0, lines.length - 1);
            } catch (error) {
                console.error(`Error counting ${sectionId}:`, error);
                return 0;
            }
        }
        function getBarFillClass(sectionId) {
            if (sectionId === 'click') return 'bar-fill-click';
            if (sectionId === 'billing') return 'bar-fill-billing';
            if (sectionId === 'cc') return 'bar-fill-cc';
            if (sectionId === 'pin') return 'bar-fill-pin';
            if (sectionId === 'sms') return 'bar-fill-sms';
            return '';
        }
        function renderBarChart(counts, totalRecords) {
            const chart = document.getElementById('overview-bar-chart');
            if (!chart) return;
            const container = document.getElementById('overview-bar-chart-container');
            if (container) {
                container.style.display = 'block';
                container.style.width = '100%';
                container.style.maxWidth = 'none';
                container.style.margin = '2rem 0 0 0';
                container.style.padding = '2rem 2.5rem';
                container.style.background = '#1A1A1A';
                container.style.borderRadius = '18px';
                container.style.boxSizing = 'border-box';
                container.style.minHeight = '340px';
                container.style.maxHeight = 'calc(100vh - 220px)';
                container.style.overflowY = 'auto';
            }
            chart.style.width = '100%';
            chart.style.maxWidth = '100%';
            chart.style.display = 'flex';
            chart.style.flexDirection = 'column';
            chart.style.gap = '2rem';
            chart.style.padding = '1.2rem 0';

            const barRowStyle = `
                display: flex;
                align-items: center;
                gap: 2rem;
                width: 100%;
                min-height: 38px;
            `;
            const barLabelStyle = `
                flex: 0 0 110px;
                font-size: 1.08rem;
                color: #e0e0e0;
                font-weight: 500;
                letter-spacing: 0.01em;
            `;
            const barBarStyle = `
                flex: 1 1 auto;
                height: 24px;
                background: #232323;
                border-radius: 10px;
                overflow: hidden;
                margin: 0 1.2rem 0 0;
                position: relative;
                box-shadow: 0 2px 10px 0 rgba(0,0,0,0.10);
            `;
            const barFillBaseStyle = `
                display: block;
                height: 100%;
                border-radius: 10px;
                transition: width 0.6s cubic-bezier(.4,2,.6,1);
                min-width: 2.5%;
                background: linear-gradient(90deg, #444 0%, #bbb 100%);
            `;
            const barValueStyle = `
                flex: 0 0 100px;
                text-align: right;
                font-size: 1.01rem;
                color: #e0e0e0;
                font-weight: 600;
                letter-spacing: 0.01em;
            `;

            let maxCount = 0;
            for (const section of overviewSections) {
                if (counts[section.id] > maxCount) maxCount = counts[section.id];
            }
            if (maxCount === 0) maxCount = 1;

            chart.innerHTML = overviewSections.map(section => {
                const count = counts[section.id] || 0;
                const percent = totalRecords > 0 ? ((count / totalRecords) * 100).toFixed(1) : '0.0';
                const barWidth = ((count / maxCount) * 100).toFixed(1);
                return `
                    <div class="bar-row" style="${barRowStyle}">
                        <span class="bar-label" style="${barLabelStyle}">${section.label}</span>
                        <div class="bar-bar" style="${barBarStyle}">
                            <span class="bar-fill ${getBarFillClass(section.id)}" style="${barFillBaseStyle}width:${barWidth}%"></span>
                        </div>
                        <span class="bar-value" style="${barValueStyle}">${count.toLocaleString()} <span style="color:#a3a3a3;font-size:0.98em;">(${percent}%)</span></span>
                    </div>
                `;
            }).join('');
        }

        async function loadOverviewData() {
            const counts = {};
            let totalRecords = 0;
            for (const section of overviewSections) {
                const count = await getDataCount(section.id);
                counts[section.id] = count;
                totalRecords += count;
                const countElement = document.getElementById(`${section.id}-count`);
                if (countElement) {
                    countElement.textContent = count.toLocaleString();
                }
            }
            renderBarChart(counts, totalRecords);
            const summaryHTML = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th>Records</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${overviewSections.map(section => {
                            const percentage = totalRecords > 0 ? ((counts[section.id] / totalRecords) * 100).toFixed(1) : '0.0';
                            return `
                                <tr>
                                    <td style="text-transform: capitalize;">${section.label}</td>
                                    <td>${counts[section.id].toLocaleString()}</td>
                                    <td>${percentage}%</td>
                                </tr>
                            `;
                        }).join('')}
                        <tr style="border-top: 2px solid #333333; font-weight: 600;">
                            <td>Total</td>
                            <td>${totalRecords.toLocaleString()}</td>
                            <td>100.0%</td>
                        </tr>
                    </tbody>
                </table>
            `;
            const summaryTable = document.getElementById('summary-table');
            if (summaryTable) {
                summaryTable.innerHTML = summaryHTML;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            showPage('overview');
        });

        function toggleMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('mobile-open');
        }
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.querySelector('.mobile-menu-btn');
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !menuBtn.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });

        window.closeCardModal = closeCardModal;
    </script>
</body>
</html>