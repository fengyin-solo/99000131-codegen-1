<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '我的工单 - 社区便民留言板';
$currentPage = 'work_orders';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

$db = getDB();
$visitorId = getVisitorId();

$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 10;
$offset = ($page - 1) * $pageSize;

// 每次进入均从数据库实时读取，保证阶段、责任人与详情页一致
$countStmt = $db->prepare("SELECT COUNT(*) FROM work_orders WHERE visitor_id = ?");
$countStmt->execute([$visitorId]);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

$sql = "SELECT w.*, m.title AS message_title, g.name AS grid_name, gw.name AS worker_name
        FROM work_orders w
        INNER JOIN messages m ON w.message_id = m.id
        LEFT JOIN grids g ON w.grid_id = g.id
        LEFT JOIN grid_workers gw ON w.worker_id = gw.id
        WHERE w.visitor_id = ?
        ORDER BY w.created_at DESC
        LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute([$visitorId]);
$orders = $stmt->fetchAll();

// 统计
$statsStmt = $db->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN stage < 3 THEN 1 ELSE 0 END) as doing_count,
    SUM(CASE WHEN stage = 3 THEN 1 ELSE 0 END) as done_count,
    SUM(CASE WHEN stage < 3 AND expected_finish_at IS NOT NULL AND expected_finish_at < NOW() THEN 1 ELSE 0 END) as overdue_count
    FROM work_orders WHERE visitor_id = ?");
$statsStmt->execute([$visitorId]);
$stats = $statsStmt->fetch();

include __DIR__ . '/includes/header.php';
?>

<section class="favorites-section">
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">📋 我的工单</h1>
            <p class="page-subtitle">共发起 <?= $stats['total'] ?? 0 ?> 张服务工单</p>
        </div>

        <div class="favorites-stats">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total'] ?? 0 ?></div>
                <div class="stat-label">全部工单</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number"><?= $stats['doing_count'] ?? 0 ?></div>
                <div class="stat-label">🛠️ 办理中</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number"><?= $stats['done_count'] ?? 0 ?></div>
                <div class="stat-label">✅ 已办结</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number"><?= $stats['overdue_count'] ?? 0 ?></div>
                <div class="stat-label">⏰ 超时待办</div>
            </div>
        </div>
    </div>
</section>

<section class="message-list-section">
    <div class="container">
        <?php if (empty($orders)): ?>
        <div class="empty-state">
            <div class="empty-icon">📋</div>
            <p>暂无工单，可从求助留言详情页发起办理</p>
            <a href="index.php?type=help" class="btn btn-primary">去浏览求助留言</a>
        </div>
        <?php else: ?>
        <div class="message-list">
            <?php foreach ($orders as $order): ?>
            <?php $overdue = isWorkOrderOverdue($order); ?>
            <a href="work_order.php?id=<?= $order['id'] ?>" class="message-card wo-card">
                <div class="card-header">
                    <span class="wo-order-no">工单 <?= cleanInput($order['order_no']) ?></span>
                    <span class="card-time"><?= timeAgo($order['created_at']) ?>发起</span>
                </div>
                <h3 class="card-title"><?= cleanInput($order['message_title']) ?></h3>
                <div class="wo-meta">
                    <span class="stage-badge stage-<?= getWorkOrderStageClass($order['stage']) ?>"><?= getWorkOrderStageLabel($order['stage']) ?></span>
                    <?php if ($overdue): ?>
                    <span class="overdue-badge">⏰ 已超时</span>
                    <?php endif; ?>
                    <span class="wo-meta-item">🏘️ 责任网格：<?= $order['grid_name'] ? cleanInput($order['grid_name']) : '待分配' ?></span>
                    <span class="wo-meta-item">👷 责任人：<?= $order['worker_name'] ? cleanInput($order['worker_name']) : '待分配' ?></span>
                    <span class="wo-meta-item<?= $overdue ? ' wo-overdue-text' : '' ?>">
                        🕐 预计完成：<?= $order['expected_finish_at'] ? date('Y-m-d H:i', strtotime($order['expected_finish_at'])) : '受理后约定' ?>
                    </span>
                </div>
                <?php if (intval($order['stage']) === 3 && $order['result']): ?>
                <p class="wo-result">✅ 办理结果：<?= cleanInput($order['result']) ?></p>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="work_orders.php?page=<?= $page - 1 ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="work_orders.php?page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="work_orders.php?page=<?= $page + 1 ?>" class="page-btn">下一页</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
