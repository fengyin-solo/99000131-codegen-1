<?php
/**
 * 命令行数据库初始化脚本 - 用于创建表结构
 * 用法: php cli_install.php
 */

$host = 'localhost';
$user = 'root';
$pass = '123456';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `community_board` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `community_board`");

    // 留言表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `messages` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `nickname` VARCHAR(50) NOT NULL COMMENT '昵称',
        `phone` VARCHAR(20) DEFAULT NULL COMMENT '联系电话',
        `type` ENUM('help','suggest','lost') NOT NULL DEFAULT 'help' COMMENT '类型: help求助, suggest建议, lost失物招领',
        `title` VARCHAR(100) NOT NULL COMMENT '标题',
        `content` TEXT NOT NULL COMMENT '内容',
        `image` VARCHAR(255) DEFAULT NULL COMMENT '图片路径',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待审核, 1已通过, 2已拒绝',
        `views` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_type` (`type`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言表'");

    // 管理员表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员表'");

    // 收藏表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `favorites` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '访客唯一标识',
        `message_id` INT UNSIGNED NOT NULL COMMENT '留言ID',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '收藏时间',
        UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_message_id` (`message_id`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏表'");

    // 举报表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `reports` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `message_id` INT UNSIGNED NOT NULL COMMENT '被举报的留言ID',
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '举报人访客标识',
        `report_type` VARCHAR(50) NOT NULL COMMENT '举报类型: spam垃圾信息, abuse辱骂攻击, illegal违法违规, porn色情低俗, other其他',
        `description` TEXT COMMENT '补充说明',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待处理, 1已处理-已删除, 2已处理-已忽略, 3已驳回',
        `processed_by` INT UNSIGNED DEFAULT NULL COMMENT '处理人管理员ID',
        `processed_at` DATETIME DEFAULT NULL COMMENT '处理时间',
        `process_note` VARCHAR(500) DEFAULT NULL COMMENT '处理备注',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '举报时间',
        UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
        INDEX `idx_message_id` (`message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`processed_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报表'");

    // 责任网格表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `grids` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(50) NOT NULL COMMENT '网格名称',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        UNIQUE KEY `uk_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='责任网格表'");

    // 网格员表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `grid_workers` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `grid_id` INT UNSIGNED NOT NULL COMMENT '所属网格ID',
        `name` VARCHAR(50) NOT NULL COMMENT '网格员姓名',
        `phone` VARCHAR(20) DEFAULT NULL COMMENT '联系电话',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        INDEX `idx_grid_id` (`grid_id`),
        FOREIGN KEY (`grid_id`) REFERENCES `grids`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='网格员表'");

    // 工单表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `work_orders` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='居民服务工单表'");

    // 工单进度记录表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `work_order_logs` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `work_order_id` INT UNSIGNED NOT NULL COMMENT '工单ID',
        `stage` TINYINT NOT NULL DEFAULT 0 COMMENT '记录时的办理阶段',
        `note` VARCHAR(500) NOT NULL COMMENT '处理说明',
        `created_by` INT UNSIGNED DEFAULT NULL COMMENT '操作人管理员ID(为空表示居民)',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录时间',
        INDEX `idx_work_order_id` (`work_order_id`),
        FOREIGN KEY (`work_order_id`) REFERENCES `work_orders`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单进度记录表'");

    // 初始化责任网格
    $pdo->exec("INSERT IGNORE INTO `grids` (`id`, `name`) VALUES (1, '城东网格'), (2, '城西网格'), (3, '中心网格')");

    // 初始化网格员
    $workerCount = $pdo->query("SELECT COUNT(*) FROM `grid_workers`")->fetchColumn();
    if ($workerCount == 0) {
        $pdo->exec("INSERT INTO `grid_workers` (`grid_id`, `name`, `phone`) VALUES
            (1, '陈东', '13911110001'),
            (1, '林芳', '13911110002'),
            (2, '赵强', '13922220001'),
            (2, '孙丽', '13922220002'),
            (3, '周敏', '13933330001'),
            (3, '吴刚', '13933330002')");
    }

    echo "数据库表创建成功！\n";

} catch (PDOException $e) {
    die("安装失败: " . $e->getMessage() . "\n");
}
