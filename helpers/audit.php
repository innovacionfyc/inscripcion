<?php
// helpers/audit.php
function getClientIp(): string {
    $keys = ['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','HTTP_X_FORWARDED','HTTP_X_CLUSTER_CLIENT_IP','HTTP_FORWARDED_FOR','HTTP_FORWARDED','REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ipList = explode(',', $_SERVER[$k]);
            return trim($ipList[0]);
        }
    }
    return '0.0.0.0';
}

function log_activity(mysqli $conn, string $action, ?string $entity_type = null, $entity_id = null, array $meta = []): void {
    if (!isset($_SESSION)) { session_start(); }
    $user_id   = $_SESSION['usuario']['id'] ?? null; // ajusta a tu estructura de sesión
    $ip        = getClientIp();
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $metaJson  = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

    $sql = "INSERT INTO audit_log (user_id, action, entity_type, entity_id, meta, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "issssss",
        $user_id,
        $action,
        $entity_type,
        $entity_id,
        $metaJson,
        $ip,
        $userAgent
    );
    $stmt->execute();
    $stmt->close();
}