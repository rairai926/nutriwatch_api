<?php


require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

try {
  $authUser = authenticate(['admin']);
  $role = strtolower((string)($authUser->role ?? 'user'));

  if ($role !== 'admin') {
    out(403, ["ok" => false, "message" => "Forbidden"]);
  }

  // --------------------
  // Params
  // --------------------
  $page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
  $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

  if ($page < 1) $page = 1;
  if ($limit < 1) $limit = 1;
  if ($limit > 100) $limit = 100;

  $offset = ($page - 1) * $limit;

  $q = trim((string)($_GET['q'] ?? ''));
  $action = trim((string)($_GET['action'] ?? ''));
  $targetTable = trim((string)($_GET['target_table'] ?? ''));
  $userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : 0;
  $dateFrom = trim((string)($_GET['date_from'] ?? ''));
  $dateTo = trim((string)($_GET['date_to'] ?? ''));

  // --------------------
  // Build filters
  // --------------------
  $where = [];
  $params = [];

  if ($q !== '') {
    $where[] = "(
      al.action LIKE ?
      OR COALESCE(al.target_table, '') LIKE ?
      OR COALESCE(al.target_id, '') LIKE ?
      OR COALESCE(al.description, '') LIKE ?
      OR COALESCE(al.ip_address, '') LIKE ?
      OR CONCAT_WS(' ', u.firstname, u.middlename, u.lastname) LIKE ?
      OR COALESCE(u.username, '') LIKE ?
      OR COALESCE(u.email, '') LIKE ?
    )";
    $like = "%{$q}%";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
  }

  if ($action !== '') {
    $where[] = "al.action = ?";
    $params[] = $action;
  }

  if ($targetTable !== '') {
    $where[] = "al.target_table = ?";
    $params[] = $targetTable;
  }

  if ($userId > 0) {
    $where[] = "al.user_id = ?";
    $params[] = $userId;
  }

  if ($dateFrom !== '') {
    $where[] = "DATE(al.created_at) >= ?";
    $params[] = $dateFrom;
  }

  if ($dateTo !== '') {
    $where[] = "DATE(al.created_at) <= ?";
    $params[] = $dateTo;
  }

  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  // --------------------
  // Count
  // --------------------
  $countSql = "
    SELECT COUNT(*)
    FROM tbl_audit_logs al
    LEFT JOIN tbl_users u ON u.users_id = al.user_id
    $whereSql
  ";
  $st = $pdo->prepare($countSql);
  $st->execute($params);
  $total = (int)$st->fetchColumn();

  // --------------------
  // Rows
  // --------------------
  $listSql = "
    SELECT
      al.audit_id,
      al.user_id,
      al.action,
      al.target_table,
      al.target_id,
      al.description,
      al.ip_address,
      al.created_at,

      u.username,
      u.email,
      u.firstname,
      u.middlename,
      u.lastname,
      u.role
    FROM tbl_audit_logs al
    LEFT JOIN tbl_users u ON u.users_id = al.user_id
    $whereSql
    ORDER BY al.created_at DESC, al.audit_id DESC
    LIMIT $limit OFFSET $offset
  ";

  $st = $pdo->prepare($listSql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $rows = array_map(function ($r) {
    $userName = trim(implode(' ', array_filter([
      $r['firstname'] ?? '',
      $r['middlename'] ?? '',
      $r['lastname'] ?? ''
    ])));

    return [
      "audit_id" => (int)$r["audit_id"],
      "user_id" => $r["user_id"] !== null ? (int)$r["user_id"] : null,
      "user_name" => $userName !== '' ? $userName : null,
      "username" => $r["username"] ?? null,
      "email" => $r["email"] ?? null,
      "role" => $r["role"] ?? null,

      "action" => $r["action"] ?? '',
      "target_table" => $r["target_table"] ?? '',
      "target_id" => $r["target_id"] ?? '',
      "description" => $r["description"] ?? '',
      "ip_address" => $r["ip_address"] ?? '',
      "created_at" => $r["created_at"] ?? null
    ];
  }, $rows);

  // --------------------
  // Filter option helpers
  // --------------------
  $actionsSql = "SELECT DISTINCT action FROM tbl_audit_logs WHERE action IS NOT NULL AND action <> '' ORDER BY action ASC";
  $actions = $pdo->query($actionsSql)->fetchAll(PDO::FETCH_COLUMN);

  $tablesSql = "SELECT DISTINCT target_table FROM tbl_audit_logs WHERE target_table IS NOT NULL AND target_table <> '' ORDER BY target_table ASC";
  $tables = $pdo->query($tablesSql)->fetchAll(PDO::FETCH_COLUMN);

  $usersSql = "
    SELECT DISTINCT
      u.users_id,
      CONCAT_WS(' ', u.firstname, u.middlename, u.lastname) AS full_name,
      u.username
    FROM tbl_audit_logs al
    JOIN tbl_users u ON u.users_id = al.user_id
    ORDER BY full_name ASC, u.username ASC
  ";
  $users = $pdo->query($usersSql)->fetchAll(PDO::FETCH_ASSOC);
  $users = array_map(function ($u) {
    return [
      "user_id" => (int)$u["users_id"],
      "label" => trim(($u["full_name"] ?? '') . (($u["username"] ?? '') !== '' ? " ({$u['username']})" : ''))
    ];
  }, $users);

  out(200, [
    "ok" => true,
    "message" => "OK",
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "rows" => $rows,
    "filters" => [
      "actions" => $actions,
      "target_tables" => $tables,
      "users" => $users
    ]
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}