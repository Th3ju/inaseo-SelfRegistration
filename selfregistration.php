<?php
// Page d'administration du module SelfRegistration
//require_once(dirname(__DIR__, 3) . '/Common/Fun_HTTP.inc.php');
require_once(dirname(__DIR__, 3) . '/Common/Lib/CommonLib.php');

// Vérification de l'authentification
CheckTourSession(true);
checkACL(AclCompetition, AclReadWrite, false);

$PAGE_TITLE = 'Auto-inscription en ligne';

// Charger la configuration actuelle
define('CONFIG_ACCESS', true);
$config_file = __DIR__ . '/config.php';
$current_config = [];

if (file_exists($config_file)) {
    $config_data = require $config_file;
    $current_config = $config_data;
}

// Traitement du formulaire
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_mail':
                $mail_from = trim($_POST['mail_from']);
                if (filter_var($mail_from, FILTER_VALIDATE_EMAIL)) {
                    $current_config['mail_from'] = $mail_from;
                    saveConfig($current_config);
                    $message = "Adresse email mise à jour avec succès.";
                } else {
                    $error = "Adresse email invalide.";
                }
                break;

            case 'add_tournament':
                $tournament_id = intval($_POST['tournament_id']);
                $token = trim($_POST['token']);
                $admin_email = trim($_POST['admin_email']);

                if ($tournament_id > 0 && !empty($token) && filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                    if (!isset($current_config['tournaments'])) {
                        $current_config['tournaments'] = [];
                    }
                    $current_config['tournaments'][$tournament_id] = [
                        'token' => $token,
                        'admin_email' => $admin_email
                    ];
                    saveConfig($current_config);
                    $message = "Tournoi ajouté avec succès.";
                } else {
                    $error = "Données invalides. Vérifiez tous les champs.";
                }
                break;

            case 'delete_tournament':
                $tournament_id = intval($_POST['tournament_id']);
                if (isset($current_config['tournaments'][$tournament_id])) {
                    unset($current_config['tournaments'][$tournament_id]);
                    saveConfig($current_config);
                    $message = "Tournoi supprimé avec succès.";
                } else {
                    $error = "Tournoi introuvable.";
                }
                break;

            case 'generate_token':
                // Génération d'un token aléatoire sécurisé
                $token = bin2hex(random_bytes(16));
                echo json_encode(['token' => $token]);
                exit;
        }
    }
}

// Fonction pour sauvegarder la configuration
function saveConfig($config) {
    global $config_file;

    $content = "<?php
";
    $content .= "// Configuration globale pour l'auto-inscription IANSEO
";
    $content .= "// Ce fichier ne doit pas être accessible directement depuis le web

";
    $content .= "// Empêcher l'accès direct
";
    $content .= "if (!defined('CONFIG_ACCESS')) {
";
    $content .= "    die('Accès direct interdit');
";
    $content .= "}

";
    $content .= "// Configuration globale de l'adresse d'expédition des emails
";
    $content .= "\$config = [
";
    $content .= "    'mail_from' => " . var_export($config['mail_from'], true) . ",

";
    $content .= "    // Configurations des tournois
";
    $content .= "    // Format: tournament_id => ['token' => 'xxx', 'admin_email' => 'xxx']
";
    $content .= "    'tournaments' => [
";

    if (isset($config['tournaments']) && is_array($config['tournaments'])) {
        foreach ($config['tournaments'] as $tid => $tconfig) {
            $content .= "        {$tid} => [
";
            $content .= "            'token' => " . var_export($tconfig['token'], true) . ",
";
            $content .= "            'admin_email' => " . var_export($tconfig['admin_email'], true) . "
";
            $content .= "        ],
";
        }
    }

    $content .= "    ]
";
    $content .= "];

";
    $content .= "return \$config;
";

    file_put_contents($config_file, $content);
}

// Récupérer la liste des tournois disponibles
$mysqli = new mysqli($CFG->W_HOST, $CFG->W_USER, $CFG->W_PASS, $CFG->W_DB);
$tournaments = [];

if (!$mysqli->connect_error) {
    $result = $mysqli->query("SELECT ToId, ToName, ToWhenFrom, ToWhenTo FROM Tournament ORDER BY ToWhenFrom DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $tournaments[$row['ToId']] = $row;
        }
    }
    $mysqli->close();
}

include('Common/Templates/head.php');
?>

<style>
.config-section {
    background: white;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.config-section h3 {
    margin-top: 0;
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    font-weight: bold;
    margin-bottom: 5px;
    color: #555;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-sizing: border-box;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    margin-right: 5px;
}

.btn-primary {
    background: #3498db;
    color: white;
}

.btn-primary:hover {
    background: #2980b9;
}

.btn-success {
    background: #27ae60;
    color: white;
}

.btn-success:hover {
    background: #229954;
}

.btn-danger {
    background: #e74c3c;
    color: white;
}

.btn-danger:hover {
    background: #c0392b;
}

.alert {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.tournament-list {
    list-style: none;
    padding: 0;
}

.tournament-item {
    background: #f8f9fa;
    padding: 15px;
    margin-bottom: 10px;
    border-radius: 4px;
    border-left: 4px solid #3498db;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.tournament-info {
    flex: 1;
}

.tournament-info strong {
    color: #2c3e50;
    font-size: 16px;
}

.tournament-details {
    margin-top: 5px;
    color: #666;
    font-size: 14px;
}

.tournament-url {
    background: #e9ecef;
    padding: 8px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 12px;
    margin-top: 5px;
    word-break: break-all;
}

.copy-btn {
    background: #6c757d;
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 3px;
    cursor: pointer;
    font-size: 12px;
    margin-left: 5px;
}

.copy-btn:hover {
    background: #5a6268;
}

.token-generator {
    display: flex;
    gap: 10px;
    align-items: center;
}

.token-generator input {
    flex: 1;
}
</style>

<div class="container">
    <h1><?php echo $PAGE_TITLE; ?></h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Configuration globale -->
    <div class="config-section">
        <h3>Configuration globale</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_mail">
            <div class="form-group">
                <label for="mail_from">Adresse d'expédition des emails :</label>
                <input type="email" id="mail_from" name="mail_from" 
                       value="<?php echo htmlspecialchars($current_config['mail_from'] ?? ''); ?>" required>
            </div>
            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>

    <!-- Liste des tournois configurés -->
    <div class="config-section">
        <h3>Tournois configurés</h3>
        <?php if (isset($current_config['tournaments']) && count($current_config['tournaments']) > 0): ?>
            <ul class="tournament-list">
                <?php foreach ($current_config['tournaments'] as $tid => $tconfig): ?>
                    <li class="tournament-item">
                        <div class="tournament-info">
                            <strong>
                                <?php echo isset($tournaments[$tid]) ? htmlspecialchars($tournaments[$tid]['ToName']) : "Tournoi #$tid"; ?>
                            </strong>
                            <div class="tournament-details">
                                ID: <?php echo $tid; ?> | 
                                Token: <code><?php echo htmlspecialchars($tconfig['token']); ?></code> | 
                                Admin: <?php echo htmlspecialchars($tconfig['admin_email']); ?>
                            </div>
                            <div class="tournament-url">
                                <strong>URL d'inscription :</strong><br>
                                <?php 
                                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
                                $inscription_url = $base_url . "/Modules/Custom/SelfRegistration/index.html?tournament={$tid}&token=" . urlencode($tconfig['token']);
                                echo htmlspecialchars($inscription_url);
                                ?>
                                <button class="copy-btn" onclick="copyToClipboard('<?php echo addslashes($inscription_url); ?>')">Copier</button>
                            </div>
                        </div>
                        <div>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce tournoi ?');">
                                <input type="hidden" name="action" value="delete_tournament">
                                <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                                <button type="submit" class="btn btn-danger">Supprimer</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>Aucun tournoi configuré.</p>
        <?php endif; ?>
    </div>

    <!-- Ajouter un tournoi -->
    <div class="config-section">
        <h3>Ajouter un tournoi</h3>
        <form method="POST" id="add-tournament-form">
            <input type="hidden" name="action" value="add_tournament">

            <div class="form-group">
                <label for="tournament_id">Tournoi :</label>
                <select id="tournament_id" name="tournament_id" required>
                    <option value="">-- Sélectionner un tournoi --</option>
                    <?php foreach ($tournaments as $tid => $tournament): ?>
                        <?php if (!isset($current_config['tournaments'][$tid])): ?>
                            <option value="<?php echo $tid; ?>">
                                <?php echo htmlspecialchars($tournament['ToName']); ?> 
                                (<?php echo date('d/m/Y', strtotime($tournament['ToWhenFrom'])); ?>)
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="token">Token de sécurité :</label>
                <div class="token-generator">
                    <input type="text" id="token" name="token" required>
                    <button type="button" class="btn btn-success" onclick="generateToken()">Générer un token</button>
                </div>
                <small>Le token doit être partagé uniquement avec les personnes autorisées à créer le lien d'inscription.</small>
            </div>

            <div class="form-group">
                <label for="admin_email">Email administrateur :</label>
                <input type="email" id="admin_email" name="admin_email" required>
                <small>Cet email recevra une copie de chaque inscription.</small>
            </div>

            <button type="submit" class="btn btn-primary">Ajouter le tournoi</button>
        </form>
    </div>
</div>

<script>
function generateToken() {
    fetch('selfregistration.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=generate_token'
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('token').value = data.token;
    })
    .catch(error => {
        alert('Erreur lors de la génération du token');
    });
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('URL copiée dans le presse-papier !');
    }).catch(err => {
        // Fallback pour les anciens navigateurs
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('URL copiée dans le presse-papier !');
    });
}
</script>

<?php
include('Common/Templates/tail.php');
?>
