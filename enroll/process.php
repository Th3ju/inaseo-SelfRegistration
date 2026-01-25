<?php
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
error_reporting(E_ALL);
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Fatal PHP: ' . $err['message'],
            'debug' => ['file'=>$err['file'], 'line'=>$err['line']]
        ], JSON_UNESCAPED_UNICODE);
    }
});

/* ===== DEBUG ===== */
define('DEBUG_MODE', true);
function dbg($arr) { return DEBUG_MODE ? $arr : null; }

/* ===== JSON helpers ===== */
function sendError($message, $debug = null) {
    echo json_encode(['success'=>false,'error'=>$message,'debug'=>dbg($debug)], JSON_UNESCAPED_UNICODE);
    exit;
}
function sendSuccess($data, $message = '', $debug = null) {
    echo json_encode(['success'=>true,'message'=>$message,'data'=>$data,'debug'=>dbg($debug)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== misc helpers ===== */
function formatProperName($name) {
    $name = mb_strtolower(trim((string)$name), 'UTF-8');
    return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

function loadConfig($tournamentId=null) {
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) return null;

    if (!defined('CONFIG_ACCESS')) define('CONFIG_ACCESS', true);
    $configData = require $configFile;

    $config = [
        'mail_from' => $configData['mail_from'] ?? 'noreply@ianseo.net',
        'tournament' => null
    ];

    if ($tournamentId !== null && isset($configData['tournaments'][$tournamentId])) {
        $t = $configData['tournaments'][$tournamentId];
        $config['tournament'] = [
            'tournamentid' => $tournamentId,
            'name' => $t['name'] ?? null,
            'token' => $t['token'] ?? '',
            'admin_email' => $t['admin_email'] ?? ''
        ];
    }
    return $config;
}

/* ===== Calcul catégorie d'âge FFTA (dynamique) ===== */
function calculateFFTAAgeCategory($dob, $refYear) {
    if (empty($dob)) return '';
    
    $birthDate = strtotime($dob);
    if ($birthDate === false) return '';
    
    // Catégories définies par leur âge minimum et maximum
    // [code, âge_min, âge_max]
    $categories = [
        ['S3', 60, 999],  // 60 ans et plus
        ['S2', 40, 59],   // 40 à 59 ans
        ['S1', 21, 39],   // 21 à 39 ans
        ['U21', 18, 20],  // 18 à 20 ans
        ['U18', 15, 17],  // 15 à 17 ans
        ['U15', 13, 14],  // 13 à 14 ans
        ['U13', 11, 12],  // 11 à 12 ans
        ['U11', 0, 10],   // 10 ans et moins
    ];
    
    // Pour chaque catégorie, on calcule si l'archer est dedans
    foreach ($categories as list($code, $ageMin, $ageMax)) {
        // Date limite basse : né après le 31.12.(refYear - ageMax - 1)
        $dateLimitLow = strtotime(($refYear - $ageMax - 1) . "-12-31");
        
        // Date limite haute : né avant le 01.01.(refYear - ageMin + 1)
        $dateLimitHigh = strtotime(($refYear - $ageMin) . "-01-01");
        
        // Vérification : né après limite basse ET avant limite haute
        if ($birthDate > $dateLimitLow && $birthDate < $dateLimitHigh) {
            return $code;
        }
    }
    
    return '';
}

/* ===== email (HTML + détails par départ) ===== */
function sendRegistrationEmail(mysqli $conn, int $tournamentId, string $userEmail, string $adminEmail, array $userData, string $tournamentName, string $fromEmail = 'noreply@ianseo.net') {
    $license   = htmlspecialchars($userData['license'] ?? '');
    $name      = htmlspecialchars(formatProperName($userData['name'] ?? ''));
    $firstname = htmlspecialchars(formatProperName($userData['firstname'] ?? ''));

    $getSessionDateTime = function(int $sessionId) use ($conn, $tournamentId) {
        $q = "SELECT DiDay, DiStart
              FROM DistanceInformation
              WHERE DiTournament = ? AND DiSession = ? AND DiDistance = 1
              LIMIT 1";
        $st = mysqli_prepare($conn, $q);
        mysqli_stmt_bind_param($st, 'ii', $tournamentId, $sessionId);
        mysqli_stmt_execute($st);
        $r = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($r);
        if (!$row) return '';

        $day = (string)$row['DiDay'];
        $start = (string)$row['DiStart'];
        $tp = explode(':', $start);
        if (count($tp) >= 2) $start = $tp[0] . ':' . $tp[1];

        // Date FR: 2026-01-30 => 30 Janvier 2026 (uniquement pour le mail)
        $dateFr = $day;
        $parts = explode('-', $day);
        if (count($parts) === 3) {
            $months = [
                '01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin',
                '07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre'
            ];
            $dateFr = intval($parts[2]) . ' ' . ($months[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
        }

        return trim($dateFr . ' ' . $start);
    };

    $getDivisionLabel = function(string $divId) use ($conn, $tournamentId) {
        $q = "SELECT DivDescription FROM Divisions WHERE DivTournament = ? AND DivId = ? LIMIT 1";
        $st = mysqli_prepare($conn, $q);
        mysqli_stmt_bind_param($st, 'is', $tournamentId, $divId);
        mysqli_stmt_execute($st);
        $r = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($r);
        return $row ? (string)$row['DivDescription'] : $divId;
    };

    $getClassLabel = function(string $clId) use ($conn, $tournamentId) {
        $q = "SELECT ClDescription FROM Classes WHERE ClTournament = ? AND ClId = ? LIMIT 1";
        $st = mysqli_prepare($conn, $q);
        mysqli_stmt_bind_param($st, 'is', $tournamentId, $clId);
        mysqli_stmt_execute($st);
        $r = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($r);
        return $row ? (string)$row['ClDescription'] : $clId;
    };

    $getTargetFaceName = function(int $tfId) use ($conn, $tournamentId) {
        $q = "SELECT TfName FROM TargetFaces WHERE TfTournament = ? AND TfId = ? LIMIT 1";
        $st = mysqli_prepare($conn, $q);
        mysqli_stmt_bind_param($st, 'ii', $tournamentId, $tfId);
        mysqli_stmt_execute($st);
        $r = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($r);
        return $row ? (string)$row['TfName'] : (string)$tfId;
    };

    $getDistanceLabel = function(string $divId, string $clId) use ($conn, $tournamentId) {
        $tq = "SELECT ToType FROM Tournament WHERE ToId = ? LIMIT 1";
        $tst = mysqli_prepare($conn, $tq);
        mysqli_stmt_bind_param($tst, 'i', $tournamentId);
        mysqli_stmt_execute($tst);
        $tr = mysqli_stmt_get_result($tst);
        $trow = mysqli_fetch_assoc($tr);
        if (!$trow) return '';

        $toType = intval($trow['ToType']);
        $tdClass = $divId . $clId;

        $q = "SELECT TdClasses, TdDist1, TdDist2, TdDist3, TdDist4, TdDist5, TdDist6, TdDist7, TdDist8
              FROM TournamentDistances
              WHERE TdTournament = ? AND TdType = ?";
        $st = mysqli_prepare($conn, $q);
        mysqli_stmt_bind_param($st, 'ii', $tournamentId, $toType);
        mysqli_stmt_execute($st);
        $r = mysqli_stmt_get_result($st);

        $best = null;
        $bestScore = -1;

        while ($row = mysqli_fetch_assoc($r)) {
            $pattern = (string)($row['TdClasses'] ?? '');
            if ($pattern === '') continue;

            $p = preg_quote($pattern, '/');
            $p = str_replace('%', '.*', $p);
            $p = str_replace('_', '.', $p);
            $rx = '/^' . $p . '$/';

            if (!preg_match($rx, $tdClass)) continue;

            $len = strlen($pattern);
            $hasPct = (strpos($pattern, '%') !== false);
            $hasUnd = (strpos($pattern, '_') !== false);
            $isSexGeneric = preg_match('/^[A-Z0-9]+_[MW]$/', $pattern);

            if (!$hasPct && !$hasUnd && !$isSexGeneric) $score = 400 + $len;
            else if ($hasUnd && !$hasPct) $score = 300 + $len;
            else if ($hasPct && !$hasUnd) $score = 200 + $len;
            else if ($hasPct && $hasUnd) $score = 180 + $len;
            else if ($isSexGeneric) $score = 100 + $len;
            else $score = $len;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        if (!$best) return '';

        $dist = [];
        for ($i=1; $i<=8; $i++) {
            $k = 'TdDist'.$i;
            $v = intval($best[$k] ?? 0);
            if ($v > 0) $dist[] = $v;
        }
        $uniq = array_values(array_unique($dist));
        if (count($uniq) === 1) return $uniq[0].'m';
        if (count($dist) > 0) return implode(' | ', array_map(fn($d)=>$d.'m', $dist));
        return '';
    };

    $sessionsHtml = "";
    $sessions = $userData['sessions'] ?? [];
    $choices = $userData['sessionChoices'] ?? [];

    if (is_array($sessions) && count($sessions) > 0) {
        foreach ($sessions as $sessionIdRaw) {
            $sessionId = intval($sessionIdRaw);
            $ch = $choices[(string)$sessionId] ?? $choices[$sessionId] ?? null;
            if (!$ch) continue;

            $divId = (string)($ch['division'] ?? '');
            $clId  = (string)($ch['ageclass'] ?? '');
            $tfId  = intval($ch['targetface'] ?? 0);

            $dt = $getSessionDateTime($sessionId);

            $divLabel = $divId ? $getDivisionLabel($divId) : '';
            $clLabel  = $clId ? $getClassLabel($clId) : '';
            $tfName   = $tfId ? $getTargetFaceName($tfId) : '';

            $distanceLabel = (string)($ch['distanceLabel'] ?? '');
            if ($distanceLabel === '' && $divId !== '' && $clId !== '') {
                $distanceLabel = $getDistanceLabel($divId, $clId);
            }

            $sessionsHtml .= "
              <div style='margin:12px 0;padding:12px;border:1px solid #e0e0e0;border-radius:8px;background:#fff;'>
                <div style='font-weight:bold;color:#333;margin-bottom:6px;'>Départ {$sessionId}</div>
                <div><strong>Date et heure :</strong> " . htmlspecialchars($dt) . "</div>
                <div><strong>Division :</strong> " . htmlspecialchars($divLabel) . "</div>
                <div><strong>Catégorie :</strong> " . htmlspecialchars($clLabel) . "</div>
                <div><strong>Distance :</strong> " . htmlspecialchars($distanceLabel) . "</div>
                <div><strong>Blason :</strong> " . htmlspecialchars($tfName) . "</div>
              </div>
            ";
        }
    }

    if ($sessionsHtml === "") {
        $sessionsHtml = "<p>Aucun départ.</p>";
    }

    $subjectUser = "Confirmation d'inscription - " . $tournamentName;

    $messageUser = "
    <html>
      <head>
        <style>
          body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
          .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; }
          .content { padding: 20px; background-color: #f9f9f9; }
          .info { background-color: white; padding: 15px; margin: 10px 0; border-left: 4px solid #4CAF50; }
          .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
        </style>
      </head>
      <body>
        <div class='header'>
          <h2>Confirmation d'inscription</h2>
        </div>
        <div class='content'>
          <p>Bonjour <strong>{$firstname} {$name}</strong>,</p>
          <p>Votre inscription au tournoi <strong>" . htmlspecialchars($tournamentName) . "</strong> a été enregistrée avec succès.</p>

          <div class='info'>
            <h3>Informations</h3>
            <ul>
              <li><strong>Licence :</strong> {$license}</li>
              <li><strong>Nom :</strong> {$name}</li>
              <li><strong>Prénom :</strong> {$firstname}</li>
            </ul>
          </div>

          <div class='info'>
            <h3>Départ(s)</h3>
            {$sessionsHtml}
          </div>

          <p>Nous vous attendons avec impatience !</p>
        </div>
        <div class='footer'>
          <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
        </div>
      </body>
    </html>";

    $headersUser  = "MIME-Version: 1.0\r\n";
    $headersUser .= "Content-type: text/html; charset=UTF-8\r\n";
    $headersUser .= "From: " . $fromEmail . "\r\n";

    $sentUser = mail($userEmail, $subjectUser, $messageUser, $headersUser);

    $subjectAdmin = "Nouvelle inscription - " . $tournamentName;

    $messageAdmin = "
    <html>
      <head>
        <style>
          body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
          .header { background-color: #2196F3; color: white; padding: 20px; text-align: center; }
          .content { padding: 20px; background-color: #f9f9f9; }
          .info { background-color: white; padding: 15px; margin: 10px 0; border-left: 4px solid #2196F3; }
        </style>
      </head>
      <body>
        <div class='header'>
          <h2>Nouvelle inscription reçue</h2>
        </div>
        <div class='content'>
          <p>Une nouvelle inscription a été enregistrée pour le tournoi <strong>" . htmlspecialchars($tournamentName) . "</strong>.</p>

          <div class='info'>
            <h3>Participant</h3>
            <ul>
              <li><strong>Licence :</strong> {$license}</li>
              <li><strong>Nom :</strong> {$name}</li>
              <li><strong>Prénom :</strong> {$firstname}</li>
              <li><strong>Email :</strong> " . htmlspecialchars($userEmail) . "</li>
            </ul>
          </div>

          <div class='info'>
            <h3>Départ(s)</h3>
            {$sessionsHtml}
          </div>
        </div>
      </body>
    </html>";

    $headersAdmin  = "MIME-Version: 1.0\r\n";
    $headersAdmin .= "Content-type: text/html; charset=UTF-8\r\n";
    $headersAdmin .= "From: " . $fromEmail . "\r\n";

    $sentAdmin = false;
    if (!empty($adminEmail)) {
        $sentAdmin = mail($adminEmail, $subjectAdmin, $messageAdmin, $headersAdmin);
    }

    return ['user' => $sentUser, 'admin' => $sentAdmin];
}

/* ---- IANSEO include ---- */
$ianseoRoot = dirname(__DIR__, 4);
$configPath = $ianseoRoot . '/Common/config.inc.php';
if (!file_exists($configPath)) sendError("Config IANSEO introuvable");

global $CFG;
$CFG = new stdClass();
include_once $configPath;

if (!isset($CFG->W_HOST, $CFG->W_USER, $CFG->W_PASS, $CFG->DB_NAME)) {
    sendError("Configuration IANSEO incomplète");
}

$conn = mysqli_connect($CFG->W_HOST, $CFG->W_USER, $CFG->W_PASS, $CFG->DB_NAME);
if (!$conn) sendError("Erreur connexion DB");
mysqli_set_charset($conn, 'utf8mb4');

/* ---- Helpers bind safe ---- */
function bindSafe(mysqli_stmt $stmt, string $query, string $types, array $vars) {
    $expected = substr_count($query, '?');
    if ($expected !== strlen($types) || $expected !== count($vars)) {
        throw new Exception("Bind mismatch: placeholders=$expected types=".strlen($types)." vars=".count($vars));
    }
    mysqli_stmt_bind_param($stmt, $types, ...$vars);
}

/* ---- Helpers distances (TournamentDistances) ---- */
function ianseoLikeToRegex($pattern) {
    $pattern = (string)$pattern;
    $p = preg_quote($pattern, '/');
    $p = str_replace('%', '.*', $p);
    $p = str_replace('_', '.', $p);
    return '/^' . $p . '$/';
}

function tdSpecificityScore($pattern) {
    $pattern = (string)$pattern;
    $len = strlen($pattern);
    $hasPct = (strpos($pattern, '%') !== false);
    $hasUnd = (strpos($pattern, '_') !== false);
    $isSexGeneric = preg_match('/^[A-Z0-9]+_[MW]$/', $pattern);

    if (!$hasPct && !$hasUnd && !$isSexGeneric) return 400 + $len;
    if ($hasUnd && !$hasPct) return 300 + $len;
    if ($hasPct && !$hasUnd) return 200 + $len;
    if ($hasPct && $hasUnd) return 180 + $len;
    if ($isSexGeneric) return 100 + $len;

    return 0 + $len;
}

/* ---- Routing ---- */
$action = $_POST['action'] ?? '';
$tournamentId = isset($_POST['tournamentid']) ? intval($_POST['tournamentid']) : 0;

/* ===== validatetoken ===== */
if ($action === 'validatetoken') {
    $token = trim($_POST['token'] ?? '');
    if ($tournamentId <= 0) sendError("ID de tournoi invalide");
    if ($token === '') sendError("Token manquant");

    $cfg = loadConfig($tournamentId);
    if (!$cfg || empty($cfg['tournament'])) sendError("Aucune configuration trouvée pour ce tournoi");
    if (($cfg['tournament']['token'] ?? '') !== $token) sendError("Token invalide pour ce tournoi");

    $q = "SELECT ToId, ToName FROM Tournament WHERE ToId = ?";
    $stmt = mysqli_prepare($conn, $q);
    bindSafe($stmt, $q, 'i', [$tournamentId]);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        sendSuccess(['tournamentid'=>$tournamentId,'tournamentname'=>$row['ToName'],'valid'=>true]);
    }
    sendError("Le tournoi ID $tournamentId n'existe pas dans la base de données");
}

/* ===== searchlicense ===== */
if ($action === 'searchlicense') {
    $license = strtoupper(trim($_POST['license'] ?? ''));
    $lastname = trim($_POST['lastname'] ?? '');

    $q = "SELECT LueCode, LueName, LueFamilyName, LueSex, LueCtrlCode, LueIocCode, LueCoDescr, LueClassified, LueCountry
          FROM LookUpEntries
          WHERE LueCode = ? AND UPPER(TRIM(LueFamilyName)) = UPPER(?)";
    $stmt = mysqli_prepare($conn, $q);
    bindSafe($stmt, $q, 'ss', [$license, $lastname]);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($res)) {
        $q2 = "SELECT COUNT(*) as count FROM Entries WHERE EnTournament = ? AND EnCode = ?";
        $stmt2 = mysqli_prepare($conn, $q2);
        bindSafe($stmt2, $q2, 'is', [$tournamentId, $license]);
        mysqli_stmt_execute($stmt2);
        $res2 = mysqli_stmt_get_result($stmt2);
        $row2 = mysqli_fetch_assoc($res2);

        if (intval($row2['count']) > 0) {
            sendError("Vous êtes déjà inscrit à ce tournoi. Si vous souhaitez modifier votre inscription, veuillez contacter l'organisateur.");
        }

        sendSuccess([
            'license'=>$row['LueCode'],
            'firstname'=>$row['LueName'],
            'name'=>$row['LueFamilyName'],
            'sex'=>intval($row['LueSex']),
            'dob'=>$row['LueCtrlCode'],
            'ioccode'=>$row['LueIocCode'],
            'club'=>$row['LueCoDescr'],
            'countrycode'=>$row['LueCountry'],
            'classified'=>intval($row['LueClassified']),
        ]);
    }
    sendError("Licence et nom de famille ne correspondent pas");
}

/* ===== getdivisions ===== */
if ($action === 'getdivisions') {
    $q = "SELECT DivId, DivDescription
          FROM Divisions
          WHERE DivTournament = ? AND DivAthlete = 1
          ORDER BY DivViewOrder";
    $stmt = mysqli_prepare($conn, $q);
    bindSafe($stmt, $q, 'i', [$tournamentId]);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $divs = [];
    while ($row = mysqli_fetch_assoc($res)) $divs[] = $row;
    sendSuccess($divs);
}

/* ===== getclasses ===== */
if ($action === 'getclasses') {
    $division = $_POST['division'] ?? '';
    $dob = $_POST['dob'] ?? '';
    $sex = intval($_POST['sex'] ?? 0);

    // Récupération de l'année de référence du tournoi
    $tourQ = "SELECT YEAR(ToWhenTo) as year FROM Tournament WHERE ToId = ?";
    $tourS = mysqli_prepare($conn, $tourQ);
    bindSafe($tourS, $tourQ, 'i', [$tournamentId]);
    mysqli_stmt_execute($tourS);
    $tourR = mysqli_stmt_get_result($tourS);
    $tourRow = mysqli_fetch_assoc($tourR);
    $refYear = intval($tourRow['year'] ?? date('Y'));

    // Calcul de la catégorie d'âge FFTA
    $categoryCode = calculateFFTAAgeCategory($dob, $refYear);

    if (empty($categoryCode)) {
        sendError("Impossible de déterminer la catégorie d'âge", ['dob' => $dob, 'refYear' => $refYear]);
    }

    // Filtrage des classes compatibles avec la catégorie calculée
    $q = "SELECT DISTINCT c.ClId, c.ClDescription
          FROM Classes c
          WHERE c.ClTournament = ?
            AND c.ClAthlete = 1
            AND (c.ClDivisionsAllowed = '' OR FIND_IN_SET(?, c.ClDivisionsAllowed))
            AND c.ClSex IN (-1, ?)
            AND c.ClId LIKE ?
          ORDER BY c.ClViewOrder";
    
    $stmt = mysqli_prepare($conn, $q);
    $categoryPattern = $categoryCode . '%'; // Ex: "S2%" matche S2H, S2F, S2D...
    bindSafe($stmt, $q, 'isis', [$tournamentId, $division, $sex, $categoryPattern]);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    
    $classes = [];
    while ($row = mysqli_fetch_assoc($res)) $classes[] = $row;
    
    sendSuccess($classes, '', ['category' => $categoryCode, 'refYear' => $refYear]);
}

/* ===== getsessions ===== */
if ($action === 'getsessions') {
    $q = "SELECT DISTINCT DiSession FROM DistanceInformation WHERE DiTournament = ? ORDER BY DiSession";
    $stmt = mysqli_prepare($conn, $q);
    bindSafe($stmt, $q, 'i', [$tournamentId]);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $months = ['01'=>'janvier','02'=>'février','03'=>'mars','04'=>'mars','05'=>'mai','06'=>'juin','07'=>'juillet','08'=>'août','09'=>'septembre','10'=>'octobre','11'=>'novembre','12'=>'décembre'];

    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $sessionNum = intval($row['DiSession']);
        $label = "Départ $sessionNum";

        $dQ = "SELECT DiDay, DiStart FROM DistanceInformation WHERE DiTournament = ? AND DiSession = ? AND DiDistance = 1 LIMIT 1";
        $dS = mysqli_prepare($conn, $dQ);
        bindSafe($dS, $dQ, 'ii', [$tournamentId, $sessionNum]);
        mysqli_stmt_execute($dS);
        $dR = mysqli_stmt_get_result($dS);

        if ($d = mysqli_fetch_assoc($dR)) {
            $dateFr = $d['DiDay'];
            $parts = explode('-', $d['DiDay']);
            if (count($parts) === 3) {
                $dateFr = intval($parts[2]).' '.($months[$parts[1]] ?? $parts[1]).' '.$parts[0];
            }
            $tparts = explode(':', (string)$d['DiStart']);
            $heureFr = (count($tparts) >= 2) ? ($tparts[0].':'.$tparts[1]) : $d['DiStart'];
            $label = "Départ $sessionNum - $dateFr $heureFr";
        }

        $out[] = ['session'=>$sessionNum,'label'=>$label];
    }
    sendSuccess($out);
}


/* ===== gettargetfaces ===== */
if ($action === 'gettargetfaces') {
    $division = trim($_POST['division'] ?? '');
    $class = trim($_POST['class'] ?? '');
    
    // Construction de la catégorie complète (ex: "COS2H")
    $fullCategory = $division . $class;

    $q = "SELECT TfId, TfName, TfDefault, TfClasses, TfRegExp
          FROM TargetFaces
          WHERE TfTournament = ?
          ORDER BY TfDefault DESC, TfName ASC";
    $stmt = mysqli_prepare($conn, $q);
    bindSafe($stmt, $q, 'i', [$tournamentId]);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $targets = [];
    $debugInfo = [];
    
    while ($row = mysqli_fetch_assoc($res)) {
        $tfClasses = $row['TfClasses'] ?? '';
        $tfRegExp = $row['TfRegExp'] ?? '';
        $isValid = false;

        $debugEntry = [
            'id' => $row['TfId'],
            'name' => $row['TfName'],
            'TfClasses_raw' => $tfClasses,
            'TfRegExp_raw' => $tfRegExp,
            'category' => $fullCategory,
            'tests' => []
        ];

        if (empty($tfClasses) || trim($tfClasses) === '%') {
            $isValid = true;
            $debugEntry['tests'][] = 'MATCH: TfClasses vide ou % (universel)';
        } else {
            $classesArray = array_map('trim', explode(',', $tfClasses));
            
            foreach ($classesArray as $pattern) {
                if (empty($pattern)) continue;
                
                if (strpos($pattern, '%') !== false) {
                    $regexPattern = '/^' . str_replace('%', '.*', preg_quote($pattern, '/')) . '$/';
                } else if (strpos($pattern, '*') !== false) {
                    $regexPattern = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
                } else {
                    $regexPattern = '/^' . preg_quote($pattern, '/') . '$/';
                }

                $matches = @preg_match($regexPattern, $fullCategory);
                
                $debugEntry['tests'][] = [
                    'pattern' => $pattern,
                    'regex' => $regexPattern,
                    'result' => $matches ? 'MATCH' : 'NO_MATCH'
                ];

                if ($matches) {
                    $isValid = true;
                    break;
                }
            }
        }

        if ($isValid && !empty($tfRegExp) && trim($tfRegExp) !== '') {
            $regexMatch = @preg_match('/' . $tfRegExp . '/', $fullCategory);
            
            if (!$regexMatch) {
                $isValid = false;
                $debugEntry['tests'][] = 'REJECT: TfRegExp ne match pas (' . $tfRegExp . ')';
            } else {
                $debugEntry['tests'][] = 'VALID: TfRegExp OK (' . $tfRegExp . ')';
            }
        }

        $debugEntry['final_valid'] = $isValid;
        $debugInfo[] = $debugEntry;

        if ($isValid) {
            $targets[] = [
                'id' => intval($row['TfId']),
                'name' => $row['TfName'],
                'default' => intval($row['TfDefault'])
            ];
        }
    }

    sendSuccess($targets, '', [
        'division' => $division,
        'class' => $class,
        'fullCategory' => $fullCategory,
        'nbTargetsTested' => count($debugInfo),
        'nbTargetsMatched' => count($targets),
        'allTests' => $debugInfo
    ]);
}


/* ===== getdistancebycategory (DEBUG) ===== */
if ($action === 'getdistancebycategory') {
    $division = (string)($_POST['division'] ?? '');
    $class = (string)($_POST['class'] ?? '');

    if ($tournamentId <= 0) sendError("ID de tournoi invalide");
    if ($division === '' || $class === '') sendError("Division ou catégorie manquante", ['division'=>$division,'class'=>$class]);

    $tdClass = $division . $class;

    $tq = "SELECT ToType FROM Tournament WHERE ToId = ? LIMIT 1";
    $tstmt = mysqli_prepare($conn, $tq);
    bindSafe($tstmt, $tq, 'i', [$tournamentId]);
    mysqli_stmt_execute($tstmt);
    $tres = mysqli_stmt_get_result($tstmt);
    $trow = mysqli_fetch_assoc($tres);
    if (!$trow) sendError("Tournoi introuvable");
    $toType = intval($trow['ToType'] ?? 0);

    $q = "SELECT TdClasses, TdDist1, TdDist2, TdDist3, TdDist4, TdDist5, TdDist6, TdDist7, TdDist8
          FROM TournamentDistances
          WHERE TdTournament = ? AND TdType = ?";
    $stmt = mysqli_prepare($conn, $q);
    bindSafe($stmt, $q, 'ii', [$tournamentId, $toType]);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $tested = [];
    $nbRows = 0;

    $bestRow = null;
    $bestPattern = null;
    $bestScore = -1;

    while ($row = mysqli_fetch_assoc($res)) {
        $nbRows++;
        $pattern = (string)($row['TdClasses'] ?? '');
        if ($pattern === '') continue;

        $rx = ianseoLikeToRegex($pattern);
        $ok = preg_match($rx, $tdClass) ? true : false;

        if (DEBUG_MODE && count($tested) < 40) {
            $tested[] = ['pattern'=>$pattern, 'regex'=>$rx, 'match'=>$ok];
        }

        if ($ok) {
            $score = tdSpecificityScore($pattern);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $row;
                $bestPattern = $pattern;
            }
        }
    }

    if (!$bestRow) {
        sendSuccess(
            [
                'tdClass' => $tdClass,
                'toType' => $toType,
                'label' => '',
                'distances_m' => [],
                'matchedPattern' => null
            ],
            '',
            [
                'nbRows' => $nbRows,
                'tested' => $tested
            ]
        );
    }

    $dist = [];
    for ($i = 1; $i <= 8; $i++) {
        $k = 'TdDist' . $i;
        $v = intval($bestRow[$k] ?? 0);
        if ($v > 0) $dist[] = $v;
    }

    $uniq = array_values(array_unique($dist));
    $label = (count($uniq) === 1)
        ? ($uniq[0] . 'm')
        : implode(' | ', array_map(fn($d) => $d . 'm', $dist));

    sendSuccess(
        [
            'tdClass' => $tdClass,
            'toType' => $toType,
            'label' => $label,
            'distances_m' => $dist,
            'matchedPattern' => $bestPattern
        ],
        '',
        [
            'nbRows' => $nbRows,
            'bestScore' => $bestScore,
            'tested' => $tested
        ]
    );
}

/* ===== submitregistration ===== */
if ($action === 'submitregistration') {
    $data = json_decode($_POST['data'] ?? '', true);
    if (!$data) sendError("Données invalides");

    if (empty($data['sessions']) || !is_array($data['sessions'])) sendError("Aucun départ sélectionné");
    if (empty($data['sessionChoices']) || !is_array($data['sessionChoices'])) sendError("Choix par départ manquants");

    $license = (string)($data['license'] ?? '');
    if ($license === '') sendError("Licence manquante");

    mysqli_begin_transaction($conn);

    try {
        $qDupe = "SELECT COUNT(*) as count FROM Entries WHERE EnTournament = ? AND EnCode = ?";
        $sDupe = mysqli_prepare($conn, $qDupe);
        bindSafe($sDupe, $qDupe, 'is', [$tournamentId, $license]);
        mysqli_stmt_execute($sDupe);
        $rDupe = mysqli_stmt_get_result($sDupe);
        $dupeRow = mysqli_fetch_assoc($rDupe);
        if (intval($dupeRow['count'] ?? 0) > 0) throw new Exception("Déjà inscrit à ce tournoi");

        $clubName = (string)($data['club'] ?? '');
        $clubCode = (string)($data['countrycode'] ?? '');

        $countryQuery = "SELECT CoId FROM Countries WHERE CoTournament = ? AND CoCode = ? LIMIT 1";
        $countryStmt = mysqli_prepare($conn, $countryQuery);
        bindSafe($countryStmt, $countryQuery, 'is', [$tournamentId, $clubCode]);
        mysqli_stmt_execute($countryStmt);
        $countryRes = mysqli_stmt_get_result($countryStmt);
        $countryRow = mysqli_fetch_assoc($countryRes);

        if (!$countryRow) {
            $insertCountryQuery = "INSERT INTO Countries
              (CoTournament, CoCode, CoName, CoNameComplete, CoIocCode, CoSubCountry, CoParent1, CoParent2, CoMaCode, CoCaCode, CoOnlineId)
              VALUES (?, ?, ?, ?, ?, '', 0, 0, '', '', 0)";
            $insertStmt = mysqli_prepare($conn, $insertCountryQuery);
            $iocTmp = !empty($data['ioccode']) ? (string)$data['ioccode'] : 'FRA';
            bindSafe($insertStmt, $insertCountryQuery, 'issss', [$tournamentId, $clubCode, $clubName, $clubName, $iocTmp]);
            if (!mysqli_stmt_execute($insertStmt)) throw new Exception("Erreur création club: " . mysqli_stmt_error($insertStmt));
            $countryId = mysqli_insert_id($conn);
        } else {
            $countryId = intval($countryRow['CoId']);
        }

        $insertedIds = [];
        $sessions = $data['sessions'];
        sort($sessions);

        foreach ($sessions as $session) {
            $session = intval($session);

            $choice = $data['sessionChoices'][(string)$session] ?? $data['sessionChoices'][$session] ?? null;
            if (!$choice) throw new Exception("Choix manquant pour le départ $session");

            $division = (string)($choice['division'] ?? '');
            $ageclass = (string)($choice['ageclass'] ?? '');
            $targetface = intval($choice['targetface'] ?? 0);
            if ($division === '' || $ageclass === '' || $targetface <= 0) throw new Exception("Choix incomplets pour le départ $session");

            $formattedName = formatProperName($data['name'] ?? '');
            $formattedFirstname = formatProperName($data['firstname'] ?? '');

            $ioc = (string)($data['ioccode'] ?? '');
            $sex = intval($data['sex'] ?? 0);
            $dob = (string)($data['dob'] ?? '');
            $classified = intval($data['classified'] ?? 0);

            $indClEvent = 1;
            $indFEvent = 1;

            $insertQuery = "INSERT INTO Entries
              (EnTournament, EnCode, EnIocCode, EnFirstName, EnName, EnSex, EnDob, EnDivision, EnClass, EnAgeClass,
               EnCountry, EnCountry2, EnCountry3, EnSubTeam, EnCtrlCode, EnTargetFace, EnStatus, EnAthlete, EnClassified,
               EnIndClEvent, EnTeamClEvent, EnIndFEvent, EnTeamFEvent, EnTeamMixEvent, EnWChair, EnSitting, EnPays, EnDoubleSpace,
               EnSubClass, EnBadgePrinted, EnNameOrder, EnLueTimeStamp, EnLueFieldChanged, EnOdfShortname, EnTvGivenName, EnTvFamilyName, EnTvInitials, EnTimestamp)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, '', ?, 0, 1, ?, ?, 0, ?, 0, 0, 0, 0, 1, 0, '', NULL, 0, '0000-00-00 00:00:00', 0, '', '', '', '', NOW())";

            $stmt = mysqli_prepare($conn, $insertQuery);
            if (!$stmt) throw new Exception("Erreur prepare Entries: " . mysqli_error($conn));

            $types = "issssissssiiiii";
            $vars = [
                $tournamentId,
                $license,
                $ioc,
                $formattedName,
                $formattedFirstname,
                $sex,
                $dob,
                $division,
                $ageclass,
                $ageclass,
                $countryId,
                $targetface,
                $classified,
                $indClEvent,
                $indFEvent
            ];
            bindSafe($stmt, $insertQuery, $types, $vars);

            if (!mysqli_stmt_execute($stmt)) throw new Exception("Erreur Entries: " . mysqli_stmt_error($stmt));

            $entryId = mysqli_insert_id($conn);
            $insertedIds[] = $entryId;

            $qualQuery = "INSERT INTO Qualifications (QuId, QuSession, QuTarget, QuLetter, QuTargetNo, QuBacknoPrinted,
                QuD1Score, QuD1Hits, QuD1Gold, QuD1Xnine, QuD1TieBreaker3, QuD1Arrowstring, QuD1Rank, QuD1Status,
                QuD2Score, QuD2Hits, QuD2Gold, QuD2Xnine, QuD2TieBreaker3, QuD2Arrowstring, QuD2Rank, QuD2Status,
                QuD3Score, QuD3Hits, QuD3Gold, QuD3Xnine, QuD3TieBreaker3, QuD3Arrowstring, QuD3Rank, QuD3Status,
                QuD4Score, QuD4Hits, QuD4Gold, QuD4Xnine, QuD4TieBreaker3, QuD4Arrowstring, QuD4Rank, QuD4Status,
                QuD5Score, QuD5Hits, QuD5Gold, QuD5Xnine, QuD5TieBreaker3, QuD5Arrowstring, QuD5Rank, QuD5Status,
                QuD6Score, QuD6Hits, QuD6Gold, QuD6Xnine, QuD6TieBreaker3, QuD6Arrowstring, QuD6Rank, QuD6Status,
                QuD7Score, QuD7Hits, QuD7Gold, QuD7Xnine, QuD7TieBreaker3, QuD7Arrowstring, QuD7Rank, QuD7Status,
                QuD8Score, QuD8Hits, QuD8Gold, QuD8Xnine, QuD8TieBreaker3, QuD8Arrowstring, QuD8Rank, QuD8Status,
                QuScore, QuHits, QuGold, QuXnine, QuTieBreaker3, QuArrow, QuConfirm, QuSigned, QuClRank, QuSubClassRank,
                QuStatus, QuTie, QuTieWeight, QuTieWeightDrops, QuTieWeightDecoded, QuTieBreak, QuTimestamp, QuNotes, QuIrmType)
              VALUES (?, ?, 0, '', '', '0000-00-00 00:00:00',
                      0,0,0,0,0,'',0,0,
                      0,0,0,0,0,'',0,0,
                      0,0,0,0,0,'',0,0,
                      0,0,0,0,0,'',0,0,
                      0,0,0,0,0,'',0,0,
                      0,0,0,0,0,'',0,0,
                      0,0,0,0,0,'',0,0,
                      0,0,0,0,0,'',0,0,
                      0,0,0,0,0,0,0,0,0,0,
                      0,0,0,0,0,0,NULL,'',0)";
            $qstmt = mysqli_prepare($conn, $qualQuery);
            if (!$qstmt) throw new Exception("Erreur prepare Qualifications: " . mysqli_error($conn));
            bindSafe($qstmt, $qualQuery, 'ii', [$entryId, $session]);
            if (!mysqli_stmt_execute($qstmt)) throw new Exception("Erreur Qualifications: " . mysqli_stmt_error($qstmt));

            if (!empty($data['email'])) {
                $emailQuery = "INSERT INTO ExtraData (EdId, EdType, EdEvent, EdEmail, EdExtra)
                               VALUES (?, 'E', '', ?, '')
                               ON DUPLICATE KEY UPDATE EdEmail = ?";
                $emailStmt = mysqli_prepare($conn, $emailQuery);
                if (!$emailStmt) throw new Exception("Erreur prepare ExtraData: " . mysqli_error($conn));
                bindSafe($emailStmt, $emailQuery, 'iss', [$entryId, (string)$data['email'], (string)$data['email']]);
                if (!mysqli_stmt_execute($emailStmt)) throw new Exception("Erreur ExtraData: " . mysqli_stmt_error($emailStmt));
            }
        }

        mysqli_commit($conn);

        $emails = ['user'=>false,'admin'=>false];
        if (!empty($data['email'])) {
            $nameQuery = "SELECT ToName FROM Tournament WHERE ToId = ?";
            $nstmt = mysqli_prepare($conn, $nameQuery);
            bindSafe($nstmt, $nameQuery, 'i', [$tournamentId]);
            mysqli_stmt_execute($nstmt);
            $nres = mysqli_stmt_get_result($nstmt);
            $tournamentName = ($nrow = mysqli_fetch_assoc($nres)) ? $nrow['ToName'] : 'Tournoi';

            $cfg = loadConfig($tournamentId);
            $adminEmail = $cfg['tournament']['admin_email'] ?? '';
            $fromEmail = $cfg['mail_from'] ?? 'noreply@ianseo.net';

            $emails = sendRegistrationEmail($conn, $tournamentId, (string)$data['email'], $adminEmail, $data, $tournamentName, $fromEmail);
        }

        sendSuccess(['entryids'=>$insertedIds, 'emails_sent'=>$emails], "Inscription réussie !");
    } catch (Exception $e) {
        mysqli_rollback($conn);
        sendError("Erreur inscription: " . $e->getMessage());
    }
}

sendError("Action inconnue");