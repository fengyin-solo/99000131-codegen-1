<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '工单管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

// 筛选参数（白名单校验，避免非法值回显）
$stage = $_GET['stage'] ?? '';
if (!in_array($stage, ['accepted', 'processing', 'completed'])) $stage = '';
$view = $_GET['view'] ?? '';
if ($view !== 'overdue') $view = '';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

$where = "WHERE 1=1";
$params = [];

// 待办视图：超过约定时限仍未完成的工单
if ($view === 'overdue') {
    $where .= " AND o.stage != 'completed' AND o.expected_finish_at < NOW()";
}
if ($stage !== '') {
    $where .= " AND o.stage = ?";
    $params[] = $stage;
}
if ($keyword) {
    $where .= " AND (o.order_no LIKE ? OR m.title LIKE ? OR g.name LIKE ?)";
    $kw = "%$keyword%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM work_orders o INNER JOIN grids g ON o.grid_id = g.id LEFT JOIN messages m ON o.message_id = m.id $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

// 超时工单排在前面，便于优先处理
$sql = "SELECT o.*, g.name AS grid_name, g.worker_name, m.title AS message_title
        FROM work_orders o
        INNER JOIN grids g ON o.grid_id = g.id
        LEFT JOIN messages m ON o.message_id = m.id
        $where
        ORDER BY (o.stage != 'completed' AND o.expected_finish_at < NOW()) DESC, o.created_at DESC
        LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// 统计
$totalCount = $db->query("SELECT COUNT(*) FROM work_orders")->fetchColumn();
$processingCount = $db->query("SELECT COUNT(*) FROM work_orders WHERE stage IN ('accepted','processing')")->fetchColumn();
$completedCount = $db->query("SELECT COUNT(*) FROM work_orders WHERE stage = 'completed'")->fetchColumn();
$overdueCount = getOverdueWorkOrderCount();

// 重新指派的网格选项
$grids = getAllGrids();

$pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
$pendingReportCount = getPendingReportCount();

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingReportCount > 0 ? "($pendingReportCount)" : '' ?></a>
            <a href="work_orders.php" class="sidebar-link active">📋 工单管理</a>
            <a href="work_orders.php?view=overdue" class="sidebar-link">⏰ 超时待办 <?= $overdueCount > 0 ? "($overdueCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>工单管理<?= $view === 'overdue' ? ' - 超时待办' : '' ?></h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 20px;">
            <div class="stat-card">
                <div class="stat-number"><?= $totalCount ?></div>
                <div class="stat-label">总工单数</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number"><?= $processingCount ?></div>
                <div class="stat-label">办理中</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number"><?= $completedCount ?></div>
                <div class="stat-label">已完成</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number"><?= $overdueCount ?></div>
                <div class="stat-label">超时待办</div>
            </div>
        </div>

        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <?php if ($view === 'overdue'): ?>
                <input type="hidden" name="view" value="overdue">
                <?php endif; ?>
                <select name="stage">
                    <option value="">全部阶段</option>
                    <option value="accepted" <?= $stage === 'accepted' ? 'selected' : '' ?>>已受理</option>
                    <option value="processing" <?= $stage === 'processing' ? 'selected' : '' ?>>处理中</option>
                    <option value="completed" <?= $stage === 'completed' ? 'selected' : '' ?>>已完成</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索工单号/留言/网格..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="work_orders.php" class="btn btn-secondary btn-sm">重置</a>
                <?php if ($view !== 'overdue'): ?>
                <a href="work_orders.php?view=overdue" class="btn btn-warning btn-sm">⏰ 超时待办 (<?= $overdueCount ?>)</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>工单号</th>
                        <th>关联留言</th>
                        <th>责任网格</th>
                        <th>阶段</th>
                        <th>预计完成</th>
                        <th>发起时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                    <tr><td colspan="7" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                    <?php $overdue = isWorkOrderOverdue($order); ?>
                    <tr>
                        <td class="td-time"><?= cleanInput($order['order_no']) ?></td>
                        <td class="td-title" title="<?= cleanInput($order['message_title'] ?? '留言已删除') ?>">
                            <?php if ($order['message_title']): ?>
                                <a href="../detail.php?id=<?= $order['message_id'] ?>" target="_blank"><?= cleanInput(mb_substr($order['message_title'], 0, 15)) ?></a>
                            <?php else: ?>
                                <span class="text-muted">留言已删除</span>
                            <?php endif; ?>
                        </td>
                        <td><?= cleanInput($order['grid_name']) ?><br><span class="text-muted"><?= cleanInput($order['worker_name']) ?></span></td>
                        <td><span class="stage-badge stage-<?= getWorkOrderStageClass($order['stage']) ?>"><?= getWorkOrderStageLabel($order['stage']) ?></span></td>
                        <td class="td-time">
                            <?= date('m-d', strtotime($order['expected_finish_at'])) ?>
                            <?php if ($overdue): ?><span class="stage-badge stage-overdue">已超时</span><?php endif; ?>
                        </td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($order['created_at'])) ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewWorkOrder(<?= $order['id'] ?>)">查看</button>
                            <?php if ($order['stage'] !== 'completed'): ?>
                            <button class="btn btn-xs btn-success" onclick="openStageModal(<?= $order['id'] ?>, '<?= $order['stage'] ?>')">更新进度</button>
                            <button class="btn btn-xs btn-warning" onclick="openReassignModal(<?= $order['id'] ?>, <?= $order['grid_id'] ?>)">重新指派</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php $qs = "&stage=$stage&view=$view&keyword=" . urlencode($keyword); ?>
            <?php if ($page > 1): ?>
            <a href="work_orders.php?page=<?= $page - 1 ?><?= $qs ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="work_orders.php?page=<?= $i ?><?= $qs ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="work_orders.php?page=<?= $page + 1 ?><?= $qs ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 工单详情弹窗 -->
<div class="modal" id="orderViewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>工单详情</h3>
            <button class="modal-close" onclick="closeOrderViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="orderViewBody">加载中...</div>
    </div>
</div>

<!-- 更新进度弹窗 -->
<div class="modal" id="stageModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>更新办理进度</h3>
            <button class="modal-close" onclick="closeStageModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="stageForm">
                <input type="hidden" id="stageOrderId">
                <div class="form-group">
                    <label for="stageSelect">办理阶段 <span class="required">*</span></label>
                    <select id="stageSelect">
                        <option value="accepted">已受理</option>
                        <option value="processing">处理中</option>
                        <option value="completed">已完成</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="stageNote">处理说明 <span class="required">*</span></label>
                    <textarea id="stageNote" rows="3" maxlength="500" placeholder="请填写本次进度处理说明（必填，将向居民公示）..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeStageModal()">取消</button>
                    <button type="submit" class="btn btn-primary" id="stageSubmitBtn">确认更新</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 重新指派弹窗 -->
<div class="modal" id="reassignModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>重新指派工单</h3>
            <button class="modal-close" onclick="closeReassignModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="reassignForm">
                <input type="hidden" id="reassignOrderId">
                <div class="form-group">
                    <label for="reassignGridId">指派到网格 <span class="required">*</span></label>
                    <select id="reassignGridId">
                        <?php foreach ($grids as $grid): ?>
                        <option value="<?= $grid['id'] ?>"><?= cleanInput($grid['name']) ?>（网格员：<?= cleanInput($grid['worker_name']) ?>）</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reassignNote">指派说明 <span class="text-muted">(可选)</span></label>
                    <textarea id="reassignNote" rows="3" maxlength="500" placeholder="可填写重新指派的原因..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeReassignModal()">取消</button>
                    <button type="submit" class="btn btn-primary" id="reassignSubmitBtn">确认指派</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function viewWorkOrder(id) {
    document.getElementById('orderViewModal').style.display = 'flex';
    document.getElementById('orderViewBody').innerHTML = '加载中...';
    fetch('api.php?action=work_order_detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const d = data.data;
            let html = '<div class="detail-view">';
            html += '<p><strong>工单号：</strong>' + d.order_no + '</p>';
            html += '<p><strong>当前阶段：</strong><span class="stage-badge stage-' + d.stage_class + '">' + d.stage_label + '</span>' + (d.overdue ? ' <span class="stage-badge stage-overdue">已超时</span>' : '') + '</p>';
            html += '<p><strong>责任网格：</strong>' + d.grid_name + '（网格员：' + d.worker_name + (d.worker_phone ? '，电话：' + d.worker_phone : '') + '）</p>';
            html += '<p><strong>预计完成时间：</strong>' + d.expected_finish_at + '</p>';
            if (d.completed_at) html += '<p><strong>实际完成时间：</strong>' + d.completed_at + '</p>';
            html += '<p><strong>发起时间：</strong>' + d.created_at + '</p>';
            html += '<hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">';
            html += '<h4 style="margin-bottom: 12px;">关联留言</h4>';
            if (d.message_exists) {
                html += '<p><strong>标题：</strong>' + d.message_title + '</p>';
                html += '<p><strong>作者：</strong>' + d.message_nickname + '</p>';
                html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.message_content + '</div>';
            } else {
                html += '<p class="text-muted">关联留言已被删除</p>';
            }
            html += '<hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">';
            html += '<h4 style="margin-bottom: 12px;">办理进度（处理说明）</h4>';
            if (d.logs.length === 0) {
                html += '<p class="text-muted">暂无进度记录</p>';
            } else {
                d.logs.forEach(function(log) {
                    html += '<div class="detail-text" style="margin-bottom:8px;">';
                    html += '<p><span class="stage-badge stage-' + log.stage_class + '">' + log.stage_label + '</span> <strong>' + log.operator_name + '</strong> <span class="text-muted">' + log.created_at + '</span></p>';
                    html += '<div>' + log.note + '</div>';
                    html += '</div>';
                });
            }
            html += '</div>';
            document.getElementById('orderViewBody').innerHTML = html;
        } else {
            document.getElementById('orderViewBody').innerHTML = data.msg;
        }
    });
}

function closeOrderViewModal() {
    document.getElementById('orderViewModal').style.display = 'none';
}

function openStageModal(id, currentStage) {
    document.getElementById('stageOrderId').value = id;
    document.getElementById('stageSelect').value = currentStage === 'completed' ? 'completed' : (currentStage === 'processing' ? 'processing' : 'accepted');
    document.getElementById('stageNote').value = '';
    document.getElementById('stageModal').style.display = 'flex';
}

function closeStageModal() {
    document.getElementById('stageModal').style.display = 'none';
}

document.getElementById('stageForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const note = document.getElementById('stageNote').value.trim();
    if (!note) {
        alert('请填写处理说明');
        return;
    }
    const btn = document.getElementById('stageSubmitBtn');
    btn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'update_work_order_stage');
    formData.append('id', document.getElementById('stageOrderId').value);
    formData.append('stage', document.getElementById('stageSelect').value);
    formData.append('note', note);

    fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('进度已更新');
            location.reload();
        } else {
            alert(data.msg);
            btn.disabled = false;
        }
    })
    .catch(() => {
        alert('网络错误，请重试');
        btn.disabled = false;
    });
});

function openReassignModal(id, currentGridId) {
    document.getElementById('reassignOrderId').value = id;
    document.getElementById('reassignGridId').value = currentGridId;
    document.getElementById('reassignNote').value = '';
    document.getElementById('reassignModal').style.display = 'flex';
}

function closeReassignModal() {
    document.getElementById('reassignModal').style.display = 'none';
}

document.getElementById('reassignForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('reassignSubmitBtn');
    btn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'reassign_work_order');
    formData.append('id', document.getElementById('reassignOrderId').value);
    formData.append('grid_id', document.getElementById('reassignGridId').value);
    formData.append('note', document.getElementById('reassignNote').value.trim());

    fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('重新指派成功');
            location.reload();
        } else {
            alert(data.msg);
            btn.disabled = false;
        }
    })
    .catch(() => {
        alert('网络错误，请重试');
        btn.disabled = false;
    });
});

document.getElementById('orderViewModal').addEventListener('click', function(e) {
    if (e.target === this) closeOrderViewModal();
});
document.getElementById('stageModal').addEventListener('click', function(e) {
    if (e.target === this) closeStageModal();
});
document.getElementById('reassignModal').addEventListener('click', function(e) {
    if (e.target === this) closeReassignModal();
});
</script>
