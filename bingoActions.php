<?php
/**
 * bingoActions.php
 * Contains helper functions for managing Bingo game state in the database.
 */

/**
 * afterGoodBingoAction
 * Updates the current activity table after a good Bingo event.
 * 
 * @param mysqli $conn
 * @return bool
 */
function afterGoodBingoAction(mysqli $conn): bool {

    $betAmount = 10;
    $percent   = 20;
    $playType  = 'ALL 1';
    $startTime = date('Y-m-d H:i:s');
    $playing   = 0;
    $noOfCartelas = 0;

    try {
        $conn->begin_transaction();

        /* 1️⃣ Find players who took cartelas */
        $takenQuery = "SELECT PhoneNo, COUNT(*) AS cartelaCount FROM bingo WHERE Taken = 1 GROUP BY PhoneNo";

        $result = $conn->query($takenQuery);

        if (!$result) {
            throw new Exception("Failed to fetch taken cartelas");
        }

        /* 2️⃣ Refund bet amounts */
        while ($row = $result->fetch_assoc()) {
            $phone   = $row['PhoneNo'];
            $count   = (int)$row['cartelaCount'];
            $refund  = $count * $betAmount*0.9;

            $refundStmt = $conn->prepare(
                "UPDATE players SET Amount = Amount + ? WHERE PhoneNo = ?"
            );
            $refundStmt->bind_param("is", $refund, $phone);
            $refundStmt->execute();
            $refundStmt->close();
        }

        /* 3️⃣ Reset currentactivity */
        $stmt = $conn->prepare(
            "UPDATE currentactivity
             SET BetAmount = ?, Percent = ?, PlayType = ?, StartTime = ?, Playing = ?, NoOfTakenCartelas = ?"
        );

        if (!$stmt) {
            throw new Exception("Prepare failed");
        }

        $stmt->bind_param(
            "iissii",
            $betAmount,
            $percent,
            $playType,
            $startTime,
            $playing,
            $noOfCartelas
        );

        $stmt->execute();
        $stmt->close();

        /* 4️⃣ Reset bingo table */
        $conn->query("UPDATE bingo SET Taken = 0, PhoneNo = ''");

        /* 5️⃣ Commit everything */
        $conn->commit();

        echo "✅ afterGoodBingoAction executed successfully\n";
        return true;

    } catch (Exception $e) {
        $conn->rollback();
        error_log("❌ afterGoodBingoAction failed: " . $e->getMessage());
        return false;
    }
}


/**
 * startTheGame
 * Generates a new set of 75 Bingo numbers and updates the database.
 * Ensures no duplicates with previous game and proper 2-digit formatting.
 * 
 * @param mysqli $conn
 * @return bool
 */
function startTheGame(mysqli $conn): bool {
    $result = mysqli_query($conn, "SELECT Winners FROM winner LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        echo "❌ Failed to fetch previous winners.\n";
        return false;
    }

    $prev_winners_row = mysqli_fetch_assoc($result);
    $prev_winners = str_split($prev_winners_row['Winners'], 2);
    $newNumbers = [];

    for ($i = 0; $i < 75; $i++) {
        do {
            $rand = str_pad(rand(1, 75), 2, "0", STR_PAD_LEFT);
        } while (in_array($rand, $newNumbers, true) || (isset($prev_winners[$i]) && $rand == $prev_winners[$i]));
        $newNumbers[] = $rand;
    }

    $winnersStr = implode("", $newNumbers);

    if (strlen($winnersStr) !== 150) {
        echo "❌ Error: Generated winners string length is incorrect.\n";
        return false;
    }

    // Update the winners table and reset current activity
    mysqli_query($conn, "UPDATE winner SET Winners='$winnersStr', NoOfWinnersShown=0") or die(mysqli_error($conn));
    mysqli_query($conn, "UPDATE currentactivity SET Playing=1, SayBingo=0, CallerPhone=''") or die(mysqli_error($conn));

    echo "✅ startTheGame executed successfully\n";
    return true;
}

function payWinner(mysqli $conn, string $phone, int $totalWinners): bool {

    $conn->begin_transaction();

    try {
        // Lock current activity
        $q = $conn->query("SELECT * FROM currentactivity FOR UPDATE");
        if (!$q || $q->num_rows === 0) {
            throw new Exception("No current activity found");
        }

        $res = $q->fetch_assoc();
        $noOfCartelas = (int)$res['NoOfTakenCartelas'];
        $betAmount   = (float)$res['BetAmount'];
        $p           = (float)$res['Percent']; // house cut

        $rewardPercent = round((100 - $p) / 100, 2);
        $winAmount = ($noOfCartelas * $betAmount * $rewardPercent)/$totalWinners;

        // Reset bingo
        if (!$conn->query("UPDATE bingo SET Taken = 0")) {
            throw new Exception($conn->error);
        }

        // Pay winner
        $stmt = $conn->prepare("
            UPDATE players
            SET Amount = Amount + ?
            WHERE PhoneNo = ?
        ");

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("ds", $winAmount, $phone);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        mysqli_query($conn, "UPDATE bingo SET Taken = 0, PhoneNo = ''");

        $conn->commit();
        return true;

    } catch (Exception $e) { // 0502071110
        $conn->rollback();
        error_log("payWinner failed: " . $e->getMessage()); // 0306130215
        return false;
    }
}


function parseColumn(string $colStr): array {
    preg_match_all('/Free|\d{2}/', $colStr, $matches);
    return $matches[0]; // always 5 items
}

function discardCartela(mysqli $conn, string $phone, int $cartelaId): void
{
    $stmt = $conn->prepare("
        DELETE FROM bingo
        WHERE PhoneNo = ? AND No = ?
    ");

    if (!$stmt) {
        error_log("discardCartela prepare failed: " . $conn->error);
        return;
    }

    $stmt->bind_param('si', $phone, $cartelaId);
    $stmt->execute();
    $stmt->close();
}

function checkBingoWinners(mysqli $conn, array $phoneCartelas, $lastShownNumber): array
{
    $results = [];

    if (empty($phoneCartelas)) return $results;

    /* -------------------------------------------------
       1️⃣ Build WHERE (PhoneNo, No) filter
    ------------------------------------------------- */
    $conditions = [];
    $params = [];
    $types = '';

    foreach ($phoneCartelas as $phone => $cartelas) {
        foreach ($cartelas as $cartelaId) {
            $conditions[] = '(PhoneNo = ? AND No = ?)';
            $params[] = $phone;
            $params[] = $cartelaId;
            $types .= 'si';
        }
    }

    if (empty($conditions)) return $results;

    $sql = "
        SELECT PhoneNo, No, B, I, N, G, O
        FROM bingo
        WHERE " . implode(' OR ', $conditions);

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        return $results;
    }

    /* -------------------------------------------------
       2️⃣ Fetch drawn numbers
    ------------------------------------------------- */
    $winnerQuery = mysqli_query($conn, "SELECT * FROM winner LIMIT 1");
    if (!$winnerQuery || mysqli_num_rows($winnerQuery) === 0) {
        return $results;
    }

    $winnerRow = mysqli_fetch_assoc($winnerQuery);
    $allNumbersStr = $winnerRow['Winners'];
    $drawnCount = (int)$winnerRow['NoOfWinnersShown'];

    $drawnNumbers = [];
    for ($i = 0; $i < $drawnCount / 2; $i++) {
        $numStr = substr($allNumbersStr, $i * 2, 2);
        if ($numStr !== '') {
            $drawnNumbers[] = (int)$numStr;
        }
    }

    /* -------------------------------------------------
       3️⃣ Fetch play type
    ------------------------------------------------- */
    $playType = '';
    $currentQuery = mysqli_query($conn, "SELECT PlayType FROM currentactivity LIMIT 1");
    if ($currentQuery && mysqli_num_rows($currentQuery) > 0) {
        $row = mysqli_fetch_assoc($currentQuery);
        $playType = strtoupper(trim($row['PlayType']));
    }

    /* -------------------------------------------------
       4️⃣ Process each requested cartela
    ------------------------------------------------- */
    while ($row = $res->fetch_assoc()) {

        $phoneNo   = $row['PhoneNo'];
        $cartelaId = (int)$row['No'];

        $B = parseColumn($row['B']);
        $I = parseColumn($row['I']);
        $N = parseColumn($row['N']);
        $G = parseColumn($row['G']);
        $O = parseColumn($row['O']);

        $columns = [$B, $I, $N, $G, $O];

        $cardGrid  = [];
        $cardMarks = [];

        for ($r = 0; $r < 5; $r++) {
            for ($c = 0; $c < 5; $c++) {
                $value = $columns[$c][$r];

                $status = ($value === 'Free' || in_array((int)$value, $drawnNumbers, true))
                    ? 'G'
                    : 'F';

                $cardGrid[$r][$c]  = ($value === 'Free') ? '★' : $value;
                $cardMarks[$r][$c] = $status;
            }
        }

        /* -------------------------------------------------
           5️⃣ Win check (unchanged)
        ------------------------------------------------- */
        $winnerFlag = false;
        if ($playType === 'ALL 1') {
            $winnerFlag = checkAll1Win($cardMarks, $cardGrid, $lastShownNumber);
        }

        /* -------------------------------------------------
           6️⃣ Save result
        ------------------------------------------------- */
        $results[$phoneNo][$cartelaId] = [
            'grid'   => $cardGrid,
            'marks'  => $cardMarks,
            'winner' => $winnerFlag
        ];
    }

    return $results;
}

function checkAll1Win(array &$cardMarks, array $cardGrid, int $lastNumber): bool
{
    /* =================================================
       1️⃣ FOUR CORNERS (0,0 0,4 4,0 4,4)
    ================================================= */
    $corners = [
        [0, 0],
        [0, 4],
        [4, 0],
        [4, 4],
    ];

    $allCornersMarked = true;
    $containsLast = false;

    foreach ($corners as [$r, $c]) {
        if ($cardMarks[$r][$c] !== 'G') {
            $allCornersMarked = false;
            break;
        }
        if ((int)$cardGrid[$r][$c] === $lastNumber) {
            $containsLast = true;
        }
    }

    if ($allCornersMarked && $containsLast) {
        foreach ($corners as [$r, $c]) {
            $cardMarks[$r][$c] =
                ((int)$cardGrid[$r][$c] === $lastNumber) ? 'M' : 'L';
        }
        return true;
    }

    /* =================================================
       2️⃣ Rows (0–4)
    ================================================= */
    for ($r = 0; $r < 5; $r++) {
        $rowWin = true;
        $containsLast = false;

        for ($c = 0; $c < 5; $c++) {
            if ($cardMarks[$r][$c] !== 'G') {
                $rowWin = false;
                break;
            }
            if ((int)$cardGrid[$r][$c] === $lastNumber) {
                $containsLast = true;
            }
        }

        if ($rowWin && $containsLast) {
            for ($c = 0; $c < 5; $c++) {
                $cardMarks[$r][$c] =
                    ((int)$cardGrid[$r][$c] === $lastNumber) ? 'M' : 'L';
            }
            return true;
        }
    }

    /* =================================================
       3️⃣ Columns (0–4)
    ================================================= */
    for ($c = 0; $c < 5; $c++) {
        $colWin = true;
        $containsLast = false;

        for ($r = 0; $r < 5; $r++) {
            if ($cardMarks[$r][$c] !== 'G') {
                $colWin = false;
                break;
            }
            if ((int)$cardGrid[$r][$c] === $lastNumber) {
                $containsLast = true;
            }
        }

        if ($colWin && $containsLast) {
            for ($r = 0; $r < 5; $r++) {
                $cardMarks[$r][$c] =
                    ((int)$cardGrid[$r][$c] === $lastNumber) ? 'M' : 'L';
            }
            return true;
        }
    }

    /* =================================================
       4️⃣ Main diagonal (0,0 → 4,4)
    ================================================= */
    $diagWin = true;
    $containsLast = false;

    for ($i = 0; $i < 5; $i++) {
        if ($cardMarks[$i][$i] !== 'G') {
            $diagWin = false;
            break;
        }
        if ((int)$cardGrid[$i][$i] === $lastNumber) {
            $containsLast = true;
        }
    }

    if ($diagWin && $containsLast) {
        for ($i = 0; $i < 5; $i++) {
            $cardMarks[$i][$i] =
                ((int)$cardGrid[$i][$i] === $lastNumber) ? 'M' : 'L';
        }
        return true;
    }

    /* =================================================
       5️⃣ Anti-diagonal (0,4 → 4,0)
    ================================================= */
    $diagWin = true;
    $containsLast = false;

    for ($i = 0; $i < 5; $i++) {
        $r = $i;
        $c = 4 - $i;

        if ($cardMarks[$r][$c] !== 'G') {
            $diagWin = false;
            break;
        }
        if ((int)$cardGrid[$r][$c] === $lastNumber) {
            $containsLast = true;
        }
    }

    if ($diagWin && $containsLast) {
        for ($i = 0; $i < 5; $i++) {
            $r = $i;
            $c = 4 - $i;
            $cardMarks[$r][$c] =
                ((int)$cardGrid[$r][$c] === $lastNumber) ? 'M' : 'L';
        }
        return true;
    }

    return false;
}

function isNoCartelaTaken(mysqli $conn): bool
{
    $q = mysqli_query($conn, "SELECT NoOfTakenCartelas FROM currentactivity LIMIT 1"
    ) or die(mysqli_error($conn));

    $res = mysqli_fetch_assoc($q);
    $takenCount = (int)$res['NoOfTakenCartelas'];

    if ($takenCount === 0) {
        return true;
    }

    $q2 = mysqli_query($conn,"SELECT 1 FROM bingo WHERE Taken = 1 LIMIT 1"
    ) or die(mysqli_error($conn));

    if (mysqli_num_rows($q2) === 0) {
        return true;
    }

    return false;
}


function checkIfAllCartelasTaken(mysqli $conn): bool {
    $q = mysqli_query($conn, "SELECT COUNT(*) as remaining FROM bingo WHERE Taken = 0") 
         or die(mysqli_error($conn));
    $res = mysqli_fetch_assoc($q);

    return ($res['remaining'] == 0);
}

function discardCards(mysqli $conn, $phone){
    mysqli_query($conn,"update bingo set Taken=0,PhoneNo='' where PhoneNo='$phone'") or die(mysqli_error($conn));
}

function checkNoOfPlayers(mysqli $conn) {
    $q = mysqli_query($conn, "SELECT DISTINCT PhoneNo FROM bingo WHERE Taken = 1") 
        or die(mysqli_error($conn));

    // Return the number of unique players
    return mysqli_num_rows($q);
}



