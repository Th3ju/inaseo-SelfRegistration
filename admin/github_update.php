<?php
/**
 * Script de mise à jour automatique depuis GitHub
 * Module SelfRegistration pour IANSEO
 * GitHub: https://github.com/Th3ju/inaseo-SelfRegistration
 * Emplacement : /Modules/Custom/SelfRegistration/admin/github_update.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------------- Authentification IANSEO ----------------
$ianseo_root = dirname(dirname(dirname(dirname(dirname(__FILE__)))));
$fallback_root = dirname(dirname(dirname(dirname(__FILE__))));

$authConfig = $ianseo_root . '/config.php';
if (!file_exists($authConfig)) {
    $authConfig = $fallback_root . '/config.php';
}
if (!file_exists($authConfig)) {
    die("Configuration IANSEO introuvable.");
}
require_once($authConfig);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $CFG;
if (!isset($CFG)) {
    $CFG = new stdClass();
}
if (!isset($CFG->W_HOST)) {
    $configPath = $ianseo_root . '/Common/config.inc.php';
    if (!file_exists($configPath)) {
        $configPath = $ianseo_root . '/ianseo/Common/config.inc.php';
    }
    if (file_exists($configPath)) {
        include_once($configPath);
    }
}
if (!isset($CFG->WDB) && isset($CFG->DB_NAME)) {
    $CFG->WDB = $CFG->DB_NAME;
}

$funCandidates = [
    $ianseo_root . '/Common/Fun_Various.inc.php',
    $fallback_root . '/Common/Fun_Various.inc.php',
    '../../../../Common/Fun_Various.inc.php',
];
foreach ($funCandidates as $funPath) {
    if (file_exists($funPath)) {
        require_once($funPath);
        break;
    }
}

CheckTourSession(true);
checkACL(AclParticipants, AclReadWrite);

// Jeton CSRF : l'action de mise à jour n'est acceptée qu'en POST.
if (empty($_SESSION['selfreg_csrf'])) {
    $_SESSION['selfreg_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['selfreg_csrf'];

// Fonction pour afficher des messages
function logMsg($msg, $type = 'info') {
    $colors = [
        'success' => '#28a745',
        'error' => '#dc3545',
        'warning' => '#ffc107',
        'info' => '#17a2b8'
    ];
    $color = $colors[$type] ?? $colors['info'];
    echo "<div style='padding: 8px 12px; margin: 5px 0; background: " . $color . "22; border-left: 4px solid $color; color: #333;'>";
    echo htmlspecialchars($msg);
    echo "</div>";
    flush();
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Mise à jour SelfRegistration depuis GitHub</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #667eea;
            margin-top: 0;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
        }
        .info-box {
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            margin: 20px 0;
        }
        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            border: none;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin: 10px 5px;
        }
        .btn:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
<div class="container">

<?php
// IMPORTANT : Définir le dossier module (racine SelfRegistration)
// Ce script est dans admin/, donc on remonte d'un niveau
$moduleDir = realpath(dirname(__DIR__));

// Vérifier si l'action est confirmée : POST + jeton CSRF obligatoires.
$sessionCsrf = (string)($_SESSION['selfreg_csrf'] ?? '');
$isConfirmed = ($_SERVER['REQUEST_METHOD'] === 'POST')
    && isset($_POST['confirm'])
    && $sessionCsrf !== ''
    && hash_equals($sessionCsrf, (string)($_POST['csrf'] ?? ''));

if (!$isConfirmed) {
    ?>
    <h1>🔄 Mise à jour du module SelfRegistration</h1>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="warning-box">
            Jeton de sécurité invalide. Rechargez la page et réessayez.
        </div>
    <?php endif; ?>

    <div class="info-box">
        <strong>📦 Source GitHub :</strong> <code>https://github.com/Th3ju/inaseo-SelfRegistration</code><br>
        <strong>🎯 Branche :</strong> <code>main</code><br>
        <strong>📁 Destination :</strong> <code><?php echo htmlspecialchars($moduleDir); ?></code>
    </div>

    <div class="warning-box">
        <strong>⚠️ Attention :</strong>
        <ul>
            <li>Cette mise à jour va <strong>remplacer tous les fichiers du module</strong> par la dernière version GitHub</li>
            <li>Le fichier <code>config.php</code> (configurations des compétitions) sera <strong>préservé</strong></li>
            <li>Assurez-vous d'avoir une <strong>sauvegarde</strong> avant de continuer</li>
            <li>La mise à jour nécessite environ 30 secondes</li>
        </ul>
    </div>

    <h3>📋 Fichiers qui seront protégés (non écrasés) :</h3>
    <ul>
        <li><code>config.php</code> - Vos configurations de compétitions et tokens</li>
    </ul>

    <h3>✅ Fichiers qui seront mis à jour :</h3>
    <ul>
        <li><code>index.html</code> - Formulaire d'inscription</li>
        <li><code>process.php</code> - Traitement des inscriptions</li>
        <li><code>js/script.js</code> - Scripts JavaScript</li>
        <li><code>css/style.css</code> - Styles CSS</li>
        <li><code>admin/selfregistration.php</code> - Page d'administration</li>
        <li><code>admin/github_update.php</code> - Ce script de mise à jour</li>
        <li><code>admin/send_recap.php</code> - Envoi recap par mail </li>
        <li><code>README.md</code> - Documentation</li>       
    </ul>

    <form method="POST" style="margin-top: 30px; text-align: center;">
        <input type="hidden" name="confirm" value="1">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <button type="submit" class="btn">🚀 Lancer la mise à jour</button>
        <a href="selfregistration.php" class="btn btn-secondary">❌ Annuler</a>
    </form>
    <?php
    exit;
}

// === DÉBUT DE LA MISE À JOUR ===
?>

<h1>🔄 Mise à jour en cours...</h1>

<?php

// Détection du système
$isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
echo "<div style='background: #f8f9fa; padding: 10px; margin: 15px 0; border-radius: 5px;'>";
echo "<strong>🖥️ Système :</strong> " . ($isWindows ? 'Windows' : 'Linux');
echo "</div>";

// Configuration
$githubRepo = "Th3ju/inaseo-SelfRegistration";
$branch = "main";

logMsg("📁 Dossier du module : " . $moduleDir);

// Vérifier les permissions
if (!is_writable($moduleDir)) {
    logMsg("❌ Le dossier n'est pas accessible en écriture", 'error');

    if (!$isWindows) {
        echo "<div class='warning-box'>";
        echo "<strong>Sur Linux, exécutez :</strong><br>";
        echo "<code>chmod -R 755 " . htmlspecialchars($moduleDir) . "</code><br><br>";
        echo "<strong>Si nécessaire :</strong><br>";
        echo "<code>chown -R www-data:www-data " . htmlspecialchars($moduleDir) . "</code>";
        echo "</div>";
    }
    exit;
}

logMsg("✅ Permissions OK", 'success');

// 1. Télécharger le ZIP
logMsg("📥 Téléchargement depuis GitHub...");
$zipUrl = "https://github.com/{$githubRepo}/archive/{$branch}.zip";
$zipContent = false;

// Essayer cURL d'abord
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $zipUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_USERAGENT, 'IANSEO-SelfRegistration-Updater');

    $zipContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $zipContent === false) {
        logMsg("Échec cURL (code " . $httpCode . ")", 'error');
        $zipContent = false;
    } else {
        logMsg("Téléchargement réussi via cURL (" . strlen($zipContent) . " octets)", 'success');
    }
}

// Fallback: file_get_contents
if ($zipContent === false && ini_get('allow_url_fopen')) {
    logMsg("Essai avec file_get_contents...", 'warning');
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
        'http' => [
            'timeout' => 60
        ]
    ]);

    $zipContent = @file_get_contents($zipUrl, false, $context);

    if ($zipContent !== false) {
        logMsg("Téléchargement réussi via file_get_contents (" . strlen($zipContent) . " octets)", 'success');
    } else {
        logMsg("Échec du téléchargement", 'error');
    }
}

if ($zipContent === false) {
    logMsg("❌ Impossible de télécharger. Vérifiez la connexion Internet.", 'error');
    echo "<div style='margin-top: 20px; text-align: center;'>";
    echo "<a href='selfregistration.php' class='btn btn-secondary'>← Retour</a>";
    echo "</div>";
    exit;
}

// 2. Extraire
logMsg("📦 Extraction de l'archive...");
$tempZip = tempnam(sys_get_temp_dir(), 'selfregistration_') . '.zip';
file_put_contents($tempZip, $zipContent);

if (!class_exists('ZipArchive')) {
    logMsg("❌ Extension ZipArchive non disponible", 'error');
    @unlink($tempZip);
    exit;
}

$zip = new ZipArchive;
if ($zip->open($tempZip) !== TRUE) {
    logMsg("❌ Erreur lors de l'ouverture du ZIP", 'error');
    @unlink($tempZip);
    exit;
}

// Anti zip-slip : refuse les chemins absolus et les remontées de répertoire.
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entry = str_replace('\\', '/', (string)$zip->getNameIndex($i));
    if ($entry === ''
        || strpos($entry, "\0") !== false
        || preg_match('#(^/|^[A-Za-z]:|(^|/)\.\.(/|$))#', $entry)) {
        logMsg("❌ Entrée ZIP invalide : " . $entry, 'error');
        $zip->close();
        @unlink($tempZip);
        exit;
    }
}

$tempDir = sys_get_temp_dir() . '/' . 'selfregistration_' . time();
mkdir($tempDir, 0755, true);

$extractResult = $zip->extractTo($tempDir);
$zip->close();

if (!$extractResult) {
    logMsg("❌ Échec de l'extraction", 'error');
    @unlink($tempZip);
    @rmdir($tempDir);
    exit;
}

logMsg("✅ Archive extraite", 'success');
@unlink($tempZip);

// 3. Trouver le dossier extrait (GitHub crée un dossier inaseo-SelfRegistration-main)
$items = scandir($tempDir);
$sourceDir = '';
foreach ($items as $item) {
    if ($item != '.' && $item != '..' && is_dir($tempDir . '/' . $item)) {
        $sourceDir = $tempDir . '/' . $item;
        break;
    }
}

if (empty($sourceDir)) {
    logMsg("❌ Archive vide ou structure incorrecte", 'error');
    exit;
}

logMsg("📂 Dossier source trouvé : " . basename($sourceDir), 'info');

// 4. Copier les fichiers avec exclusion
logMsg("📝 Copie des fichiers...");

$count = 0;
$errorCount = 0;
$skippedCount = 0;

// Fichiers à ne PAS remplacer s'ils existent déjà (chemins relatifs au module)
$protectedFiles = ['/enroll/config.php'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    if ($item->isFile()) {
        // Calculer le chemin relatif depuis le dossier source
        $relativePath = substr($item->getPathname(), strlen($sourceDir));

        // Normaliser les séparateurs et le chemin relatif
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = '/' . ltrim($relativePath, '/');

        // Refuser toute remontée de répertoire (défense en profondeur)
        if (strpos($relativePath, '..') !== false) {
            logMsg("❌ Chemin invalide ignoré : " . $relativePath, 'error');
            $errorCount++;
            continue;
        }

        // Construire le chemin de destination dans $moduleDir
        $destPath = $moduleDir . $relativePath;

        // Vérifier si c'est un fichier protégé qui existe déjà
        if (in_array($relativePath, $protectedFiles, true) && file_exists($destPath)) {
            logMsg("🔒 Fichier protégé conservé : " . $relativePath, 'info');
            $skippedCount++;
            continue;
        }

        // Ignorer les fichiers .git
        if (strpos($relativePath, '/.git') !== false || strpos($relativePath, '\.git') !== false) {
            continue;
        }

        // Créer le répertoire de destination si nécessaire
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true)) {
                logMsg("Impossible de créer : " . $destDir, 'error');
                $errorCount++;
                continue;
            }
        }

        // Copie du fichier
        if (copy($item->getPathname(), $destPath)) {
            $count++;

            // Ajuster les permissions sur Linux
            if (!$isWindows) {
                @chmod($destPath, 0644);
            }

            // Afficher progression tous les 5 fichiers
            if ($count % 5 === 0) {
                logMsg($count . " fichiers copiés...");
                flush();
            }
        } else {
            logMsg("Échec copie : " . basename($item->getPathname()), 'error');
            $errorCount++;
        }
    }
}

// 5. Nettoyer
logMsg("🧹 Nettoyage des fichiers temporaires...");
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($files as $file) {
    if ($file->isDir()) {
        @rmdir($file->getPathname());
    } else {
        @unlink($file->getPathname());
    }
}
@rmdir($tempDir);

logMsg("✅ Nettoyage terminé", 'success');

// Résumé
echo "<div style='margin: 30px 0; padding: 20px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 5px;'>";
echo "<h2 style='margin-top: 0; color: #155724;'>✅ Mise à jour terminée avec succès !</h2>";
echo "<p><strong>Fichiers copiés :</strong> " . $count . "</p>";

if ($skippedCount > 0) {
    echo "<p><strong>Fichiers protégés conservés :</strong> " . $skippedCount . "</p>";
}

if ($errorCount > 0) {
    echo "<p style='color: #dc3545;'><strong>Erreurs :</strong> " . $errorCount . "</p>";
}
echo "</div>";

// Vérifier quelques fichiers importants
echo "<h3>🔍 Vérification rapide :</h3>";
echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>";

$checkFiles = [
    'index.html' => $moduleDir . '/enroll/index.html',
    'process.php' => $moduleDir . '/enroll/process.php',
    'js/script.js' => $moduleDir . '/enroll/js/script.js',
    'css/style.css' => $moduleDir . '/enroll/css/style.css',
    'admin/selfregistration.php' => $moduleDir . '/admin/selfregistration.php',
    'admin/github_update.php' => $moduleDir . '/admin/github_update.php',
    'admin/send_recap.php' => $moduleDir . '/admin/send_recap.php',
    'config.php (protégé)' => $moduleDir . '/enroll//config.php',
    'README.md' => $moduleDir . '/README.md',
    'index.php' => $moduleDir . '/index.php',
];

foreach ($checkFiles as $name => $path) {
    if (file_exists($path)) {
        $status = strpos($name, 'protégé') !== false ? 'conservé' : 'présent';
        $icon = strpos($name, 'protégé') !== false ? '🔒' : '✅';
        echo "<div style='padding: 5px 0;'>" . $icon . " <strong>" . htmlspecialchars($name) . "</strong> - " . $status . "</div>";
    } else {
        if (strpos($name, 'protégé') === false) {
            echo "<div style='padding: 5px 0; color: #dc3545;'>❌ <strong>" . htmlspecialchars($name) . "</strong> - absent</div>";
        } else {
            echo "<div style='padding: 5px 0; color: #6c757d;'>ℹ️ <strong>" . htmlspecialchars($name) . "</strong> - non présent</div>";
        }
    }
}

echo "</div>";

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='selfregistration.php' class='btn'>🏠 Retour à l'administration</a>";
echo "</div>";

?>

</div>
</body>
</html>
