<?php
/* =========================================================
   Courtside Scoreboard — backend.php
   One file that does everything the PHP side needs:
     1) MySQL connection settings (edit these for your XAMPP setup)
     2) GET  -> returns the current tbl_team + tbl_buffer rows (page load)
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
// 1b) GET with ?stream=1 — Server-Sent Events push feed for viewer.html
//     Holds the connection open and only sends data when tbl_team /
//     tbl_buffer actually changed, instead of the browser polling.
// ---------------------------------------------------------
if ($method === 'GET' && isset($_GET['stream'])) {
    set_time_limit(0);
    ignore_user_abort(false);

    // disable any output buffering/compression that would delay delivery
    while (ob_get_level()) { ob_end_clean(); }
    ini_set('zlib.output_compression', '0');
    ini_set('output_buffering', 'off');
    ini_set('implicit_flush', '1');

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // harmless if not behind nginx

    // tell the browser to reconnect fast if this connection ever drops
    echo "retry: 1000\n\n";
    flush();

    $lastPayload = null;
    $started = time();

    // stays open for up to 5 minutes per connection, then exits cleanly —
    // EventSource auto-reconnects, so this is invisible to the viewer
    while (time() - $started < 300) {
        if (connection_aborted()) break;

        try {
            $team = $pdo->query('SELECT * FROM tbl_team ORDER BY id')->fetchAll();
            $buffer = $pdo->query('SELECT * FROM tbl_buffer ORDER BY id')->fetchAll();
            $payload = json_encode(['ok' => true, 'team' => $team, 'buffer' => $buffer]);

            if ($payload !== $lastPayload) {
                echo "data: {$payload}\n\n";
                flush();
                $lastPayload = $payload;
            } else {
                echo ": heartbeat\n\n"; // comment line keeps proxies/browsers from timing out
                flush();
            }
        } catch (Throwable $e) {
            echo "data: " . json_encode(['ok' => false, 'error' => $e->getMessage()]) . "\n\n";
            flush();
        }

        usleep(300000); // check for DB changes ~3x/sec
    }
    exit;
}

// ---------------------------------------------------------
// 2) GET — load current scoreboard state (called on page load)
// ---------------------------------------------------------
if ($method === 'GET') {
    try {
        $team = $pdo->query('SELECT * FROM tbl_team ORDER BY id')->fetchAll();
        $buffer = $pdo->query('SELECT * FROM tbl_buffer ORDER BY id')->fetchAll();
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

        // INSERT ... ON DUPLICATE KEY UPDATE so a save works even if the seed
        // row for this id doesn't exist yet (requires id to be a PRIMARY KEY).
        $updateTeam = $pdo->prepare(
            'INSERT INTO tbl_team (id, Team, Points, Clock, quarter, OT)
                  VALUES (:id, :team, :points, :clock, :quarter, :ot)
             ON DUPLICATE KEY UPDATE
                  Team = VALUES(Team), Points = VALUES(Points), Clock = VALUES(Clock),
                  quarter = VALUES(quarter), OT = VALUES(OT)'
        );

        $updateBuffer = $pdo->prepare(
            'INSERT INTO tbl_buffer (id, Team, Points, Foul, `T/O`, Clock)
                  VALUES (:id, :team, :points, :foul, :timeouts, :clock)
             ON DUPLICATE KEY UPDATE
                  Team = VALUES(Team), Points = VALUES(Points), Foul = VALUES(Foul),
                  `T/O` = VALUES(`T/O`), Clock = VALUES(Clock)'
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