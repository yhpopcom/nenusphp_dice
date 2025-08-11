<?php
require_once("../include/bittorrent.php");
dbconn();
loggedinorreturn();

// 确保会话已启动
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$user_id = $CURUSER['id'];
$user = $CURUSER;

// 加载配置
$config = include('dice_config.php');

// 连接数据库并确保表存在
try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 自动创建必要的表
    create_necessary_tables($db);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 检查并处理开奖
check_and_process_draw($db, $config);

$error = '';
$success = '';
$today = date('Y-m-d');
$current_time = date('H:i');

// 生成CSRF令牌
if (!isset($_SESSION['bet_token']) || empty($_SESSION['bet_token'])) {
    $_SESSION['bet_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['bet_token'];

// 处理投注提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bet_type']) && isset($_POST['bet_amount'])) {
    // 验证CSRF令牌
    if (!isset($_POST['token']) || empty($_POST['token'])) {
        $error = "提交验证失败：缺少令牌";
    } elseif ($_POST['token'] !== $_SESSION['bet_token']) {
        $error = "提交验证失败：令牌不匹配，请刷新页面重试";
    } else {
        $bet_type = $_POST['bet_type'];
        $bet_amount = (int)$_POST['bet_amount'];
        
        // 验证输入
        if (!array_key_exists($bet_type, $config['bets'])) {
            $error = "无效的投注类型";
        } elseif (!in_array($bet_amount, array_keys($config['bet_amounts']))) {
            $error = "无效的投注金额";
        } elseif ($user['seedbonus'] < $bet_amount) {
            $error = "魔力值不足，无法投注";
        } else {
            // 检查是否已过今天的投注时间
            if ($current_time >= $config['draw_time']) {
                $error = "今日投注已截止，请等待开奖后参与明天的投注";
            } else {
                try {
                    // 扣除用户魔力值
                    sql_query("UPDATE users SET seedbonus = seedbonus - $bet_amount WHERE id = " . sqlesc($user_id));
                    
                    // 记录投注
                    $stmt = $db->prepare("INSERT INTO bets (user_id, bet_type, bet_amount, bet_date, created_at) 
                                        VALUES (:user_id, :bet_type, :bet_amount, :bet_date, datetime('now'))");
                    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
                    $stmt->bindValue(':bet_type', $bet_type);
                    $stmt->bindValue(':bet_amount', $bet_amount, PDO::PARAM_INT);
                    $stmt->bindValue(':bet_date', $today);
                    $stmt->execute();
                    
                    // 生成新的令牌
                    $_SESSION['bet_token'] = bin2hex(random_bytes(32));
                    
                    // 设置成功消息
                    $_SESSION['bet_success'] = "投注成功！你的" . $config['bet_amounts'][$bet_amount] . "已下注" . $config['bets'][$bet_type]['name'];
                    
                    // 重定向
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } catch (Exception $e) {
                    $error = "投注失败：" . $e->getMessage();
                }
            }
        }
    }
}

// 从会话中获取成功消息
if (isset($_SESSION['bet_success'])) {
    $success = $_SESSION['bet_success'];
    unset($_SESSION['bet_success']);
}

// 获取今日投注
    $stmt = $db->prepare("SELECT * FROM bets WHERE user_id = :user_id AND bet_date = :date ORDER BY created_at DESC");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':date', $today);
    $stmt->execute();
    $today_bets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 关键修复：定义并初始化今日开奖结果变量
    $today_result = null;
    try {
        // 获取今日开奖结果（用于判断今日是否已开奖）
        $stmt = $db->prepare("SELECT * FROM daily_results WHERE draw_date = :today");
        $stmt->bindValue(':today', $today);
        $stmt->execute();
        $today_result = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // 处理查询错误
        error_log("获取今日开奖结果失败: " . $e->getMessage());
        $today_result = null;
    }

    // 获取昨日开奖结果
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $db->prepare("SELECT * FROM daily_results WHERE draw_date = :date");
    $stmt->bindValue(':date', $yesterday);
    $stmt->execute();
    $yesterday_result = $stmt->fetch(PDO::FETCH_ASSOC);

// 获取昨日投注结果
$stmt = $db->prepare("SELECT * FROM bets WHERE user_id = :user_id AND bet_date = :date ORDER BY created_at DESC");
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':date', $yesterday);
$stmt->execute();
$yesterday_bets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 计算今日剩余投注时间
list($draw_hour, $draw_minute) = explode(':', $config['draw_time']);
$draw_time_str = "{$today} {$draw_hour}:{$draw_minute}:00";
$draw_timestamp = strtotime($draw_time_str);
$now_timestamp = time();
$remaining_seconds = $draw_timestamp - $now_timestamp;

// 获取历史开奖结果（最近10天）
$stmt = $db->query("SELECT * FROM daily_results ORDER BY draw_date DESC LIMIT 10");
$historical_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取投注类型统计数据
$stmt = $db->query("SELECT 
    bet_type, 
    COUNT(DISTINCT user_id) as user_count, 
    SUM(bet_amount) as total_amount,
    COUNT(*) as bet_count
FROM bets 
GROUP BY bet_type 
ORDER BY total_amount DESC");
$bet_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 转换统计数据为关联数组
$stats_by_type = [];
$max_amount = 0;
$max_users = 0;
foreach ($bet_stats as $stat) {
    $stats_by_type[$stat['bet_type']] = $stat;
    $max_amount = max($max_amount, $stat['total_amount']);
    $max_users = max($max_users, $stat['user_count']);
}

// 格式化魔力值显示
function format_magic($amount) {
    return number_format($amount, 0) . ' 魔力';
}

// 格式化剩余时间显示
function format_seconds($seconds) {
    if ($seconds <= 0) {
        return "今日投注已截止";
    }
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    
    return "$hours小时$minutes分钟$seconds秒";
}

// 计算百分比（用于进度条）
function calculate_percentage($value, $max_value) {
    return $max_value > 0 ? min(100, (int)(($value / $max_value) * 100)) : 0;
}

// 创建必要的数据表
function create_necessary_tables($db) {
    // 创建投注表
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
    
    // 创建每日结果表（解决当前错误的关键）
    $db->exec("CREATE TABLE IF NOT EXISTS daily_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        draw_date DATE NOT NULL UNIQUE,
        dice_result INTEGER NOT NULL,
        drawn_at DATETIME NOT NULL
    )");
    
    // 创建索引提升查询性能
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bets_user_date ON bets(user_id, bet_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bets_date ON bets(bet_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_results_date ON daily_results(draw_date)");
}

// 开奖和结算处理函数
function check_and_process_draw($db, $config) {
    $today = date('Y-m-d');
    $current_time = date('H:i:s');
    $draw_time = $config['draw_time'] . ':00'; // 补全秒数
    
    // 检查今日是否已开奖
    $stmt = $db->prepare("SELECT * FROM daily_results WHERE draw_date = :today");
    $stmt->bindValue(':today', $today);
    $stmt->execute();
    $has_drawn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 如果未开奖，且当前时间已过开奖时间，则执行开奖
    if (!$has_drawn && $current_time >= $draw_time) {
        // 生成随机骰子结果（1-6）
        $dice_result = rand(1, 6);
        
        // 记录开奖结果到数据库
        $stmt = $db->prepare("INSERT INTO daily_results (draw_date, dice_result, drawn_at) 
                            VALUES (:date, :result, datetime('now'))");
        $stmt->bindValue(':date', $today);
        $stmt->bindValue(':result', $dice_result);
        $stmt->execute();
        
        // 获取今日所有投注
        $stmt = $db->prepare("SELECT * FROM bets WHERE bet_date = :today");
        $stmt->bindValue(':today', $today);
        $stmt->execute();
        $today_bets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 新增：获取今日开奖结果（用于判断今日是否已开奖）
        $stmt = $db->prepare("SELECT * FROM daily_results WHERE draw_date = :today");
        $stmt->bindValue(':today', $today);
        $stmt->execute();
        $today_result = $stmt->fetch(PDO::FETCH_ASSOC);

    // 获取昨日开奖结果（保持不变）
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $db->prepare("SELECT * FROM daily_results WHERE draw_date = :date");
    $stmt->bindValue(':date', $yesterday);
    $stmt->execute();
    $yesterday_result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 逐个处理投注，计算中奖情况
        foreach ($today_bets as $bet) {
            $bet_type = $bet['bet_type'];
            $is_winner = false;
            
            // 判断是否中奖（支持大/小/单/双和具体点数）
            if (in_array($bet_type, ['big', 'small', 'odd', 'even'])) {
                // 大/小/单/双类型判断
                // 定义每种类型对应的数字范围
                $ranges = [
                    'big' => [4,5,6],
                    'small' => [1,2,3],
                    'odd' => [1,3,5],
                    'even' => [2,4,6]
                ];
                $is_winner = in_array($dice_result, $ranges[$bet_type] ?? []);
            } else {
                // 具体点数类型判断
                $is_winner = ($dice_result == $bet_type);
            }
            
            // 计算奖励（投注金额 × 赔率）
            $odds = $config['bets'][$bet_type]['odds'] ?? 1;
            $reward = $is_winner ? $bet['bet_amount'] * $odds : 0;
            
            // 更新投注记录
            $stmt = $db->prepare("UPDATE bets SET is_winner = :is_winner, reward = :reward 
                                WHERE id = :bet_id");
            $stmt->bindValue(':is_winner', $is_winner ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':reward', $reward, PDO::PARAM_INT);
            $stmt->bindValue(':bet_id', $bet['id'], PDO::PARAM_INT);
            $stmt->execute();
            
            // 给中奖用户增加魔力值
            if ($is_winner) {
                sql_query("UPDATE users SET seedbonus = seedbonus + $reward WHERE id = " . sqlesc($bet['user_id']));
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>每日幸运骰子</title>
    <style>
        :root {
            --primary: #165DFF;
            --secondary: #36CFC9;
            --accent: #722ED1;
            --light: #F2F3F5;
            --dark: #1D2129;
            --success: #00B42A;
            --danger: #F53F3F;
            --warning: #FF7D00;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f8f9fa;
            color: var(--dark);
            line-height: 1.6;
            padding: 15px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px 0;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 12px;
            color: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        header h1 {
            font-size: 1.8rem;
            margin-bottom: 8px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .card h2 {
            color: var(--primary);
            margin-bottom: 15px;
            font-size: 1.3rem;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--light);
        }

        .status-bar {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }

        .status-item {
            background: var(--light);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.9rem;
            flex: 1;
            min-width: 200px;
        }

        .status-item strong {
            color: var(--primary);
        }

        .time-placeholder {
            opacity: 0.6;
        }

        .option-group {
            margin-bottom: 15px;
        }

        .option-group h3 {
            margin-bottom: 8px;
            color: var(--primary);
            font-size: 1.1rem;
        }

        .bet-type-options, .bet-amount-options {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }

        .bet-btn {
            background: var(--light);
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.9rem;
            flex: 1;
            min-width: 80px;
            text-align: center;
        }

        .bet-btn:hover, .bet-btn.selected {
            background: var(--primary);
            color: white;
        }

        .submit-btn {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            padding: 10px 25px;
            font-size: 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(22, 93, 255, 0.3);
            display: block;
            margin: 15px auto;
            width: 100%;
            max-width: 200px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(22, 93, 255, 0.4);
        }

        .submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .bets-list, .history-list {
            margin-top: 15px;
        }

        .bet-item, .history-item {
            padding: 10px;
            border-radius: 6px;
            background: var(--light);
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .bet-item.win {
            border-left: 4px solid var(--success);
        }

        .bet-item.lose {
            border-left: 4px solid var(--danger);
        }

        .history-item {
            background: #f9f9f9;
            border-left: 4px solid var(--primary);
        }

        .bet-details, .history-details {
            flex: 1;
        }

        .bet-result, .history-result {
            font-weight: bold;
            margin-left: 10px;
            white-space: nowrap;
        }

        .history-result {
            color: var(--primary);
            font-size: 1.5rem;
        }

        .message {
            padding: 10px;
            border-radius: 6px;
            margin: 15px 0;
            text-align: center;
            font-weight: bold;
        }

        .success {
            background: #f0fff4;
            color: var(--success);
            border: 1px solid #c9e7d2;
        }

        .error {
            background: #fff1f0;
            color: var(--danger);
            border: 1px solid #ffccc7;
        }

        .dice-result {
            text-align: center;
            font-size: 3rem;
            font-weight: bold;
            color: var(--primary);
            margin: 15px 0;
        }

        .info-text {
            font-size: 0.95rem;
            color: #666;
            margin-bottom: 10px;
        }

        .bet-description {
            font-size: 0.85rem;
            color: #666;
            margin-top: 3px;
        }

        .odds-badge {
            display: inline-block;
            background: var(--warning);
            color: white;
            font-size: 0.75rem;
            padding: 2px 5px;
            border-radius: 3px;
            margin-left: 5px;
        }

        /* 统计样式 */
        .stats-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
        }

        .stat-item {
            flex: 1;
            min-width: 250px;
            background: var(--light);
            padding: 12px;
            border-radius: 8px;
            position: relative;
        }

        .stat-item h4 {
            margin-bottom: 8px;
            color: var(--primary);
            display: flex;
            justify-content: space-between;
        }

        .stat-item .highlight {
            color: var(--warning);
            font-weight: bold;
        }

        .progress-container {
            width: 100%;
            background-color: #e0e0e0;
            border-radius: 5px;
            margin: 5px 0;
        }

        .progress-bar {
            height: 8px;
            border-radius: 5px;
            background-color: var(--secondary);
        }

        .progress-bar.top {
            background-color: var(--warning);
        }

        .stat-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .status-item, .stat-item {
                min-width: 100%;
            }
            
            .bet-btn {
                min-width: 45%;
            }
            
            .bet-result, .history-result {
                width: 100%;
                margin-left: 0;
                margin-top: 5px;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 时间计算与显示
            const drawTime = "<?php echo $draw_time_str; ?>";
            const drawTimestamp = new Date(drawTime).getTime() / 1000;
            const nowTimestamp = <?php echo time(); ?>;
            let remainingSeconds = drawTimestamp - nowTimestamp;
            
            const timerElement = document.getElementById('remaining-time');
            const betTypeBtns = document.querySelectorAll('.bet-type-options .bet-btn');
            const betAmountBtns = document.querySelectorAll('.bet-amount-options .bet-btn');
            const submitBtn = document.querySelector('.submit-btn');
            const form = document.querySelector('form');
            
            // 初始化时间显示
            updateTimeDisplay(remainingSeconds);
            
            // 计时器逻辑
            if (remainingSeconds > 0) {
                function updateTimer() {
                    remainingSeconds--;
                    updateTimeDisplay(remainingSeconds);
                    
                    if (remainingSeconds <= 0) {
                        submitBtn.disabled = true;
                        betTypeBtns.forEach(btn => btn.disabled = true);
                        betAmountBtns.forEach(btn => btn.disabled = true);
                        return;
                    }
                    
                    setTimeout(updateTimer, 1000);
                }
                
                setTimeout(updateTimer, 1000);
            } else {
                submitBtn.disabled = true;
                betTypeBtns.forEach(btn => btn.disabled = true);
                betAmountBtns.forEach(btn => btn.disabled = true);
            }
            
            // 更新时间显示
            function updateTimeDisplay(seconds) {
                if (!timerElement) return;
                
                if (seconds <= 0) {
                    timerElement.textContent = "今日投注已截止";
                    timerElement.classList.remove('time-placeholder');
                    return;
                }
                
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                const secs = seconds % 60;
                
                timerElement.textContent = `${hours}小时${minutes}分钟${secs}秒`;
                timerElement.classList.remove('time-placeholder');
            }
            
            // 投注按钮逻辑
            let selectedBetType = null;
            let selectedBetAmount = null;
            
            // 选择投注类型
            betTypeBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    betTypeBtns.forEach(b => b.classList.remove('selected'));
                    this.classList.add('selected');
                    selectedBetType = this.value;
                    updateSubmitButton();
                });
            });
            
            // 选择投注金额
            betAmountBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    betAmountBtns.forEach(b => b.classList.remove('selected'));
                    this.classList.add('selected');
                    selectedBetAmount = this.value;
                    updateSubmitButton();
                });
            });
            
            // 更新提交按钮状态
            function updateSubmitButton() {
                submitBtn.disabled = !(selectedBetType && selectedBetAmount) || remainingSeconds <= 0;
            }
            
            // 表单提交处理
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (!selectedBetType || !selectedBetAmount) return;
                
                // 设置表单值
                document.getElementById('bet_type').value = selectedBetType;
                document.getElementById('bet_amount').value = selectedBetAmount;
                
                // 提交表单
                form.submit();
            });
        });

        // 从统计区域选择投注类型
        function selectBetType(type) {
            const betTypeBtns = document.querySelectorAll('.bet-type-options .bet-btn');
            betTypeBtns.forEach(btn => {
                if (btn.value === type) {
                    btn.classList.add('selected');
                    selectedBetType = type;
                } else {
                    btn.classList.remove('selected');
                }
            });
            updateSubmitButton();
            
            // 滚动到投注区域
            document.querySelector('.option-group').scrollIntoView({ behavior: 'smooth' });
        }
    </script>
</head>
<body>
    <div class="container">
        <header>
            <h1>每日幸运骰子</h1>
            <p>每日<?php echo $config['draw_time']; ?>开奖，支持多种投注方式</p>
        </header>

        <div class="card">
            <h2>我的状态</h2>
            <div class="status-bar">
                <div class="status-item">
                    当前魔力值: <strong><?php echo format_magic($user['seedbonus']); ?></strong>
                </div>
                <div class="status-item">
                    今日剩余投注时间: 
                    <strong id="remaining-time" class="time-placeholder">计算中...</strong>
                </div>
                <div class="status-item">
                    今日投注次数: <strong><?php echo count($today_bets); ?></strong>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="message success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>投注区域</h2>
            <p class="info-text">选择投注类型和金额，每日<?php echo $config['draw_time']; ?>将统一开奖。可多次投注，每次投注都会扣除相应魔力值。</p>
            
            <form method="POST" id="bet-form">
                <!-- CSRF令牌 -->
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="bet_type" id="bet_type">
                <input type="hidden" name="bet_amount" id="bet_amount">
                
                <div class="option-group">
                    <h3>选择投注类型</h3>
                    <div class="bet-type-options">
                        <?php foreach ($config['bets'] as $key => $bet): ?>
                            <div>
                                <button type="button" class="bet-btn" value="<?php echo $key; ?>" 
                                    <?php echo $current_time >= $config['draw_time'] ? 'disabled' : ''; ?>>
                                    <?php echo $bet['name']; ?>
                                    <span class="odds-badge">赔率 <?php echo $bet['odds']; ?>x</span>
                                </button>
                                <div class="bet-description"><?php echo $bet['description']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="option-group">
                    <h3>选择投注金额</h3>
                    <div class="bet-amount-options">
                        <?php foreach ($config['bet_amounts'] as $amount => $label): ?>
                            <button type="button" class="bet-btn" value="<?php echo $amount; ?>"
                                <?php echo $current_time >= $config['draw_time'] ? 'disabled' : ''; ?>>
                                <?php echo $label; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" class="submit-btn" 
                    <?php echo $current_time >= $config['draw_time'] ? 'disabled' : ''; ?>>
                    <?php echo $current_time >= $config['draw_time'] ? '今日投注已截止' : '确认投注'; ?>
                </button>
            </form>
        </div>

        <div class="card">
        <h2>今日我的投注</h2>
        <?php if (count($today_bets) > 0): ?>
            <div class="bets-list">
                <?php foreach ($today_bets as $bet): ?>
                    <div class="bet-item <?php 
                        // 如果今日已开奖，根据是否中奖添加对应样式
                        echo ($today_result && $bet['is_winner']) ? 'win' : (($today_result && !$bet['is_winner']) ? 'lose' : ''); 
                    ?>">
                        <div class="bet-details">
                            投注类型: <?php echo $config['bets'][$bet['bet_type']]['name']; ?><br>
                            投注金额: <?php echo format_magic($bet['bet_amount']); ?><br>
                            投注时间: <?php echo date('H:i:s', strtotime($bet['created_at'])); ?>
                        </div>
                        <div class="bet-result">
                            <?php 
                            // 现在$today_result已确保被定义，可以安全使用
                            if ($today_result):
                                // 已开奖：显示中奖结果
                                echo $bet['is_winner'] ? '赢: ' . format_magic($bet['reward']) : '未中奖';
                            else:
                                // 未开奖：显示等待状态
                                echo "等待开奖";
                            endif;
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="info-text">今日尚未投注，快来参与吧！</p>
        <?php endif; ?>
    </div>

        <div class="card">
            <h2>昨日开奖结果</h2>
            <?php if ($yesterday_result): ?>
                <div class="dice-result"><?php echo $yesterday_result['dice_result']; ?></div>
                <p class="info-text">开奖日期: <?php echo $yesterday_result['draw_date']; ?></p>
                
                <?php if (count($yesterday_bets) > 0): ?>
                    <h3>我的昨日投注结果</h3>
                    <div class="bets-list">
                        <?php foreach ($yesterday_bets as $bet): ?>
                            <div class="bet-item <?php echo $bet['is_winner'] ? 'win' : 'lose'; ?>">
                                <div class="bet-details">
                                    投注类型: <?php echo $config['bets'][$bet['bet_type']]['name']; ?><br>
                                    投注金额: <?php echo format_magic($bet['bet_amount']); ?><br>
                                    投注时间: <?php echo date('H:i:s', strtotime($bet['created_at'])); ?>
                                </div>
                                <div class="bet-result">
                                    <?php echo $bet['is_winner'] ? '赢: ' . format_magic($bet['reward']) : '未中奖'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="info-text">昨日未参与投注</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="info-text">等待今日开奖...</p>
            <?php endif; ?>
        </div>

        <!-- 历史开奖结果 -->
        <div class="card">
            <h2>历史开奖结果</h2>
            <p class="info-text">最近10天的开奖记录</p>
            <?php if (count($historical_results) > 0): ?>
                <div class="history-list">
                    <?php foreach ($historical_results as $result): ?>
                        <div class="history-item">
                            <div class="history-details">
                                开奖日期: <?php echo $result['draw_date']; ?><br>
                                开奖时间: <?php echo date('H:i:s', strtotime($result['drawn_at'])); ?>
                            </div>
                            <div class="history-result">
                                <?php echo $result['dice_result']; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="info-text">暂无历史开奖记录</p>
            <?php endif; ?>
        </div>

        <!-- 投注类型统计 -->
        <div class="card">
            <h2>投注类型统计</h2>
            <p class="info-text">所有历史投注的统计数据，点击类型可直接选择投注</p>
            <div class="stats-container">
                <?php foreach ($config['bets'] as $key => $bet): ?>
                    <?php 
                    $stat = isset($stats_by_type[$key]) ? $stats_by_type[$key] : [
                        'user_count' => 0,
                        'total_amount' => 0,
                        'bet_count' => 0
                    ];
                    $amount_percent = calculate_percentage($stat['total_amount'], $max_amount);
                    $users_percent = calculate_percentage($stat['user_count'], $max_users);
                    $is_top = $stat['total_amount'] == $max_amount && $max_amount > 0;
                    ?>
                    
                    <div class="stat-item">
                        <h4>
                            <button type="button" class="bet-btn" value="<?php echo $key; ?>"
                                style="padding: 2px 8px; font-size: 0.9rem; display: inline-block;"
                                onclick="selectBetType('<?php echo $key; ?>')">
                                <?php echo $bet['name']; ?>
                                <span class="odds-badge">赔率 <?php echo $bet['odds']; ?>x</span>
                            </button>
                            <?php echo $is_top ? '<span class="highlight">最热门</span>' : ''; ?>
                        </h4>
                        
                        <div>
                            <small>总魔力值: <?php echo format_magic($stat['total_amount']); ?></small>
                            <div class="progress-container">
                                <div class="progress-bar <?php echo $is_top ? 'top' : ''; ?>" 
                                    style="width: <?php echo $amount_percent; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="stat-meta">
                            <span>投注人数: <?php echo $stat['user_count']; ?></span>
                            <span>投注次数: <?php echo $stat['bet_count']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>
