<?php
/* =========================================================
   Courtside Scoreboard — backend.php
   One file that does everything the PHP side needs:
     1) MySQL connection settings (edit these for your XAMPP setup)
     2) GET  -> returns the current Tbl_team + Tbl_buffer rows (page load)
     3) POST -> receives updates from script.js and saves them
   ========================================================= */

// ---------------------------------------------------------
// 1) DATABASE CONNECTION — edit these to match phpMyAdmin/XAMPP
// ---------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'scoring_db');      // the database you imported schema.sql into
define('DB_USER', 'root');            // default XAMPP user
define('DB_PASS', '');                // default XAMPP password (blank)

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------
// 2) GET — load current scoreboard state (called on page load)
// ---------------------------------------------------------
if ($method === 'GET') {
    try {
        $team = $pdo->query('SELECT * FROM Tbl_team ORDER BY id')->fetchAll();
        $buffer = $pdo->query('SELECT * FROM Tbl_buffer ORDER BY id')->fetchAll();
        echo json_encode(['ok' => true, 'team' => $team, 'buffer' => $buffer]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------
// 3) POST — save score / foul / timeout / clock / period updates
// ---------------------------------------------------------
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data) || !isset($data['teams'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
        exit;
    }

    $quarter = isset($data['quarter']) ? (int)$data['quarter'] : 1;
    $ot      = isset($data['ot']) ? (int)$data['ot'] : 0;
    $clock   = isset($data['clock']) ? substr((string)$data['clock'], 0, 20) : '00:00.000';

    try {
        $pdo->beginTransaction();

        $updateTeam = $pdo->prepare(
            'UPDATE Tbl_team
                SET Team = :team, Points = :points, Clock = :clock, quarter = :quarter, OT = :ot
              WHERE id = :id'
        );

        $updateBuffer = $pdo->prepare(
            'UPDATE Tbl_buffer
                SET Team = :team, Points = :points, Foul = :foul, `T/O` = :timeouts, Clock = :clock
              WHERE id = :id'
        );

        foreach ($data['teams'] as $id => $team) {
            $id       = (int)$id;
            $name     = substr((string)($team['name'] ?? ''), 0, 100);
            $points   = (int)($team['points'] ?? 0);
            $foul     = (int)($team['foul'] ?? 0);
            $timeouts = (int)($team['timeouts'] ?? 0);

            $updateTeam->execute([
                ':team' => $name, ':points' => $points, ':clock' => $clock,
                ':quarter' => $quarter, ':ot' => $ot, ':id' => $id,
            ]);

            $updateBuffer->execute([
                ':team' => $name, ':points' => $points, ':foul' => $foul,
                ':timeouts' => $timeouts, ':clock' => $clock, ':id' => $id,
            ]);
        }

        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------
// Anything else (PUT, DELETE, ...) is not supported
// ---------------------------------------------------------
http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);