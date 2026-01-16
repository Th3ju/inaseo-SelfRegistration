<?php
/**
 * Page d'administration pour la gestion des compétitions
 * Emplacement : /var/www/html/Modules/Custom/SelfRegistration/selfregistration.php
 */

// Charger la configuration Ianseo
$ianseoRoot = dirname(__DIR__, 3);
$configPath = $ianseoRoot . '/Common/config.inc.php';

if (!file_exists($configPath)) {
    die("Erreur : Configuration IANSEO introuvable à " . htmlspecialchars($configPath));
}

// Initialiser CFG global
global $CFG;
$CFG = new stdClass();
include_once($configPath);

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
        // Format: return $config avec $config['tournaments']
        $competitions = $loadedConfig['tournaments'];
        $mailfrom = $loadedConfig['mail_from'] ?? 'noreply@ianseo.net';
    } elseif (isset($tournaments)) {
        // Format: $tournaments directement défini
        $competitions = $tournaments;
    } elseif (isset($config) && is_array($config) && isset($config['tournaments'])) {
        // Format: $config['tournaments']
        $competitions = $config['tournaments'];
        $mailfrom = $config['mail_from'] ?? 'noreply@ianseo.net';
    }
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - Gestion des Compétitions</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 40px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
        }
        
        h2 {
            color: #667eea;
            margin-top: 30px;
            margin-bottom: 20px;
            font-size: 20px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .message.success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        
        .message.error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        
        .config-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border: 2px solid #e0e0e0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 600;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-control[readonly] {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }
        
        small {
            display: block;
            margin-top: 5px;
            color: #999;
            font-size: 14px;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 8px 16px;
            font-size: 14px;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }
        
        .btn-warning {
            background: #ffc107;
            color: #333;
            padding: 8px 16px;
            font-size: 14px;
            margin-right: 10px;
        }
        
        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
            margin-left: 10px;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        th {
            background: #f8f9fa;
            color: #333;
            font-weight: 600;
        }
        
        tr:hover {
            background: #f8f9ff;
        }
        
        .actions {
            white-space: nowrap;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
            font-style: italic;
        }
        
        .form-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        
        .url-link {
            color: #667eea;
            text-decoration: none;
        }
        
        .url-link:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            
            table {
                font-size: 14px;
            }
            
            th, td {
                padding: 8px;
            }
            
            .btn {
                padding: 10px 20px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏹 Administration des Compétitions</h1>
        
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
        
        <form method="POST" action="">
            <input type="hidden" name="action" value="<?php echo $editComp ? 'edit' : 'add'; ?>">
            
            <div class="form-group">
                <label for="id">ID de la compétition *</label>
                <input type="text" 
                       id="id" 
                       name="id" 
                       class="form-control" 
                       value="<?php echo $editId ? htmlspecialchars($editId) : ''; ?>"
                       <?php echo $editComp ? 'readonly' : ''; ?>
                       placeholder="Ex: 124"
                       required>
                <small>Numéro du tournoi IANSEO (ToId dans la base de données)</small>
            </div>
            
            <div class="form-group">
                <label for="name">Nom de la compétition *</label>
                <input type="text" 
                       id="name" 
                       name="name" 
                       class="form-control" 
                       value="<?php echo $editComp ? htmlspecialchars($editComp['name'] ?? '') : ''; ?>"
                       placeholder="Ex: Championnat Régional 2026"
                       required>
                <small>Nom affiché sur le formulaire d'inscription</small>
            </div>
            
            <div class="form-group">
                <label for="token">Token d'accès *</label>
                <input type="text" 
                       id="token" 
                       name="token" 
                       class="form-control" 
                       value="<?php echo $editComp ? htmlspecialchars($editComp['token']) : ''; ?>"
                       placeholder="Ex: abc123xyz789"
                       required>
                <small>Token de sécurité pour l'accès au formulaire (générez un token aléatoire)</small>
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
                            <td><?php echo htmlspecialchars($comp['name'] ?? 'Non défini'); ?></td>
                            <td><code><?php echo htmlspecialchars($comp['token']); ?></code></td>
                            <td><?php echo !empty($comp['admin_email']) ? htmlspecialchars($comp['admin_email']) : '<em style="color: #999;">Non défini</em>'; ?></td>
                            <td>
                                <a href="index.html?tournament_id=<?php echo urlencode($id); ?>&token=<?php echo urlencode($comp['token']); ?>" 
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
