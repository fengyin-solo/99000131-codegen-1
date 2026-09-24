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
            <?php $workOrder = getWorkOrderByMessage($msg['id']); ?>
            <div class="work-order-card">
                <?php if ($workOrder): ?>
                <?php $overdue = isWorkOrderOverdue($workOrder); ?>
                <div class="wo-card-header">
                    <h3>📋 服务工单 <?= cleanInput($workOrder['order_no']) ?></h3>
                    <span class="stage-badge stage-<?= getWorkOrderStageClass($workOrder['stage']) ?>"><?= getWorkOrderStageLabel($workOrder['stage']) ?></span>
                    <?php if ($overdue): ?>
                    <span class="overdue-badge">⏰ 已超时</span>
                    <?php endif; ?>
                </div>
                <div class="wo-info-grid">
                    <div class="wo-info-item">
                        <div class="wo-label">🏘️ 责任网格</div>
                        <div class="wo-value"><?= $workOrder['grid_name'] ? cleanInput($workOrder['grid_name']) : '待分配' ?></div>
                    </div>
                    <div class="wo-info-item">
                        <div class="wo-label">👷 责任人</div>
                        <div class="wo-value"><?= $workOrder['worker_name'] ? cleanInput($workOrder['worker_name']) : '待分配' ?></div>
                    </div>
                    <div class="wo-info-item">
                        <div class="wo-label">🕐 预计完成时间</div>
                        <div class="wo-value<?= $overdue ? ' wo-overdue-text' : '' ?>">
                            <?= $workOrder['expected_finish_at'] ? date('Y-m-d H:i', strtotime($workOrder['expected_finish_at'])) : '受理后约定' ?>
                        </div>
                    </div>
                </div>
                <?php if (intval($workOrder['stage']) === 3 && $workOrder['result']): ?>
                <div class="wo-result-box">
                    <strong>✅ 办理结果：</strong><?= nl2br(cleanInput($workOrder['result'])) ?>
                </div>
                <?php endif; ?>
                <a href="work_order.php?id=<?= $workOrder['id'] ?>" class="btn btn-sm btn-info">查看办理进度</a>
                <?php else: ?>
                <div class="wo-card-header">
                    <h3>🛠️ 居民服务工单</h3>
                </div>
                <p class="wo-card-tip">该求助尚未发起办理，发起后将由责任网格跟进处理，可随时跟踪办理进度。</p>
                <button type="button" class="btn btn-primary btn-sm" onclick="openWorkOrderModal()">发起办理</button>
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

<?php if ($msg['type'] === 'help' && empty($workOrder)): ?>
<!-- 发起办理弹窗 -->
<div class="modal" id="workOrderModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🛠️ 发起办理</h3>
            <button class="modal-close" onclick="closeWorkOrderModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-tip">
                <p>📋 将为该求助留言生成服务工单，由责任网格跟进办理。同一留言重复发起会自动合并为一张工单。</p>
            </div>
            <div class="form-group">
                <label for="woNote">补充说明 <span class="text-muted">(可选，最多500字)</span></label>
                <textarea id="woNote" rows="4" maxlength="500" placeholder="可补充具体位置、联系方式等信息，方便网格员处理..."></textarea>
                <span class="char-count"><span id="woNoteCount">0</span>/500</span>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeWorkOrderModal()">取消</button>
                <button type="button" class="btn btn-primary" id="woSubmitBtn" onclick="submitWorkOrder(<?= $msg['id'] ?>)">确认发起</button>
            </div>
        </div>
    </div>
</div>

<script>
// 草稿key：网络中断后重试不丢失已填写的内容
const WO_DRAFT_KEY = 'wo_draft_<?= $msg['id'] ?>';

document.addEventListener('DOMContentLoaded', function() {
    const noteEl = document.getElementById('woNote');
    if (!noteEl) return;

    // 恢复上次未提交的草稿
    const draft = localStorage.getItem(WO_DRAFT_KEY);
    if (draft) {
        noteEl.value = draft;
        document.getElementById('woNoteCount').textContent = draft.length;
    }

    // 输入时实时保存草稿
    noteEl.addEventListener('input', function() {
        document.getElementById('woNoteCount').textContent = this.value.length;
        localStorage.setItem(WO_DRAFT_KEY, this.value);
    });

    document.getElementById('workOrderModal').addEventListener('click', function(e) {
        if (e.target === this) closeWorkOrderModal();
    });
});

function openWorkOrderModal() {
    document.getElementById('workOrderModal').style.display = 'flex';
}

function closeWorkOrderModal() {
    document.getElementById('workOrderModal').style.display = 'none';
}

function submitWorkOrder(messageId) {
    const btn = document.getElementById('woSubmitBtn');
    const note = document.getElementById('woNote').value;
    btn.disabled = true;
    btn.textContent = '提交中...';

    const formData = new FormData();
    formData.append('action', 'initiate');
    formData.append('message_id', messageId);
    formData.append('note', note);

    fetch('api/work_order.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            // 成功后清除草稿
            localStorage.removeItem(WO_DRAFT_KEY);
            showToast(data.msg, 'success');
            setTimeout(function() {
                window.location.href = 'work_order.php?id=' + data.data.order_id;
            }, 800);
        } else {
            showToast(data.msg || '发起失败', 'error');
            btn.disabled = false;
            btn.textContent = '确认发起';
        }
    })
    .catch(function() {
        // 网络异常：保留已填写内容，恢复网络后可直接重试
        showToast('网络错误，已保留填写内容，请重试', 'error');
        btn.disabled = false;
        btn.textContent = '确认发起';
    });
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
