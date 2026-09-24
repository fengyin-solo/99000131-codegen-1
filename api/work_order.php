<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$action = $_POST['action'] ?? 'initiate';

if ($action !== 'initiate') {
    jsonResponse(1, '未知操作');
}

$messageId = intval($_POST['message_id'] ?? 0);
$note = trim($_POST['note'] ?? '');

if ($messageId <= 0) jsonResponse(1, '无效的留言ID');
if (mb_strlen($note) > 500) jsonResponse(1, '补充说明不能超过500字');

$db = getDB();

// 仅已通过审核的求助类留言可发起办理
$stmt = $db->prepare("SELECT id, type, status FROM messages WHERE id = ?");
$stmt->execute([$messageId]);
$message = $stmt->fetch();
if (!$message || intval($message['status']) !== 1) {
    jsonResponse(1, '留言不存在或未通过审核');
}
if ($message['type'] !== 'help') {
    jsonResponse(1, '仅居民求助类留言支持发起办理');
}

$visitorId = getVisitorId();

try {
    $db->beginTransaction();

    // 锁定该留言的已有工单：同一居民重复发起时合并为同一张工单
    $stmt = $db->prepare("SELECT * FROM work_orders WHERE message_id = ? FOR UPDATE");
    $stmt->execute([$messageId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $db->commit();
        jsonResponse(0, '该留言已发起办理，已合并到原有工单', [
            'merged' => true,
            'order_id' => $existing['id'],
            'order_no' => $existing['order_no'],
        ]);
    }

    $orderNo = 'GD' . date('Ymd') . '-' . str_pad($messageId, 4, '0', STR_PAD_LEFT);
    $stmt = $db->prepare("INSERT INTO work_orders (order_no, message_id, visitor_id, stage) VALUES (?, ?, ?, 0)");
    $stmt->execute([$orderNo, $messageId, $visitorId]);
    $orderId = $db->lastInsertId();

    // 居民发起时的补充说明写入进度记录，永久保留
    $noteText = $note !== '' ? $note : '居民发起办理';
    $stmt = $db->prepare("INSERT INTO work_order_logs (work_order_id, stage, note, created_by) VALUES (?, 0, ?, NULL)");
    $stmt->execute([$orderId, $noteText]);

    $db->commit();

    jsonResponse(0, '工单发起成功，可在"我的工单"中跟踪办理进度', [
        'merged' => false,
        'order_id' => $orderId,
        'order_no' => $orderNo,
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    // 并发下唯一键冲突：返回已有工单，保证只合并成一张
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        $stmt = $db->prepare("SELECT * FROM work_orders WHERE message_id = ?");
        $stmt->execute([$messageId]);
        $existing = $stmt->fetch();
        if ($existing) {
            jsonResponse(0, '该留言已发起办理，已合并到原有工单', [
                'merged' => true,
                'order_id' => $existing['id'],
                'order_no' => $existing['order_no'],
            ]);
        }
    }
    jsonResponse(500, '服务器错误，请稍后重试');
}
