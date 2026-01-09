<?php
// =========================================================
// planning_infirmier.php (1 seule page)
// ✅ Snowflake via ODBC NATIF (évite PDO/SQLFetchScroll SL009)
// ✅ 1 seule requête SQL -> 1 colonne JSON (STRING) via TO_JSON(...)
// =========================================================

$dsnName = "SnowflakeDSN";   // ton DSN ODBC Windows
$user    = "COYOTE";
$pass    = "dummy";

$dashboard = [
  "STAT_NURSES"   => 0,
  "STAT_ASSIGNED" => 0,
  "NURSES"        => [],
  "SPECIALTIES"   => []
];

$error = "";

// --- Connexion ODBC (le plus stable avec Snowflake ODBC)
$conn = @odbc_connect($dsnName, $user, $pass);
if (!$conn) {
  $error = "ODBC connect error: " . odbc_errormsg();
} else {
  // 1 seule requête -> renvoie 1 colonne (DASHBOARD_JSON)
  $sql = <<<SQL
WITH
centre AS (
  SELECT ID
  FROM DB_CANCER_ISLAM.PUBLIC."CENTRE"
  WHERE CODE = 'CANCER_BRAIN'
),

stat_nurses AS (
  SELECT COUNT(*) AS NBR_INFIRMIERS
  FROM DB_CANCER_ISLAM.PUBLIC."INFIRMIER" i
  JOIN centre c ON c.ID = i.CENTRE_ID
  WHERE i.ACTIF = TRUE
),

stat_assigned AS (
  SELECT COUNT(*) AS NBR_PATIENTS_ASSIGNE
  FROM DB_CANCER_ISLAM.PUBLIC."PATIENT_INFIRMIER"
  WHERE ACTIF = TRUE
),

jour AS (
  SELECT DAYOFWEEKISO(CURRENT_DATE()) AS J
),

patients_par_inf AS (
  SELECT INFIRMIER_ID, COUNT(*) AS NB_PATIENTS
  FROM DB_CANCER_ISLAM.PUBLIC."PATIENT_INFIRMIER"
  WHERE ACTIF = TRUE
  GROUP BY INFIRMIER_ID
),

nurses_rows AS (
  SELECT
    i.ID,
    i.PRENOM || ' ' || i.NOM AS NAME,

    -- ✅ éviter START / END (mots réservés)
    TO_CHAR(h.HEURE_DEBUT, 'HH24:MI') AS START_TIME,
    TO_CHAR(h.HEURE_FIN,   'HH24:MI') AS END_TIME,

    COALESCE(pp.NB_PATIENTS, 0) AS PATIENT_COUNT,
    h.CAPACITE_PATIENTS AS CAPACITY,

    CASE
      WHEN COALESCE(pp.NB_PATIENTS,0) < h.CAPACITE_PATIENTS THEN 'available'
      ELSE 'limited'
    END AS STATUS,

    TO_CHAR(h.PAUSE_DEBUT, 'HH24:MI') AS PAUSE_START,
    TO_CHAR(h.PAUSE_FIN,   'HH24:MI') AS PAUSE_END
  FROM DB_CANCER_ISLAM.PUBLIC."INFIRMIER" i
  JOIN centre c ON c.ID = i.CENTRE_ID
  JOIN jour j ON 1=1
  JOIN DB_CANCER_ISLAM.PUBLIC."INFIRMIER_HORAIRE" h
    ON h.INFIRMIER_ID = i.ID
   AND h.JOUR_SEMAINE = j.J
   AND h.ACTIF = TRUE
  LEFT JOIN patients_par_inf pp ON pp.INFIRMIER_ID = i.ID
  WHERE i.ACTIF = TRUE
  ORDER BY i.NOM, i.PRENOM
),

specialties_rows AS (
  SELECT
    p.NOM AS PROTOCOLE,
    COUNT(*) AS NB_INFIRMIERS
  FROM DB_CANCER_ISLAM.PUBLIC."PROTOCOLE" p
  JOIN DB_CANCER_ISLAM.PUBLIC."INFIRMIER_PROTOCOLE" ip ON ip.PROTOCOLE_ID = p.ID
  JOIN DB_CANCER_ISLAM.PUBLIC."INFIRMIER" i ON i.ID = ip.INFIRMIER_ID AND i.ACTIF = TRUE
  JOIN centre c ON c.ID = i.CENTRE_ID
  GROUP BY p.NOM
)

SELECT TO_JSON(
  OBJECT_CONSTRUCT(
    'STAT_NURSES',   (SELECT NBR_INFIRMIERS FROM stat_nurses),
    'STAT_ASSIGNED', (SELECT NBR_PATIENTS_ASSIGNE FROM stat_assigned),

    'NURSES', COALESCE(
      (SELECT ARRAY_AGG(OBJECT_CONSTRUCT(
        'id', ID,
        'name', NAME,
        'start', START_TIME,
        'end', END_TIME,
        'patients', PATIENT_COUNT,
        'capacity', CAPACITY,
        'status', STATUS,
        'pause_start', PAUSE_START,
        'pause_end', PAUSE_END
      )) FROM nurses_rows),
      ARRAY_CONSTRUCT()
    ),

    'SPECIALTIES', COALESCE(
      (SELECT ARRAY_AGG(OBJECT_CONSTRUCT(
        'name', PROTOCOLE,
        'count', NB_INFIRMIERS
      )) FROM specialties_rows),
      ARRAY_CONSTRUCT()
    )
  )
) AS DASHBOARD_JSON;
SQL;

  $res = @odbc_exec($conn, $sql);
  if (!$res) {
    $error = "ODBC exec error: " . odbc_errormsg($conn);
  } else {
   
    if (odbc_fetch_row($res)) {
      $jsonRaw = odbc_result($res, 1); // colonne 1
      if (is_string($jsonRaw) && $jsonRaw !== "") {
        $decoded = json_decode($jsonRaw, true);
        if (is_array($decoded)) $dashboard = $decoded;
      }
    }
  }

  @odbc_free_result($res);
  @odbc_close($conn);
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Planning Infirmier</title>
  <link rel="stylesheet" href="../css/ajout_medecin.css">
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .db-error{
      max-width: 1200px;
      margin: 14px auto 0;
      background:#fee2e2;
      border:1px solid rgba(239,68,68,.35);
      color:#7f1d1d;
      padding: 12px 14px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      white-space: pre-wrap;
    }
  </style>
</head>

<body>
  <?php if ($error): ?>
    <div class="db-error">❌ Erreur Snowflake : <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <main class="page">
    <h1 class="page-title">Planning Infirmier</h1>
    <p class="page-sub" id="pageDate">—</p>

    <nav class="nav" aria-label="Navigation">
      <a href="#"><i class="fa-solid fa-gear"></i> Tableau de Bord</a>
      <a href="#"><i class="fa-solid fa-wave-square"></i> Planning Quotidien</a>
      <a href="#"><i class="fa-regular fa-calendar"></i> Programmation</a>
      <a class="active" href="#"><i class="fa-solid fa-users"></i> Planning Infirmier</a>
      <a href="#"><i class="fa-solid fa-user-plus"></i> Nouveau Patient</a>
    </nav>

    <section class="stats">
      <div class="stat">
        <div>
          <div class="meta"><i class="fa-solid fa-user-nurse"></i> Infirmières</div>
          <div class="value primary" id="statNurses">0</div>
        </div>
      </div>

      <div class="stat">
        <div>
          <div class="meta"><i class="fa-regular fa-circle-check"></i> Disponibles</div>
          <div class="value ok" id="statAvailable">0</div>
        </div>
      </div>

      <div class="stat">
        <div>
          <div class="meta"><i class="fa-regular fa-calendar"></i> Patients assignés</div>
          <div class="value primary" id="statAssigned">0</div>
        </div>
      </div>

      <div class="stat">
        <div>
          <div class="meta"><i class="fa-regular fa-clock"></i> Taux d'occupation</div>
          <div class="value primary" id="statOcc">0%</div>
        </div>
      </div>
    </section>

    <div class="tabs" role="tablist" aria-label="Filtres">
      <button class="tab active" data-view="all" role="tab">Toutes</button>
      <button class="tab" data-view="available" role="tab">Disponibles</button>
      <button class="tab" data-view="limited" role="tab">Charge limitée</button>
      <button class="tab" data-view="specialty" role="tab">Par spécialité</button>
    </div>

    <section class="panel">
      <div class="view active" id="view-all">
        <div class="subhead">
          <div class="left">
            <div class="t">Planning Infirmier</div>
            <div class="d" id="subDateAll">—</div>
          </div>
          <div class="badge-small"><i class="fa-solid fa-users"></i> <span id="badgeAll">0 disponibles</span></div>
        </div>
        <div class="list" id="listAll"></div>
      </div>

      <div class="view" id="view-available">
        <h2>Infirmières disponibles</h2>
        <div class="desc">Infirmières ayant de la capacité pour de nouveaux patients</div>

        <div class="subhead">
          <div class="left">
            <div class="t">Planning Infirmier</div>
            <div class="d" id="subDateAvail">—</div>
          </div>
          <div class="badge-small"><i class="fa-solid fa-users"></i> <span id="badgeAvail">0 disponibles</span></div>
        </div>

        <div class="list" id="listAvailable"></div>
      </div>

      <div class="view" id="view-limited">
        <h2>Charge de travail par infirmière</h2>
        <div class="desc">Vue détaillée de la répartition des patients</div>
        <div class="list" id="listLimited"></div>
      </div>

      <div class="view" id="view-specialty">
        <h2>Infirmières par spécialité</h2>
        <div class="desc">Filtrer par qualifications et protocoles</div>

        <div style="margin-top:14px; font-weight:900; font-size:14px;">Protocoles spécifiques</div>
        <div class="spec-grid" id="specGrid"></div>
      </div>
    </section>
  </main>

  <!-- ===== MODAL ===== -->
  <div class="modal" id="detailsModal" aria-hidden="true">
    <div class="modal-backdrop" data-close="1"></div>

    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div class="modal-head">
        <div>
          <div class="modal-title" id="modalTitle">—</div>
          <div class="modal-sub">Qualifications et planning de la journée</div>
        </div>

        <button class="modal-x" type="button" aria-label="Fermer" data-close="1">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="modal-body">
        <div class="modal-section">
          <div class="sec-title">
            <i class="fa-regular fa-circle-check"></i>
            <span>Qualifications &amp; Protocoles</span>
          </div>
          <div class="chips-row" id="modalQualifs"></div>
        </div>

        <hr class="modal-hr" />

        <div class="modal-section">
          <div class="sec-title">
            <i class="fa-regular fa-calendar"></i>
            <span>Planning du jour</span>
          </div>

          <div class="planning-box">
            <div>
              <div class="planning-time" id="modalTime">—</div>
              <div class="planning-muted"><span id="modalPatients">—</span> patient(s) assigné(s)</div>
            </div>

            <div>
              <span class="pill" id="modalPause">Pause: —</span>
            </div>
          </div>
        </div>

        <hr class="modal-hr" />

        <div class="modal-section">
          <div class="sec-title">
            <i class="fa-solid fa-stethoscope"></i>
            <span>Spécialités</span>
          </div>
          <div class="chips-row" id="modalSpecs"></div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // ===== Données venant de Snowflake (PHP -> JS)
    const DASHBOARD = <?= json_encode($dashboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function formatDateFR(d = new Date()){
      return d.toLocaleDateString("fr-FR", { weekday:"long", day:"numeric", month:"long", year:"numeric" });
    }
    function formatShortFR(d = new Date()){
      return d.toLocaleDateString("fr-FR");
    }

    const tabs = [...document.querySelectorAll(".tab")];
    const views = {
      all: document.getElementById("view-all"),
      available: document.getElementById("view-available"),
      limited: document.getElementById("view-limited"),
      specialty: document.getElementById("view-specialty"),
    };

    const listAll = document.getElementById("listAll");
    const listAvailable = document.getElementById("listAvailable");
    const listLimited = document.getElementById("listLimited");
    const specGrid = document.getElementById("specGrid");

    const badgeAll = document.getElementById("badgeAll");
    const badgeAvail = document.getElementById("badgeAvail");

    const statNurses = document.getElementById("statNurses");
    const statAvailable = document.getElementById("statAvailable");
    const statAssigned = document.getElementById("statAssigned");
    const statOcc = document.getElementById("statOcc");

    const pageDate = document.getElementById("pageDate");
    const subDateAll = document.getElementById("subDateAll");
    const subDateAvail = document.getElementById("subDateAvail");

    const modal = document.getElementById("detailsModal");
    const modalTitle = document.getElementById("modalTitle");
    const modalQualifs = document.getElementById("modalQualifs");
    const modalSpecs = document.getElementById("modalSpecs");
    const modalTime = document.getElementById("modalTime");
    const modalPatients = document.getElementById("modalPatients");
    const modalPause = document.getElementById("modalPause");

    const nurses = Array.isArray(DASHBOARD?.NURSES) ? DASHBOARD.NURSES : [];
    const specialties = Array.isArray(DASHBOARD?.SPECIALTIES) ? DASHBOARD.SPECIALTIES : [];

    function escapeHtml(str){
      return String(str ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
    }

    function pct(patients, capacity){
      if(!capacity) return 0;
      return Math.max(0, Math.min(100, Math.round((patients / capacity) * 100)));
    }

    function statusBadge(n){
      if(n.status === "available"){
        return `<span class="status ok"><i class="fa-regular fa-circle-check"></i> Disponible</span>`;
      }
      return `<span class="status warn"><i class="fa-solid fa-circle-exclamation"></i> Limité</span>`;
    }

    function barClass(n){
      return n.status === "limited" ? "bar yellow" : "bar green";
    }

    function nurseCard(n){
      const percent = pct(n.patients, n.capacity);
      return `
        <div class="item">
          <div class="item-top">
            <div>
              <div class="who">${escapeHtml(n.name)} <i class="fa-solid fa-user-nurse" style="color:#94a3b8"></i></div>
              <div class="mini"><i class="fa-regular fa-clock"></i> ${escapeHtml(n.start)} - ${escapeHtml(n.end)}</div>
            </div>
            ${statusBadge(n)}
          </div>

          <div class="work">Charge de travail</div>
          <div class="${barClass(n)}"><span style="width:${percent}%"></span></div>

          <div class="right-metrics">${Number(n.patients||0)}/${Number(n.capacity||0)} patients</div>

          <button class="btn-details" type="button" data-action="open-details" data-id="${escapeHtml(n.id)}">
            Voir détails &amp; qualifications
          </button>
        </div>
      `;
    }

    function nurseRow(n){
      return `
        <div class="item" style="display:flex;align-items:center;justify-content:space-between;gap:14px">
          <div>
            <div class="who">${escapeHtml(n.name)}</div>
            <div class="mini">${escapeHtml(n.start)} - ${escapeHtml(n.end)}</div>
          </div>
          <div style="display:flex;align-items:center;gap:14px">
            <div style="text-align:right;font-weight:900;font-size:13px">
              ${Number(n.patients||0)}/${Number(n.capacity||0)}
              <div style="font-weight:700;color:var(--muted);font-size:12px">patients</div>
            </div>
            ${n.status === "available"
              ? `<span class="status ok">Disponible</span>`
              : `<span class="status" style="background:#f1f5f9">Limité</span>`
            }
          </div>
        </div>
      `;
    }

    function setView(key){
      tabs.forEach(t => t.classList.toggle("active", t.dataset.view === key));
      Object.entries(views).forEach(([k,el]) => el.classList.toggle("active", k === key));
    }
    tabs.forEach(t => t.addEventListener("click", () => setView(t.dataset.view)));

    function openDetailsById(id){
      const n = nurses.find(x => String(x.id) === String(id));
      if(!n || !modal) return;

      modalTitle.textContent = `${n.name} - Détails du planning`;

      const qualifs = specialties.length ? specialties.map(s => s.name) : ["Non renseigné"];
      modalQualifs.innerHTML = qualifs.map(q => `<span class="chip2">${escapeHtml(q)}</span>`).join("");

      modalSpecs.innerHTML = qualifs.slice(0, 4).map(s =>
        `<span class="chip2" style="background:var(--primary);color:#fff;border-color:var(--primary)">${escapeHtml(s)}</span>`
      ).join("");

      modalTime.textContent = `${n.start} - ${n.end}`;
      modalPatients.textContent = n.patients ?? "—";
      modalPause.textContent = `Pause: ${n.pause_start || "—"} - ${n.pause_end || "—"}`;

      modal.classList.add("open");
      modal.setAttribute("aria-hidden", "false");
      document.body.style.overflow = "hidden";
    }

    function closeModal(){
      if(!modal) return;
      modal.classList.remove("open");
      modal.setAttribute("aria-hidden", "true");
      document.body.style.overflow = "";
    }

    document.addEventListener("click", (e) => {
      const btn = e.target.closest('[data-action="open-details"]');
      if(btn) openDetailsById(btn.dataset.id);

      if(modal && modal.classList.contains("open") && e.target.closest("[data-close='1']")){
        closeModal();
      }
    });

    document.addEventListener("keydown", (e) => {
      if(e.key === "Escape" && modal && modal.classList.contains("open")) closeModal();
    });

    function render(){
      const now = new Date();
      pageDate.textContent = formatDateFR(now);
      subDateAll.textContent = formatShortFR(now);
      subDateAvail.textContent = formatShortFR(now);

      const totalNurses = Number(DASHBOARD?.STAT_NURSES ?? nurses.length ?? 0);
      const assigned = Number(DASHBOARD?.STAT_ASSIGNED ?? 0);
      const availableCount = nurses.filter(n => n.status === "available").length;

      statNurses.textContent = totalNurses;
      statAssigned.textContent = assigned;
      statAvailable.textContent = availableCount;

      const totalPatients = nurses.reduce((s,n)=>s + Number(n.patients||0), 0);
      const totalCapacity = nurses.reduce((s,n)=>s + Number(n.capacity||0), 0);
      const occ = totalCapacity ? Math.round((totalPatients / totalCapacity) * 100) : 0;
      statOcc.textContent = `${occ}%`;

      badgeAll.textContent = `${availableCount} disponibles`;
      badgeAvail.textContent = `${availableCount} disponibles`;

      listAll.innerHTML = nurses.map(nurseCard).join("") || "<div style='color:#64748b;font-weight:800'>Aucune donnée infirmier.</div>";
      listAvailable.innerHTML = nurses.filter(n => n.status === "available").map(nurseCard).join("") || "<div style='color:#64748b;font-weight:800'>Aucune infirmière disponible.</div>";
      listLimited.innerHTML = nurses.map(nurseRow).join("") || "<div style='color:#64748b;font-weight:800'>Aucune donnée.</div>";

      specGrid.innerHTML = specialties.map(s => `
        <div class="spec-card">
          <div class="spec-name">${escapeHtml(s.name)}</div>
          <div class="spec-count">${escapeHtml(s.count)} infirmière(s)</div>
        </div>
      `).join("") || "<div style='color:#64748b;font-weight:800'>Aucun protocole.</div>";
    }

    render();
    setView("all");
  </script>
</body>
</html>
