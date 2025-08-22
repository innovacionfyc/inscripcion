<?php
// admin/helpers/audit.php
if (!function_exists('audit_get_ip')) {
  function audit_get_ip() {
    $keys = array('HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','HTTP_X_FORWARDED','HTTP_X_CLUSTER_CLIENT_IP','HTTP_FORWARDED_FOR','HTTP_FORWARDED','REMOTE_ADDR');
    foreach ($keys as $k) {
      if (!empty($_SERVER[$k])) {
        $ipList = explode(',', $_SERVER[$k]);
        return trim($ipList[0]);
      }
    }
    return '0.0.0.0';
  }
}

if (!function_exists('log_activity')) {
  function log_activity($conn, $action, $entity_type = NULL, $entity_id = NULL, $meta = NULL) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $user_id = NULL;
    if (isset($_SESSION['usuario']) && is_array($_SESSION['usuario']) && isset($_SESSION['usuario']['id'])) {
      $user_id = $_SESSION['usuario']['id'];
    } elseif (isset($_SESSION['user_id'])) {
      $user_id = $_SESSION['user_id'];
    }

    $ip  = audit_get_ip();
    $ua  = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
    if (is_array($meta)) { $meta = json_encode($meta); }

    $sql = "INSERT INTO audit_log (user_id, action, entity_type, entity_id, meta, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $stmt->bind_param("issssss", $user_id, $action, $entity_type, $entity_id, $meta, $ip, $ua);
      $stmt->execute();
      $stmt->close();
    }
  }
}

function fyc_current_role() {
    if (isset($_SESSION['user']['role'])) return $_SESSION['user']['role'];
    if (isset($_SESSION['user']['rol']))  return $_SESSION['user']['rol'];
    if (isset($_SESSION['role']))         return $_SESSION['role'];
    return '';
}

function require_any_role($roles = array()) {
    require_login();
    $role = fyc_current_role();
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        echo "No autorizado";
        exit;
    }
}

// Helper por si quieres consultarlo en vistas
function is_admin() { return fyc_current_role() === 'admin'; }
