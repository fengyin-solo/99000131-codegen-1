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

        $stmt = $db->prepare("SELECT title, nickname FROM messages WHERE id = ?");
        $stmt->execute([$order['message_id']]);
        $message = $stmt->fetch();

        $logs = getWorkOrderLogs($id);
        $logList = [];
        foreach ($logs as $log) {
            $logList[] = [
                'stage_label' => getWorkOrderStageLabel($log['stage']),
                'stage_class' => getWorkOrderStageClass($log['stage']),
                'note' => nl2br(cleanInput($log['note'])),
                'operator' => $log['operator_name'] ? '👷 ' . cleanInput($log['operator_name']) : '👤 居民',
                'created_at' => $log['created_at'],
            ];
        }

        jsonResponse(0, 'ok', [
            'id' => $order['id'],
            'order_no' => cleanInput($order['order_no']),
            'message_id' => $order['message_id'],
            'message_title' => $message ? cleanInput($message['title']) : '留言已删除',
            'message_nickname' => $message ? cleanInput($message['nickname']) : '-',
            'stage_label' => getWorkOrderStageLabel($order['stage']),
            'stage_class' => getWorkOrderStageClass($order['stage']),
            'overdue' => isWorkOrderOverdue($order),
            'grid_name' => $order['grid_name'] ? cleanInput($order['grid_name']) : '',
            'worker_name' => $order['worker_name'] ? cleanInput($order['worker_name']) : '',
            'expected_finish_at' => $order['expected_finish_at'] ?: '',
            'finished_at' => $order['finished_at'] ?: '',
            'result' => $order['result'] ? nl2br(cleanInput($order['result'])) : '',
            'logs' => $logList,
        ]);
        break;

    case 'assign_order':
        $id = intval($_POST['id'] ?? 0);
        $workerId = intval($_POST['worker_id'] ?? 0);
        $expected = trim($_POST['expected_finish_at'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if ($workerId <= 0) jsonResponse(1, '请选择网格员');
        if ($note === '') jsonResponse(1, '请填写处理说明');
        if (mb_strlen($note) > 500) jsonResponse(1, '处理说明不能超过500字');

        $expectedTs = strtotime($expected);
        if ($expectedTs === false) jsonResponse(1, '请选择预计完成时间');
        if ($expectedTs <= time()) jsonResponse(1, '预计完成时间必须晚于当前时间');
        $expectedAt = date('Y-m-d H:i:s', $expectedTs);

        // 校验网格员及其所属网格
        $stmt = $db->prepare("SELECT gw.*, g.name AS grid_name FROM grid_workers gw INNER JOIN grids g ON gw.grid_id = g.id WHERE gw.id = ?");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        if (!$worker) jsonResponse(1, '网格员不存在');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM work_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            if (!$order) {
                $db->rollBack();
                jsonResponse(1, '工单不存在');
            }
            if (intval($order['stage']) !== 0) {
                $db->rollBack();
                jsonResponse(1, '工单已受理，请勿重复操作');
            }

            $stmt = $db->prepare("UPDATE work_orders SET stage = 1, grid_id = ?, worker_id = ?, expected_finish_at = ? WHERE id = ?");
            $stmt->execute([$worker['grid_id'], $worker['id'], $expectedAt, $id]);

            $stmt = $db->prepare("INSERT INTO work_order_logs (work_order_id, stage, note, created_by) VALUES (?, 1, ?, ?)");
            $stmt->execute([$id, $note, $_SESSION['admin_id']]);

            $db->commit();
            jsonResponse(0, '受理成功，已指派网格员');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    case 'update_stage':
        $id = intval($_POST['id'] ?? 0);
        $stage = intval($_POST['stage'] ?? -1);
        $note = trim($_POST['note'] ?? '');
        $result = trim($_POST['result'] ?? '');

        if (!in_array($stage, [2, 3])) jsonResponse(1, '无效的办理阶段');
        if ($note === '') jsonResponse(1, '请填写处理说明');
        if (mb_strlen($note) > 500) jsonResponse(1, '处理说明不能超过500字');
        if ($stage === 3 && $result === '') jsonResponse(1, '办结时请填写办理结果');
        if (mb_strlen($result) > 500) jsonResponse(1, '办理结果不能超过500字');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM work_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            if (!$order) {
                $db->rollBack();
                jsonResponse(1, '工单不存在');
            }
            $currentStage = intval($order['stage']);
            if ($currentStage === 0) {
                $db->rollBack();
                jsonResponse(1, '工单尚未受理，请先受理指派');
            }
            if ($currentStage === 3) {
                $db->rollBack();
                jsonResponse(1, '工单已办结，无需再更新');
            }
            if ($stage <= $currentStage) {
                $db->rollBack();
                jsonResponse(1, '办理阶段不能回退');
            }

            if ($stage === 3) {
                $stmt = $db->prepare("UPDATE work_orders SET stage = 3, finished_at = NOW(), result = ? WHERE id = ?");
                $stmt->execute([$result, $id]);
            } else {
                $stmt = $db->prepare("UPDATE work_orders SET stage = ? WHERE id = ?");
                $stmt->execute([$stage, $id]);
            }

            $stmt = $db->prepare("INSERT INTO work_order_logs (work_order_id, stage, note, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, $stage, $note, $_SESSION['admin_id']]);

            $db->commit();
            jsonResponse(0, $stage === 3 ? '工单已办结' : '进度已更新');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    case 'reassign_order':
        $id = intval($_POST['id'] ?? 0);
        $workerId = intval($_POST['worker_id'] ?? 0);
        $expected = trim($_POST['expected_finish_at'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if ($workerId <= 0) jsonResponse(1, '请选择新责任人');
        if ($note === '') jsonResponse(1, '请填写处理说明');
        if (mb_strlen($note) > 500) jsonResponse(1, '处理说明不能超过500字');

        // 预计完成时间为可选项，填写时校验
        $expectedAt = null;
        if ($expected !== '') {
            $expectedTs = strtotime($expected);
            if ($expectedTs === false) jsonResponse(1, '预计完成时间格式不正确');
            if ($expectedTs <= time()) jsonResponse(1, '预计完成时间必须晚于当前时间');
            $expectedAt = date('Y-m-d H:i:s', $expectedTs);
        }

        $stmt = $db->prepare("SELECT gw.*, g.name AS grid_name FROM grid_workers gw INNER JOIN grids g ON gw.grid_id = g.id WHERE gw.id = ?");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        if (!$worker) jsonResponse(1, '网格员不存在');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM work_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            if (!$order) {
                $db->rollBack();
                jsonResponse(1, '工单不存在');
            }
            $currentStage = intval($order['stage']);
            if ($currentStage === 0) {
                $db->rollBack();
                jsonResponse(1, '工单尚未受理，请使用受理指派');
            }
            if ($currentStage === 3) {
                $db->rollBack();
                jsonResponse(1, '工单已办结，不能重新指派');
            }

            if ($expectedAt !== null) {
                $stmt = $db->prepare("UPDATE work_orders SET grid_id = ?, worker_id = ?, expected_finish_at = ? WHERE id = ?");
                $stmt->execute([$worker['grid_id'], $worker['id'], $expectedAt, $id]);
            } else {
                $stmt = $db->prepare("UPDATE work_orders SET grid_id = ?, worker_id = ? WHERE id = ?");
                $stmt->execute([$worker['grid_id'], $worker['id'], $id]);
            }

            // 留痕：记录重新指派说明，阶段保持不变
            $stmt = $db->prepare("INSERT INTO work_order_logs (work_order_id, stage, note, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, $currentStage, $note, $_SESSION['admin_id']]);

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
