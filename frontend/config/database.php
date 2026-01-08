<?php
$dsn  = "odbc:DSN=SnowflakeDSN;UseCursors=0";
$user = "COYOTE";
$pass = "dummy";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY,
    ]);
} catch (PDOException $e) {
    die("Erreur connexion DB : " . $e->getMessage());
}