<?php
session_start();

/**
 * 返回JSON响应
 */
function jsonResponse($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取留言类型文字
 */
function getTypeLabel($type) {
    $map = ['help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return $map[$type] ?? '其他';
}

/**
 * 获取类型图标
 */
function getTypeIcon($type) {
    $map = ['help' => '🆘', 'suggest' => '💡', 'lost' => '🔍'];
    return $map[$type] ?? '📌';
}

/**
 * 获取状态文字
 */
function getStatusLabel($status) {
    $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
    return $map[$status] ?? '未知';
}

/**
 * 获取状态样式类
 */
function getStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    return $map[$status] ?? '';
}

/**
 * 时间格式化
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . '个月前';
    if ($diff->d > 0) return $diff->d . '天前';
    if ($diff->h > 0) return $diff->h . '小时前';
    if ($diff->i > 0) return $diff->i . '分钟前';
    return '刚刚';
}

/**
 * 检查管理员登录
 */
function requireAdmin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * 过滤输入
 */
function cleanInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * 获取访客唯一标识
 * 基于session和cookie实现匿名用户标识
 */
function getVisitorId() {
    if (empty($_SESSION['visitor_id'])) {
        if (!empty($_COOKIE['visitor_id'])) {
            $_SESSION['visitor_id'] = $_COOKIE['visitor_id'];
        } else {
            $visitorId = md5(uniqid('visitor_', true) . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $_SESSION['visitor_id'] = $visitorId;
            setcookie('visitor_id', $visitorId, time() + 86400 * 365, '/');
        }
    }
    return $_SESSION['visitor_id'];
}

/**
 * 检查留言是否已收藏
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove']
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => false, 'action' => 'remove'];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id) VALUES (?, ?)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add'];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 获取举报类型文字
 */
function getReportTypeLabel($type) {
    $map = [
        'spam' => '垃圾信息',
        'abuse' => '辱骂攻击',
        'illegal' => '违法违规',
        'porn' => '色情低俗',
        'other' => '其他'
    ];
    return $map[$type] ?? '未知';
}

/**
 * 获取举报状态文字
 */
function getReportStatusLabel($status) {
    $map = [
        0 => '待处理',
        1 => '已处理-已删除',
        2 => '已处理-已忽略',
        3 => '已驳回'
    ];
    return $map[$status] ?? '未知';
}

/**
 * 获取举报状态样式类
 */
function getReportStatusClass($status) {
    $map = [
        0 => 'pending',
        1 => 'resolved-deleted',
        2 => 'resolved-ignored',
        3 => 'rejected'
    ];
    return $map[$status] ?? '';
}

/**
 * 检查当前访客是否已举报过某条留言
 */
function hasReported($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 提交举报
 */
function submitReport($messageId, $reportType, $description = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $validTypes = ['spam', 'abuse', 'illegal', 'porn', 'other'];
    if (!in_array($reportType, $validTypes)) {
        throw new Exception('无效的举报类型');
    }

    $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    if (!$stmt->fetch()) {
        throw new Exception('留言不存在或未通过审核');
    }

    if (hasReported($messageId)) {
        throw new Exception('您已经举报过这条留言了');
    }

    $stmt = $db->prepare("INSERT INTO reports (message_id, visitor_id, report_type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$messageId, $visitorId, $reportType, $description]);

    return $db->lastInsertId();
}

/**
 * 获取待处理举报数量
 */
function getPendingReportCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn();
}

/**
 * 获取工单阶段文字
 */
function getWorkOrderStageLabel($stage) {
    $map = ['accepted' => '已受理', 'processing' => '处理中', 'completed' => '已完成'];
    return $map[$stage] ?? '未知';
}

/**
 * 获取工单阶段样式类
 */
function getWorkOrderStageClass($stage) {
    $map = ['accepted' => 'accepted', 'processing' => 'processing', 'completed' => 'completed'];
    return $map[$stage] ?? '';
}

/**
 * 判断工单是否超时（超过约定时限仍未完成）
 */
function isWorkOrderOverdue($order) {
    return $order['stage'] !== 'completed' && strtotime($order['expected_finish_at']) < time();
}

/**
 * 获取所有网格
 */
function getAllGrids() {
    $db = getDB();
    return $db->query("SELECT * FROM grids ORDER BY id ASC")->fetchAll();
}

/**
 * 根据留言ID获取工单（关联网格信息）
 */
function getWorkOrderByMessageId($messageId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT o.*, g.name AS grid_name, g.worker_name, g.worker_phone
        FROM work_orders o INNER JOIN grids g ON o.grid_id = g.id
        WHERE o.message_id = ?");
    $stmt->execute([$messageId]);
    $order = $stmt->fetch();
    return $order ? $order : null;
}

/**
 * 根据工单ID获取工单（关联网格信息）
 */
function getWorkOrderById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT o.*, g.name AS grid_name, g.worker_name, g.worker_phone
        FROM work_orders o INNER JOIN grids g ON o.grid_id = g.id
        WHERE o.id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    return $order ? $order : null;
}

/**
 * 获取工单进度记录（处理说明留痕，按时间正序）
 */
function getWorkOrderLogs($orderId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM work_order_logs WHERE order_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

/**
 * 生成工单编号
 */
function generateOrderNo() {
    return 'GD' . date('Ymd') . strtoupper(bin2hex(random_bytes(4)));
}

/**
 * 发起工单
 * 同一留言重复发起只合并成一张工单（幂等，网络重试/重复提交不会产生重复工单）
 * 返回: ['order' => array, 'merged' => bool] merged为true表示合并到已有工单
 */
function createWorkOrder($messageId, $gridId, $expectedFinishAt, $note = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        // 锁定同一留言的已有工单，重复发起直接合并返回
        $stmt = $db->prepare("SELECT id FROM work_orders WHERE message_id = ? FOR UPDATE");
        $stmt->execute([$messageId]);
        $existId = $stmt->fetchColumn();
        if ($existId) {
            $db->commit();
            return ['order' => getWorkOrderById($existId), 'merged' => true];
        }

        $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1 AND type = 'help'");
        $stmt->execute([$messageId]);
        if (!$stmt->fetch()) {
            throw new Exception('留言不存在、未通过审核或不属于居民求助');
        }

        $stmt = $db->prepare("SELECT id FROM grids WHERE id = ?");
        $stmt->execute([$gridId]);
        if (!$stmt->fetch()) {
            throw new Exception('所选网格不存在');
        }

        $stmt = $db->prepare("INSERT INTO work_orders (order_no, message_id, visitor_id, grid_id, stage, expected_finish_at) VALUES (?, ?, ?, ?, 'accepted', ?)");
        $stmt->execute([generateOrderNo(), $messageId, $visitorId, $gridId, $expectedFinishAt]);
        $orderId = $db->lastInsertId();

        // 初始进度记录
        $initNote = $note !== '' ? $note : '居民发起办理，工单已受理';
        $db->prepare("INSERT INTO work_order_logs (order_id, stage, note, operator_name) VALUES (?, 'accepted', ?, ?)")
            ->execute([$orderId, $initNote, '居民']);

        $db->commit();
        return ['order' => getWorkOrderById($orderId), 'merged' => false];
    } catch (Exception $e) {
        $db->rollBack();
        // 并发重复发起导致唯一键冲突时，合并返回已有工单
        $stmt = $db->prepare("SELECT id FROM work_orders WHERE message_id = ?");
        $stmt->execute([$messageId]);
        $existId = $stmt->fetchColumn();
        if ($existId) {
            return ['order' => getWorkOrderById($existId), 'merged' => true];
        }
        throw $e;
    }
}

/**
 * 格式化工单信息（用于接口返回）
 */
function formatWorkOrder($order) {
    if (!$order) return null;
    return [
        'id' => intval($order['id']),
        'order_no' => $order['order_no'],
        'stage' => $order['stage'],
        'stage_label' => getWorkOrderStageLabel($order['stage']),
        'grid_name' => $order['grid_name'],
        'worker_name' => $order['worker_name'],
        'expected_finish_at' => $order['expected_finish_at'],
        'overdue' => isWorkOrderOverdue($order),
    ];
}

/**
 * 获取超时未完成工单数量（待办）
 */
function getOverdueWorkOrderCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM work_orders WHERE stage != 'completed' AND expected_finish_at < NOW()")->fetchColumn();
}
