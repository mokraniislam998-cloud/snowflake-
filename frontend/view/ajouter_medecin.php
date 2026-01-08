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
    <input type="email" name="email"><br><br>

    <label>Téléphone :</label><br>
    <input type="text" name="telephone"><br><br>

    <label>Adresse :</label><br>
    <input type="text" name="adresse"><br><br>

    <button type="submit">Ajouter</button>
</form>

<p><?= htmlspecialchars($message ?? "") ?></p>

</body>
</html>
