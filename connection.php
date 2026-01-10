<?php

/*$servername = "localhost";
$database = "xhawaluq_xb";
include_once("encryption.php");
$encrypt = new encryption();
$username = "xhawaluq_xhawaluq";
$password = "00(@uK]ZZ@GmV-z8k3Wk(p";

// Create connection using mysqli_connect function
$conn = mysqli_connect($servername, $username, $password, $database);

// Connection Check
if (!$conn) {
    die("Connection failed: " . $conn->connect_error);
}
else{
   // echo "sucess";
}
*/
$host = 'caboose.proxy.rlwy.net';      // Railway host
$port = 15017;                          // Railway port
$user = 'root';                          // Railway username
$pass = 'BEISavWriMRByOjzVgMAXvhsXqDBQXmY';  // Railway password
$db   = 'railway';                      // Your Railway database

$conn = mysqli_connect($host, $user, $pass, $db, $port);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// No closing PHP tag here — keep it out