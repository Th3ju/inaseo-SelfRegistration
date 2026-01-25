<?php
/**
 * Page autonome (sans interface/menu IANSEO)
 * /Modules/Custom/SelfRegistration/admin/send_recap.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---------------- IANSEO root / config DB ----------------
$ianseo_root = dirname(dirname(dirname(dirname(dirname(__FILE__)))));

$config_path = $ianseo_root . '/Common/config.inc.php';
if (!file_exists($config_path)) {
    $config_path = $ianseo_root . '/ianseo/Common/config.inc.php';
}
if (!file_exists($config_path)) {
    die("Configuration IANSEO introuvable.");
}

global $CFG;
$CFG = new stdClass();
include_once($config_path);

if (!isset($CFG->WDB) && isset($CFG->DB_NAME)) {
    $CFG->WDB = $CFG->DB_NAME;
}
if (!isset($CFG->W_HOST) || !isset($CFG->W_USER) || !isset($CFG->W_PASS) || !isset($CFG->WDB)) {
    die("Configuration DB incomplète (W_HOST/W_USER/W_PASS/WDB).");
}

session_start();

// ---------------- DB ----------------
$db = new mysqli($CFG->W_HOST, $CFG->W_USER, $CFG->W_PASS, $CFG->WDB);
if ($db->connect_error) {
    die("Erreur de connexion DB : " . $db->connect_error);
}
$db->set_charset("utf8mb4");

// ---------------- helpers: columns / safe select ----------------
function getTableColumns(mysqli $db, string $table): array {
    $cols = [];
    $res = $db->query("SHOW COLUMNS FROM `$table`");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cols[] = $r['Field'];
        }
        $res->free();
    }
    return $cols;
}

function firstExistingColumn(array $existingCols, array $candidates): ?string {
    $set = array_fill_keys($existingCols, true);
    foreach ($candidates as $c) {
        if (isset($set[$c])) return $c;
    }
    return null;
}

function selectTournamentInfo(mysqli $db, int $tournamentId): object {
    $cols = getTableColumns($db, 'Tournament');

    $base = ['ToName', 'ToWhenFrom', 'ToWhenTo', 'ToComDescr'];

    // Lieu: on prend un champ "lieu" (chez toi c'est OK)
    $colLieu = firstExistingColumn($cols, ['ToWhere', 'ToLocation', 'ToLoc', 'ToAddress', 'ToVenue']);

    // Ville: demandé -> ToVenue
    $colVille = firstExistingColumn($cols, ['ToVenue', 'ToCity', 'ToTown', 'ToPlace', 'ToCityName', 'ToLocality']);

    $select = [];
    foreach ($base as $c) {
        if (in_array($c, $cols, true)) $select[] = $c;
    }

    if (!in_array('ToName', $select, true)) $select[] = "'' AS ToName";
    if (!in_array('ToWhenFrom', $select, true)) $select[] = "NULL AS ToWhenFrom";
    if (!in_array('ToWhenTo', $select, true)) $select[] = "NULL AS ToWhenTo";
    if (!in_array('ToComDescr', $select, true)) $select[] = "'' AS ToComDescr";

    $select[] = ($colLieu  ? "`$colLieu`"  : "''") . " AS MailLieu";
    $select[] = ($colVille ? "`$colVille`" : "''") . " AS MailVille";

    $sql = "SELECT " . implode(", ", $select) . " FROM Tournament WHERE ToId = ?";

    $stmt = $db->prepare($sql);
    if (!$stmt) die("Erreur SQL (prepare Tournament): " . $db->error);

    $stmt->bind_param("i", $tournamentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $obj = $res->fetch_object();
    $stmt->close();

    if (!$obj) die("Tournoi introuvable (ID: $tournamentId).");
    return $obj;
}

// ---------------- Tournament id ----------------
$tournament_id = isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0;
if (!$tournament_id) {
    $result = $db->query("SELECT ToId FROM Tournament ORDER BY ToWhenFrom DESC LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) $tournament_id = intval($row['ToId']);
}
if (!$tournament_id) die("Aucun tournoi trouvé (session TourId vide et aucun tournoi en base).");

$action = $_POST['action'] ?? 'view';

// ---------------- Tournament info ----------------
$tournament = selectTournamentInfo($db, $tournament_id);

// ---------------- Enroll config ----------------
function loadEnrollConfig(int $tournament_id): array {
    $configFile = dirname(__DIR__) . '/enroll/config.php';
    if (!file_exists($configFile)) return ['mail_from' => null, 'tournament' => null];

    if (!defined('CONFIG_ACCESS')) define('CONFIG_ACCESS', true);
    $cfg = require $configFile;

    $mailFrom = is_array($cfg) ? ($cfg['mail_from'] ?? null) : null;

    $t = null;
    if (is_array($cfg)
        && isset($cfg['tournaments']) && is_array($cfg['tournaments'])
        && isset($cfg['tournaments'][$tournament_id]) && is_array($cfg['tournaments'][$tournament_id])) {

        $tour = $cfg['tournaments'][$tournament_id];
        $t = [
            'name' => $tour['name'] ?? null,
            'token' => $tour['token'] ?? null,
            'admin_email' => $tour['admin_email'] ?? null,
        ];
    }

    return ['mail_from' => $mailFrom, 'tournament' => $t];
}

function formatSessionDateTimeFR($day, $start) {
    $monthsFr = [
        '01' => 'janvier', '02' => 'février', '03' => 'mars', '04' => 'avril',
        '05' => 'mai', '06' => 'juin', '07' => 'juillet', '08' => 'août',
        '09' => 'septembre', '10' => 'octobre', '11' => 'novembre', '12' => 'décembre'
    ];

    $day = (string)$day;
    $start = (string)$start;

    $datefr = $day;
    $p = explode('-', $day);
    if (count($p) === 3) {
        $annee = $p[0];
        $moisKey = $p[1];
        $jour = intval($p[2]);
        $mois = $monthsFr[$moisKey] ?? $moisKey;
        $datefr = $jour . ' ' . $mois . ' ' . $annee;
    }

    $heurefr = $start;
    $t = explode(':', $start);
    if (count($t) >= 2) $heurefr = $t[0] . ':' . $t[1];

    return [$datefr, $heurefr];
}

function formatTarget($num, $letter): string {
    $numRaw = $num; // peut être NULL
    $letter = strtoupper(trim((string)$letter));

    // Si pas de numéro de cible OU pas de lettre -> pas affecté
    if (empty($numRaw) || empty($letter)) {
        return "Pas encore affecté";
    }

    $num = intval($numRaw);
    return $num . '-' . $letter;
}


function getInscriptionsByEmail(mysqli $db, int $tournament_id): array {
    $sql = "
        SELECT
            e.EnCode as code,
            e.EnName as nom,
            e.EnFirstName as prenom,
            ed.EdEmail as email,
            e.EnClass as classe,
            e.EnAgeClass as age_classe,
            d.DivDescription as division_label,
            q.QuSession as session_num,
            di.DiDay as session_day,
            di.DiStart as session_start,
            tf.TfName as blason,
            q.QuTarget as target_num,
            q.QuLetter as target_letter
        FROM Entries e
        LEFT JOIN ExtraData ed
            ON ed.EdId = e.EnId AND ed.EdType = 'E'
        LEFT JOIN Qualifications q
            ON q.QuId = e.EnId
        LEFT JOIN DistanceInformation di
            ON di.DiTournament = e.EnTournament
           AND di.DiSession = q.QuSession
           AND di.DiDistance = 1
        LEFT JOIN TargetFaces tf
            ON tf.TfId = e.EnTargetFace
           AND tf.TfTournament = e.EnTournament
        LEFT JOIN Divisions d
            ON d.DivTournament = e.EnTournament
           AND d.DivId = e.EnDivision
        WHERE e.EnTournament = ? AND e.EnStatus = 0
        ORDER BY ed.EdEmail, e.EnCode, q.QuSession ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $tournament_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $grouped = [];

    while ($row = $result->fetch_assoc()) {
        $email = trim($row['email'] ?? '');
        if ($email === '') continue;

        $code = $row['code'] ?? '';
        if ($code === '') continue;

        if (!isset($grouped[$email])) $grouped[$email] = [];

        $sessionNum = intval($row['session_num'] ?? 0);
        [$datefr, $heurefr] = formatSessionDateTimeFR($row['session_day'] ?? '', $row['session_start'] ?? '');

        $divisionLabel = $row['division_label'] ?: 'Division inconnue';
        $blason = $row['blason'] ?: 'Non défini';

        $target = formatTarget($row['target_num'] ?? 0, $row['target_letter'] ?? '');

        $depart = [
            'num' => $sessionNum,
            'label' => "Départ " . $sessionNum . " : " . $datefr . " - " . $heurefr . " - " . $divisionLabel . " - " . $blason,
            'target' => $target
        ];

        $found = false;
        foreach ($grouped[$email] as &$a) {
            if ($a['code'] === $code) {
                $key = $sessionNum . '|' . $divisionLabel . '|' . $blason . '|' . $target;
                if ($sessionNum > 0 && !isset($a['_keys'][$key])) {
                    $a['_keys'][$key] = true;
                    $a['departs'][] = $depart;
                    usort($a['departs'], fn($x,$y) => intval($x['num']) <=> intval($y['num']));
                }
                $found = true;
                break;
            }
        }
        unset($a);

        if (!$found) {
            $grouped[$email][] = [
                'code' => $code,
                'nom' => $row['nom'] ?? '',
                'prenom' => $row['prenom'] ?? '',
                'classe' => $row['classe'] ?? '',
                'age_classe' => $row['age_classe'] ?? '',
                'departs' => ($sessionNum > 0 ? [$depart] : []),
                '_keys' => ($sessionNum > 0 ? [($sessionNum . '|' . $divisionLabel . '|' . $blason . '|' . $target) => true] : []),
            ];
        }
    }

    $stmt->close();

    foreach ($grouped as &$archers) {
        foreach ($archers as &$a) unset($a['_keys']);
        unset($a);
    }
    unset($archers);

    return $grouped;
}

function sendRecapEmail(string $toEmail, array $archers, object $tournament_info, ?string $mailFrom, ?string $adminEmail, bool $includeTargets): bool {
    $subject = "Récapitulatif de vos inscriptions - " . $tournament_info->ToName;

    $message  = "<html><head><meta charset='UTF-8'>";
    $message .= "<style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin:0; padding:0; }
        .header { background-color: #4CAF50; color: white; padding: 16px; text-align: center; }
        .content { padding: 16px; background-color: #f9f9f9; }
        .info { background-color: white; padding: 12px; margin: 10px 0; border-left: 4px solid #4CAF50; }
        .footer { padding: 16px; text-align: center; font-size: 12px; color: #666; }
        ul { margin: 0; padding-left: 18px; }
        h2,h3 { margin: 0 0 10px 0; }
        .alert { background-color: white; padding: 12px; margin: 10px 0; border-left: 4px solid #ff9800; }
      </style></head><body>";

    $message .= "<div class='header'><h2>Récapitulatif d'inscription</h2></div>";
    $message .= "<div class='content'>";
    $message .= "<p>Bonjour,</p>";
    $message .= "<p>Voici le récapitulatif de vos inscriptions au tournoi <strong>" . htmlspecialchars($tournament_info->ToName) . "</strong>.</p>";

    $lieu  = trim((string)($tournament_info->MailLieu ?? ''));
    $ville = trim((string)($tournament_info->MailVille ?? ''));

    $whenFrom = $tournament_info->ToWhenFrom ? date('d/m/Y', strtotime($tournament_info->ToWhenFrom)) : '';
    $whenTo   = $tournament_info->ToWhenTo ? date('d/m/Y', strtotime($tournament_info->ToWhenTo)) : '';
    $datesTxt = ($whenFrom && $whenTo) ? ("Du " . $whenFrom . " au " . $whenTo) : 'Non définies';

    $message .= "<div class='info'>";
    $message .= "<h3>Informations tournoi</h3>";
    $message .= "<ul>";
    $message .= "<li><strong>Tournoi :</strong> " . htmlspecialchars($tournament_info->ToName) . "</li>";
    $message .= "<li><strong>Dates :</strong> " . htmlspecialchars($datesTxt) . "</li>";
    $message .= "<li><strong>Adresse :</strong> " . htmlspecialchars($lieu ?: 'Non défini') . "</li>";
    # $message .= "<li><strong>Ville :</strong> " . htmlspecialchars($ville ?: 'Non défini') . "</li>";
    $message .= "</ul>";
    if (!empty($tournament_info->ToComDescr)) {
        $message .= "<p><strong>Club organisateur :</strong> " . nl2br(htmlspecialchars($tournament_info->ToComDescr)) . "</p>";
    }
    $message .= "</div>";

    // Détails (HTML propre, multi inscriptions OK)
    foreach ($archers as $a) {
        $message .= "<div class='info'>";
        $message .= "<h3>Détails de votre inscription</h3>";
        $message .= "<ul>";
        $message .= "<li><strong>Archer :</strong> " . htmlspecialchars($a['prenom'] . " " . $a['nom']) . "</li>";
        $message .= "<li><strong>Licence :</strong> " . htmlspecialchars($a['code']) . "</li>";
        $message .= "<li><strong>Classe d'âge :</strong> " . htmlspecialchars($a['age_classe']) . "</li>";
        $message .= "<li><strong>Classement :</strong> " . htmlspecialchars($a['classe']) . "</li>";

        $message .= "<li><strong>Départs :</strong>";
        if (!empty($a['departs'])) {
            $message .= "<ul>";
            foreach ($a['departs'] as $d) {
                $line = $d['label'];
                if ($includeTargets && !empty($d['target'])) {
                    $line .= " - Cible (donnée à titre indicatif, susceptible d'être modifiée) : " . $d['target'];
                }
                $message .= "<li>" . htmlspecialchars($line) . "</li>";
            }
            $message .= "</ul>";
        } else {
            $message .= " Aucun";
        }
        $message .= "</li>";

        $message .= "</ul>";
        $message .= "</div>";
    }

    if (!empty($adminEmail)) {
        $message .= "<div class='alert'><p><strong>En cas d'erreur</strong>, merci de nous contacter au plus vite : \"" . htmlspecialchars($adminEmail) . "\".</p></div>";
    }

    $message .= "<p>Nous vous attendons avec impatience !</p>";
    $message .= "</body></html>";

    $fromEmail = $mailFrom ?: ($adminEmail ?: ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . $fromEmail . "\r\n";

    return mail($toEmail, $subject, $message, $headers);
}

// ---------------- ACTION SEND (avec sélection) ----------------
if ($action === 'send') {
    $selected = $_POST['selected_emails'] ?? [];
    if (!is_array($selected)) $selected = [];
    $selected = array_values(array_filter(array_map('trim', $selected), fn($e) => $e !== ''));

    $includeTargets = !empty($_POST['include_targets']);

    $inscriptions_by_email = getInscriptionsByEmail($db, $tournament_id);

    $enrollCfg = loadEnrollConfig($tournament_id);
    $mailFrom = $enrollCfg['mail_from'] ?? null;
    $adminEmail = $enrollCfg['tournament']['admin_email'] ?? null;

    $stats = [
        'total_selected' => count($selected),
        'success' => 0,
        'errors' => 0,
        'skipped' => 0,
        'details' => [],
        'include_targets' => $includeTargets ? 1 : 0,
    ];

    $selectedSet = array_fill_keys($selected, true);

    foreach ($inscriptions_by_email as $email => $archers) {
        if (!isset($selectedSet[$email])) {
            $stats['skipped']++;
            continue;
        }

        if (sendRecapEmail($email, $archers, $tournament, $mailFrom, $adminEmail, $includeTargets)) {
            $stats['success']++;
            $stats['details'][$email] = 'Envoyé avec succès (' . count($archers) . ' archer(s))';
        } else {
            $stats['errors']++;
            $stats['details'][$email] = "Erreur lors de l'envoi";
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($stats, JSON_UNESCAPED_UNICODE);
    $db->close();
    exit;
}

// ---------------- VIEW ----------------
$inscriptions_by_email = getInscriptionsByEmail($db, $tournament_id);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Envoi récapitulatifs - <?php echo htmlspecialchars($tournament->ToName); ?></title>
  <style>
    body { font-family: Arial, sans-serif; background:#f6f7fb; margin:0; padding:20px; color:#222; }
    .wrap { max-width: 1100px; margin: 0 auto; }
    .card { background:#fff; border-radius:10px; padding:16px; margin-bottom:14px; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
    h1 { margin:0 0 10px 0; }
    .muted { color:#666; margin:0 0 10px 0; }
    .email-group { background:#fbfbfd; border:1px solid #e8e9f0; border-radius:10px; padding:14px; margin-bottom:14px; }
    .badge { display:inline-block; background:#0d6efd; color:#fff; padding:2px 8px; border-radius:999px; font-size:12px; }
    button { background:#0d6efd; color:#fff; border:none; padding:10px 14px; border-radius:8px; cursor:pointer; font-weight:600; }
    button.secondary { background:#6c757d; }
    button:disabled { opacity:.6; cursor:not-allowed; }
    .alert { padding:12px; border-radius:10px; background:#eef5ff; border:1px solid #d8e6ff; }
    .ok { background:#eaf7ee; border-color:#cfead7; }
    .err { background:#fdecec; border-color:#f6caca; }
    code { background:#f0f0f0; padding:2px 6px; border-radius:6px; }
    #loading { display:none; }
    .toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:12px; }
    .chk { transform: scale(1.15); }
    .rowhead { display:flex; justify-content:space-between; gap:10px; align-items:center; }
    .rowleft { display:flex; align-items:center; gap:10px; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Envoi des récapitulatifs d'inscription</h1>
    <p class="muted">Tournoi : <strong><?php echo htmlspecialchars($tournament->ToName); ?></strong> (ID <code><?php echo $tournament_id; ?></code>)</p>

    <div class="alert">
      <div><strong>Informations :</strong></div>
      <div>Total d'adresses email : <strong><?php echo count($inscriptions_by_email); ?></strong></div>
      <div>Sélectionne les destinataires, puis clique sur “Envoyer”.</div>
    </div>

    <?php if (!empty($inscriptions_by_email)): ?>
      <div class="toolbar">
        <label>
          <input type="checkbox" id="checkAll" class="chk" />
          Tout cocher
        </label>

        <button class="secondary" type="button" onclick="toggleAll(false)">Tout décocher</button>

        <label>
          <input type="checkbox" id="includeTargets" class="chk" />
          Inclure les cibles (IANSEO)
        </label>

        <button id="sendBtn" type="button" onclick="sendEmails()">Envoyer les emails sélectionnés</button>
        <span class="muted" id="selCount">0 sélectionné(s)</span>
      </div>

      <div id="loading" class="alert" style="margin-top:12px;">Envoi en cours…</div>
      <div id="results" style="display:none; margin-top:12px;"></div>
    <?php else: ?>
      <div class="alert err" style="margin-top:12px;">Aucune inscription avec email trouvée.</div>
    <?php endif; ?>
  </div>

  <?php if (!empty($inscriptions_by_email)): ?>
  <div class="card">
    <h2 style="margin-top:0;">Aperçu</h2>

    <?php foreach ($inscriptions_by_email as $email => $archers): ?>
      <div class="email-group">
        <div class="rowhead">
          <div class="rowleft">
            <input type="checkbox"
                   class="chk emailCheck"
                   value="<?php echo htmlspecialchars($email); ?>"
                   id="<?php echo 'em_' . md5($email); ?>">
            <label for="<?php echo 'em_' . md5($email); ?>">
              <strong><?php echo htmlspecialchars($email); ?></strong>
            </label>
          </div>
          <div class="badge"><?php echo count($archers); ?> archer(s)</div>
        </div>

        <?php foreach ($archers as $a): ?>
          <div style="margin-top:10px; border-left:4px solid #0d6efd; padding-left:10px;">
            <div><strong><?php echo htmlspecialchars($a['prenom'] . ' ' . $a['nom']); ?></strong> — Licence <?php echo htmlspecialchars($a['code']); ?></div>
            <div class="muted">Classement: <?php echo htmlspecialchars($a['classe']); ?> | Catégorie: <?php echo htmlspecialchars($a['age_classe']); ?></div>
            <?php if (!empty($a['departs'])): ?>
              <div style="margin-top:6px;">
                <strong>Départs :</strong><br>
                <?php foreach ($a['departs'] as $d): ?>
                  <?php
                    $line = $d['label'];
                    if (!empty($d['target'])) $line .= " - Cible : " . $d['target'];
                    echo htmlspecialchars($line) . "<br>";
                  ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

  </div>
  <?php endif; ?>
</div>

<script>
function getSelectedEmails() {
  return Array.from(document.querySelectorAll('.emailCheck:checked')).map(x => x.value);
}

function updateSelectedCount() {
  const n = getSelectedEmails().length;
  document.getElementById('selCount').textContent = n + ' sélectionné(s)';

  const all = document.querySelectorAll('.emailCheck').length;
  const checkAll = document.getElementById('checkAll');
  checkAll.checked = (all > 0 && n === all);
  checkAll.indeterminate = (n > 0 && n < all);
}

function toggleAll(state) {
  document.querySelectorAll('.emailCheck').forEach(cb => cb.checked = state);
  updateSelectedCount();
}

document.addEventListener('DOMContentLoaded', () => {
  toggleAll(false); // par défaut : rien coché
  document.getElementById('checkAll').addEventListener('change', (e) => toggleAll(e.target.checked));
  document.querySelectorAll('.emailCheck').forEach(cb => cb.addEventListener('change', updateSelectedCount));
  updateSelectedCount();
});

function sendEmails() {
  const emails = getSelectedEmails();
  if (emails.length === 0) {
    alert('Aucun destinataire sélectionné.');
    return;
  }
  if (!confirm('Envoyer ' + emails.length + ' email(s) ?')) return;

  const btn = document.getElementById('sendBtn');
  const loading = document.getElementById('loading');
  const results = document.getElementById('results');

  btn.disabled = true;
  loading.style.display = 'block';
  results.style.display = 'none';
  results.innerHTML = '';

  const params = new URLSearchParams();
  params.set('action', 'send');
  params.set('include_targets', document.getElementById('includeTargets').checked ? '1' : '0');
  emails.forEach(e => params.append('selected_emails[]', e));

  fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params.toString()
  })
  .then(r => r.json())
  .then(data => {
    loading.style.display = 'none';
    results.style.display = 'block';

    let html = '';
    html += '<div class="alert ok"><strong>Résumé</strong><br>';
    html += 'Sélectionnés: ' + data.total_selected + ' — Succès: ' + data.success + ' — Erreurs: ' + data.errors + ' — Ignorés: ' + data.skipped;
    html += (data.include_targets ? ' — Cibles: OUI' : ' — Cibles: NON');
    html += '</div>';

    html += '<div class="card" style="box-shadow:none; border:1px solid #e8e9f0; margin-top:10px;">';
    html += '<strong>Détails (uniquement sélectionnés)</strong><div style="margin-top:8px;">';
    for (let email in data.details) {
      const ok = data.details[email].includes('succès');
      html += '<div class="alert ' + (ok ? 'ok' : 'err') + '" style="margin:8px 0;">';
      html += '<strong>' + email + '</strong> : ' + data.details[email];
      html += '</div>';
    }
    html += '</div></div>';

    results.innerHTML = html;
    btn.disabled = false;
  })
  .catch(err => {
    loading.style.display = 'none';
    alert('Erreur: ' + err);
    btn.disabled = false;
  });
}
</script>
</body>
</html>
<?php
$db->close();
