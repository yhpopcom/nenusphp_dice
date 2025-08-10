<?php
/**
 * 数据库连接工具类
 */
function connect_db($db_path) {
    try {
        // 连接SQLite数据库
        $db = new PDO('sqlite:' . $db_path);
        
        // 设置错误模式为异常
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 检查并创建必要的表
        create_tables($db);
        
        return $db;
    } catch (PDOException $e) {
        die("数据库连接失败: " . $e->getMessage());
    }
}

/**
 * 创建必要的数据库表
 */
function create_tables($db) {
    // 投注记录表
    $db->exec("CREATE TABLE IF NOT EXISTS bets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        bet_type TEXT NOT NULL,
        bet_amount INTEGER NOT NULL,
        bet_date DATE NOT NULL,
        created_at DATETIME NOT NULL,
        is_winner INTEGER DEFAULT 0,
        reward INTEGER DEFAULT 0
    )");
    
    // 每日开奖结果表
    $db->exec("CREATE TABLE IF NOT EXISTS daily_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        draw_date DATE NOT NULL UNIQUE,
        dice_result INTEGER NOT NULL,
        drawn_at DATETIME NOT NULL
    )");
    
    // 创建索引提升查询性能
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bets_user_date ON bets(user_id, bet_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bets_date ON bets(bet_date)");
}
?>