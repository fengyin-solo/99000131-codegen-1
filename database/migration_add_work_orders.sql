-- 居民服务工单跟踪迁移脚本
-- 执行此 SQL 来添加工单跟踪功能所需的表结构

USE `community_board`;

-- 责任网格表
CREATE TABLE IF NOT EXISTS `grids` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL COMMENT '网格名称',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='责任网格表';

-- 网格员表
CREATE TABLE IF NOT EXISTS `grid_workers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `grid_id` INT UNSIGNED NOT NULL COMMENT '所属网格ID',
    `name` VARCHAR(50) NOT NULL COMMENT '网格员姓名',
    `phone` VARCHAR(20) DEFAULT NULL COMMENT '联系电话',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    INDEX `idx_grid_id` (`grid_id`),
    FOREIGN KEY (`grid_id`) REFERENCES `grids`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='网格员表';

-- 工单表
CREATE TABLE IF NOT EXISTS `work_orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_no` VARCHAR(32) NOT NULL COMMENT '工单编号',
    `message_id` INT UNSIGNED NOT NULL COMMENT '关联求助留言ID',
    `visitor_id` VARCHAR(64) NOT NULL COMMENT '发起人访客标识',
    `grid_id` INT UNSIGNED DEFAULT NULL COMMENT '责任网格ID',
    `worker_id` INT UNSIGNED DEFAULT NULL COMMENT '责任网格员ID',
    `stage` TINYINT NOT NULL DEFAULT 0 COMMENT '办理阶段: 0待受理, 1已受理, 2处理中, 3已办结',
    `expected_finish_at` DATETIME DEFAULT NULL COMMENT '预计完成时间(约定时限)',
    `finished_at` DATETIME DEFAULT NULL COMMENT '实际完成时间',
    `result` VARCHAR(500) DEFAULT NULL COMMENT '办理结果',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '发起时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    UNIQUE KEY `uk_order_no` (`order_no`),
    UNIQUE KEY `uk_message` (`message_id`),
    INDEX `idx_visitor_id` (`visitor_id`),
    INDEX `idx_stage` (`stage`),
    INDEX `idx_expected_finish` (`expected_finish_at`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`grid_id`) REFERENCES `grids`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`worker_id`) REFERENCES `grid_workers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='居民服务工单表';

-- 工单进度记录表(处理说明留痕)
CREATE TABLE IF NOT EXISTS `work_order_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `work_order_id` INT UNSIGNED NOT NULL COMMENT '工单ID',
    `stage` TINYINT NOT NULL DEFAULT 0 COMMENT '记录时的办理阶段',
    `note` VARCHAR(500) NOT NULL COMMENT '处理说明',
    `created_by` INT UNSIGNED DEFAULT NULL COMMENT '操作人管理员ID(为空表示居民)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录时间',
    INDEX `idx_work_order_id` (`work_order_id`),
    FOREIGN KEY (`work_order_id`) REFERENCES `work_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单进度记录表';

-- 初始化责任网格
INSERT IGNORE INTO `grids` (`id`, `name`) VALUES
(1, '城东网格'),
(2, '城西网格'),
(3, '中心网格');

-- 初始化网格员
INSERT INTO `grid_workers` (`grid_id`, `name`, `phone`)
SELECT * FROM (
    SELECT 1 AS grid_id, '陈东' AS name, '13911110001' AS phone
    UNION ALL SELECT 1, '林芳', '13911110002'
    UNION ALL SELECT 2, '赵强', '13922220001'
    UNION ALL SELECT 2, '孙丽', '13922220002'
    UNION ALL SELECT 3, '周敏', '13933330001'
    UNION ALL SELECT 3, '吴刚', '13933330002'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `grid_workers` LIMIT 1);

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'work_order%';
-- DESCRIBE work_orders;
-- SELECT * FROM grids;
-- SELECT * FROM grid_workers;
