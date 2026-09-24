<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    // 居民从求助留言发起办理（幂等：同一留言重复发起合并为一张工单）
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(405, '不支持的请求方式');
        }

        $messageId = intval($_POST['message_id'] ?? 0);
        $gridId = intval($_POST['grid_id'] ?? 0);
        $expectedDate = trim($_POST['expected_finish_at'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if ($messageId <= 0) jsonResponse(1, '无效的留言');
        if ($gridId <= 0) jsonResponse(1, '请选择责任网格');

        $dt = DateTime::createFromFormat('Y-m-d', $expectedDate);
        if (!$dt || $dt->format('Y-m-d') !== $expectedDate) {
            jsonResponse(1, '请选择预计完成时间');
        }
        if ($expectedDate < date('Y-m-d')) {
            jsonResponse(1, '预计完成时间不能早于今天');
        }
        if (mb_strlen($note) > 500) jsonResponse(1, '补充说明不能超过500字');

        try {
            // 约定时限按当天结束时间计算
            $result = createWorkOrder($messageId, $gridId, $expectedDate . ' 23:59:59', $note);
        } catch (PDOException $e) {
            jsonResponse(500, '服务器错误，请稍后重试');
        } catch (Exception $e) {
            jsonResponse(1, $e->getMessage());
        }

        jsonResponse(0, $result['merged'] ? '该留言已发起过工单，已为您合并到原工单' : '工单发起成功', [
            'merged' => $result['merged'],
            'order' => formatWorkOrder($result['order']),
        ]);
        break;

    default:
        jsonResponse(1, '未知操作');
}
