<?php
$dsn  = "odbc:DSN=SnowflakeDSN;UseCursors=0";
$user = "COYOTE";
$pass = "dummy";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_CURSOR  => PDO::CURSOR_FWDONLY,
        ]);

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
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Ajouter un médecin</title>
</head>
<body>

<h2>Ajouter un médecin</h2>

<form method="POST">
    <label>Nom :</label><br>
    <input type="text" name="nom" required><br><br>

    <label>Prénom :</label><br>
    <input type="text" name="prenom" required><br><br>

    <label>Spécialité :</label><br>
    <input type="text" name="specialite" required><br><br>

    <label>Email :</label><br>
    <in
