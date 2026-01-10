<?php
/**
 * Bingo actions now use $conn (RemoteDB) instead of raw MySQL queries.
 * $conn is injected from connection.php
 */

function checkIfAllCartelasTaken($conn) {
    $res = $conn->queryCartelas(['status' => 'taken']);
    return !empty($res);
}

function checkNoOfPlayers($conn) {
    return $conn->checkNoOfPlayers();
}

function startTheGame($conn) {
    return $conn->startTheGame();
}

function checkBingoWinners($conn, $phoneCartelas, $lastShownNumber) {
    return $conn->checkBingoWinners($phoneCartelas, $lastShownNumber);
}

function payWinner($conn, $phone, $totalWinners) {
    return $conn->payWinner($phone, $totalWinners);
}

function discardCartela($conn, $phone, $cartelaId) {
    return $conn->discardCartela($phone, $cartelaId);
}

function isNoCartelaTaken($conn) {
    $res = $conn->queryCartelas(['status' => 'taken']);
    return empty($res);
}

function afterGoodBingoAction($conn) {
    // Perform any server-side post-win action
    $res = $conn->request("afterGoodBingoAction");
    return $res['success'] ?? false;
}
