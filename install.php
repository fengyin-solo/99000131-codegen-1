<?php
/**
 * 数据库初始化脚本 - 运行一次后删除
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

    // 社区网格表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `grids` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(50) NOT NULL COMMENT '网格名称',
        `worker_name` VARCHAR(50) NOT NULL COMMENT '网格员姓名',
        `worker_phone` VARCHAR(20) DEFAULT NULL COMMENT '网格员联系电话',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='社区网格表'");

    // 居民服务工单表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `work_orders` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='居民服务工单表'");

    // 工单进度记录表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `work_order_logs` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT UNSIGNED NOT NULL COMMENT '工单ID',
        `stage` VARCHAR(20) NOT NULL COMMENT '进度阶段: accepted已受理, processing处理中, completed已完成',
        `note` VARCHAR(500) NOT NULL COMMENT '处理说明',
        `operator_name` VARCHAR(50) NOT NULL COMMENT '操作人',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录时间',
        INDEX `idx_order` (`order_id`),
        FOREIGN KEY (`order_id`) REFERENCES `work_orders`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单进度记录表'");

    // 初始化网格数据
    $gridCount = $pdo->query("SELECT COUNT(*) FROM `grids`")->fetchColumn();
    if ($gridCount == 0) {
        $grids = [
            ['第一网格', '陈明', '13900000001'],
            ['第二网格', '刘芳', '13900000002'],
            ['第三网格', '张强', '13900000003'],
            ['第四网格', '杨丽', '13900000004'],
        ];
        $stmt = $pdo->prepare("INSERT INTO `grids` (`name`, `worker_name`, `worker_phone`) VALUES (?, ?, ?)");
        foreach ($grids as $g) {
            $stmt->execute($g);
        }
    }

    // 插入默认管理员 admin/admin123
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT IGNORE INTO `admins` (`username`, `password`) VALUES ('admin', ?)");
    $stmt->execute([$hash]);

    // 插入测试数据
    $testData = [
        ['张大爷', '13800001111', 'help', '楼道灯坏了', '3号楼2单元楼道灯已经坏了一周，晚上出行很不方便，希望能尽快维修。', null, 1],
        ['李阿姨', '13800002222', 'suggest', '建议增加健身器材', '小区广场上没有健身器材，建议物业能增加一些简单的健身设施，方便居民锻炼。', null, 1],
        ['王先生', '13800003333', 'lost', '捡到一只白色小猫', '昨天在小区门口捡到一只白色小猫，有项圈，应该是附近居民养的。联系电话联系我。', null, 1],
        ['赵女士', '13800004444', 'help', '下水道堵塞', '1号楼1单元下水道堵塞严重，污水都漫出来了，影响整栋楼居民生活，急需处理！', null, 1],
        ['孙师傅', '13800005555', 'suggest', '停车位规划建议', '小区停车位紧张，建议物业重新规划停车区域，利用闲置空地增加停车位。', null, 1],
        ['周同学', '13800006666', 'lost', '丢失蓝色书包', '今天下午在小区花园丢失一个蓝色书包，里面有课本和文具，如有拾到请联系我，万分感谢！', null, 1],
    ];

    $stmt = $pdo->prepare("INSERT INTO `messages` (`nickname`, `phone`, `type`, `title`, `content`, `image`, `status`, `views`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($testData as $i => $d) {
        $stmt->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], rand(10, 200)]);
    }

    // 创建上传目录
    if (!is_dir(__DIR__ . '/uploads')) {
        mkdir(__DIR__ . '/uploads', 0755, true);
    }

    echo "<h2>安装成功！</h2>";
    echo "<p>数据库和表已创建完成，测试数据已插入。</p>";
    echo "<p>后台管理账号：<strong>admin</strong> / <strong>admin123</strong></p>";
    echo "<p><a href='index.php'>访问首页</a> | <a href='admin/login.php'>进入后台</a></p>";
    echo "<p style='color:red;'>请删除此安装文件 (install.php) 以确保安全！</p>";

} catch (PDOException $e) {
    die("安装失败: " . $e->getMessage());
}
