<?php
require_once __DIR__ . '/../config/database.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $sql = '
            INSERT INTO DB_CANCER_ISLAM.PUBLIC."MEDECIN"
            (NOM, PRENOM, SPECIALITE, EMAIL, TELEPHONE, ADRESSE)
            VALUES (?, ?, ?, ?, ?, ?)
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['nom'],
            $_POST['prenom'],
            $_POST['specialite'],
            $_POST['email'],
            $_POST['telephone'],
            $_POST['adresse']
        ]);

        $message = "✅ Médecin ajouté avec succès";

    } catch (PDOException $e) {
        $message = "❌ Erreur : " . $e->getMessage();
    }
}
