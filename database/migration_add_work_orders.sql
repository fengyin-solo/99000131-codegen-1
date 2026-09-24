-- 居民服务工单跟踪迁移脚本
-- 执行此 SQL 来添加工单功能所需的表结构

USE `community_board`;

-- 社区网格表
CREATE TABLE IF NOT EXISTS `grids` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL COMMENT '网格名称',
    `worker_name` VARCHAR(50) NOT NULL COMMENT '网格员姓名',
    `worker_phone` VARCHAR(20) DEFAULT NULL COMMENT '网格员联系电话',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='社区网格表';

-- 居民服务工单表
CREATE TABLE IF NOT EXISTS `work_orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_no` VARCHAR(32) NOT NULL COMMENT '工单编号',
    `message_id` INT UNSIGNED NOT NULL COMMENT '关联求助留言ID',
    `visitor_id` VARCHAR(64) NOT NULL COMMENT '发起人访客标识',
    `grid_id` INT UNSIGNED NOT NULL COMMENT '责任网格ID',
    `stage` VARCHAR(20) NOT NULL DEFAULT 'accepted' COMMENT '办理阶段: accepted已受理, processing处理中, completed已完成',
    `expected_finish_at` DATETIME NOT NULL COMMENT '预计完成时间(约定时限)',
    `completed_at` DATETIME DEFAULT NULL COMMENT '实际完成时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '发起时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    UNIQUE KEY `uk_order_no` (`order_no`),
    UNIQUE KEY `uk_message` (`message_id`),
    INDEX `idx_visitor` (`visitor_id`),
    INDEX `idx_stage` (`stage`),
    INDEX `idx_expected_finish` (`expected_finish_at`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`grid_id`) REFERENCES `grids`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='居民服务工单表';

-- 工单进度记录表（处理说明留痕）
CREATE TABLE IF NOT EXISTS `work_order_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL COMMENT '工单ID',
    `stage` VARCHAR(20) NOT NULL COMMENT '进度阶段: accepted已受理, processing处理中, completed已完成',
    `note` VARCHAR(500) NOT NULL COMMENT '处理说明',
    `operator_name` VARCHAR(50) NOT NULL COMMENT '操作人',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录时间',
    INDEX `idx_order` (`order_id`),
    FOREIGN KEY (`order_id`) REFERENCES `work_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单进度记录表';

-- 初始化网格数据（已存在则跳过）
INSERT INTO `grids` (`name`, `worker_name`, `worker_phone`)
SELECT * FROM (
    SELECT '第一网格' AS name, '陈明' AS worker_name, '13900000001' AS worker_phone
    UNION ALL SELECT '第二网格', '刘芳', '13900000002'
    UNION ALL SELECT '第三网格', '张强', '13900000003'
    UNION ALL SELECT '第四网格', '杨丽', '13900000004'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `grids` LIMIT 1);

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'work_orders';
-- DESCRIBE work_orders;
-- SELECT * FROM grids;
