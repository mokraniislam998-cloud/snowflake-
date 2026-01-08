<?php
$host = "localhost"; 
$user = "root"; 
$pass = ""; 
$db   = "islam_test";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Erreur : " . mysqli_connect_error());
}

$sql = "SELECT * FROM bobo";
$result = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_assoc($result)) {
    echo "ID: " . $row["id"] . " | Nom: " . $row["nom"] . "<br>";
    echo "<br>";
}
?>
