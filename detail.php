<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// 增加浏览量
$db->prepare("UPDATE messages SET views = views + 1 WHERE id = ?")->execute([$id]);

// 获取详情
$stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND status = 1");
$stmt->execute([$id]);
$msg = $stmt->fetch();

if (!$msg) {
    header('Location: index.php');
    exit;
}

// 求助类留言：查询关联工单与可选网格（工单数据与工单列表、工单详情读取同一数据源，保证一致）
$workOrder = null;
$grids = [];
if ($msg['type'] === 'help') {
    $workOrder = getWorkOrderByMessageId($msg['id']);
    if (!$workOrder) {
        $grids = getAllGrids();
    }
}

$pageTitle = cleanInput($msg['title']) . ' - 社区便民留言板';
$currentPage = '';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

include __DIR__ . '/includes/header.php';
?>

<section class="detail-section">
    <div class="container">
        <div class="detail-card">
            <div class="detail-header">
                <span class="card-type type-<?= $msg['type'] ?>"><?= getTypeIcon($msg['type']) ?> <?= getTypeLabel($msg['type']) ?></span>
                <div class="detail-meta">
                    <span>👤 <?= cleanInput($msg['nickname']) ?></span>
                    <span>🕐 <?= $msg['created_at'] ?></span>
                    <span>👁 <?= $msg['views'] ?> 次浏览</span>
                </div>
            </div>

            <h1 class="detail-title"><?= cleanInput($msg['title']) ?></h1>

            <div class="detail-content">
                <?= nl2br(cleanInput($msg['content'])) ?>
            </div>

            <?php if ($msg['image']): ?>
            <div class="detail-image">
                <img src="<?= cleanInput($msg['image']) ?>" alt="留言图片" onclick="window.open(this.src)">
            </div>
            <?php endif; ?>

            <?php if ($msg['phone']): ?>
            <div class="detail-contact">
                <span>📞 联系方式：<?= cleanInput($msg['phone']) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($msg['type'] === 'help'): ?>
            <div class="work-order-section">
                <?php if ($workOrder): ?>
                <?php $overdue = isWorkOrderOverdue($workOrder); ?>
                <!-- 已发起工单：展示责任网格、当前阶段、预计完成时间 -->
                <div class="work-order-card work-order-status-card">
                    <div class="work-order-card-header">
                        <span class="work-order-no">📋 工单号：<?= cleanInput($workOrder['order_no']) ?></span>
                        <span class="stage-badge stage-<?= getWorkOrderStageClass($workOrder['stage']) ?>"><?= getWorkOrderStageLabel($workOrder['stage']) ?></span>
                    </div>
                    <div class="work-order-meta">
                        <span>🏘 责任网格：<?= cleanInput($workOrder['grid_name']) ?>（<?= cleanInput($workOrder['worker_name']) ?>）</span>
                        <span class="<?= $overdue ? 'text-overdue' : '' ?>">
                            ⏰ 预计完成：<?= date('Y-m-d', strtotime($workOrder['expected_finish_at'])) ?>
                            <?php if ($overdue): ?><span class="stage-badge stage-overdue">已超时</span><?php endif; ?>
                        </span>
                    </div>
                    <a href="work_order_detail.php?id=<?= $workOrder['id'] ?>" class="btn btn-sm btn-info">查看工单进度</a>
                </div>
                <?php else: ?>
                <!-- 未发起工单：可发起办理 -->
                <div class="work-order-entry">
                    <button class="btn btn-primary" onclick="openWorkOrderModal()">🛎 发起办理</button>
                    <span class="text-muted">发起后生成服务工单，可跟踪责任网格、办理阶段和预计完成时间</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="detail-actions">
                <a href="index.php" class="btn btn-secondary">← 返回列表</a>
                <?php $isFav = isFavorited($msg['id']); ?>
                <button class="btn favorite-detail-btn <?= $isFav ? 'btn-warning' : 'btn-secondary' ?>" data-message-id="<?= $msg['id'] ?>" onclick="toggleFavorite(event, this)">
                    <span class="favorite-icon"><?= $isFav ? '⭐' : '☆' ?></span>
                    <span class="favorite-text"><?= $isFav ? '已收藏' : '收藏' ?></span>
                </button>
                <?php $hasReported = hasReported($msg['id']); ?>
                <button class="btn <?= $hasReported ? 'btn-secondary' : 'btn-danger' ?> report-btn" data-message-id="<?= $msg['id'] ?>" onclick="openReportModal(<?= $msg['id'] ?>)" <?= $hasReported ? 'disabled' : '' ?>>
                    <span>🚩</span>
                    <span class="report-text"><?= $hasReported ? '已举报' : '举报' ?></span>
                </button>
                <a href="submit.php" class="btn btn-primary">发布留言</a>
            </div>
        </div>
    </div>
</section>

<!-- 举报弹窗 -->
<div class="modal" id="reportModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🚩 举报留言</h3>
            <button class="modal-close" onclick="closeReportModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="reportForm">
                <input type="hidden" id="reportMessageId" name="message_id">
                <div class="form-group">
                    <label>举报类型 <span class="required">*</span></label>
                    <div class="report-type-options">
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="spam" required>
                            <span>🗑️ 垃圾信息</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="abuse">
                            <span>😡 辱骂攻击</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="illegal">
                            <span>⚖️ 违法违规</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="porn">
                            <span>🔞 色情低俗</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="other">
                            <span>📝 其他</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="reportDescription">补充说明 <span class="text-muted">(可选，最多500字)</span></label>
                    <textarea id="reportDescription" name="description" rows="4" maxlength="500" placeholder="请描述具体的违规内容，帮助我们更好地处理..."></textarea>
                    <span class="char-count"><span id="reportDescCount">0</span>/500</span>
                </div>
                <div class="form-tip">
                    <p>⚠️ 恶意举报将被限制功能使用，请如实填写举报内容。</p>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeReportModal()">取消</button>
                    <button type="submit" class="btn btn-danger" id="reportSubmitBtn">提交举报</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($msg['type'] === 'help' && !$workOrder): ?>
<!-- 发起办理弹窗 -->
<div class="modal" id="workOrderModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🛎 发起办理</h3>
            <button class="modal-close" onclick="closeWorkOrderModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="workOrderForm">
                <input type="hidden" id="woMessageId" value="<?= $msg['id'] ?>">
                <div class="form-group">
                    <label for="woGridId">责任网格 <span class="required">*</span></label>
                    <select id="woGridId" required>
                        <option value="">请选择所属网格</option>
                        <?php foreach ($grids as $grid): ?>
                        <option value="<?= $grid['id'] ?>"><?= cleanInput($grid['name']) ?>（网格员：<?= cleanInput($grid['worker_name']) ?>）</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="woExpectedFinish">预计完成时间 <span class="required">*</span></label>
                    <input type="date" id="woExpectedFinish" min="<?= date('Y-m-d') ?>" required>
                    <span class="text-muted">约定完成时限，超时未完成的工单将进入待办并督促处理</span>
                </div>
                <div class="form-group">
                    <label for="woNote">补充说明 <span class="text-muted">(可选，最多500字)</span></label>
                    <textarea id="woNote" rows="3" maxlength="500" placeholder="可补充具体位置、联系人等信息..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeWorkOrderModal()">取消</button>
                    <button type="submit" class="btn btn-primary" id="woSubmitBtn">确认发起</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    const messageId = document.getElementById('woMessageId').value;
    const draftKey = 'workOrderDraft_' + messageId;
    const gridInput = document.getElementById('woGridId');
    const dateInput = document.getElementById('woExpectedFinish');
    const noteInput = document.getElementById('woNote');

    // 网络中断或页面关闭后，已填写内容保存在本地，重新进入时自动恢复
    function saveDraft() {
        const draft = {
            grid_id: gridInput.value,
            expected_finish_at: dateInput.value,
            note: noteInput.value
        };
        try { localStorage.setItem(draftKey, JSON.stringify(draft)); } catch (e) {}
    }

    function restoreDraft() {
        let draft = null;
        try { draft = JSON.parse(localStorage.getItem(draftKey) || 'null'); } catch (e) {}
        if (!draft) return;
        if (draft.grid_id) gridInput.value = draft.grid_id;
        if (draft.expected_finish_at) dateInput.value = draft.expected_finish_at;
        if (draft.note) noteInput.value = draft.note;
    }

    function clearDraft() {
        try { localStorage.removeItem(draftKey); } catch (e) {}
    }

    [gridInput, dateInput, noteInput].forEach(function(el) {
        el.addEventListener('input', saveDraft);
        el.addEventListener('change', saveDraft);
    });

    window.openWorkOrderModal = function() {
        restoreDraft();
        document.getElementById('workOrderModal').style.display = 'flex';
    };

    window.closeWorkOrderModal = function() {
        document.getElementById('workOrderModal').style.display = 'none';
    };

    document.getElementById('workOrderModal').addEventListener('click', function(e) {
        if (e.target === this) closeWorkOrderModal();
    });

    document.getElementById('workOrderForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('woSubmitBtn');
        btn.disabled = true;
        btn.textContent = '提交中...';

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('message_id', messageId);
        formData.append('grid_id', gridInput.value);
        formData.append('expected_finish_at', dateInput.value);
        formData.append('note', noteInput.value);

        fetch('api/work_order.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.code === 0) {
                clearDraft();
                showToast(data.msg, 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(data.msg || '发起失败，请重试', 'error');
                btn.disabled = false;
                btn.textContent = '确认发起';
            }
        })
        .catch(() => {
            // 网络中断：草稿已保存在本地，重试不会丢失已填写内容
            saveDraft();
            showToast('网络错误，已填写内容已保留，请重试', 'error');
            btn.disabled = false;
            btn.textContent = '确认发起';
        });
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
