<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        jsonResponse(0, '操作成功');
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        // 删除关联图片
        $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if ($msg && $msg['image']) {
            $imgFile = __DIR__ . '/../' . $msg['image'];
            if (file_exists($imgFile)) unlink($imgFile);
        }
        $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
        jsonResponse(0, '删除成功');
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image, a.username as admin_name FROM reports r LEFT JOIN messages m ON r.message_id = m.id LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(1, '举报不存在');

        $report['report_type_label'] = getReportTypeLabel($report['report_type']);
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        $report['message_exists'] = !empty($report['message_title']);
        $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
        $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
        $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
        $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
        $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
        $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';

        jsonResponse(0, 'ok', $report);
        break;

    case 'process_report':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        $note = cleanInput($_POST['note'] ?? '');

        if (!in_array($status, [1, 2, 3])) jsonResponse(1, '无效状态');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0 FOR UPDATE");
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) jsonResponse(1, '举报不存在或已处理');

            if ($status === 1) {
                $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
                $stmt->execute([$report['message_id']]);
                $msg = $stmt->fetch();
                if ($msg && $msg['image']) {
                    $imgFile = __DIR__ . '/../' . $msg['image'];
                    if (file_exists($imgFile)) unlink($imgFile);
                }
                $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ?");
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);

            $db->commit();

            $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
            jsonResponse(0, $statusMsg[$status] . '成功');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    case 'work_order_detail':
        $id = intval($_GET['id'] ?? 0);
        $order = getWorkOrderById($id);
        if (!$order) jsonResponse(1, '工单不存在');

        $stmt = $db->prepare("SELECT id, title, nickname, content FROM messages WHERE id = ?");
        $stmt->execute([$order['message_id']]);
        $msg = $stmt->fetch();

        $logs = [];
        foreach (array_reverse(getWorkOrderLogs($id)) as $log) {
            $logs[] = [
                'stage_label' => getWorkOrderStageLabel($log['stage']),
                'stage_class' => getWorkOrderStageClass($log['stage']),
                'note' => nl2br(cleanInput($log['note'])),
                'operator_name' => cleanInput($log['operator_name']),
                'created_at' => $log['created_at'],
            ];
        }

        jsonResponse(0, 'ok', [
            'order_no' => cleanInput($order['order_no']),
            'stage' => $order['stage'],
            'stage_label' => getWorkOrderStageLabel($order['stage']),
            'stage_class' => getWorkOrderStageClass($order['stage']),
            'overdue' => isWorkOrderOverdue($order),
            'grid_name' => cleanInput($order['grid_name']),
            'worker_name' => cleanInput($order['worker_name']),
            'worker_phone' => cleanInput($order['worker_phone'] ?? ''),
            'expected_finish_at' => $order['expected_finish_at'],
            'completed_at' => $order['completed_at'],
            'created_at' => $order['created_at'],
            'message_exists' => !empty($msg),
            'message_title' => $msg ? cleanInput($msg['title']) : '',
            'message_nickname' => $msg ? cleanInput($msg['nickname']) : '',
            'message_content' => $msg ? nl2br(cleanInput($msg['content'])) : '',
            'logs' => $logs,
        ]);
        break;

    case 'update_work_order_stage':
        $id = intval($_POST['id'] ?? 0);
        $stage = $_POST['stage'] ?? '';
        $note = trim($_POST['note'] ?? '');

        if (!in_array($stage, ['accepted', 'processing', 'completed'])) jsonResponse(1, '无效的办理阶段');
        if ($note === '') jsonResponse(1, '请填写处理说明');
        if (mb_strlen($note) > 500) jsonResponse(1, '处理说明不能超过500字');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM work_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            if (!$order) jsonResponse(1, '工单不存在');

            // 完成时记录实际完成时间，回退到未完成状态时清空
            $completedAt = $stage === 'completed' ? date('Y-m-d H:i:s') : null;
            $stmt = $db->prepare("UPDATE work_orders SET stage = ?, completed_at = ? WHERE id = ?");
            $stmt->execute([$stage, $completedAt, $id]);

            // 进度留痕：处理说明必填，原有说明全部保留
            $stmt = $db->prepare("INSERT INTO work_order_logs (order_id, stage, note, operator_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, $stage, $note, $_SESSION['admin_name'] ?? '管理员']);

            $db->commit();
            jsonResponse(0, '进度已更新');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    case 'reassign_work_order':
        $id = intval($_POST['id'] ?? 0);
        $gridId = intval($_POST['grid_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');

        if ($gridId <= 0) jsonResponse(1, '请选择指派网格');
        if (mb_strlen($note) > 500) jsonResponse(1, '指派说明不能超过500字');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT o.*, g.name AS grid_name FROM work_orders o INNER JOIN grids g ON o.grid_id = g.id WHERE o.id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            if (!$order) jsonResponse(1, '工单不存在');
            if ($order['stage'] === 'completed') jsonResponse(1, '工单已完成，无需重新指派');

            $stmt = $db->prepare("SELECT * FROM grids WHERE id = ?");
            $stmt->execute([$gridId]);
            $grid = $stmt->fetch();
            if (!$grid) jsonResponse(1, '所选网格不存在');
            if (intval($order['grid_id']) === $gridId) jsonResponse(1, '工单已在该网格，请选择其他网格');

            $stmt = $db->prepare("UPDATE work_orders SET grid_id = ? WHERE id = ?");
            $stmt->execute([$gridId, $id]);

            // 指派记录留痕
            $logNote = '重新指派：' . $order['grid_name'] . ' → ' . $grid['name'];
            if ($note !== '') $logNote .= '；' . $note;
            $logNote = mb_substr($logNote, 0, 500);
            $stmt = $db->prepare("INSERT INTO work_order_logs (order_id, stage, note, operator_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, $order['stage'], $logNote, $_SESSION['admin_name'] ?? '管理员']);

            $db->commit();
            jsonResponse(0, '重新指派成功');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    default:
        jsonResponse(1, '未知操作');
}
