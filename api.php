<?php
// =============================================
// api.php — Point d'entrée unique de l'API
// =============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/game.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Nettoyage automatique ~5% des requêtes
if (rand(1, 20) === 1) cleanOldGames();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = [];
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
}

switch ("$method:$action") {
    case 'POST:create': createGame();                                                          break;
    case 'POST:join':   joinGame($body['code'] ?? '');                                        break;
    case 'GET:state':   getState($_GET['gameId'] ?? '', $_GET['token'] ?? '');                break;
    case 'POST:move':   handleMove($body['gameId'] ?? '', $body['token'] ?? '', (int)($body['col'] ?? -1)); break;
    default: http_response_code(404); echo json_encode(['error' => 'Route inconnue']);        break;
}

// ── Handlers ──────────────────────────────────────────────────────────

function createGame(): void {
    $db       = getDB();
    $gameId   = generateUUID();
    $tokenSud = generateUUID();
    $code     = generateCode($db);
    $stmt = $db->prepare("INSERT INTO games
        (id, code, board, scores, current_player, status, token_sud, log, last_activity)
        VALUES (:id,:code,:board,:scores,1,'waiting',:token_sud,:log,NOW())");
    $stmt->execute([
        'id'        => $gameId,
        'code'      => $code,
        'board'     => json_encode([[5,5,5,5,5,5,5],[5,5,5,5,5,5,5]]),
        'scores'    => json_encode([0,0]),
        'token_sud' => $tokenSud,
        'log'       => json_encode(['Partie créée — en attente du joueur Nord.']),
    ]);
    echo json_encode(['gameId' => $gameId, 'code' => $code, 'token' => $tokenSud, 'playerNum' => 1]);
}

function joinGame(string $code): void {
    if (!$code) { apiError(400, 'Code manquant'); return; }
    $db   = getDB();
    $code = strtoupper(trim($code));
    $stmt = $db->prepare("SELECT * FROM games WHERE code = :code");
    $stmt->execute(['code' => $code]);
    $row  = $stmt->fetch();
    if (!$row)                        { apiError(404, 'Partie introuvable'); return; }
    if ($row['status'] !== 'waiting') { apiError(400, 'Partie déjà commencée ou terminée'); return; }
    $tokenNord = generateUUID();
    $log = json_decode($row['log'], true);
    $log[] = 'Joueur Nord a rejoint — la partie commence !';
    $db->prepare("UPDATE games SET token_nord=:tn, status='playing', log=:log, last_activity=NOW() WHERE id=:id")
       ->execute(['tn' => $tokenNord, 'log' => json_encode($log), 'id' => $row['id']]);
    echo json_encode(['gameId' => $row['id'], 'code' => $row['code'], 'token' => $tokenNord, 'playerNum' => 2]);
}

function getState(string $gameId, string $token): void {
    if (!$gameId || !$token) { apiError(400, 'Paramètres manquants'); return; }
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM games WHERE id = :id");
    $stmt->execute(['id' => $gameId]);
    $row  = $stmt->fetch();
    if (!$row)                        { apiError(404, 'Partie introuvable'); return; }
    $p = resolvePlayer($row, $token);
    if (!$p)                          { apiError(403, 'Token invalide'); return; }
    echo json_encode(gameStateFor($row, $p));
}

function handleMove(string $gameId, string $token, int $col): void {
    if (!$gameId || !$token || $col < 0) { apiError(400, 'Paramètres manquants'); return; }
    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM games WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $gameId]);
        $row = $stmt->fetch();
        if (!$row)                             { $db->rollBack(); apiError(404, 'Partie introuvable'); return; }
        if ($row['status'] !== 'playing')      { $db->rollBack(); apiError(400, 'Partie non active'); return; }
        $p = resolvePlayer($row, $token);
        if (!$p)                               { $db->rollBack(); apiError(403, 'Token invalide'); return; }
        if ((int)$row['current_player'] !== $p){ $db->rollBack(); apiError(400, "Ce n'est pas votre tour"); return; }

        $board   = json_decode($row['board'],  true);
        $scores  = json_decode($row['scores'], true);
        $log     = json_decode($row['log'],    true);
        $hl      = null;
        $current = (int)$row['current_player'];

        $res = applyMove($board, $scores, $current, $log, $hl, $col);
        if (!$res['ok']) { $db->rollBack(); apiError(400, $res['error']); return; }

        $endResult = checkEnd($board, $scores, $current);
        $status    = $endResult ? 'finished' : 'playing';

        $db->prepare("UPDATE games SET board=:board, scores=:scores, current_player=:cp,
            status=:status, result=:result, log=:log, highlight=:hl,
            last_move_at=NOW(), last_activity=NOW() WHERE id=:id")
           ->execute([
               'board'  => json_encode($board),
               'scores' => json_encode($scores),
               'cp'     => $current,
               'status' => $status,
               'result' => $endResult ? json_encode($endResult) : null,
               'log'    => json_encode($log),
               'hl'     => $hl ? json_encode($hl) : null,
               'id'     => $gameId,
           ]);
        $db->commit();

        $stmt2 = $db->prepare("SELECT * FROM games WHERE id=:id");
        $stmt2->execute(['id' => $gameId]);
        echo json_encode(gameStateFor($stmt2->fetch(), $p));
    } catch (Exception $e) {
        $db->rollBack();
        apiError(500, 'Erreur serveur');
    }
}

// ── Utilitaires ───────────────────────────────────────────────────────

function resolvePlayer(array $row, string $token): int {
    if ($row['token_sud']  === $token) return 1;
    if ($row['token_nord'] === $token) return 2;
    return 0;
}

function cleanOldGames(): void {
    try { getDB()->exec("DELETE FROM games WHERE last_activity < DATE_SUB(NOW(), INTERVAL 2 HOUR)"); }
    catch (Exception $e) {}
}

function generateUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

function generateCode(PDO $db): string {
    do {
        $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
        $s    = $db->prepare("SELECT COUNT(*) FROM games WHERE code=:c");
        $s->execute(['c' => $code]);
    } while ($s->fetchColumn() > 0);
    return $code;
}

function apiError(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
}
