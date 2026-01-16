<?php
/**
 * Page d'administration pour la gestion des compétitions
 * Emplacement : /var/www/html/Modules/Custom/SelfRegistration/selfregistration.php
 */

// Charger la configuration Ianseo - EXACTEMENT COMME process.php
$ianseoRoot = dirname(__DIR__, 3);
$configPath = $ianseoRoot . '/Common/config.inc.php';

if (!file_exists($configPath)) {
    die("Erreur : Configuration IANSEO introuvable à " . htmlspecialchars($configPath));
}

// Initialiser CFG global - EXACTEMENT COMME DANS process.php
global $CFG;
$CFG = new stdClass();
include_once($configPath);

// Compatibilité : Créer WDB depuis DBNAME si nécessaire - COMME process.php
if (!isset($CFG->WDB) && isset($CFG->DBNAME)) {
    $CFG->WDB = $CFG->DBNAME;
}

// Connexion à la base de données pour récupérer les tournois
$conn = null;
$availableTournaments = [];
$dbError = null;

try {
    // Vérifier les variables nécessaires - avec underscores
    if (!isset($CFG->W_HOST) || !isset($CFG->W_USER) || !isset($CFG->W_PASS) || !isset($CFG->DB_NAME)) {
        throw new Exception("Configuration IANSEO incomplète");
    }
    
    $conn = mysqli_connect($CFG->W_HOST, $CFG->W_USER, $CFG->W_PASS, $CFG->DB_NAME);
    if (!$conn) {
        throw new Exception("Erreur connexion DB: " . mysqli_connect_error());
    }
    mysqli_set_charset($conn, 'utf8mb4');
    
    // Récupérer tous les tournois
    $query = "SELECT ToId, ToName, ToWhenFrom, ToWhenTo FROM Tournament ORDER BY ToWhenFrom DESC, ToId DESC";
    $result = mysqli_query($conn, $query);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $availableTournaments[$row['ToId']] = [
                'name' => $row['ToName'],
                'from' => $row['ToWhenFrom'],
                'to' => $row['ToWhenTo']
            ];
        }
    }
} catch (Exception $e) {
    $dbError = $e->getMessage();
}


// Chemin du fichier de configuration
$configFile = __DIR__ . '/config.php';

// Initialiser les compétitions
$competitions = [];
$mailfrom = 'noreply@ianseo.net';

if (file_exists($configFile)) {
    if (!defined('CONFIG_ACCESS')) {
        define('CONFIG_ACCESS', true);
    }
    
    // Charger le fichier config
    $loadedConfig = include $configFile;
    
    // Gérer les deux formats possibles
    if (is_array($loadedConfig) && isset($loadedConfig['tournaments'])) {
        $competitions = $loadedConfig['tournaments'];
        $mailfrom = $loadedConfig['mail_from'] ?? 'noreply@ianseo.net';
    } elseif (isset($tournaments)) {
        $competitions = $tournaments;
    } elseif (isset($config) && is_array($config) && isset($config['tournaments'])) {
        $competitions = $config['tournaments'];
        $mailfrom = $config['mail_from'] ?? 'noreply@ianseo.net';
    }
}

// Fonction pour générer un token aléatoire
function generateToken($length = 20) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $token = '';
    for ($i = 0; $i < $length; $i++) {
        $token .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $token;
}

// Traitement des actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $id = trim($_POST['id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $token = trim($_POST['token'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        
        if (empty($id) || empty($name) || empty($token)) {
            $message = 'Les champs ID, Nom et Token sont obligatoires.';
            $messageType = 'error';
        } elseif (!isset($availableTournaments[$id])) {
            $message = 'Le tournoi sélectionné n\'existe pas dans IANSEO.';
            $messageType = 'error';
        } elseif (!empty($adminEmail) && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'L\'adresse email admin n\'est pas valide.';
            $messageType = 'error';
        } else {
            $competitions[$id] = [
                'name' => $name,
                'token' => $token,
                'admin_email' => $adminEmail
            ];
            
            if (saveConfig($configFile, $competitions, $mailfrom)) {
                $message = $action === 'add' ? 'Compétition ajoutée avec succès.' : 'Compétition modifiée avec succès.';
                $messageType = 'success';
            } else {
                $message = 'Erreur lors de l\'enregistrement. Vérifiez les permissions d\'écriture.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        if (isset($competitions[$id])) {
            unset($competitions[$id]);
            if (saveConfig($configFile, $competitions, $mailfrom)) {
                $message = 'Compétition supprimée avec succès.';
                $messageType = 'success';
            } else {
                $message = 'Erreur lors de la suppression.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'update_mailfrom') {
        $newMailFrom = trim($_POST['mail_from'] ?? '');
        if (empty($newMailFrom) || !filter_var($newMailFrom, FILTER_VALIDATE_EMAIL)) {
            $message = 'L\'adresse email d\'expédition n\'est pas valide.';
            $messageType = 'error';
        } else {
            $mailfrom = $newMailFrom;
            if (saveConfig($configFile, $competitions, $mailfrom)) {
                $message = 'Email d\'expédition mis à jour avec succès.';
                $messageType = 'success';
            } else {
                $message = 'Erreur lors de la mise à jour.';
                $messageType = 'error';
            }
        }
    }
}

/**
 * Sauvegarde les compétitions dans le fichier config.php
 */
function saveConfig($file, $competitions, $mailfrom) {
    $content = "<?php\n";
    $content .= "// Configuration globale pour l'auto-inscription IANSEO\n";
    $content .= "// Ce fichier ne doit pas être accessible directement depuis le web\n";
    $content .= "// Dernière modification : " . date('Y-m-d H:i:s') . "\n\n";
    $content .= "// Empêcher l'accès direct\n";
    $content .= "if (!defined('CONFIG_ACCESS')) {\n";
    $content .= "    die('Accès direct interdit');\n";
    $content .= "}\n\n";
    $content .= "// Configuration globale de l'adresse d'expédition des emails\n";
    $content .= "\$config = [\n";
    $content .= "    'mail_from' => " . var_export($mailfrom, true) . ",\n\n";
    $content .= "    // Configurations des tournois\n";
    $content .= "    // Format: tournament_id => ['token' => 'xxx', 'admin_email' => 'xxx']\n";
    $content .= "    'tournaments' => [\n";
    
    foreach ($competitions as $id => $comp) {
        $content .= "        " . var_export($id, true) . " => [\n";
        $content .= "            'name' => " . var_export($comp['name'] ?? '', true) . ",\n";
        $content .= "            'token' => " . var_export($comp['token'], true) . ",\n";
        $content .= "            'admin_email' => " . var_export($comp['admin_email'] ?? '', true) . ",\n";
        $content .= "        ],\n\n";
    }
    
    $content .= "        // Ajoutez d'autres tournois ici\n";
    $content .= "    ]\n";
    $content .= "];\n\n";
    $content .= "return \$config;\n";
    $content .= "?>\n";
    
    return file_put_contents($file, $content) !== false;
}

// Récupérer l'ID de compétition à éditer
$editId = $_GET['edit'] ?? '';
$editComp = $editId && isset($competitions[$editId]) ? $competitions[$editId] : null;

// Générer un token par défaut pour les nouveaux ajouts
$defaultToken = $editComp ? $editComp['token'] : generateToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - Gestion des Compétitions</title>
    <link rel="stylesheet" href="css/style.css">
    
    <script>
        function generateNewToken() {
            const chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            let token = '';
            for (let i = 0; i < 20; i++) {
                token += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('token').value = token;
        }
        
        function updateTournamentName() {
            const select = document.getElementById('id');
            const nameInput = document.getElementById('name');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value && selectedOption.dataset.name) {
                nameInput.value = selectedOption.dataset.name;
            }
        }
    </script>
</head>
<body>
    <div class="container">
        <h1>🏹 Administration des Compétitions</h1>
        
        <?php if (isset($dbError)): ?>
            <div class="message warning">
                ⚠️ Attention : Impossible de se connecter à la base IANSEO. <?php echo htmlspecialchars($dbError); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Configuration globale -->
        <div class="config-section">
            <h2>⚙️ Configuration globale</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_mailfrom">
                <div class="form-group">
                    <label for="mail_from">Email d'expédition (From)</label>
                    <input type="email" 
                           id="mail_from" 
                           name="mail_from" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($mailfrom); ?>"
                           required>
                    <small>Adresse email utilisée comme expéditeur pour tous les emails envoyés</small>
                </div>
                <button type="submit" class="btn btn-primary">💾 Mettre à jour</button>
            </form>
        </div>
        
        <h2><?php echo $editComp ? '✏️ Modifier la compétition' : '➕ Ajouter une compétition'; ?></h2>
        
        <?php if (empty($availableTournaments)): ?>
            <div class="message warning">
                ⚠️ Aucun tournoi trouvé dans IANSEO. Veuillez d'abord créer un tournoi dans IANSEO.
            </div>
        <?php else: ?>
            <form method="POST" action="">
                <input type="hidden" name="action" value="<?php echo $editComp ? 'edit' : 'add'; ?>">
                
                <div class="form-group">
                    <label for="id">Tournoi IANSEO *</label>
                    <select id="id" 
                            name="id" 
                            class="form-control" 
                            required
                            <?php echo $editComp ? 'disabled' : ''; ?>
                            onchange="updateTournamentName()">
                        <option value="">-- Sélectionner un tournoi --</option>
                        <?php foreach ($availableTournaments as $tid => $tdata): ?>
                            <option value="<?php echo $tid; ?>" 
                                    data-name="<?php echo htmlspecialchars($tdata['name']); ?>"
                                    <?php echo ($editId == $tid) ? 'selected' : ''; ?>>
                                [<?php echo $tid; ?>] <?php echo htmlspecialchars($tdata['name']); ?>
                                <?php if ($tdata['from']): ?>
                                    (<?php echo date('d/m/Y', strtotime($tdata['from'])); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($editComp): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editId); ?>">
                    <?php endif; ?>
                    <small>Sélectionnez le tournoi IANSEO à lier au formulaire d'inscription</small>
                </div>
                
                <div class="form-group">
                    <label for="name">Nom d'affichage *</label>
                    <input type="text" 
                           id="name" 
                           name="name" 
                           class="form-control" 
                           value="<?php echo $editComp ? htmlspecialchars($editComp['name'] ?? '') : ''; ?>"
                           placeholder="Sera rempli automatiquement"
                           required>
                    <small>Nom affiché sur le formulaire d'inscription (rempli automatiquement depuis IANSEO)</small>
                </div>
                
                <div class="form-group">
                    <label for="token">Token d'accès *</label>
                    <div class="token-input-group">
                        <input type="text" 
                               id="token" 
                               name="token" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($defaultToken); ?>"
                               placeholder="Token de sécurité"
                               required
                               style="padding-right: 140px;">
                        <button type="button" class="btn btn-secondary" onclick="generateNewToken()">🔄 Régénérer</button>
                    </div>
                    <small>Token de sécurité pour l'accès au formulaire (cliquez sur Régénérer pour un nouveau token)</small>
                </div>
                
                <div class="form-group">
                    <label for="admin_email">Email administrateur</label>
                    <input type="email" 
                           id="admin_email" 
                           name="admin_email" 
                           class="form-control" 
                           value="<?php echo $editComp ? htmlspecialchars($editComp['admin_email'] ?? '') : ''; ?>"
                           placeholder="Ex: admin@example.com">
                    <small>Email pour recevoir les notifications d'inscription (optionnel)</small>
                </div>
                
                <div class="form-buttons">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $editComp ? '💾 Enregistrer' : '➕ Ajouter'; ?>
                    </button>
                    <?php if ($editComp): ?>
                        <a href="selfregistration.php" class="btn btn-cancel">❌ Annuler</a>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
        
        <h2>📋 Liste des compétitions (<?php echo count($competitions); ?>)</h2>
        
        <?php if (empty($competitions)): ?>
            <div class="empty-state">
                Aucune compétition configurée. Ajoutez-en une ci-dessus.
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nom</th>
                        <th>Token</th>
                        <th>Email Admin</th>
                        <th>URL d'inscription</th>
                        <th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($competitions as $id => $comp): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($id); ?></code></td>
                            <td>
                                <?php echo htmlspecialchars($comp['name'] ?? 'Non défini'); ?>
                                <?php if (!isset($availableTournaments[$id])): ?>
                                    <br><small style="color: #dc3545;">⚠️ Tournoi introuvable dans IANSEO</small>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($comp['token']); ?></code></td>
                            <td><?php echo !empty($comp['admin_email']) ? htmlspecialchars($comp['admin_email']) : '<em style="color: #999;">Non défini</em>'; ?></td>
                            <td>
                                <a href="index.html?tournament=<?php echo urlencode($id); ?>&token=<?php echo urlencode($comp['token']); ?>" 
                                   target="_blank"
                                   class="url-link">
                                    🔗 Ouvrir le formulaire
                                </a>
                            </td>
                            <td class="actions">
                                <a href="?edit=<?php echo urlencode($id); ?>" class="btn btn-warning">✏️ Modifier</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette compétition ?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
                                    <button type="submit" class="btn btn-danger">🗑️ Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
