<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: work_orders.php');
    exit;
}

$db = getDB();

// 实时读取工单，保证再次进入时状态同步
$order = getWorkOrderById($id);
if (!$order) {
    header('Location: work_orders.php');
    exit;
}

// 关联留言
$stmt = $db->prepare("SELECT id, nickname, type, title, content, created_at FROM messages WHERE id = ?");
$stmt->execute([$order['message_id']]);
$msg = $stmt->fetch();
if (!$msg) {
    header('Location: work_orders.php');
    exit;
}

// 进度记录(原有说明全部保留)
$logs = getWorkOrderLogs($id);
$overdue = isWorkOrderOverdue($order);

$pageTitle = '工单 ' . $order['order_no'] . ' - 社区便民留言板';
$currentPage = 'work_orders';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

include __DIR__ . '/includes/header.php';
?>

<section class="detail-section">
    <div class="container">
        <div class="detail-card">
            <div class="detail-header">
                <span class="wo-order-no">📋 工单 <?= cleanInput($order['order_no']) ?></span>
                <div class="detail-meta">
                    <span class="stage-badge stage-<?= getWorkOrderStageClass($order['stage']) ?>"><?= getWorkOrderStageLabel($order['stage']) ?></span>
                    <?php if ($overdue): ?>
                    <span class="overdue-badge">⏰ 已超时</span>
                    <?php endif; ?>
                </div>
            </div>

            <h1 class="detail-title"><?= cleanInput($msg['title']) ?></h1>

            <div class="wo-info-grid">
                <div class="wo-info-item">
                    <div class="wo-label">🏘️ 责任网格</div>
                    <div class="wo-value"><?= $order['grid_name'] ? cleanInput($order['grid_name']) : '待分配' ?></div>
                </div>
                <div class="wo-info-item">
                    <div class="wo-label">👷 责任人</div>
                    <div class="wo-value"><?= $order['worker_name'] ? cleanInput($order['worker_name']) : '待分配' ?></div>
                </div>
                <div class="wo-info-item">
                    <div class="wo-label">🕐 预计完成时间</div>
                    <div class="wo-value<?= $overdue ? ' wo-overdue-text' : '' ?>">
                        <?= $order['expected_finish_at'] ? date('Y-m-d H:i', strtotime($order['expected_finish_at'])) : '受理后约定' ?>
                    </div>
                </div>
                <div class="wo-info-item">
                    <div class="wo-label">✅ 实际完成时间</div>
                    <div class="wo-value"><?= $order['finished_at'] ? date('Y-m-d H:i', strtotime($order['finished_at'])) : '未完成' ?></div>
                </div>
            </div>

            <?php if (intval($order['stage']) === 3 && $order['result']): ?>
            <div class="wo-result-box">
                <strong>✅ 办理结果：</strong><?= nl2br(cleanInput($order['result'])) ?>
            </div>
            <?php endif; ?>

            <div class="wo-message-box">
                <div class="wo-label">📝 关联求助留言</div>
                <p><?= cleanInput(mb_substr($msg['content'], 0, 120)) ?><?= mb_strlen($msg['content']) > 120 ? '...' : '' ?></p>
                <a href="detail.php?id=<?= $msg['id'] ?>" class="btn btn-sm btn-info">查看留言详情</a>
            </div>

            <div class="wo-timeline-section">
                <h3 class="wo-section-title">🛠️ 办理进度</h3>
                <?php if (empty($logs)): ?>
                <p class="text-muted">暂无进度记录</p>
                <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($logs as $log): ?>
                    <li class="timeline-item">
                        <div class="timeline-stage">
                            <span class="stage-badge stage-<?= getWorkOrderStageClass($log['stage']) ?>"><?= getWorkOrderStageLabel($log['stage']) ?></span>
                        </div>
                        <div class="timeline-note"><?= nl2br(cleanInput($log['note'])) ?></div>
                        <div class="timeline-meta">
                            <?= $log['operator_name'] ? '👷 ' . cleanInput($log['operator_name']) : '👤 居民' ?>
                            · <?= $log['created_at'] ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="detail-actions">
                <a href="work_orders.php" class="btn btn-secondary">← 返回我的工单</a>
                <a href="detail.php?id=<?= $msg['id'] ?>" class="btn btn-primary">查看留言</a>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
