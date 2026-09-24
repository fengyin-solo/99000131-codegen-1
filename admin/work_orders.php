<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '工单管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

// 筛选参数
$view = $_GET['view'] ?? '';
$stage = $_GET['stage'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

$where = "WHERE 1=1";
$params = [];

// 待办视图：超过约定时限仍未完成的工单
if ($view === 'todo') {
    $where .= " AND w.stage < 3 AND w.expected_finish_at IS NOT NULL AND w.expected_finish_at < NOW()";
}
if ($stage !== '' && in_array($stage, ['0', '1', '2', '3'])) {
    $where .= " AND w.stage = ?";
    $params[] = intval($stage);
}
if ($keyword) {
    $where .= " AND (w.order_no LIKE ? OR m.title LIKE ? OR m.nickname LIKE ?)";
    $kw = "%$keyword%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM work_orders w INNER JOIN messages m ON w.message_id = m.id $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

$sql = "SELECT w.*, m.title AS message_title, m.nickname AS message_nickname, g.name AS grid_name, gw.name AS worker_name
        FROM work_orders w
        INNER JOIN messages m ON w.message_id = m.id
        LEFT JOIN grids g ON w.grid_id = g.id
        LEFT JOIN grid_workers gw ON w.worker_id = gw.id
        $where
        ORDER BY w.created_at DESC
        LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// 统计
$totalOrderCount = $db->query("SELECT COUNT(*) FROM work_orders")->fetchColumn();
$pendingOrderCount = $db->query("SELECT COUNT(*) FROM work_orders WHERE stage = 0")->fetchColumn();
$doingOrderCount = $db->query("SELECT COUNT(*) FROM work_orders WHERE stage IN (1, 2)")->fetchColumn();
$doneOrderCount = $db->query("SELECT COUNT(*) FROM work_orders WHERE stage = 3")->fetchColumn();
$todoCount = getTodoWorkOrderCount();

// 网格员(按网格分组，用于指派/重新指派)
$workers = getAllGridWorkers();
$workersByGrid = [];
foreach ($workers as $w) {
    $workersByGrid[$w['grid_name']][] = $w;
}

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
            <a href="work_orders.php" class="sidebar-link active">🛠️ 工单管理</a>
            <a href="work_orders.php?view=todo" class="sidebar-link">⏰ 超时待办 <?= $todoCount > 0 ? "($todoCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2><?= $view === 'todo' ? '超时待办工单' : '工单管理' ?></h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <div class="stats-grid" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 20px;">
            <div class="stat-card">
                <div class="stat-number"><?= $totalOrderCount ?></div>
                <div class="stat-label">全部工单</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number"><?= $pendingOrderCount ?></div>
                <div class="stat-label">待受理</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number"><?= $doingOrderCount ?></div>
                <div class="stat-label">办理中</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number"><?= $doneOrderCount ?></div>
                <div class="stat-label">已办结</div>
            </div>
            <div class="stat-card" style="border-top-color: var(--danger);">
                <div class="stat-number"><?= $todoCount ?></div>
                <div class="stat-label">⏰ 超时待办</div>
            </div>
        </div>

        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <?php if ($view === 'todo'): ?>
                <input type="hidden" name="view" value="todo">
                <?php endif; ?>
                <select name="stage">
                    <option value="">全部阶段</option>
                    <option value="0" <?= $stage === '0' ? 'selected' : '' ?>>待受理</option>
                    <option value="1" <?= $stage === '1' ? 'selected' : '' ?>>已受理</option>
                    <option value="2" <?= $stage === '2' ? 'selected' : '' ?>>处理中</option>
                    <option value="3" <?= $stage === '3' ? 'selected' : '' ?>>已办结</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索工单号/留言标题/昵称..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="work_orders.php" class="btn btn-secondary btn-sm">重置</a>
                <?php if ($view !== 'todo'): ?>
                <a href="work_orders.php?view=todo" class="btn btn-danger btn-sm">⏰ 超时待办 (<?= $todoCount ?>)</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>工单编号</th>
                        <th>关联留言</th>
                        <th>发起人</th>
                        <th>责任网格</th>
                        <th>责任人</th>
                        <th>阶段</th>
                        <th>预计完成</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                    <tr><td colspan="8" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                    <?php $overdue = isWorkOrderOverdue($order); ?>
                    <tr class="<?= $overdue ? 'overdue-row' : '' ?>">
                        <td class="td-time"><?= cleanInput($order['order_no']) ?></td>
                        <td class="td-title" title="<?= cleanInput($order['message_title']) ?>">
                            <a href="../detail.php?id=<?= $order['message_id'] ?>" target="_blank"><?= cleanInput(mb_substr($order['message_title'], 0, 12)) ?></a>
                        </td>
                        <td><?= cleanInput($order['message_nickname']) ?></td>
                        <td><?= $order['grid_name'] ? cleanInput($order['grid_name']) : '<span class="text-muted">待分配</span>' ?></td>
                        <td><?= $order['worker_name'] ? cleanInput($order['worker_name']) : '<span class="text-muted">待分配</span>' ?></td>
                        <td>
                            <span class="stage-badge stage-<?= getWorkOrderStageClass($order['stage']) ?>"><?= getWorkOrderStageLabel($order['stage']) ?></span>
                            <?php if ($overdue): ?>
                            <span class="overdue-badge">超时</span>
                            <?php endif; ?>
                        </td>
                        <td class="td-time<?= $overdue ? ' wo-overdue-text' : '' ?>">
                            <?= $order['expected_finish_at'] ? date('m-d H:i', strtotime($order['expected_finish_at'])) : '-' ?>
                        </td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewWorkOrder(<?= $order['id'] ?>)">查看</button>
                            <?php if (intval($order['stage']) === 0): ?>
                            <button class="btn btn-xs btn-success" onclick="openAssignModal(<?= $order['id'] ?>)">受理指派</button>
                            <?php endif; ?>
                            <?php if (in_array(intval($order['stage']), [1, 2])): ?>
                            <button class="btn btn-xs btn-primary" onclick="openProgressModal(<?= $order['id'] ?>, <?= intval($order['stage']) ?>)">更新进度</button>
                            <button class="btn btn-xs btn-warning" onclick="openReassignModal(<?= $order['id'] ?>)">重新指派</button>
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
            <?php $qs = "view=$view&stage=$stage&keyword=" . urlencode($keyword); ?>
            <?php if ($page > 1): ?>
            <a href="work_orders.php?page=<?= $page - 1 ?>&<?= $qs ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="work_orders.php?page=<?= $i ?>&<?= $qs ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="work_orders.php?page=<?= $page + 1 ?>&<?= $qs ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 工单详情弹窗 -->
<div class="modal" id="woViewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>工单详情</h3>
            <button class="modal-close" onclick="closeWoViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="woViewBody">加载中...</div>
    </div>
</div>

<!-- 受理指派弹窗 -->
<div class="modal" id="assignModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>受理并指派工单</h3>
            <button class="modal-close" onclick="closeAssignModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="assignOrderId">
            <div class="form-group">
                <label for="assignWorker">指派网格员 <span class="required">*</span></label>
                <select id="assignWorker">
                    <option value="">请选择网格员</option>
                    <?php foreach ($workersByGrid as $gridName => $gridWorkers): ?>
                    <optgroup label="<?= cleanInput($gridName) ?>">
                        <?php foreach ($gridWorkers as $w): ?>
                        <option value="<?= $w['id'] ?>"><?= cleanInput($w['name']) ?><?= $w['phone'] ? ' (' . cleanInput($w['phone']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="assignExpected">预计完成时间(约定时限) <span class="required">*</span></label>
                <input type="datetime-local" id="assignExpected">
            </div>
            <div class="form-group">
                <label for="assignNote">处理说明 <span class="required">*</span></label>
                <textarea id="assignNote" rows="3" maxlength="500" placeholder="请填写受理说明，将同步展示给居民..."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAssignModal()">取消</button>
                <button type="button" class="btn btn-success" id="assignSubmitBtn" onclick="submitAssign()">确认受理</button>
            </div>
        </div>
    </div>
</div>

<!-- 更新进度弹窗 -->
<div class="modal" id="progressModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>更新办理进度</h3>
            <button class="modal-close" onclick="closeProgressModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="progressOrderId">
            <div class="form-group">
                <label for="progressStage">办理阶段 <span class="required">*</span></label>
                <select id="progressStage">
                    <option value="2">处理中</option>
                    <option value="3">已办结</option>
                </select>
            </div>
            <div class="form-group" id="resultGroup" style="display:none;">
                <label for="progressResult">办理结果 <span class="required">*</span></label>
                <textarea id="progressResult" rows="2" maxlength="500" placeholder="请填写办理结果，将展示在留言详情中..."></textarea>
            </div>
            <div class="form-group">
                <label for="progressNote">处理说明 <span class="required">*</span></label>
                <textarea id="progressNote" rows="3" maxlength="500" placeholder="请填写本次进度说明，将同步展示给居民..."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeProgressModal()">取消</button>
                <button type="button" class="btn btn-primary" id="progressSubmitBtn" onclick="submitProgress()">确认更新</button>
            </div>
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
            <input type="hidden" id="reassignOrderId">
            <div class="form-group">
                <label for="reassignWorker">新责任人 <span class="required">*</span></label>
                <select id="reassignWorker">
                    <option value="">请选择网格员</option>
                    <?php foreach ($workersByGrid as $gridName => $gridWorkers): ?>
                    <optgroup label="<?= cleanInput($gridName) ?>">
                        <?php foreach ($gridWorkers as $w): ?>
                        <option value="<?= $w['id'] ?>"><?= cleanInput($w['name']) ?><?= $w['phone'] ? ' (' . cleanInput($w['phone']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="reassignExpected">新的预计完成时间 <span class="text-muted">(可选，不填则保持原约定)</span></label>
                <input type="datetime-local" id="reassignExpected">
            </div>
            <div class="form-group">
                <label for="reassignNote">处理说明 <span class="required">*</span></label>
                <textarea id="reassignNote" rows="3" maxlength="500" placeholder="请说明重新指派的原因..."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeReassignModal()">取消</button>
                <button type="button" class="btn btn-warning" id="reassignSubmitBtn" onclick="submitReassign()">确认指派</button>
            </div>
        </div>
    </div>
</div>

<script>
/* ========== 草稿存取：网络中断后重试不丢失已填写内容 ========== */
function saveWoDraft(key, data) {
    try { localStorage.setItem(key, JSON.stringify(data)); } catch (e) {}
}
function loadWoDraft(key) {
    try { return JSON.parse(localStorage.getItem(key)) || null; } catch (e) { return null; }
}
function clearWoDraft(key) {
    localStorage.removeItem(key);
}

/* ========== 工单详情 ========== */
function viewWorkOrder(id) {
    document.getElementById('woViewModal').style.display = 'flex';
    document.getElementById('woViewBody').innerHTML = '加载中...';
    fetch('api.php?action=work_order_detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const d = data.data;
            let html = '<div class="detail-view">';
            html += '<p><strong>工单编号：</strong>' + d.order_no + '</p>';
            html += '<p><strong>关联留言：</strong><a href="../detail.php?id=' + d.message_id + '" target="_blank">' + d.message_title + '</a></p>';
            html += '<p><strong>发起人：</strong>' + d.message_nickname + '</p>';
            html += '<p><strong>当前阶段：</strong><span class="stage-badge stage-' + d.stage_class + '">' + d.stage_label + '</span>';
            if (d.overdue) html += ' <span class="overdue-badge">⏰ 已超时</span>';
            html += '</p>';
            html += '<p><strong>责任网格：</strong>' + (d.grid_name || '待分配') + '</p>';
            html += '<p><strong>责任人：</strong>' + (d.worker_name || '待分配') + '</p>';
            html += '<p><strong>预计完成：</strong>' + (d.expected_finish_at || '受理后约定') + '</p>';
            html += '<p><strong>实际完成：</strong>' + (d.finished_at || '未完成') + '</p>';
            if (d.result) {
                html += '<p><strong>办理结果：</strong></p><div class="detail-text">' + d.result + '</div>';
            }
            html += '<hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">';
            html += '<h4 style="margin-bottom: 12px;">办理进度</h4>';
            if (d.logs.length === 0) {
                html += '<p class="text-muted">暂无进度记录</p>';
            } else {
                html += '<ul class="timeline">';
                d.logs.forEach(function(log) {
                    html += '<li class="timeline-item">';
                    html += '<div class="timeline-stage"><span class="stage-badge stage-' + log.stage_class + '">' + log.stage_label + '</span></div>';
                    html += '<div class="timeline-note">' + log.note + '</div>';
                    html += '<div class="timeline-meta">' + log.operator + ' · ' + log.created_at + '</div>';
                    html += '</li>';
                });
                html += '</ul>';
            }
            html += '</div>';
            document.getElementById('woViewBody').innerHTML = html;
        } else {
            document.getElementById('woViewBody').innerHTML = data.msg;
        }
    })
    .catch(() => {
        document.getElementById('woViewBody').innerHTML = '网络错误，请稍后重试';
    });
}

function closeWoViewModal() {
    document.getElementById('woViewModal').style.display = 'none';
}

/* ========== 受理指派 ========== */
function openAssignModal(id) {
    document.getElementById('assignOrderId').value = id;
    const draft = loadWoDraft('wo_assign_' + id);
    document.getElementById('assignWorker').value = draft ? (draft.worker_id || '') : '';
    document.getElementById('assignExpected').value = draft ? (draft.expected || '') : '';
    document.getElementById('assignNote').value = draft ? (draft.note || '') : '';
    document.getElementById('assignModal').style.display = 'flex';
}

function closeAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
}

function collectAssignDraft() {
    const id = document.getElementById('assignOrderId').value;
    saveWoDraft('wo_assign_' + id, {
        worker_id: document.getElementById('assignWorker').value,
        expected: document.getElementById('assignExpected').value,
        note: document.getElementById('assignNote').value
    });
}

function submitAssign() {
    const id = document.getElementById('assignOrderId').value;
    const workerId = document.getElementById('assignWorker').value;
    const expected = document.getElementById('assignExpected').value;
    const note = document.getElementById('assignNote').value.trim();

    if (!workerId) { alert('请选择网格员'); return; }
    if (!expected) { alert('请选择预计完成时间'); return; }
    if (!note) { alert('请填写处理说明'); return; }

    const btn = document.getElementById('assignSubmitBtn');
    btn.disabled = true;
    btn.textContent = '提交中...';

    const formData = new FormData();
    formData.append('action', 'assign_order');
    formData.append('id', id);
    formData.append('worker_id', workerId);
    formData.append('expected_finish_at', expected);
    formData.append('note', note);

    fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            clearWoDraft('wo_assign_' + id);
            alert('受理成功');
            location.reload();
        } else {
            alert(data.msg);
            btn.disabled = false;
            btn.textContent = '确认受理';
        }
    })
    .catch(() => {
        alert('网络错误，已保留填写内容，请重试');
        btn.disabled = false;
        btn.textContent = '确认受理';
    });
}

/* ========== 更新进度 ========== */
function openProgressModal(id, currentStage) {
    document.getElementById('progressOrderId').value = id;
    const draft = loadWoDraft('wo_progress_' + id);
    const defaultStage = currentStage >= 2 ? '3' : '2';
    const stageSelect = document.getElementById('progressStage');
    stageSelect.value = draft ? (draft.stage || defaultStage) : defaultStage;
    document.getElementById('progressNote').value = draft ? (draft.note || '') : '';
    document.getElementById('progressResult').value = draft ? (draft.result || '') : '';
    toggleResultGroup();
    document.getElementById('progressModal').style.display = 'flex';
}

function closeProgressModal() {
    document.getElementById('progressModal').style.display = 'none';
}

function toggleResultGroup() {
    const stage = document.getElementById('progressStage').value;
    document.getElementById('resultGroup').style.display = stage === '3' ? 'block' : 'none';
}

function collectProgressDraft() {
    const id = document.getElementById('progressOrderId').value;
    saveWoDraft('wo_progress_' + id, {
        stage: document.getElementById('progressStage').value,
        note: document.getElementById('progressNote').value,
        result: document.getElementById('progressResult').value
    });
}

function submitProgress() {
    const id = document.getElementById('progressOrderId').value;
    const stage = document.getElementById('progressStage').value;
    const note = document.getElementById('progressNote').value.trim();
    const result = document.getElementById('progressResult').value.trim();

    if (!note) { alert('请填写处理说明'); return; }
    if (stage === '3' && !result) { alert('办结时请填写办理结果'); return; }

    const btn = document.getElementById('progressSubmitBtn');
    btn.disabled = true;
    btn.textContent = '提交中...';

    const formData = new FormData();
    formData.append('action', 'update_stage');
    formData.append('id', id);
    formData.append('stage', stage);
    formData.append('note', note);
    formData.append('result', result);

    fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            clearWoDraft('wo_progress_' + id);
            alert('进度已更新');
            location.reload();
        } else {
            alert(data.msg);
            btn.disabled = false;
            btn.textContent = '确认更新';
        }
    })
    .catch(() => {
        alert('网络错误，已保留填写内容，请重试');
        btn.disabled = false;
        btn.textContent = '确认更新';
    });
}

/* ========== 重新指派 ========== */
function openReassignModal(id) {
    document.getElementById('reassignOrderId').value = id;
    const draft = loadWoDraft('wo_reassign_' + id);
    document.getElementById('reassignWorker').value = draft ? (draft.worker_id || '') : '';
    document.getElementById('reassignExpected').value = draft ? (draft.expected || '') : '';
    document.getElementById('reassignNote').value = draft ? (draft.note || '') : '';
    document.getElementById('reassignModal').style.display = 'flex';
}

function closeReassignModal() {
    document.getElementById('reassignModal').style.display = 'none';
}

function collectReassignDraft() {
    const id = document.getElementById('reassignOrderId').value;
    saveWoDraft('wo_reassign_' + id, {
        worker_id: document.getElementById('reassignWorker').value,
        expected: document.getElementById('reassignExpected').value,
        note: document.getElementById('reassignNote').value
    });
}

function submitReassign() {
    const id = document.getElementById('reassignOrderId').value;
    const workerId = document.getElementById('reassignWorker').value;
    const expected = document.getElementById('reassignExpected').value;
    const note = document.getElementById('reassignNote').value.trim();

    if (!workerId) { alert('请选择新责任人'); return; }
    if (!note) { alert('请填写处理说明'); return; }

    const btn = document.getElementById('reassignSubmitBtn');
    btn.disabled = true;
    btn.textContent = '提交中...';

    const formData = new FormData();
    formData.append('action', 'reassign_order');
    formData.append('id', id);
    formData.append('worker_id', workerId);
    formData.append('expected_finish_at', expected);
    formData.append('note', note);

    fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            clearWoDraft('wo_reassign_' + id);
            alert('重新指派成功');
            location.reload();
        } else {
            alert(data.msg);
            btn.disabled = false;
            btn.textContent = '确认指派';
        }
    })
    .catch(() => {
        alert('网络错误，已保留填写内容，请重试');
        btn.disabled = false;
        btn.textContent = '确认指派';
    });
}

/* ========== 事件绑定 ========== */
document.addEventListener('DOMContentLoaded', function() {
    // 输入时自动保存草稿
    ['assignWorker', 'assignExpected', 'assignNote'].forEach(function(elId) {
        document.getElementById(elId).addEventListener('input', collectAssignDraft);
        document.getElementById(elId).addEventListener('change', collectAssignDraft);
    });
    ['progressStage', 'progressNote', 'progressResult'].forEach(function(elId) {
        document.getElementById(elId).addEventListener('input', collectProgressDraft);
        document.getElementById(elId).addEventListener('change', collectProgressDraft);
    });
    ['reassignWorker', 'reassignExpected', 'reassignNote'].forEach(function(elId) {
        document.getElementById(elId).addEventListener('input', collectReassignDraft);
        document.getElementById(elId).addEventListener('change', collectReassignDraft);
    });

    document.getElementById('progressStage').addEventListener('change', toggleResultGroup);

    // 点击遮罩关闭弹窗
    document.getElementById('woViewModal').addEventListener('click', function(e) {
        if (e.target === this) closeWoViewModal();
    });
    document.getElementById('assignModal').addEventListener('click', function(e) {
        if (e.target === this) closeAssignModal();
    });
    document.getElementById('progressModal').addEventListener('click', function(e) {
        if (e.target === this) closeProgressModal();
    });
    document.getElementById('reassignModal').addEventListener('click', function(e) {
        if (e.target === this) closeReassignModal();
    });
});
</script>
