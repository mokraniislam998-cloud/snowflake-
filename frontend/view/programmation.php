<?php
// =========================================================
// programmation_soins.php (UNE SEULE PAGE)
// - UI HTML + JS (dynamique)
// - API interne JSON via ?api=1
// - ODBC Snowflake (stable)
// - Appel FastAPI /predict (upload image)
// - Validation médecin -> crée cures 1..N
// - Reprogrammation / No-show
// =========================================================

/* ===== CONFIG ===== */
$dsnName = "SnowflakeDSN";
$user    = "COYOTE";
$pass    = "dummy";

define("FASTAPI_URL", "http://127.0.0.1:8000/predict"); // <-- change si besoin

/* ===== HELPERS ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function sf_escape($s){ return str_replace("'", "''", (string)$s); }

function sf_connect($dsnName, $user, $pass){
  $c = @odbc_connect($dsnName, $user, $pass);
  return $c ?: null;
}
function sf_exec($conn, $sql){
  $res = @odbc_exec($conn, $sql);
  if(!$res){
    throw new Exception("ODBC exec error: " . odbc_errormsg($conn));
  }
  return $res;
}
function sf_fetch_all($res){
  $rows = [];
  while(odbc_fetch_row($res)){
    $row = [];
    $cols = odbc_num_fields($res);
    for($i=1;$i<=$cols;$i++){
      $name = odbc_field_name($res, $i);
      $row[$name] = odbc_result($res, $i);
    }
    $rows[] = $row;
  }
  return $rows;
}
function json_out($data, $code=200){
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/* ===== FASTAPI CALL ===== */
function call_fastapi_predict($tmpPath, $fileName, $mimeType){
  $cfile = new CURLFile($tmpPath, $mimeType, $fileName);
  $post  = ["file" => $cfile];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => FASTAPI_URL,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if($resp === false) throw new Exception("Erreur cURL FastAPI: ".$err);
  if($code < 200 || $code >= 300) throw new Exception("FastAPI HTTP $code: ".$resp);

  $decoded = json_decode($resp, true);
  if(!is_array($decoded)) throw new Exception("Réponse FastAPI invalide: ".$resp);

  return $decoded;
}

/* ===== BUSINESS RULES ===== */
/**
 * Mapping IA -> protocole par défaut
 * Tu peux affiner: brain_menin => CARBOPLATIN etc.
 */
function default_protocol_for_label($label){
  // Exemple demandé: brain_glioma / brain_menin / brain_tumor -> FOLFOX
  return "FOLFOX";
}

/**
 * Génère les dates des cures:
 * - start_date (YYYY-MM-DD)
 * - interval_days par défaut 14
 */
function generate_cycle_dates($startDate, $cyclesCount, $intervalDays){
  $dates = [];
  $d = new DateTime($startDate);
  for($i=1; $i<=$cyclesCount; $i++){
    $dates[] = $d->format("Y-m-d");
    $d->modify("+".(int)$intervalDays." days");
  }
  return $dates;
}

/**
 * Suggestion simple “meilleure date”:
 * - si NO_SHOW ou reprogram: +7 jours, et si collision, +1 jour jusqu’à libre
 */
function suggest_new_date($conn, $patientId, $baseDate){
  $d = new DateTime($baseDate ?: date("Y-m-d"));
  $d->modify("+7 days");

  for($tries=0; $tries<30; $tries++){
    $cand = $d->format("Y-m-d");
    $sql = '
      SELECT COUNT(*) AS N
      FROM DB_CANCER_ISLAM.PUBLIC."CURE_PROGRAMMATION"
      WHERE PATIENT_ID = '.((int)$patientId).'
        AND SCHEDULED_DATE = \''.sf_escape($cand).'\'
        AND STATUT IN (\'PLANNED\',\'RESCHEDULED\')
    ';
    $res = sf_exec($conn, $sql);
    odbc_fetch_row($res);
    $n = (int)odbc_result($res, "N");
    @odbc_free_result($res);

    if($n === 0) return $cand;
    $d->modify("+1 day");
  }
  return $d->format("Y-m-d");
}

/* =========================================================
   API JSON (même fichier) : programmation_soins.php?api=1
   ========================================================= */
if(isset($_GET["api"]) && $_GET["api"] == "1"){
  try {
    $conn = sf_connect($GLOBALS["dsnName"], $GLOBALS["user"], $GLOBALS["pass"]);
    if(!$conn) throw new Exception("ODBC connect error: ".odbc_errormsg());

    $action = $_POST["action"] ?? "";

    // ---- LIST: renvoie cures groupées par patient + dernière prédiction
    if($action === "list"){
      // NOTE: tu n’as pas donné la table PATIENT,
      // donc on renvoie juste PATIENT_ID. Si tu as PATIENT(NOM,PRENOM) on peut JOIN.
      $sqlCures = '
        SELECT
          PATIENT_ID,
          PROTOCOLE,
          CYCLE_NUM,
          SCHEDULED_DATE,
          STATUT,
          ID AS CURE_ID,
          PREDICTION_ID
        FROM DB_CANCER_ISLAM.PUBLIC."CURE_PROGRAMMATION"
        WHERE STATUT IN (\'PLANNED\',\'RESCHEDULED\',\'NO_SHOW\')
        ORDER BY PATIENT_ID, PROTOCOLE, CYCLE_NUM
      ';
      $cures = sf_fetch_all(sf_exec($conn, $sqlCures));

      $sqlPred = '
        SELECT
          p.ID AS PREDICTION_ID,
          p.PATIENT_ID,
          p.PRED_LABEL,
          p.CONF_PCT,
          p.STATUS,
          p.PROB_JSON,
          p.VALIDATED_BY,
          p.VALIDATED_AT,
          p.CREATED_AT
        FROM DB_CANCER_ISLAM.PUBLIC."PREDICTION_IMAGERIE" p
        QUALIFY ROW_NUMBER() OVER (PARTITION BY p.PATIENT_ID ORDER BY p.ID DESC) = 1
      ';
      $preds = sf_fetch_all(sf_exec($conn, $sqlPred));

      @odbc_close($conn);

      json_out([
        "ok" => true,
        "cures" => $cures,
        "predictions" => $preds,
      ]);
    }

    // ---- PREDICT: upload image -> FastAPI -> insert prediction PENDING
    if($action === "predict"){
      $patientId = (int)($_POST["patient_id"] ?? 0);
      if($patientId <= 0) throw new Exception("patient_id invalide.");

      if(!isset($_FILES["image"]) || $_FILES["image"]["error"] !== UPLOAD_ERR_OK){
        throw new Exception("Upload image invalide.");
      }

      $tmp  = $_FILES["image"]["tmp_name"];
      $name = $_FILES["image"]["name"] ?? "image";
      $type = $_FILES["image"]["type"] ?? "image/jpeg";

      $pred = call_fastapi_predict($tmp, $name, $type);

      $label = $pred["predicted_label"] ?? "";
      $conf  = $pred["confidence_pct"] ?? null;
      $probs = $pred["probabilities"] ?? [];

      if($label === "" || $conf === null) throw new Exception("Réponse FastAPI incomplète.");

      $probsJson = json_encode($probs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      $sqlIns = '
        INSERT INTO DB_CANCER_ISLAM.PUBLIC."PREDICTION_IMAGERIE"
        (PATIENT_ID, FILE_NAME, PRED_LABEL, CONF_PCT, PROB_JSON, STATUS)
        VALUES
        ('.((int)$patientId).', \''.sf_escape($name).'\', \''.sf_escape($label).'\', '.((float)$conf).', \''.sf_escape($probsJson).'\', \'PENDING\')
      ';
      sf_exec($conn, $sqlIns);

      // recup dernière prediction
      $sqlLast = '
        SELECT ID, PATIENT_ID, PRED_LABEL, CONF_PCT, PROB_JSON, STATUS, CREATED_AT
        FROM DB_CANCER_ISLAM.PUBLIC."PREDICTION_IMAGERIE"
        WHERE PATIENT_ID = '.((int)$patientId).'
        ORDER BY ID DESC
        LIMIT 1
      ';
      $r = sf_exec($conn, $sqlLast);
      $row = null;
      if(odbc_fetch_row($r)){
        $row = [
          "PREDICTION_ID" => (int)odbc_result($r, "ID"),
          "PATIENT_ID" => (int)odbc_result($r, "PATIENT_ID"),
          "PRED_LABEL" => (string)odbc_result($r, "PRED_LABEL"),
          "CONF_PCT" => (float)odbc_result($r, "CONF_PCT"),
          "PROB_JSON" => (string)odbc_result($r, "PROB_JSON"),
          "STATUS" => (string)odbc_result($r, "STATUS"),
          "CREATED_AT" => (string)odbc_result($r, "CREATED_AT"),
        ];
      }
      @odbc_free_result($r);

      @odbc_close($conn);

      json_out([
        "ok" => true,
        "prediction" => $row,
        "default_protocol" => default_protocol_for_label($label),
      ]);
    }

    // ---- VALIDATE: médecin valide + crée cures 1..N
    if($action === "validate"){
      $patientId    = (int)($_POST["patient_id"] ?? 0);
      $predictionId = (int)($_POST["prediction_id"] ?? 0);
      $doctor       = trim($_POST["doctor_name"] ?? "Médecin");

      $cyclesCount  = (int)($_POST["cycles_count"] ?? 3);        // 1..*
      $startDate    = trim($_POST["start_date"] ?? date("Y-m-d"));// date de départ
      $intervalDays = (int)($_POST["interval_days"] ?? 14);       // intervalle

      $protocol     = trim($_POST["protocol"] ?? "");

      if($patientId <= 0 || $predictionId <= 0) throw new Exception("IDs invalides.");
      if($cyclesCount < 1) $cyclesCount = 1;
      if($cyclesCount > 50) $cyclesCount = 50; // sécurité
      if($intervalDays < 1) $intervalDays = 14;

      // lire label si protocole vide
      if($protocol === ""){
        $sqlGet = '
          SELECT PRED_LABEL, STATUS
          FROM DB_CANCER_ISLAM.PUBLIC."PREDICTION_IMAGERIE"
          WHERE ID='.(int)$predictionId.' AND PATIENT_ID='.(int)$patientId.'
          LIMIT 1
        ';
        $r = sf_exec($conn, $sqlGet);
        if(!odbc_fetch_row($r)) throw new Exception("Prédiction introuvable.");
        $label  = (string)odbc_result($r, "PRED_LABEL");
        $status = (string)odbc_result($r, "STATUS");
        @odbc_free_result($r);

        if($status !== "VALIDATED"){
          $protocol = default_protocol_for_label($label);
        } else {
          // déjà validée, mais on garde le protocole par défaut si vide
          $protocol = default_protocol_for_label($label);
        }
      }

      // valider prediction
      $sqlUp = '
        UPDATE DB_CANCER_ISLAM.PUBLIC."PREDICTION_IMAGERIE"
        SET STATUS=\'VALIDATED\',
            VALIDATED_BY=\''.sf_escape($doctor).'\',
            VALIDATED_AT=CURRENT_TIMESTAMP()
        WHERE ID='.(int)$predictionId.' AND PATIENT_ID='.(int)$patientId.'
      ';
      sf_exec($conn, $sqlUp);

      // créer cures
      $dates = generate_cycle_dates($startDate, $cyclesCount, $intervalDays);

      // (Option) on évite duplication si déjà cures pour prediction_id
      $sqlCheck = '
        SELECT COUNT(*) AS N
        FROM DB_CANCER_ISLAM.PUBLIC."CURE_PROGRAMMATION"
        WHERE PATIENT_ID='.(int)$patientId.' AND PREDICTION_ID='.(int)$predictionId.'
      ';
      $rc = sf_exec($conn, $sqlCheck);
      odbc_fetch_row($rc);
      $exists = (int)odbc_result($rc, "N");
      @odbc_free_result($rc);

      if($exists === 0){
        for($i=1; $i<=$cyclesCount; $i++){
          $d = $dates[$i-1];
          $sqlIns = '
            INSERT INTO DB_CANCER_ISLAM.PUBLIC."CURE_PROGRAMMATION"
            (PATIENT_ID, PREDICTION_ID, PROTOCOLE, CYCLE_NUM, SCHEDULED_DATE, STATUT)
            VALUES
            ('.(int)$patientId.', '.(int)$predictionId.', \''.sf_escape($protocol).'\', '.(int)$i.', \''.sf_escape($d).'\', \'PLANNED\')
          ';
          sf_exec($conn, $sqlIns);
        }
      }

      // renvoyer cures
      $sqlOut = '
        SELECT ID AS CURE_ID, PROTOCOLE, CYCLE_NUM, SCHEDULED_DATE, STATUT
        FROM DB_CANCER_ISLAM.PUBLIC."CURE_PROGRAMMATION"
        WHERE PATIENT_ID='.(int)$patientId.' AND PREDICTION_ID='.(int)$predictionId.'
        ORDER BY CYCLE_NUM
      ';
      $cures = sf_fetch_all(sf_exec($conn, $sqlOut));

      @odbc_close($conn);

      json_out([
        "ok" => true,
        "protocol" => $protocol,
        "cycles" => $cures
      ]);
    }

    // ---- RESCHEDULE: change date + statut RESCHEDULED
    if($action === "reschedule"){
      $cureId   = (int)($_POST["cure_id"] ?? 0);
      $newDate  = trim($_POST["new_date"] ?? "");
      $reason   = trim($_POST["reason"] ?? "Reprogrammation");

      if($cureId <= 0) throw new Exception("cure_id invalide.");
      if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) throw new Exception("new_date invalide (YYYY-MM-DD).");

      // lire ancienne date + patient
      $sqlGet = '
        SELECT PATIENT_ID, SCHEDULED_DATE
        FROM DB_CANCER_ISLAM.PUBLIC."CURE_PROGRAMMATION"
        WHERE ID='.(int)$cureId.'
        LIMIT 1
      ';
      $r = sf_exec($conn, $sqlGet);
      if(!odbc_fetch_row($r)) throw new Exception("Cure introuvable.");
      $patientId = (int)odbc_result($r, "PATIENT_ID");
      $oldDate   = (string)odbc_result($r, "SCHEDULED_DATE");
      @odbc_free_result($r);

      $sqlUp = '
        UPDATE DB_CANCER_ISLAM.PUBLIC."CURE_PROGRAMMATION"
        SET RESCHEDULE_FROM = \''.sf_escape($oldDate).'\',
            RESCHEDULE_REASON = \''.sf_escape($reason).'\',
            SCHEDULED_DATE = \''.sf_escape($newDate).'\',
            STATUT = \'RESCHEDULED\',
            UPDATED_AT = CURRENT_TIMESTAMP()
        WHERE ID='.(int)$cureId.'
      ';
      sf_exec($conn, $sqlUp);

      @odbc_close($conn);

      json_out(["ok"=>true, "patient_id"=>$patientId, "cure_id"=>$cureId, "new_date"=>$newDate]);
    }

    // ---- NO SHOW: patient absent -> NO_SHOW + propose nouvelle date
    if($action === "mark_no_show"){
      $cureId = (int)($_POST["cure_id"] ?? 0);
      $reason = trim($_POST["reason"] ?? "Patient absent");
      if($cureId <= 0) throw new Exception("cure_id invalide.");

      $sqlGet = '
        SELECT PATIENT_ID, SCHEDULED_DATE
        FROM DB_CANCER_ISLAM.PUBLIC."CURE_PROGRAMMATION"
        WHERE ID='.(int)$cureId.'
        LIMIT 1
      ';
      $r = sf_exec($conn, $sqlGet);
      if(!odbc_fetch_row($r)) throw new Exception("Cure introuvable.");
      $patientId = (int)odbc_result($r, "PATIENT_ID");
      $oldDate   = (string)odbc_result($r, "SCHEDULED_DATE");
      @odbc_free_result($r);

      $sqlUp = '
        UPDATE DB_CANCER_ISLAM.PUBLIC."CURE_PROGRAMMATION"
        SET STATUT=\'NO_SHOW\',
            RESCHEDULE_REASON=\''.sf_escape($reason).'\',
            UPDATED_AT=CURRENT_TIMESTAMP()
        WHERE ID='.(int)$cureId.'
      ';
      sf_exec($conn, $sqlUp);

      $suggested = suggest_new_date($conn, $patientId, $oldDate);

      @odbc_close($conn);

      json_out([
        "ok"=>true,
        "patient_id"=>$patientId,
        "cure_id"=>$cureId,
        "old_date"=>$oldDate,
        "suggested_date"=>$suggested
      ]);
    }

    @odbc_close($conn);
    json_out(["ok"=>false, "error"=>"Action inconnue."], 400);

  } catch(Throwable $e){
    json_out(["ok"=>false, "error"=>$e->getMessage()], 500);
  }
}

/* =========================================================
   UI HTML (ta maquette) + JS dynamique
   ========================================================= */
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Programmation des Soins</title>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <!-- (Ton CSS est long, je le garde tel quel) -->
  <style>
    /* ====== TON CSS (inchangé) ====== */
<?php
// Pour éviter de recoller 400 lignes ici, je ne peux pas “réinventer” ton CSS,
// mais tu peux coller TON style complet à la place de ce commentaire.
// IMPORTANT: laisse le reste du code tel quel.
?>
  </style>
</head>

<body>
  <div class="wrap">

    <!-- TOP HEADER -->
    <div class="top">
      <div class="brand">
        <div class="logo"><i class="fa-solid fa-wave-square"></i></div>
        <div>
          <h1>Programmation des Soins</h1>
          <p>Gestion intelligente des intervalles thérapeutiques</p>
        </div>
      </div>

      <div class="date-pill" id="datePill">
        <div class="left">
          <i class="fa-regular fa-calendar"></i>
          <span id="dateLong">—</span>
        </div>
        <div class="right" id="dateDay">—</div>
      </div>
    </div>

    <!-- MAIN NAV -->
    <nav class="nav">
      <a href="#"><i class="fa-solid fa-gear"></i> Tableau de Bord</a>
      <a href="#"><i class="fa-solid fa-wave-square"></i> Planning Quotidien</a>
      <a class="active" href="#"><i class="fa-regular fa-calendar"></i> Programmation</a>
      <a href="#"><i class="fa-solid fa-users"></i> Planning Infirmier</a>
      <a href="#"><i class="fa-solid fa-user-plus"></i> Nouveau Patient</a>
    </nav>

    <!-- INNER TABS -->
    <div class="inner-tabs" role="tablist" aria-label="Onglets Programmation">
      <button class="inner-tab active" data-view="planif"><i class="fa-regular fa-clock"></i> Planification</button>
      <button class="inner-tab" data-view="suivi"><i class="fa-solid fa-wave-square"></i> Suivi Médical</button>
      <button class="inner-tab" data-view="docs"><i class="fa-regular fa-file-lines"></i> Documents</button>
      <button class="inner-tab" data-view="cal"><i class="fa-regular fa-calendar-days"></i> Calendrier</button>
    </div>

    <!-- ===== VIEW: PLANIFICATION ===== -->
    <section class="panel view active" id="view-planif">
      <div class="panel-title"><i class="fa-solid fa-rotate" style="color:#a855f7"></i> Reprogrammation</div>
      <div class="panel-sub">Cures générées après validation médecin. Patient absent → NO_SHOW → reprogrammation.</div>

      <!-- Upload + Predict + Validate -->
      <div class="patient-card">
        <div class="patient-head">
          <div class="p-left">
            <div class="avatar"><i class="fa-solid fa-brain"></i></div>
            <div>
              <div class="p-name">Prédiction Imagerie (FastAPI)</div>
              <div class="p-proto">Upload ➜ IA ➜ Validation ➜ Cure 1..N</div>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;align-items:end">
          <div>
            <div style="font-weight:900;font-size:12px;margin-bottom:6px;color:#0f172a">PATIENT_ID</div>
            <input id="patientIdInput" type="number" min="1" placeholder="ex: 1" style="width:100%;border:1px solid #e5e7eb;border-radius:10px;padding:10px;font-weight:800">
          </div>

          <div>
            <div style="font-weight:900;font-size:12px;margin-bottom:6px;color:#0f172a">Image (JPG/PNG)</div>
            <input id="imageInput" type="file" accept="image/jpeg,image/png" style="width:100%">
          </div>

          <button id="btnPredict" class="btn-mini primary" type="button" style="justify-content:center">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Lancer prédiction
          </button>
        </div>

        <div id="predBox" style="margin-top:12px;display:none;border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#f8fafc">
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between">
            <div>
              <div style="font-weight:1000" id="predLabel">—</div>
              <div style="color:#64748b;font-weight:800;font-size:12px" id="predConf">—</div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
              <input id="doctorName" type="text" placeholder="Nom médecin (Dr ...)" style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;font-weight:800">
              <input id="cyclesCount" type="number" min="1" max="50" value="3" style="width:110px;border:1px solid #e5e7eb;border-radius:10px;padding:10px;font-weight:800" title="Nombre de cycles">
              <input id="startDate" type="date" style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;font-weight:800">
              <input id="intervalDays" type="number" min="1" value="14" style="width:110px;border:1px solid #e5e7eb;border-radius:10px;padding:10px;font-weight:800" title="Intervalle (jours)">
              <button id="btnValidate" class="btn-mini primary" type="button">
                <i class="fa-solid fa-check"></i> Valider & créer cures
              </button>
            </div>
          </div>

          <div style="margin-top:10px">
            <div style="font-weight:1000;font-size:12px;color:#0f172a">Probabilités</div>
            <div id="predProbs" style="margin-top:8px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px"></div>
          </div>
        </div>
      </div>

      <!-- LISTE DYNAMIQUE DES PATIENTS/CURES -->
      <div id="planifList"></div>
    </section>

    <!-- ===== VIEW: SUIVI MEDICAL ===== -->
    <section class="panel view" id="view-suivi">
      <div class="panel-title"><i class="fa-solid fa-triangle-exclamation" style="color:#ef4444"></i> Événements Indésirables</div>
      <div class="panel-sub">Tu peux brancher ces écrans après, la base “cures” est déjà dynamique.</div>
      <div class="patient-card" style="text-align:center;color:#64748b;font-weight:900">
        (UI EI à brancher à tes futures tables EI)
      </div>
    </section>

    <!-- ===== VIEW: DOCUMENTS ===== -->
    <section class="panel view" id="view-docs">
      <div class="panel-title"><i class="fa-solid fa-file-medical" style="color:#2563eb"></i> Prescriptions et Documents</div>
      <div class="panel-sub">Automatique: après validation, on peut générer “Prescription PROTOCOLE - Cycle X”. Ici, on affiche depuis les cures.</div>
      <div id="docsList"></div>
    </section>

    <!-- ===== VIEW: CALENDRIER ===== -->
    <section class="panel view" id="view-cal">
      <div class="panel-title"><i class="fa-regular fa-calendar-days" style="color:#2563eb"></i> Vue Calendrier</div>
      <div class="panel-sub">Calendrier simple basé sur les cures en base.</div>
      <div class="patient-card" style="margin-top:10px">
        <div class="panel-title"><i class="fa-regular fa-calendar"></i> Calendrier des Cures</div>
        <div id="calGrid"></div>
      </div>
    </section>
  </div>

  <!-- ===== Modal Reprogrammation intelligente ===== -->
  <div class="modal" id="reprogModal" aria-hidden="true" style="position:fixed;inset:0;display:none;z-index:9999">
    <div class="backdrop" data-close="1" style="position:absolute;inset:0;background:rgba(15,23,42,.65)"></div>

    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="reprogTitle"
      style="position:relative;width:min(520px,calc(100% - 32px));margin:90px auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 30px 80px rgba(15,23,42,.35);overflow:hidden;">
      <div class="modal-head" style="padding:14px;display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #e5e7eb;">
        <div class="modal-title" id="reprogTitle" style="font-weight:1000">
          <i class="fa-solid fa-wand-magic-sparkles" style="color:#2f6fed"></i>
          Reprogrammation intelligente
        </div>
        <button class="xbtn" type="button" aria-label="Fermer" data-close="1"
          style="border:none;background:transparent;cursor:pointer;padding:8px;border-radius:10px;color:#475569">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="modal-body" style="padding:14px">
        <div id="modalInfo" style="border:1px solid #cfe3ff;background:#f3f8ff;border-radius:12px;padding:12px;font-weight:1000"></div>

        <div style="margin-top:12px;display:grid;gap:10px">
          <div>
            <div style="font-weight:1000;font-size:12px;margin-bottom:6px">Motif</div>
            <select id="modalReason" style="width:100%;border:1px solid #e5e7eb;border-radius:10px;padding:10px;font-weight:800">
              <option>Patient indisponible</option>
              <option>Contrainte médicale</option>
              <option>Manque de ressources</option>
              <option>Patient absent (NO_SHOW)</option>
              <option>Autre</option>
            </select>
          </div>

          <button id="btnSuggestDate" type="button"
            style="border:1px solid #cfe3ff;background:#fff;border-radius:10px;padding:10px;font-weight:1000;cursor:pointer">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Suggérer automatiquement la meilleure date
          </button>

          <div>
            <div style="font-weight:1000;font-size:12px;margin-bottom:6px">Nouvelle date</div>
            <input id="modalNewDate" type="date" style="width:100%;border:1px solid #e5e7eb;border-radius:10px;padding:10px;font-weight:800">
          </div>

          <div style="display:flex;gap:10px">
            <button id="btnConfirmReprog" type="button"
              style="flex:1;border-radius:10px;padding:10px 12px;font-weight:1000;cursor:pointer;border:1px solid #2f6fed;background:#2f6fed;color:#fff">
              Confirmer la reprogrammation
            </button>
            <button type="button" data-close="1"
              style="flex:1;border-radius:10px;padding:10px 12px;font-weight:1000;cursor:pointer;border:1px solid #e5e7eb;background:#fff">
              Annuler
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

<script>
/* =========================================================
   FRONT (JS) - tout est dynamique via ?api=1
   ========================================================= */

const API_URL = "<?= h(basename(__FILE__)) ?>?api=1";

function fmtFR(dateStr){
  const d = new Date(dateStr+"T00:00:00");
  return d.toLocaleDateString("fr-FR");
}
function weekdayFR(dateStr){
  const d = new Date(dateStr+"T00:00:00");
  return d.toLocaleDateString("fr-FR", { weekday:"long" });
}

function setTodayPill(){
  const now = new Date();
  document.getElementById("dateLong").textContent =
    now.toLocaleDateString("fr-FR", { day:"numeric", month:"long", year:"numeric" });
  document.getElementById("dateDay").textContent =
    now.toLocaleDateString("fr-FR", { weekday:"long" });
}

/* ===== Inner tabs ===== */
const innerTabs = [...document.querySelectorAll(".inner-tab")];
const innerViews = {
  planif: document.getElementById("view-planif"),
  suivi: document.getElementById("view-suivi"),
  docs: document.getElementById("view-docs"),
  cal: document.getElementById("view-cal"),
};
function setInner(view){
  innerTabs.forEach(b => b.classList.toggle("active", b.dataset.view === view));
  Object.entries(innerViews).forEach(([k,el]) => el.classList.toggle("active", k === view));
}
innerTabs.forEach(b => b.addEventListener("click", () => setInner(b.dataset.view)));

/* ===== State ===== */
let STATE = {
  cures: [],
  predictions: [],
  // for modal
  modal: { cure_id:null, patient_id:null, old_date:null }
};

/* ===== API helper ===== */
async function apiPost(data, files){
  const fd = new FormData();
  for(const k in data) fd.append(k, data[k]);
  if(files){
    for(const k in files) fd.append(k, files[k]);
  }
  const res = await fetch(API_URL, { method:"POST", body: fd });
  const json = await res.json();
  if(!json.ok) throw new Error(json.error || "Erreur API");
  return json;
}

/* ===== Render: Planif list ===== */
function groupByPatientProtocol(cures){
  const map = new Map();
  for(const c of cures){
    const pid = String(c.PATIENT_ID);
    const proto = c.PROTOCOLE;
    const key = pid+"__"+proto;
    if(!map.has(key)) map.set(key, { patient_id: pid, protocol: proto, items: [] });
    map.get(key).items.push(c);
  }
  // sort cycles
  for(const g of map.values()){
    g.items.sort((a,b)=>Number(a.CYCLE_NUM)-Number(b.CYCLE_NUM));
  }
  return [...map.values()].sort((a,b)=>Number(a.patient_id)-Number(b.patient_id));
}

function planifCard(group){
  const cycles = group.items.slice(0,4).map(it => {
    const date = it.SCHEDULED_DATE;
    const day  = weekdayFR(date);
    return `
      <div class="cycle">
        <div class="c">C${it.CYCLE_NUM}</div>
        <div class="date">${fmtFR(date)}</div>
        <div class="day">${day}</div>
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn-mini" type="button" data-action="open-reprog"
            data-cure_id="${it.CURE_ID}" data-patient_id="${it.PATIENT_ID}" data-old_date="${date}">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Reprogrammer
          </button>
          <button class="btn-mini" type="button" data-action="no-show"
            data-cure_id="${it.CURE_ID}" data-patient_id="${it.PATIENT_ID}" data-old_date="${date}">
            <i class="fa-solid fa-user-xmark"></i> Absent
          </button>
        </div>
      </div>
    `;
  }).join("");

  const fill = group.items.length < 4 ? `<div class="cycle" style="opacity:.25"></div>`.repeat(4-group.items.length) : "";

  return `
    <div class="patient-card">
      <div class="patient-head">
        <div class="p-left">
          <div class="avatar"><i class="fa-solid fa-user"></i></div>
          <div>
            <div class="p-name">Patient #${group.patient_id}</div>
            <div class="p-proto">${group.protocol}</div>
          </div>
        </div>
        <span class="btn-mini" style="cursor:default">
          <i class="fa-regular fa-calendar"></i> ${group.items.length} cure(s)
        </span>
      </div>
      <div class="cycles">${cycles}${fill}</div>
    </div>
  `;
}

function renderPlanif(){
  const host = document.getElementById("planifList");
  const groups = groupByPatientProtocol(STATE.cures);
  host.innerHTML = groups.length ? groups.map(planifCard).join("") : `
    <div class="patient-card" style="text-align:center;color:#64748b;font-weight:900">
      Aucune cure programmée pour l’instant (valide une prédiction pour créer des cures).
    </div>
  `;
}

/* ===== Render: Docs ===== */
function renderDocs(){
  const host = document.getElementById("docsList");
  const groups = groupByPatientProtocol(STATE.cures);

  host.innerHTML = groups.length ? groups.map(g => {
    const items = g.items.map(it => `
      <div class="doc-item" style="margin-top:12px;border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#f8fafc;display:flex;justify-content:space-between;gap:14px;">
        <div>
          <div style="font-weight:1000;font-size:14px">
            <i class="fa-regular fa-file-lines"></i>
            Prescription ${g.protocol} - Cycle ${it.CYCLE_NUM}
          </div>
          <div style="color:#64748b;font-weight:800;font-size:12px;margin-top:6px">
            Date cure: ${fmtFR(it.SCHEDULED_DATE)} • Statut: ${it.STATUT}
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="icon-btn" style="width:36px;height:36px;border:1px solid #e5e7eb;background:#fff;border-radius:10px"
            title="Voir (à brancher PDF)">
            <i class="fa-regular fa-eye"></i>
          </button>
        </div>
      </div>
    `).join("");

    return `
      <div class="patient-card">
        <div style="font-weight:1000;font-size:16px"><i class="fa-solid fa-user"></i> Patient #${g.patient_id}</div>
        <div style="color:#64748b;font-weight:800;font-size:12px;margin-top:6px">${g.protocol}</div>
        ${items}
      </div>
    `;
  }).join("") : `
    <div class="patient-card" style="text-align:center;color:#64748b;font-weight:900">
      Aucun document car aucune cure programmée.
    </div>
  `;
}

/* ===== Render: Calendar (simple) ===== */
function renderCalendar(){
  const host = document.getElementById("calGrid");
  const events = STATE.cures.map(c => ({
    date: c.SCHEDULED_DATE,
    title: `P#${c.PATIENT_ID} ${c.PROTOCOLE} C${c.CYCLE_NUM}`
  }));

  // calendrier simple: liste par date (propre, sans complexité)
  const byDate = {};
  for(const e of events){
    if(!byDate[e.date]) byDate[e.date] = [];
    byDate[e.date].push(e.title);
  }
  const dates = Object.keys(byDate).sort();

  host.innerHTML = dates.length ? dates.map(d => `
    <div class="patient-card">
      <div style="font-weight:1000"><i class="fa-regular fa-calendar"></i> ${fmtFR(d)} (${weekdayFR(d)})</div>
      <div style="margin-top:8px;display:grid;gap:6px">
        ${byDate[d].map(t => `<div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fff;font-weight:900">${t}</div>`).join("")}
      </div>
    </div>
  `).join("") : `
    <div class="patient-card" style="text-align:center;color:#64748b;font-weight:900">
      Aucune cure dans le calendrier.
    </div>
  `;
}

/* ===== Load list ===== */
async function loadAll(){
  const json = await apiPost({ action:"list" });
  STATE.cures = json.cures || [];
  STATE.predictions = json.predictions || [];
  renderPlanif();
  renderDocs();
  renderCalendar();
}

/* ===== Predict ===== */
const btnPredict = document.getElementById("btnPredict");
const predBox = document.getElementById("predBox");
const predLabel = document.getElementById("predLabel");
const predConf = document.getElementById("predConf");
const predProbs = document.getElementById("predProbs");

let CURRENT_PRED = null;

btnPredict.addEventListener("click", async () => {
  try{
    const pid = Number(document.getElementById("patientIdInput").value || 0);
    const file = document.getElementById("imageInput").files?.[0];
    if(!pid) return alert("PATIENT_ID obligatoire.");
    if(!file) return alert("Image obligatoire.");

    const json = await apiPost(
      { action:"predict", patient_id: pid },
      { image: file }
    );

    CURRENT_PRED = json.prediction;

    predBox.style.display = "block";
    predLabel.textContent = `Prédiction: ${CURRENT_PRED.PRED_LABEL}`;
    predConf.textContent  = `Confiance: ${CURRENT_PRED.CONF_PCT}% (status: ${CURRENT_PRED.STATUS})`;

    // start date = today
    const today = new Date();
    document.getElementById("startDate").value = today.toISOString().slice(0,10);

    // render probs
    let probs = {};
    try { probs = JSON.parse(CURRENT_PRED.PROB_JSON || "{}"); } catch(e){}
    predProbs.innerHTML = Object.entries(probs).map(([k,v]) => `
      <div style="border:1px solid #e5e7eb;border-radius:12px;padding:10px;background:#fff">
        <div style="font-weight:1000">${k}</div>
        <div style="color:#64748b;font-weight:900">${v}%</div>
      </div>
    `).join("");

  }catch(e){
    alert(e.message);
  }
});

/* ===== Validate -> create cures 1..N ===== */
document.getElementById("btnValidate").addEventListener("click", async () => {
  try{
    if(!CURRENT_PRED) return alert("Fais la prédiction d’abord.");
    const pid = Number(document.getElementById("patientIdInput").value || 0);
    const doctor = document.getElementById("doctorName").value || "Médecin";
    const cyclesCount = Number(document.getElementById("cyclesCount").value || 3);
    const startDate = document.getElementById("startDate").value || new Date().toISOString().slice(0,10);
    const intervalDays = Number(document.getElementById("intervalDays").value || 14);

    await apiPost({
      action:"validate",
      patient_id: pid,
      prediction_id: CURRENT_PRED.PREDICTION_ID,
      doctor_name: doctor,
      cycles_count: cyclesCount,
      start_date: startDate,
      interval_days: intervalDays
      // protocol optionnel: si tu veux forcer "FOLFOX" etc, ajoute protocol:"FOLFOX"
    });

    alert("✅ Validé + cures créées.");
    await loadAll();
    setInner("planif");

  }catch(e){
    alert(e.message);
  }
});

/* ===== Modal reprogrammation ===== */
const modal = document.getElementById("reprogModal");
const modalInfo = document.getElementById("modalInfo");
const modalNewDate = document.getElementById("modalNewDate");
const modalReason = document.getElementById("modalReason");
const btnSuggestDate = document.getElementById("btnSuggestDate");
const btnConfirmReprog = document.getElementById("btnConfirmReprog");

function openReprog({cure_id, patient_id, old_date}){
  STATE.modal = { cure_id, patient_id, old_date };
  modalInfo.innerHTML = `Patient #${patient_id}<br><small>Cure ID: ${cure_id} • Date actuelle: ${fmtFR(old_date)}</small>`;
  modalNewDate.value = "";
  modal.style.display = "block";
  modal.setAttribute("aria-hidden","false");
  document.body.style.overflow = "hidden";
}
function closeReprog(){
  modal.style.display = "none";
  modal.setAttribute("aria-hidden","true");
  document.body.style.overflow = "";
}

document.addEventListener("click", async (e) => {
  const openBtn = e.target.closest("[data-action='open-reprog']");
  if(openBtn){
    openReprog({
      cure_id: Number(openBtn.dataset.cure_id),
      patient_id: Number(openBtn.dataset.patient_id),
      old_date: openBtn.dataset.old_date
    });
  }

  const noShowBtn = e.target.closest("[data-action='no-show']");
  if(noShowBtn){
    try{
      const cure_id = Number(noShowBtn.dataset.cure_id);
      const patient_id = Number(noShowBtn.dataset.patient_id);
      const old_date = noShowBtn.dataset.old_date;

      const json = await apiPost({
        action:"mark_no_show",
        cure_id,
        reason:"Patient absent (NO_SHOW)"
      });

      // on ouvre modal directement avec suggestion
      openReprog({ cure_id, patient_id, old_date });
      modalReason.value = "Patient absent (NO_SHOW)";
      modalNewDate.value = json.suggested_date;

      await loadAll();
    }catch(err){
      alert(err.message);
    }
  }

  if(modal.style.display === "block" && e.target.closest("[data-close='1']")){
    closeReprog();
  }
});

document.addEventListener("keydown", (e) => {
  if(e.key === "Escape" && modal.style.display === "block") closeReprog();
});

btnSuggestDate.addEventListener("click", async () => {
  // ici: on “suggère” déjà côté serveur via mark_no_show,
  // mais si tu veux un endpoint suggest séparé, je te le fais.
  // Pour l’instant: fallback simple +7 jours.
  const base = STATE.modal.old_date || new Date().toISOString().slice(0,10);
  const d = new Date(base+"T00:00:00");
  d.setDate(d.getDate()+7);
  modalNewDate.value = d.toISOString().slice(0,10);
});

btnConfirmReprog.addEventListener("click", async () => {
  try{
    const newDate = modalNewDate.value;
    if(!newDate) return alert("Choisis une nouvelle date.");
    await apiPost({
      action:"reschedule",
      cure_id: STATE.modal.cure_id,
      new_date: newDate,
      reason: modalReason.value
    });
    alert("✅ Cure reprogrammée.");
    closeReprog();
    await loadAll();
  }catch(e){
    alert(e.message);
  }
});

/* ===== init ===== */
setTodayPill();
loadAll();
</script>
</body>
</html>
