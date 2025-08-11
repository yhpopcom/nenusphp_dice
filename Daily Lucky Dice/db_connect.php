<?php
/**
 * 每日幸运骰子游戏 - 数据库连接工具类
 * 负责SQLite数据库连接管理和表结构初始化
 */

/**
 * 连接SQLite数据库并初始化表结构
 * 
 * @param string $db_path 数据库文件路径
 * @return PDO 数据库连接对象
 * @throws PDOException 当数据库连接失败时抛出异常
 */
function connect_db($db_path) {
    try {
        // 连接SQLite数据库
        $db = new PDO('sqlite:' . $db_path);
        
        // 设置错误模式为异常，便于错误捕获
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // 设置返回数组类型为关联数组
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // 检查并创建必要的表结构
        create_necessary_tables($db);
        
        return $db;
    } catch (PDOException $e) {
        // 记录错误日志
        error_log("数据库连接失败: " . $e->getMessage());
        // 向用户显示友好错误信息
        die("数据库连接失败，请稍后重试");
    }
}

/**
 * 创建游戏所需的数据库表结构
 * 
 * @param PDO $db 数据库连接对象
 */
function create_necessary_tables($db) {
    // 投注记录表：存储用户的所有投注信息
    $db->exec("CREATE TABLE IF NOT EXISTS bets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,          -- 关联的用户ID
        bet_type TEXT NOT NULL,           -- 投注类型（如big、small、1-6）
        bet_amount INTEGER NOT NULL,      -- 投注魔力值数量
        bet_date DATE NOT NULL,           -- 投注日期（YYYY-MM-DD）
        created_at DATETIME NOT NULL,     -- 投注时间戳
        is_winner INTEGER DEFAULT 0,      -- 是否中奖（0=未中奖，1=中奖）
        reward INTEGER DEFAULT 0          -- 中奖奖励魔力值
    )");
    
    // 每日开奖结果表：存储每日开奖结果
    $db->exec("CREATE TABLE IF NOT EXISTS daily_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        draw_date DATE NOT NULL UNIQUE,   -- 开奖日期（YYYY-MM-DD），唯一约束确保每日只开一次奖
        dice_result INTEGER NOT NULL,     -- 骰子结果（1-6）
        drawn_at DATETIME NOT NULL        -- 开奖时间戳
    )");
    
    // 创建索引提升查询性能
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bets_user_date ON bets(user_id, bet_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bets_date ON bets(bet_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_results_date ON daily_results(draw_date)");
}

/**
 * 安全执行MySQLi更新操作（修复语法错误）
 * 
 * @param string $sql SQL语句
 * @param array $params 参数数组
 * @return bool 执行结果
 */
function safe_mysqli_update($sql, $params) {
    global $___mysqli_ston;
    
    if (!$___mysqli_ston) {
        return false;
    }
    
    $stmt = $___mysqli_ston->prepare($sql);
    if (!$stmt) {
        error_log("MySQLi准备语句失败: " . $___mysqli_ston->error);
        return false;
    }
    
    // 生成参数类型字符串（i=整数, s=字符串, d=小数, b=二进制）
    $types = '';
    foreach ($params as $param) {
        $types .= is_int($param) ? 'i' : 's';
    }
    
    // 绑定参数
    $bindParams = array_merge([$types], $params);
    $bindResult = call_user_func_array([$stmt, 'bind_param'], $bindParams);
    
    if (!$bindResult) {
        error_log("MySQLi参数绑定失败: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    // 执行语句
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}
?>
