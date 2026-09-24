<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$id = intval($_GET['id'] ?? 0);
$order = $id > 0 ? getWorkOrderById($id) : null;

if (!$order) {
    header('Location: work_orders.php');
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, nickname, type, title, content, image, created_at FROM messages WHERE id = ?");
$stmt->execute([$order['message_id']]);
$msg = $stmt->fetch();

if (!$msg) {
    header('Location: work_orders.php');
    exit;
}

// 进度记录（处理说明留痕，原有说明全部保留）
$logs = getWorkOrderLogs($order['id']);
$overdue = isWorkOrderOverdue($order);

// 阶段进度：已受理 → 处理中 → 已完成
$stages = ['accepted', 'processing', 'completed'];
$currentIndex = array_search($order['stage'], $stages);
if ($currentIndex === false) $currentIndex = 0;

$pageTitle = '工单详情 - 社区便民留言板';
$currentPage = 'work_orders';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

include __DIR__ . '/includes/header.php';
?>

<section class="detail-section">
    <div class="container">
        <div class="detail-card">
            <div class="detail-header">
                <span class="work-order-no">工单号：<?= cleanInput($order['order_no']) ?></span>
                <div class="detail-meta">
                    <span>🕐 发起于 <?= $order['created_at'] ?></span>
                </div>
            </div>

            <h1 class="detail-title"><?= cleanInput($msg['title']) ?></h1>

            <!-- 办理阶段 -->
            <div class="stage-steps">
                <?php foreach ($stages as $i => $s): ?>
                <div class="stage-step <?= $i < $currentIndex ? 'done' : ($i === $currentIndex ? ($order['stage'] === 'completed' ? 'done' : 'active') : '') ?>">
                    <div class="stage-dot"><?= $i < $currentIndex || $order['stage'] === 'completed' ? '✓' : ($i + 1) ?></div>
                    <div class="stage-name"><?= getWorkOrderStageLabel($s) ?></div>
                </div>
                <?php if ($i < count($stages) - 1): ?>
                <div class="stage-line <?= $i < $currentIndex || $order['stage'] === 'completed' ? 'done' : '' ?>"></div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- 工单信息 -->
            <div class="work-order-info">
                <div class="info-item">
                    <span class="info-label">当前阶段</span>
                    <span class="stage-badge stage-<?= getWorkOrderStageClass($order['stage']) ?>"><?= getWorkOrderStageLabel($order['stage']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">责任网格</span>
                    <span>🏘 <?= cleanInput($order['grid_name']) ?>（网格员：<?= cleanInput($order['worker_name']) ?><?= $order['worker_phone'] ? '，电话：' . cleanInput($order['worker_phone']) : '' ?>）</span>
                </div>
                <div class="info-item">
                    <span class="info-label">预计完成时间</span>
                    <span class="<?= $overdue ? 'text-overdue' : '' ?>">
                        ⏰ <?= date('Y-m-d', strtotime($order['expected_finish_at'])) ?>
                        <?php if ($overdue): ?><span class="stage-badge stage-overdue">已超时</span><?php endif; ?>
                    </span>
                </div>
                <?php if ($order['completed_at']): ?>
                <div class="info-item">
                    <span class="info-label">实际完成时间</span>
                    <span>✅ <?= $order['completed_at'] ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- 关联留言 -->
            <div class="work-order-message">
                <h3 class="section-subtitle">📄 关联留言</h3>
                <div class="detail-text"><?= nl2br(cleanInput(mb_substr($msg['content'], 0, 200))) ?><?= mb_strlen($msg['content']) > 200 ? '...' : '' ?></div>
                <p style="margin-top:10px;"><a href="detail.php?id=<?= $msg['id'] ?>" class="btn btn-sm btn-info">查看留言详情</a></p>
            </div>

            <!-- 办理进度（处理说明留痕） -->
            <div class="work-order-logs">
                <h3 class="section-subtitle">📈 办理进度</h3>
                <?php if (empty($logs)): ?>
                <p class="text-muted">暂无进度记录</p>
                <?php else: ?>
                <div class="timeline">
                    <?php foreach (array_reverse($logs) as $log): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot stage-<?= getWorkOrderStageClass($log['stage']) ?>"></div>
                        <div class="timeline-content">
                            <div class="timeline-header">
                                <span class="stage-badge stage-<?= getWorkOrderStageClass($log['stage']) ?>"><?= getWorkOrderStageLabel($log['stage']) ?></span>
                                <span class="timeline-operator">👤 <?= cleanInput($log['operator_name']) ?></span>
                                <span class="timeline-time"><?= $log['created_at'] ?></span>
                            </div>
                            <div class="timeline-note"><?= nl2br(cleanInput($log['note'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="detail-actions">
                <a href="work_orders.php" class="btn btn-secondary">← 返回我的工单</a>
                <a href="detail.php?id=<?= $msg['id'] ?>" class="btn btn-secondary">查看留言</a>
                <a href="index.php" class="btn btn-primary">返回首页</a>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
