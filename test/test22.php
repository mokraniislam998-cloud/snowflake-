<?php

$dsn  = "odbc:DSN=SnowflakeDSN;UseCursors=0";
$user = "COYOTE";
$pass = "dummy";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CURSOR  => PDO::CURSOR_FWDONLY,
    ]);

    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

    echo "✅ Connexion Snowflake réussie<br><br>";

    // Requête préparée
    $sql = 'SELECT * FROM DB_CANCER_ISLAM.PUBLIC."MEDECIN"';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    echo "<h3>Données de la table MEDECIN</h3>";

    $hasRows = false;
    echo "<pre>";
    while ($row = $stmt->fetch()) {
        $hasRows = true;
        print_r($row);
    }
    echo "</pre>";

    if (!$hasRows) {
        echo "ℹ️ Aucune donnée trouvée.";
    }

} catch (PDOException $e) {
    echo "❌ Erreur : " . $e->getMessage();
}
