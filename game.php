<?php
// =============================================
// game.php — Toute la logique du jeu Songo
// =============================================

// ── Navigation ────────────────────────────────────────────────────────

function ownRow(int $player): int { return $player === 1 ? 1 : 0; }
function oppRow(int $player): int { return $player === 1 ? 0 : 1; }

function nextPos(int $row, int $col, int $player): array {
    if ($player === 1) {
        if ($row === 1) return $col > 0 ? [1, $col - 1] : [0, 0];
        else            return $col < 6 ? [0, $col + 1] : [1, 6];
    } else {
        if ($row === 0) return $col < 6 ? [0, $col + 1] : [1, 6];
        else            return $col > 0 ? [1, $col - 1] : [0, 0];
    }
}

function prevPos(int $row, int $col, int $player): array {
    if ($player === 1) {
        if ($row === 0) return $col > 0 ? [0, $col - 1] : [1, 0];
        else            return $col < 6 ? [1, $col + 1] : [0, 6];
    } else {
        if ($row === 1) return $col < 6 ? [1, $col + 1] : [0, 6];
        else            return $col > 0 ? [0, $col - 1] : [1, 0];
    }
}

function oppFirstCol(int $player): int { return $player === 1 ? 0 : 6; }

// ── Utilitaires ───────────────────────────────────────────────────────

function totalOnBoard(array $board): int {
    return array_sum($board[0]) + array_sum($board[1]);
}

function simulateDistInOpp(array $board, int $col, int $player): int {
    $own   = ownRow($player);
    $opp   = oppRow($player);
    $seeds = $board[$own][$col];
    $count = 0;
    $row = $own; $c = $col; $s = $seeds;
    while ($s > 0) {
        [$row, $c] = nextPos($row, $c, $player);
        if ($row === $own && $c === $col && $seeds > 13) { $s--; continue; }
        $s--;
        if ($row === $opp) $count++;
    }
    return $count;
}

// ── Cases jouables ────────────────────────────────────────────────────

function getSelectableCells(array $board, int $player): array {
    $own      = ownRow($player);
    $opp      = oppRow($player);
    $oppEmpty = count(array_filter($board[$opp], fn($x) => $x > 0)) === 0;
    $allOwn   = array_keys(array_filter($board[$own], fn($x) => $x > 0));

    if (!$oppEmpty) return $allOwn;

    // Solidarité : coups qui distribuent ≥7 graines chez l'adversaire
    $reach7 = array_filter($allOwn, fn($c) => simulateDistInOpp($board, $c, $player) >= 7);
    if (count($reach7) > 0) return array_values($reach7);

    // Sinon : coup qui distribue le maximum
    $maxDist = 0;
    foreach ($allOwn as $c) {
        $d = simulateDistInOpp($board, $c, $player);
        if ($d > $maxDist) $maxDist = $d;
    }
    return array_values(array_filter($allOwn, fn($c) => simulateDistInOpp($board, $c, $player) === $maxDist));
}

// ── Application d'un coup ─────────────────────────────────────────────

function applyMove(array &$board, array &$scores, int &$currentPlayer, array &$log, ?array &$highlight, int $col): array {
    $player = $currentPlayer;
    $own    = ownRow($player);
    $opp    = oppRow($player);

    $selectable = getSelectableCells($board, $player);
    if (!in_array($col, $selectable, true)) {
        return ['ok' => false, 'error' => 'Coup invalide'];
    }

    $seeds = $board[$own][$col];
    $board[$own][$col] = 0;

    // Distribution
    $row = $own; $c = $col; $s = $seeds;
    while ($s > 0) {
        [$row, $c] = nextPos($row, $c, $player);
        if ($row === $own && $c === $col && $seeds > 13) { $s--; continue; }
        $board[$row][$c]++;
        $s--;
    }
    $lastRow = $row; $lastCol = $c;

    // Prises
    $captureList = [];
    $pr = $lastRow; $pc = $lastCol;

    if ($pr === $opp) {
        $fc = oppFirstCol($player);
        if ($pc === $fc) {
            if ($seeds >= 14) {
                $captureList[] = [$pr, $pc, 1];
                $board[$pr][$pc]--;
            }
        } else {
            while ($pr === $opp && $pc !== $fc) {
                $cnt = $board[$pr][$pc];
                if ($cnt >= 2 && $cnt <= 4) {
                    $captureList[] = [$pr, $pc, $cnt];
                    $board[$pr][$pc] = 0;
                    [$pr, $pc] = prevPos($pr, $pc, $player);
                } else break;
            }
            if ($pr === $opp && $pc === $fc && count($captureList) > 0) {
                $cnt = $board[$pr][$pc];
                if ($cnt >= 2 && $cnt <= 4) {
                    $captureList[] = [$pr, $pc, $cnt];
                    $board[$pr][$pc] = 0;
                }
            }
        }
    }

    // Interdit : vider le camp adverse
    $wouldEmpty = count(array_filter($board[$opp], fn($x) => $x > 0)) === 0;
    $logMsg = null;
    if ($wouldEmpty && count($captureList) > 0) {
        foreach ($captureList as [$r, $c2, $cnt]) $board[$r][$c2] += $cnt;
        $captureList = [];
        $logMsg = 'Interdit : vider le camp adverse. Aucune prise.';
    }

    $totalCaptured = array_sum(array_column($captureList, 2));
    $scoreIdx      = $player === 1 ? 1 : 0;
    $scores[$scoreIdx] += $totalCaptured;

    $caseNum    = $player === 1 ? (7 - $col) : ($col + 1);
    $playerName = $player === 1 ? 'Sud' : 'Nord';
    if (!$logMsg) {
        $logMsg = $totalCaptured > 0
            ? "$playerName joue case $caseNum ($seeds gr.) → +$totalCaptured capturées."
            : "$playerName joue case $caseNum ($seeds gr.) — aucune prise.";
    }

    $log[] = $logMsg;
    if (count($log) > 20) array_shift($log);

    $highlight = [
        'last'     => [$lastRow, $lastCol],
        'captured' => array_map(fn($x) => [$x[0], $x[1]], $captureList),
    ];

    // Changer le joueur
    $currentPlayer = $player === 1 ? 2 : 1;

    return ['ok' => true];
}

// ── Fin de partie ─────────────────────────────────────────────────────

function checkEnd(array &$board, array &$scores, int $currentPlayer): ?array {
    if ($scores[0] >= 40) return ['winner' => 2, 'reason' => 'score'];
    if ($scores[1] >= 40) return ['winner' => 1, 'reason' => 'score'];

    if (totalOnBoard($board) < 10) {
        foreach ($board[0] as $i => $v) { $scores[0] += $v; $board[0][$i] = 0; }
        foreach ($board[1] as $i => $v) { $scores[1] += $v; $board[1][$i] = 0; }
        if ($scores[0] >= 40) return ['winner' => 2, 'reason' => 'low'];
        if ($scores[1] >= 40) return ['winner' => 1, 'reason' => 'low'];
        return ['winner' => 0, 'reason' => 'low'];
    }

    $opp = oppRow($currentPlayer);
    if (count(array_filter($board[$opp], fn($x) => $x > 0)) === 0) {
        $sel = getSelectableCells($board, $currentPlayer);
        $canReach = count(array_filter($sel, fn($c) => simulateDistInOpp($board, $c, $currentPlayer) > 0)) > 0;
        if (!$canReach) {
            $own = ownRow($currentPlayer);
            foreach ($board[$own] as $i => $v) {
                $idx = $currentPlayer === 1 ? 1 : 0;
                $scores[$idx] += $v;
                $board[$own][$i] = 0;
            }
            if ($scores[0] >= 40) return ['winner' => 2, 'reason' => 'solidarity'];
            if ($scores[1] >= 40) return ['winner' => 1, 'reason' => 'solidarity'];
            return ['winner' => 0, 'reason' => 'solidarity'];
        }
    }

    return null;
}

// ── État retourné au client ───────────────────────────────────────────

function gameStateFor(array $game, int $playerNum): array {
    $board    = json_decode($game['board'], true);
    $scores   = json_decode($game['scores'], true);
    $log      = json_decode($game['log'], true);
    $highlight= $game['highlight'] ? json_decode($game['highlight'], true) : null;
    $result   = $game['result']    ? json_decode($game['result'], true)    : null;
    $current  = (int)$game['current_player'];
    $status   = $game['status'];

    $selectable = ($status === 'playing' && $current === $playerNum)
        ? getSelectableCells($board, $playerNum)
        : [];

    return [
        'gameId'          => $game['id'],
        'code'            => $game['code'],
        'board'           => $board,
        'scores'          => $scores,
        'currentPlayer'   => $current,
        'myPlayer'        => $playerNum,
        'status'          => $status,
        'result'          => $result,
        'log'             => $log,
        'highlight'       => $highlight,
        'selectableCells' => $selectable,
        'lastMoveAt'      => $game['last_move_at'],
    ];
}
