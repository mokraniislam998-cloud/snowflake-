<?php
// ======================
//  ADD_PATIENT.PHP (tout en une seule page)
//  - Form wizard (5 étapes) + JS
//  - Insertion Snowflake via PDO ODBC
// ======================

$dsn  = "odbc:DSN=SnowflakeDSN;UseCursors=0";
$user = "COYOTE";
$pass = "dummy";

$message = "";
$messageType = "info"; // success | error | info

// Connexion DB
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY,
    ]);
} catch (PDOException $e) {
    die("Erreur connexion DB : " . $e->getMessage());
}

// Traitement POST (finalisation)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Petite fonction utilitaire
    $post = function(string $key, $default = null) {
        return isset($_POST[$key]) && $_POST[$key] !== "" ? $_POST[$key] : $default;
    };

    // Champs minimaux requis (côté serveur)
    $required = ["nip","prenom","nom","dob","genre","telephone","diagnostic","urgent_nom","urgent_relation","urgent_tel"];
    foreach ($required as $k) {
        if (!isset($_POST[$k]) || trim((string)$_POST[$k]) === "") {
            $message = "❌ Champ requis manquant : " . htmlspecialchars($k);
            $messageType = "error";
            break;
        }
    }

    if ($message === "") {
        try {
            $pdo->beginTransaction();

            // 1) Insert PATIENT
            $sqlPatient = 'INSERT INTO DB_CANCER_ISLAM.PUBLIC."PATIENT"
                (NIP, PRENOM, NOM, DOB, GENRE, TELEPHONE, EMAIL, ADRESSE, VILLE, CODE_POSTAL)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sqlPatient);
            $stmt->execute([
                $post("nip"),
                $post("prenom"),
                $post("nom"),
                $post("dob"),
                $post("genre"),
                $post("telephone"),
                $post("email"),
                $post("adresse"),
                $post("ville"),
                $post("cp"),
            ]);

            // Récupérer l'ID patient via NIP (fiable en ODBC)
            $stmt = $pdo->prepare('SELECT ID FROM DB_CANCER_ISLAM.PUBLIC."PATIENT" WHERE NIP = ?');
            $stmt->execute([$post("nip")]);
            $patientId = $stmt->fetchColumn();

            if (!$patientId) {
                throw new Exception("Impossible de récupérer l'ID du patient après insertion.");
            }

            // 2) Insert PATIENT_MEDICAL
            $sqlMedical = 'INSERT INTO DB_CANCER_ISLAM.PUBLIC."PATIENT_MEDICAL"
                (PATIENT_ID, DIAGNOSTIC, STADE, ONCOLOGUE, TRAITEMENTS, ALLERGIES, MEDICAMENTS, ANTECEDENTS)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            $pdo->prepare($sqlMedical)->execute([
                $patientId,
                $post("diagnostic"),
                $post("stade"),
                $post("oncologue"),
                $post("traitements"),
                $post("allergies"),
                $post("medicaments"),
                $post("antecedents"),
            ]);

            // 3) Insert PATIENT_URGENT_CONTACT
            $sqlUrgent = 'INSERT INTO DB_CANCER_ISLAM.PUBLIC."PATIENT_URGENT_CONTACT"
                (PATIENT_ID, NOM_CONTACT, RELATION, TELEPHONE, EMAIL)
                VALUES (?, ?, ?, ?, ?)';
            $pdo->prepare($sqlUrgent)->execute([
                $patientId,
                $post("urgent_nom"),
                $post("urgent_relation"),
                $post("urgent_tel"),
                $post("urgent_email"),
            ]);

            // 4) Insert PATIENT_PREFERENCES
            $sqlPref = 'INSERT INTO DB_CANCER_ISLAM.PUBLIC."PATIENT_PREFERENCES"
                (PATIENT_ID, LANGUE, COMMUNICATION, NOTES)
                VALUES (?, ?, ?, ?)';
            $pdo->prepare($sqlPref)->execute([
                $patientId,
                $post("langue", "fr"),
                $post("communication"),
                $post("notes"),
            ]);

            $pdo->commit();
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?success=1&nip=" . urlencode($post("nip")));
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();

            // Message plus clair si NIP déjà existant
            $msg = $e->getMessage();
            if (stripos($msg, "UQ_PATIENT_NIP") !== false || stripos($msg, "unique") !== false) {
                $message = "❌ Ce NIP existe déjà. Veuillez en choisir un autre.";
            } else {
                $message = "❌ Erreur : " . $msg;
            }
            $messageType = "error";
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Inscription d'un nouveau patient</title>

  <!-- Si tu as déjà ton fichier CSS, garde-le -->
  <link rel="stylesheet" href="./css/Ajout_patient.css">

  <link rel="preconnect" href="https://cdnjs.cloudflare.com" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    /* Petit style message (au cas où ton CSS n'en a pas) */
    .server-msg{
      max-width:1100px;
      margin: 14px auto 0;
      padding: 12px 14px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      border: 1px solid #e5e7eb;
      background: #fff;
    }
    .server-msg.success{ border-color: rgba(34,197,94,.35); background:#dcfce7; color:#166534; }
    .server-msg.error{ border-color: rgba(239,68,68,.35); background:#fee2e2; color:#7f1d1d; }
    .server-msg.info{ border-color: rgba(59,130,246,.25); background:#dbeafe; color:#1e3a8a; }

    /* rendre disabled visible si ton CSS ne le fait pas */
    button:disabled{ opacity:.5; cursor:not-allowed; }
  </style>
</head>

<body>
<header class="topbar">
  <div class="topbar-inner">

    <div class="brand">
      <div class="brand-icon" aria-hidden="true">
        <i class="fa-solid fa-wave-square"></i>
      </div>
      <div class="brand-title">Onboarding Patient</div>
    </div>

    <nav class="menu" aria-label="Navigation principale">
      <a class="menu-item" href="#">
        <i class="fa-solid fa-gear"></i>
        <span>Tableau de Bord</span>
      </a>
      <a class="menu-item" href="#">
        <i class="fa-solid fa-wave-square"></i>
        <span>Planning Quotidien</span>
      </a>
      <a class="menu-item" href="#">
        <i class="fa-regular fa-calendar"></i>
        <span>Programmation</span>
      </a>
<a class="menu-item" href="view/Ajout_infirmier.php">
  <i class="fa-regular fa-user"></i>
  <span>Planning Infirmier</span>
</a>

      <a class="menu-item btn-pill active" href="#">
        <i class="fa-solid fa-user-plus"></i>
        <span>Nouveau Patient</span>
      </a>
    </nav>

  </div>
</header>

<?php if ($message): ?>
  <div class="server-msg <?= htmlspecialchars($messageType) ?>">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<div class="wrap">
  <div class="card">
    <div class="header">
      <div class="title-row">
        <div class="badge-icon"><i class="fa-regular fa-user"></i></div>
        <div>Inscription d'un nouveau patient</div>
      </div>

      <div class="progress-row">
        <div>Étape <strong id="stepText">1</strong> sur 5</div>
        <div><strong id="percentText">20%</strong> complété</div>
      </div>

      <div class="progressbar"><span id="bar" style="width:20%"></span></div>
    </div>

    <div class="stepper">
      <div class="step active" data-step="1">
        <div class="circle"><i class="fa-regular fa-user"></i></div>
        <div class="label">Informations<br>Personnelles</div>
      </div>
      <div class="step" data-step="2">
        <div class="circle"><i class="fa-regular fa-heart"></i></div>
        <div class="label">Informations<br>Médicales</div>
      </div>
      <div class="step" data-step="3">
        <div class="circle"><i class="fa-solid fa-phone"></i></div>
        <div class="label">Contact<br>d'Urgence</div>
      </div>
      <div class="step" data-step="4">
        <div class="circle"><i class="fa-regular fa-file-lines"></i></div>
        <div class="label">Préférences</div>
      </div>
      <div class="step" data-step="5">
        <div class="circle"><i class="fa-regular fa-circle-check"></i></div>
        <div class="label">Validation</div>
      </div>
    </div>

    <div class="content">
      <!-- IMPORTANT: method POST + action same page -->
      <form id="wizardForm" method="POST" action="">
        <!-- Étape 1 -->
        <section class="step-section active" data-section="1">
          <div class="grid">
            <div class="field full">
              <label>Numéro d'identification patient (NIP) <span class="req">*</span></label>
              <input name="nip" type="text" placeholder="ex: NIP2024001" required value="<?= htmlspecialchars($_POST['nip'] ?? '') ?>" />
            </div>

            <div class="field">
              <label>Prénom <span class="req">*</span></label>
              <input name="prenom" type="text" placeholder="Prénom du patient" required value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>" />
            </div>

            <div class="field">
              <label>Nom <span class="req">*</span></label>
              <input name="nom" type="text" placeholder="Nom du patient" required value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>" />
            </div>

            <div class="field">
              <label>Date de naissance <span class="req">*</span></label>
              <div class="icon-input">
                <i class="fa-regular fa-calendar"></i>
                <input name="dob" type="date" required value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>" />
              </div>
            </div>

            <div class="field">
              <label>Genre <span class="req">*</span></label>
              <select name="genre" required>
                <?php $g = $_POST['genre'] ?? ""; ?>
                <option value="" <?= $g===""?'selected':'' ?> disabled>Sélectionner le genre</option>
                <option value="female" <?= $g==="female"?'selected':'' ?>>Femme</option>
                <option value="male" <?= $g==="male"?'selected':'' ?>>Homme</option>
                <option value="other" <?= $g==="other"?'selected':'' ?>>Autre</option>
              </select>
            </div>

            <div class="field">
              <label>Téléphone <span class="req">*</span></label>
              <input name="telephone" type="tel" placeholder="+33 1 23 45 67 89" required value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>" />
            </div>

            <div class="field">
              <label>Email</label>
              <input name="email" type="email" placeholder="patient@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />
            </div>

            <div class="field full">
              <label>Adresse</label>
              <input name="adresse" type="text" placeholder="123 Rue de la Paix" value="<?= htmlspecialchars($_POST['adresse'] ?? '') ?>" />
            </div>

            <div class="field">
              <label>Ville</label>
              <input name="ville" type="text" placeholder="Paris" value="<?= htmlspecialchars($_POST['ville'] ?? '') ?>" />
            </div>

            <div class="field">
              <label>Code postal</label>
              <input name="cp" type="text" inputmode="numeric" placeholder="75001" value="<?= htmlspecialchars($_POST['cp'] ?? '') ?>" />
            </div>
          </div>
        </section>

        <!-- Étape 2 -->
        <section class="step-section" data-section="2">
          <div class="grid">
            <div class="field">
              <label>Diagnostic <span class="req">*</span></label>
              <input name="diagnostic" type="text" placeholder="ex: Cancer colorectal" required value="<?= htmlspecialchars($_POST['diagnostic'] ?? '') ?>" />
            </div>

            <div class="field">
              <label>Stade</label>
              <input name="stade" type="text" placeholder="ex: T3N1M0" value="<?= htmlspecialchars($_POST['stade'] ?? '') ?>" />
            </div>

            <div class="field full">
              <label>Oncologue référent</label>
              <input name="oncologue" type="text" placeholder="Dr. Martin" value="<?= htmlspecialchars($_POST['oncologue'] ?? '') ?>" />
            </div>

            <div class="field full">
              <label>Traitements antérieurs</label>
              <textarea name="traitements" placeholder="Décrivez les traitements déjà reçus..."><?= htmlspecialchars($_POST['traitements'] ?? '') ?></textarea>
            </div>

            <div class="field full">
              <label>Allergies connues</label>
              <textarea name="allergies" placeholder="Allergies médicamenteuses, alimentaires..."><?= htmlspecialchars($_POST['allergies'] ?? '') ?></textarea>
            </div>

            <div class="field full">
              <label>Médicaments actuels</label>
              <textarea name="medicaments" placeholder="Liste des médicaments en cours..."><?= htmlspecialchars($_POST['medicaments'] ?? '') ?></textarea>
            </div>

            <div class="field full">
              <label>Antécédents médicaux</label>
              <textarea name="antecedents" placeholder="Autres pathologies, interventions chirurgicales..."><?= htmlspecialchars($_POST['antecedents'] ?? '') ?></textarea>
            </div>
          </div>
        </section>

        <!-- Étape 3 -->
        <section class="step-section" data-section="3">
          <div class="grid">
            <div class="field">
              <label>Nom du contact <span class="req">*</span></label>
              <input name="urgent_nom" type="text" placeholder="Nom du contact" required value="<?= htmlspecialchars($_POST['urgent_nom'] ?? '') ?>" />
            </div>

            <div class="field">
              <label>Relation <span class="req">*</span></label>
              <select name="urgent_relation" required>
                <?php $r = $_POST['urgent_relation'] ?? ""; ?>
                <option value="" <?= $r===""?'selected':'' ?> disabled>Type de relation</option>
                <option value="spouse" <?= $r==="spouse"?'selected':'' ?>>Conjoint(e)</option>
                <option value="parent" <?= $r==="parent"?'selected':'' ?>>Parent</option>
                <option value="child" <?= $r==="child"?'selected':'' ?>>Enfant</option>
                <option value="sibling" <?= $r==="sibling"?'selected':'' ?>>Frère/Sœur</option>
                <option value="friend" <?= $r==="friend"?'selected':'' ?>>Ami(e)</option>
                <option value="other" <?= $r==="other"?'selected':'' ?>>Autre</option>
              </select>
            </div>

            <div class="field">
              <label>Téléphone <span class="req">*</span></label>
              <input name="urgent_tel" type="tel" placeholder="+33..." required value="<?= htmlspecialchars($_POST['urgent_tel'] ?? '') ?>" />
            </div>

            <div class="field">
              <label>Email</label>
              <input name="urgent_email" type="email" placeholder="contact@email.com" value="<?= htmlspecialchars($_POST['urgent_email'] ?? '') ?>" />
            </div>
          </div>
        </section>

        <!-- Étape 4 -->
        <section class="step-section" data-section="4">
          <div class="grid">
            <div class="field full">
              <label>Langue préférée</label>
              <?php $lang = $_POST['langue'] ?? "fr"; ?>
              <select name="langue">
                <option value="fr" <?= $lang==="fr"?'selected':'' ?>>Français</option>
                <option value="en" <?= $lang==="en"?'selected':'' ?>>English</option>
                <option value="ar" <?= $lang==="ar"?'selected':'' ?>>العربية</option>
              </select>
            </div>

            <div class="field full">
              <label>Préférences de communication</label>
              <div class="chips" id="comChips">
                <span class="chip" data-value="sms">SMS</span>
                <span class="chip" data-value="email">Email</span>
                <span class="chip" data-value="phone">Appel téléphonique</span>
                <span class="chip" data-value="mail">Courrier</span>
              </div>
              <!-- stocke les choix -->
              <input type="hidden" name="communication" value="<?= htmlspecialchars($_POST['communication'] ?? '') ?>" />
            </div>

            <div class="field full">
              <label>Notes supplémentaires</label>
              <textarea name="notes" placeholder="Informations supplémentaires, besoins spéciaux..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>
          </div>
        </section>

        <!-- Étape 5 -->
        <section class="step-section" data-section="5">
          <div class="summary">
            <div class="summary-title">
              <div class="ok"><i class="fa-solid fa-check"></i></div>
              <div>Récapitulatif des informations</div>
            </div>

            <div class="summary-grid">
              <div>
                <h4>Informations personnelles</h4>
                <div class="kv"><b>NIP:</b> <span data-k="nip"></span></div>
                <div class="kv"><b>Nom:</b> <span data-k="nom_full"></span></div>
                <div class="kv"><b>Date de naissance:</b> <span data-k="dob"></span></div>
                <div class="kv"><b>Genre:</b> <span data-k="genre"></span></div>
                <div class="kv"><b>Téléphone:</b> <span data-k="telephone"></span></div>
                <div class="kv"><b>Email:</b> <span data-k="email"></span></div>
              </div>

              <div>
                <h4>Informations médicales</h4>
                <div class="kv"><b>Diagnostic:</b> <span data-k="diagnostic"></span></div>
                <div class="kv"><b>Stade:</b> <span data-k="stade"></span></div>
                <div class="kv"><b>Oncologue:</b> <span data-k="oncologue"></span></div>
              </div>

              <div>
                <h4>Contact d'urgence</h4>
                <div class="kv"><b>Nom:</b> <span data-k="urgent_nom"></span></div>
                <div class="kv"><b>Relation:</b> <span data-k="urgent_relation"></span></div>
                <div class="kv"><b>Téléphone:</b> <span data-k="urgent_tel"></span></div>
              </div>

              <div>
                <h4>Préférences</h4>
                <div class="kv"><b>Langue:</b> <span data-k="langue"></span></div>
                <div class="kv"><b>Communication:</b> <span data-k="communication"></span></div>
              </div>
            </div>
          </div>

          <div class="warn">
            <div class="t"><i class="fa-solid fa-triangle-exclamation"></i> Vérification importante</div>
            <div>
              Veuillez vérifier que toutes les informations sont correctes avant de finaliser l'inscription.
              Ces données seront utilisées pour le suivi médical du patient.
            </div>
          </div>
        </section>

      </form>
    </div>

    <div class="footer">
      <button class="btn" id="prevBtn" type="button">
        <i class="fa-solid fa-chevron-left"></i> Précédent
      </button>

      <button class="btn btn-primary" id="nextBtn" type="button">
        Suivant <i class="fa-solid fa-chevron-right"></i>
      </button>
    </div>
  </div>
</div>

<script>
  const form = document.getElementById("wizardForm");
  const steps = [...document.querySelectorAll(".step")];
  const sections = [...document.querySelectorAll(".step-section")];

  const bar = document.getElementById("bar");
  const stepText = document.getElementById("stepText");
  const percentText = document.getElementById("percentText");

  const prevBtn = document.getElementById("prevBtn");
  const nextBtn = document.getElementById("nextBtn");

  let currentStep = 1;

  function pctFromStep(step){ return step * 20; }

  function showStep(step){
    currentStep = Math.max(1, Math.min(5, step));

    sections.forEach(sec => {
      sec.classList.toggle("active", Number(sec.dataset.section) === currentStep);
    });

    steps.forEach(s => {
      const n = Number(s.dataset.step);
      s.classList.toggle("active", n === currentStep);
      s.classList.toggle("done", n < currentStep);
    });

    const pct = pctFromStep(currentStep);
    bar.style.width = pct + "%";
    stepText.textContent = currentStep;
    percentText.textContent = pct + "%";

    prevBtn.disabled = currentStep === 1;

    if(currentStep === 5){
      nextBtn.classList.remove("btn-primary");
      nextBtn.classList.add("btn-ok");
      nextBtn.innerHTML = `<i class="fa-regular fa-circle-check"></i> Finaliser l'inscription`;
      fillSummary();
    } else {
      nextBtn.classList.add("btn-primary");
      nextBtn.classList.remove("btn-ok");
      nextBtn.innerHTML = `Suivant <i class="fa-solid fa-chevron-right"></i>`;
    }
  }

  function validateCurrentStep(){
    const activeSection = sections.find(s => Number(s.dataset.section) === currentStep);
    if(!activeSection) return true;

    const requiredFields = [...activeSection.querySelectorAll("[required]")];
    for(const el of requiredFields){
      if(!el.checkValidity()){
        el.reportValidity();
        el.focus();
        return false;
      }
    }
    return true;
  }

  function getFormData(){
    const fd = new FormData(form);
    const obj = {};
    for(const [k,v] of fd.entries()){
      obj[k] = String(v ?? "");
    }
    return obj;
  }

  function formatDateFR(iso){
    if(!iso) return "";
    const [y,m,d] = iso.split("-").map(x => parseInt(x,10));
    if(!y || !m || !d) return iso;
    const dt = new Date(y, m-1, d);
    return dt.toLocaleDateString("fr-FR", { day:"numeric", month:"long", year:"numeric" });
  }

  // Affichage récap (labels FR)
  function mapGenre(v){
    if(v === "female") return "Femme";
    if(v === "male") return "Homme";
    if(v === "other") return "Autre";
    return v || "";
  }

  function mapRelation(v){
    const map = {
      spouse: "Conjoint(e)",
      parent: "Parent",
      child: "Enfant",
      sibling: "Frère/Sœur",
      friend: "Ami(e)",
      other: "Autre"
    };
    return map[v] || v || "";
  }

  function mapLangue(v){
    const map = { fr:"Français", en:"English", ar:"العربية" };
    return map[v] || v || "";
  }

  function mapComms(raw){
    if(!raw) return "Non renseigné";
    const map = { sms:"SMS", email:"Email", phone:"Appel téléphonique", mail:"Courrier" };
    return raw.split(",").map(x => map[x] || x).join(", ");
  }

  function fillSummary(){
    const data = getFormData();

    const summary = {
      nip: data.nip || "",
      nom_full: `${data.prenom || ""} ${data.nom || ""}`.trim(),
      dob: formatDateFR(data.dob || ""),
      genre: mapGenre(data.genre || ""),
      telephone: data.telephone || "",
      email: data.email || "",
      diagnostic: data.diagnostic || "",
      stade: data.stade || "",
      oncologue: data.oncologue || "",
      urgent_nom: data.urgent_nom || "",
      urgent_relation: mapRelation(data.urgent_relation || ""),
      urgent_tel: data.urgent_tel || "",
      langue: mapLangue(data.langue || "fr"),
      communication: mapComms(data.communication || "")
    };

    document.querySelectorAll("[data-k]").forEach(el => {
      const k = el.getAttribute("data-k");
      el.textContent = summary[k] ?? "";
    });
  }

  // Buttons
  prevBtn.addEventListener("click", () => showStep(currentStep - 1));

  nextBtn.addEventListener("click", () => {
    if(currentStep < 5){
      if(!validateCurrentStep()) return;
      showStep(currentStep + 1);
    } else {
      // FINAL: submit vers PHP (insertion Snowflake)
      if(!validateCurrentStep()) return;
      // Optionnel: confirmation
      // if(!confirm("Confirmer la finalisation de l'inscription ?")) return;
      form.submit();
    }
  });

  // Chips logic
  const chipsWrap = document.getElementById("comChips");
  const commHidden = form.querySelector('input[name="communication"]');

  function syncChipsFromHidden(){
    const raw = commHidden?.value || "";
    const set = new Set(raw.split(",").map(x => x.trim()).filter(Boolean));
    chipsWrap?.querySelectorAll(".chip").forEach(ch => {
      ch.classList.toggle("active", set.has(ch.dataset.value));
    });
  }

  chipsWrap?.addEventListener("click", (e) => {
    const chip = e.target.closest(".chip");
    if(!chip) return;
    chip.classList.toggle("active");

    const active = [...chipsWrap.querySelectorAll(".chip.active")]
      .map(c => c.dataset.value)
      .filter(Boolean);

    commHidden.value = active.join(",");
  });

  // Click stepper (optionnel)
  steps.forEach(s => {
    s.addEventListener("click", () => {
      const target = Number(s.dataset.step);
      if(target > currentStep && !validateCurrentStep()) return;
      showStep(target);
    });
  });

  // Init
  syncChipsFromHidden();
  showStep(1);
</script>
</body>
</html>
