<?php
/**
 * Module d'auto-inscription IANSEO v1.0
 * Traitement AJAX
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DEBUG_MODE', true);

header('Content-Type: application/json; charset=utf-8');

function debug_response($message, $data = null) {
    if (DEBUG_MODE) {
        error_log("[IANSEO DEBUG] " . $message . ($data ? " : " . print_r($data, true) : ""));
    }
}

function send_error($message, $debug_data = null) {
    debug_response("ERROR: " . $message, $debug_data);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'debug' => DEBUG_MODE ? $debug_data : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function send_success($data = [], $message = '') {
    debug_response("SUCCESS: " . $message, $data);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
        'debug' => DEBUG_MODE ? ['timestamp' => date('Y-m-d H:i:s')] : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Chemin IANSEO
$ianseo_root = dirname(__DIR__);
$config_path = $ianseo_root . '/Common/config.inc.php';

if (!file_exists($config_path)) {
    send_error("Config IANSEO introuvable", $config_path);
}

// Initialiser $CFG
global $CFG;
$CFG = new stdClass();

include_once($config_path);

debug_response("Config chargée", get_object_vars($CFG));

// Compatibilité : Créer W_DB depuis DB_NAME si nécessaire
if (!isset($CFG->W_DB) && isset($CFG->DB_NAME)) {
    $CFG->W_DB = $CFG->DB_NAME;
}

// Vérifier les variables nécessaires
if (!isset($CFG->W_HOST) || !isset($CFG->W_USER) || !isset($CFG->W_PASS) || !isset($CFG->W_DB)) {
    send_error("Configuration IANSEO incomplète");
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$tournament_id = isset($_POST['tournament_id']) ? intval($_POST['tournament_id']) : 0;

debug_response("Action", ['action' => $action, 'tournament_id' => $tournament_id]);

$conn = mysqli_connect($CFG->W_HOST, $CFG->W_USER, $CFG->W_PASS, $CFG->W_DB);
if (!$conn) {
    send_error("Erreur connexion DB", mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');


// ACTION: search_license
if ($action === 'search_license') {
    $license = strtoupper(trim($_POST['license']));
    $lastname = trim($_POST['lastname']);
    
    debug_response("Recherche licence + nom", ['license' => $license, 'lastname' => $lastname]);
    
    $query = "SELECT LueCode, LueName, LueFamilyName, LueSex, LueCtrlCode, 
                     LueIocCode, LueCoDescr, LueClassified
              FROM LookUpEntries 
              WHERE LueCode = ? 
              AND UPPER(TRIM(LueFamilyName)) = UPPER(?)";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ss", $license, $lastname);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        debug_response("Archer trouvé", $row);
        
        // Vérifier doublon
        $check_query = "SELECT COUNT(*) as count FROM Entries 
                       WHERE EnTournament = ? AND EnCode = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "is", $tournament_id, $license);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        $check_row = mysqli_fetch_assoc($check_result);
        
        if ($check_row['count'] > 0) {
            send_error("Vous êtes déjà inscrit à ce tournoi");
        }
        
        send_success([
            'license' => $row['LueCode'],
            'name' => $row['LueName'],
            'firstname' => $row['LueFamilyName'],
            'sex' => intval($row['LueSex']),
            'dob' => $row['LueCtrlCode'],
            'ioccode' => $row['LueIocCode'],
            'club' => $row['LueCoDescr'],
            'classified' => intval($row['LueClassified'])
        ]);
    } else {
        send_error("Licence et nom de famille ne correspondent pas");
    }
}



// ACTION: get_divisions
if ($action === 'get_divisions') {
    debug_response("Récupération divisions");
    
    $query = "SELECT DivId, DivDescription FROM Divisions 
              WHERE DivTournament = ? AND DivAthlete = '1'
              ORDER BY DivViewOrder";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $tournament_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $divisions = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $divisions[] = $row;
    }
    
    debug_response("Divisions trouvées", count($divisions));
    send_success($divisions);
}

// ACTION: get_classes
if ($action === 'get_classes') {
    $division = $_POST['division'];
    $dob = $_POST['dob'];
    $sex = intval($_POST['sex']);
    
    debug_response("Calcul classes", ['division' => $division, 'dob' => $dob, 'sex' => $sex]);
    
    $tour_query = "SELECT YEAR(ToWhenTo) as year FROM Tournament WHERE ToId = ?";
    $tour_stmt = mysqli_prepare($conn, $tour_query);
    mysqli_stmt_bind_param($tour_stmt, "i", $tournament_id);
    mysqli_stmt_execute($tour_stmt);
    $tour_result = mysqli_stmt_get_result($tour_stmt);
    $tour_row = mysqli_fetch_assoc($tour_result);
    $ref_year = $tour_row['year'];
    
    $birth_year = intval(substr($dob, 0, 4));
    $age = $ref_year - $birth_year;
    
    debug_response("Âge calculé", ['ref_year' => $ref_year, 'birth_year' => $birth_year, 'age' => $age]);
    
    $query = "SELECT DISTINCT c.ClId, c.ClDescription
              FROM Classes c
              WHERE c.ClTournament = ?
                AND c.ClAthlete = '1'
                AND (c.ClDivisionsAllowed = '' OR FIND_IN_SET(?, c.ClDivisionsAllowed))
                AND c.ClSex IN (-1, ?)
                AND (c.ClAgeFrom <= ? AND c.ClAgeTo >= ?)
              ORDER BY c.ClViewOrder";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "isiii", $tournament_id, $division, $sex, $age, $age);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $classes = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $classes[] = $row;
    }
    
    debug_response("Classes trouvées", count($classes));
    send_success($classes);
}

// ACTION: get_sessions
if ($action === 'get_sessions') {
    debug_response("Récupération départs");
    
    // Étape 1: Récupérer toutes les sessions distinctes
    $sessions_query = "SELECT DISTINCT DiSession FROM DistanceInformation WHERE DiTournament = ? ORDER BY DiSession";
    
    $sessions_stmt = mysqli_prepare($conn, $sessions_query);
    mysqli_stmt_bind_param($sessions_stmt, "i", $tournament_id);
    mysqli_stmt_execute($sessions_stmt);
    $sessions_result = mysqli_stmt_get_result($sessions_stmt);
    
    debug_response("Sessions trouvées", mysqli_num_rows($sessions_result));
    
    $sessions = [];
    $months_fr = [
        '01' => 'janvier', '02' => 'février', '03' => 'mars', '04' => 'avril',
        '05' => 'mai', '06' => 'juin', '07' => 'juillet', '08' => 'août',
        '09' => 'septembre', '10' => 'octobre', '11' => 'novembre', '12' => 'décembre'
    ];
    
    // Étape 2: Pour chaque session, récupérer les infos de la distance 1
    while ($session_row = mysqli_fetch_assoc($sessions_result)) {
        $session_num = intval($session_row['DiSession']);
        
        $detail_query = "SELECT DiDay, DiStart FROM DistanceInformation 
                        WHERE DiTournament = ? AND DiSession = ? AND DiDistance = 1 
                        LIMIT 1";
        
        $detail_stmt = mysqli_prepare($conn, $detail_query);
        mysqli_stmt_bind_param($detail_stmt, "ii", $tournament_id, $session_num);
        mysqli_stmt_execute($detail_stmt);
        $detail_result = mysqli_stmt_get_result($detail_stmt);
        
        if ($detail_row = mysqli_fetch_assoc($detail_result)) {
            // Formater la date en français
            $date_parts = explode('-', $detail_row['DiDay']);
            if (count($date_parts) == 3) {
                $jour = intval($date_parts[2]);
                $mois = isset($months_fr[$date_parts[1]]) ? $months_fr[$date_parts[1]] : $date_parts[1];
                $annee = $date_parts[0];
                $date_fr = "$jour $mois $annee";
            } else {
                $date_fr = $detail_row['DiDay'];
            }
            
            // Formater l'heure (enlever les secondes)
            $heure_parts = explode(':', $detail_row['DiStart']);
            $heure_fr = $heure_parts[0] . ':' . $heure_parts[1];
            
            $sessions[] = [
                'session' => $session_num,
                'label' => 'Départ ' . $session_num . ' : ' . $date_fr . ' à ' . $heure_fr
            ];
        }
    }
    
    debug_response("Départs retournés", $sessions);
    send_success($sessions);
}

// ACTION: get_target_faces
if ($action === 'get_target_faces') {
    $division = $_POST['division'];
    $class = $_POST['class'];
    
    debug_response("Récupération blasons", ['division' => $division, 'class' => $class, 'tournament' => $tournament_id]);
    
    // Construction de la catégorie complète (ex: "COSH3")
    $full_category = $division . $class;
    
    // Récupérer tous les blasons du tournoi avec TfClasses et TfRegExp
    $query = "SELECT TfId, TfName, TfDefault, TfClasses, TfRegExp 
              FROM TargetFaces
              WHERE TfTournament = ?
              ORDER BY TfDefault DESC, TfName ASC";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $tournament_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $targets = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $tf_classes = $row['TfClasses'];
        $tf_regexp = $row['TfRegExp'];
        $is_valid = false;
        
        debug_response("Test blason", [
            'name' => $row['TfName'],
            'TfClasses' => $tf_classes,
            'TfRegExp' => $tf_regexp,
            'category' => $full_category
        ]);
        
        // Vérification 1: TfClasses vide ou % = tous
        if (empty($tf_classes) || $tf_classes === '%') {
            $is_valid = true;
            debug_response("Match: TfClasses universel");
        }
        // Vérification 2: TfClasses - liste CSV avec wildcards
        else {
            $classes_array = array_map('trim', explode(',', $tf_classes));
            foreach ($classes_array as $pattern) {
                // Convertir le pattern IANSEO en regex
                // Ex: "CO%" devient "^CO.*"
                // Ex: "CLS2" devient "^CLS2$"
                
                if (strpos($pattern, '%') !== false) {
                    // Pattern avec wildcard
                    $regex_pattern = '^' . str_replace('%', '.*', preg_quote($pattern, '/')) . '$';
                } else {
                    // Pattern exact
                    $regex_pattern = '^' . preg_quote($pattern, '/') . '$';
                }
                
                debug_response("Test pattern", ['pattern' => $pattern, 'regex' => $regex_pattern]);
                
                if (preg_match('/' . $regex_pattern . '/', $full_category)) {
                    $is_valid = true;
                    debug_response("Match: TfClasses pattern", $pattern);
                    break;
                }
            }
        }
        
        // Vérification 3: TfRegExp si défini (après TfClasses)
        if ($is_valid && !empty($tf_regexp)) {
            // Appliquer la regex sur la catégorie complète
            if (!@preg_match('/' . $tf_regexp . '/', $full_category)) {
                $is_valid = false;
                debug_response("Rejet: TfRegExp ne match pas", $tf_regexp);
            } else {
                debug_response("Match: TfRegExp OK", $tf_regexp);
            }
        }
        
        if ($is_valid) {
            $targets[] = [
                'id' => $row['TfId'], 
                'name' => $row['TfName'],
                'default' => intval($row['TfDefault'])
            ];
        }
    }
    
    debug_response("Blasons compatibles trouvés", count($targets));
    send_success($targets);
}

// ACTION: submit_registration
if ($action === 'submit_registration') {
    $data = json_decode($_POST['data'], true);
    
    debug_response("Début inscription", $data);
    
    mysqli_begin_transaction($conn);
    
    try {
        // Récupérer le premier CoId du tournoi
        $country_query = "SELECT CoId FROM Countries WHERE CoTournament = ? LIMIT 1";
        $country_stmt = mysqli_prepare($conn, $country_query);
        mysqli_stmt_bind_param($country_stmt, "i", $tournament_id);
        mysqli_stmt_execute($country_stmt);
        $country_result = mysqli_stmt_get_result($country_stmt);
        $country_row = mysqli_fetch_assoc($country_result);
        
        if (!$country_row) {
            throw new Exception("Aucun pays configuré pour ce tournoi");
        }
        
        $country_id = $country_row['CoId'];
        debug_response("Country ID", $country_id);
        
        sort($data['sessions']);
        
        $inserted_ids = [];
        
        foreach ($data['sessions'] as $index => $session) {
            $ind_cl_event = ($index === 0) ? 1 : 0;
            
            // Récupérer le blason pour ce départ spécifique
            $targetface = isset($data['targetfaces'][$session]) ? intval($data['targetfaces'][$session]) : 0;
            
            debug_response("Insertion session", ['session' => $session, 'index' => $index, 'targetface' => $targetface]);
            
            $insert_query = "INSERT INTO Entries (
                EnTournament, EnCode, EnIocCode, EnName, EnFirstName, 
                EnSex, EnDob, EnDivision, EnClass, EnAgeClass,
                EnCountry, EnCountry2, EnCountry3, EnSubTeam, EnCtrlCode,
                EnTargetFace, EnStatus, EnAthlete, EnClassified,
                EnIndClEvent, EnTeamClEvent, EnIndFEvent, EnTeamFEvent, EnTeamMixEvent,
                EnWChair, EnSitting, EnPays, EnDoubleSpace, EnSubClass,
                EnBadgePrinted, EnNameOrder,
                EnLueTimeStamp, EnLueFieldChanged,
                EnOdfShortname, EnTvGivenName, EnTvFamilyName, EnTvInitials,
                EnTimestamp
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, 0, 0, 0, '',
                ?, 0, 1, ?,
                ?, 0, 0, 0, 0,
                0, 0, 1, 0, '',
                NULL, 0,
                '0000-00-00 00:00:00', 0,
                '', '', '', '',
                NOW()
            )";
            
            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($stmt, "isssssssssiiii",
                $tournament_id, 
                $data['license'], 
                $data['ioccode'],
                $data['name'], 
                $data['firstname'],
                $data['sex'], 
                $data['dob'], 
                $data['division'], 
                $data['ageclass'], 
                $data['ageclass'],
                $country_id,
                $targetface,
                $data['classified'], 
                $ind_cl_event
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Erreur Entries: " . mysqli_stmt_error($stmt));
            }
            
            $entry_id = mysqli_insert_id($conn);
            $inserted_ids[] = $entry_id;
            
            debug_response("Entry créée", ['EnId' => $entry_id]);
            
            // INSERT dans Qualifications avec TOUS les champs
            $qual_query = "INSERT INTO Qualifications (
                QuId, QuSession, QuTarget, QuLetter, QuTargetNo, QuBacknoPrinted,
                QuD1Score, QuD1Hits, QuD1Gold, QuD1Xnine, QuD1TieBreaker3, QuD1Arrowstring, QuD1Rank, QuD1Status,
                QuD2Score, QuD2Hits, QuD2Gold, QuD2Xnine, QuD2TieBreaker3, QuD2Arrowstring, QuD2Rank, QuD2Status,
                QuD3Score, QuD3Hits, QuD3Gold, QuD3Xnine, QuD3TieBreaker3, QuD3Arrowstring, QuD3Rank, QuD3Status,
                QuD4Score, QuD4Hits, QuD4Gold, QuD4Xnine, QuD4TieBreaker3, QuD4Arrowstring, QuD4Rank, QuD4Status,
                QuD5Score, QuD5Hits, QuD5Gold, QuD5Xnine, QuD5TieBreaker3, QuD5Arrowstring, QuD5Rank, QuD5Status,
                QuD6Score, QuD6Hits, QuD6Gold, QuD6Xnine, QuD6TieBreaker3, QuD6Arrowstring, QuD6Rank, QuD6Status,
                QuD7Score, QuD7Hits, QuD7Gold, QuD7Xnine, QuD7TieBreaker3, QuD7Arrowstring, QuD7Rank, QuD7Status,
                QuD8Score, QuD8Hits, QuD8Gold, QuD8Xnine, QuD8TieBreaker3, QuD8Arrowstring, QuD8Rank, QuD8Status,
                QuScore, QuHits, QuGold, QuXnine, QuTieBreaker3, QuArrow, QuConfirm, QuSigned,
                QuClRank, QuSubClassRank, QuStatus, QuTie, QuTieWeight, QuTieWeightDrops, QuTieWeightDecoded, QuTieBreak,
                QuTimestamp, QuNotes, QuIrmType
            ) VALUES (
                ?, ?, 0, '', '', '0000-00-00 00:00:00',
                0, 0, 0, 0, 0, '', 0, 0,
                0, 0, 0, 0, 0, '', 0, 0,
                0, 0, 0, 0, 0, '', 0, 0,
                0, 0, 0, 0, 0, '', 0, 0,
                0, 0, 0, 0, 0, '', 0, 0,
                0, 0, 0, 0, 0, '', 0, 0,
                0, 0, 0, 0, 0, '', 0, 0,
                0, 0, 0, 0, 0, '', 0, 0,
                0, 0, 0, 0, 0, 0, 0, 0,
                0, 0, 0, 0, '', '', '', '', NULL,
                '', 0
            )";
            
            $qual_stmt = mysqli_prepare($conn, $qual_query);
            mysqli_stmt_bind_param($qual_stmt, "ii", $entry_id, $session);
            
            if (!mysqli_stmt_execute($qual_stmt)) {
                throw new Exception("Erreur Qualifications: " . mysqli_stmt_error($qual_stmt));
            }
            
            debug_response("Qualification créée", ['QuId' => $entry_id, 'QuSession' => $session]);

// Insertion de l'email dans ExtraData
if (!empty($data['email'])) {
    $email_query = "INSERT INTO ExtraData (EdId, EdType, EdEmail) 
                   VALUES (?, 'E', ?)
                   ON DUPLICATE KEY UPDATE EdEmail = ?";
    $email_stmt = mysqli_prepare($conn, $email_query);
    mysqli_stmt_bind_param($email_stmt, "iss", $entry_id, $data['email'], $data['email']);
    
    if (!mysqli_stmt_execute($email_stmt)) {
        debug_response("Avertissement email", mysqli_stmt_error($email_stmt));
    } else {
        debug_response("Email enregistré", ['EnId' => $entry_id, 'Email' => $data['email']]);
    }
}


        }
        
        mysqli_commit($conn);
        
        send_success([
            'entry_ids' => $inserted_ids,
            'message' => 'Inscription réussie !'
        ], 'Enregistré');
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        send_error("Erreur inscription: " . $e->getMessage());
    }
}

// ACTION: get_tournament_name
if ($action === 'get_tournament_name') {
    $query = "SELECT ToName FROM Tournament WHERE ToId = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $tournament_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        send_success(['name' => $row['ToName']]);
    } else {
        send_error("Tournoi introuvable");
    }
}

mysqli_close($conn);
send_error("Action inconnue", ['action' => $action]);
?>

