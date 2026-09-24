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

$countStmt = $db->prepare("SELECT COUNT(*) FROM work_orders WHERE visitor_id = ?");
$countStmt->execute([$visitorId]);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

// 每次进入都从数据库重新读取，保证阶段、责任人与详情页一致
$sql = "SELECT o.*, g.name AS grid_name, g.worker_name, m.title AS message_title
        FROM work_orders o
        INNER JOIN grids g ON o.grid_id = g.id
        INNER JOIN messages m ON o.message_id = m.id
        WHERE o.visitor_id = ?
        ORDER BY o.created_at DESC
        LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute([$visitorId]);
$orders = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<section class="work-orders-section">
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">📋 我的工单</h1>
            <p class="page-subtitle">共发起 <?= $total ?> 张服务工单</p>
        </div>

        <?php if (empty($orders)): ?>
        <div class="empty-state">
            <div class="empty-icon">📭</div>
            <p>暂无工单，可从求助留言详情页发起办理</p>
            <a href="index.php?type=help" class="btn btn-primary">去浏览求助留言</a>
        </div>
        <?php else: ?>
        <div class="work-order-list">
            <?php foreach ($orders as $order): ?>
            <?php $overdue = isWorkOrderOverdue($order); ?>
            <a href="work_order_detail.php?id=<?= $order['id'] ?>" class="work-order-card">
                <div class="work-order-card-header">
                    <span class="work-order-no">工单号：<?= cleanInput($order['order_no']) ?></span>
                    <span class="stage-badge stage-<?= getWorkOrderStageClass($order['stage']) ?>"><?= getWorkOrderStageLabel($order['stage']) ?></span>
                </div>
                <h3 class="work-order-title"><?= cleanInput($order['message_title']) ?></h3>
                <div class="work-order-meta">
                    <span>🏘 责任网格：<?= cleanInput($order['grid_name']) ?>（<?= cleanInput($order['worker_name']) ?>）</span>
                    <span class="<?= $overdue ? 'text-overdue' : '' ?>">
                        ⏰ 预计完成：<?= date('Y-m-d', strtotime($order['expected_finish_at'])) ?>
                        <?php if ($overdue): ?><span class="stage-badge stage-overdue">已超时</span><?php endif; ?>
                    </span>
                    <span>🕐 发起于 <?= date('Y-m-d H:i', strtotime($order['created_at'])) ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- 分页 -->
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
