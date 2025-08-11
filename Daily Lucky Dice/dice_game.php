<?php
require_once("../include/bittorrent.php");
require_once("db_connect.php"); // 引入数据库连接工具类
dbconn();
loggedinorreturn();

// 确保会话已启动
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$user_id = $CURUSER['id'];
$user = $CURUSER;

// 加载配置（拆分核心与扩展配置）
$config = include('dice_config.php');
$core_config = $config['core'];
$ext_config = $config['extensions'];

// 补充投注金额配置（之前遗漏的配置项）
$core_config['bet_amounts'] = [
    1000 => '1000 魔力',
    5000 => '5000 魔力',
    10000 => '10000 魔力',
    50000 => '50000 魔力',
    100000 => '100000 魔力'
];

// 连接数据库（使用工具类的连接函数）
try {
    $db = connect_db($core_config['db_path']);
} catch (PDOException $e) {
    if ($ext_config['debug_mode']) {
        die("数据库连接失败: " . $e->getMessage());
    } else {
        die("系统错误，请稍后重试");
    }
}

// 检查并处理开奖
check_and_process_draw($db, $core_config, $ext_config);

$error = '';
$success = '';
$today = date($core_config['date_format']);
$current_time = date($core_config['time_format']);

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
        
        // 验证投注类型和金额基础合法性
        if (!array_key_exists($bet_type, $core_config['bets'])) {
            $error = "无效的投注类型";
        } elseif (!in_array($bet_amount, array_keys($core_config['bet_amounts']))) {
            $error = "无效的投注金额";
        } elseif ($user['seedbonus'] < $bet_amount) {
            $error = "魔力值不足，无法投注";
        } 
        // 验证投注限制
        else {
            // 检查单注金额限制
            if ($bet_amount < $ext_config['bet_limits']['min_single_bet'] || $bet_amount > $ext_config['bet_limits']['max_single_bet']) {
                $error = "投注金额超出限制（" . $ext_config['bet_limits']['min_single_bet'] . "-" . $ext_config['bet_limits']['max_single_bet'] . "魔力）";
            } 
            // 检查每日投注次数限制
            else {
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM bets WHERE user_id = :user_id AND bet_date = :date");
                $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
                $stmt->bindValue(':date', $today);
                $stmt->execute();
                $daily_bet_count = $stmt->fetchColumn();
                
                if ($daily_bet_count >= $ext_config['bet_limits']['max_daily_bets']) {
                    $error = "今日投注次数已达上限（每日最多" . $ext_config['bet_limits']['max_daily_bets'] . "次）";
                }
                // 检查每日累计金额限制
                else {
                    $stmt = $db->prepare("SELECT SUM(bet_amount) as total FROM bets WHERE user_id = :user_id AND bet_date = :date");
                    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
                    $stmt->bindValue(':date', $today);
                    $stmt->execute();
                    $daily_total = $stmt->fetchColumn() ?: 0;
                    
                    if ($daily_total + $bet_amount > $ext_config['bet_limits']['max_daily_total']) {
                        $error = "今日累计投注金额已达上限（每日最多" . $ext_config['bet_limits']['max_daily_total'] . "魔力）";
                    }
                    // 检查投注时间
                    elseif ($current_time >= $core_config['draw_time']) {
                        $error = "今日投注已截止，请等待开奖后参与明天的投注";
                    }
                    // 执行投注
                    else {
                        try {
                            // 扣除用户魔力值（使用安全更新函数）
                            if (!safe_mysqli_update(
                                "UPDATE users SET seedbonus = seedbonus - ? WHERE id = ?",
                                [$bet_amount, $user_id]
                            )) {
                                throw new Exception("扣除魔力值失败");
                            }
                            
                            // 记录投注
                            $stmt = $db->prepare("INSERT INTO bets (user_id, bet_type, bet_amount, bet_date, created_at) 
                                                VALUES (:user_id, :bet_type, :bet_amount, :bet_date, datetime('now', 'localtime'))");
                            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
                            $stmt->bindValue(':bet_type', $bet_type);
                            $stmt->bindValue(':bet_amount', $bet_amount, PDO::PARAM_INT);
                            $stmt->bindValue(':bet_date', $today);
                            $stmt->execute();
                            
                            // 生成新的令牌
                            $_SESSION['bet_token'] = bin2hex(random_bytes(32));
                            
                            // 设置成功消息
                            $_SESSION['bet_success'] = "投注成功！你的" . $core_config['bet_amounts'][$bet_amount] . "已下注" . $core_config['bets'][$bet_type]['name'];
                            
                            // 重定向（使用安全URL）
                            $safe_url = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES);
                            header('Location: ' . $safe_url);
                            exit;
                        } catch (Exception $e) {
                            // 发生错误时回滚魔力值
                            safe_mysqli_update(
                                "UPDATE users SET seedbonus = seedbonus + ? WHERE id = ?",
                                [$bet_amount, $user_id]
                            );
                            
                            $error = $ext_config['debug_mode'] ? "投注失败：" . $e->getMessage() : "投注失败，请稍后重试";
                        }
                    }
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

// 获取今日开奖结果
$today_result = null;
try {
    $stmt = $db->prepare("SELECT * FROM daily_results WHERE draw_date = :today");
    $stmt->bindValue(':today', $today);
    $stmt->execute();
    $today_result = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("获取今日开奖结果失败: " . $e->getMessage());
    if ($ext_config['debug_mode']) {
        $error .= " [获取开奖结果失败: " . $e->getMessage() . "]";
    }
}

// 获取昨日数据
$yesterday = date($core_config['date_format'], strtotime('-1 day'));
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

// 计算今日剩余投注时间（基于统一时间格式）
$draw_time_str = $today . ' ' . $core_config['draw_time'] . ':00';
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
foreach ($bet_stats as $stat) {
    $stats_by_type[$stat['bet_type']] = $stat;
    $max_amount = max($max_amount, $stat['total_amount']);
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

// 开奖和结算处理函数
function check_and_process_draw($db, $core_config, $ext_config) {
    $today = date($core_config['date_format']);
    $current_time = date($core_config['datetime_format']);
    $draw_time = $core_config['draw_time'] . ':00';
    
    // 检查今日是否已开奖
    $stmt = $db->prepare("SELECT * FROM daily_results WHERE draw_date = :today");
    $stmt->bindValue(':today', $today);
    $stmt->execute();
    $has_drawn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 未开奖且已过开奖时间，执行开奖
    if (!$has_drawn && strtotime($current_time) >= strtotime($today . ' ' . $draw_time)) {
        // 生成安全随机数
        $dice_result = random_int(1, 6);
        
        // 记录开奖结果
        $stmt = $db->prepare("INSERT INTO daily_results (draw_date, dice_result, drawn_at) 
                            VALUES (:date, :result, datetime('now', 'localtime'))");
        $stmt->bindValue(':date', $today);
        $stmt->bindValue(':result', $dice_result);
        $stmt->execute();
        
        // 获取今日所有投注
        $stmt = $db->prepare("SELECT * FROM bets WHERE bet_date = :today");
        $stmt->bindValue(':today', $today);
        $stmt->execute();
        $today_bets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 处理每个投注的中奖情况
        foreach ($today_bets as $bet) {
            $bet_type = $bet['bet_type'];
            $is_winner = false;
            
            // 判断中奖逻辑
            if (in_array($bet_type, ['big', 'small', 'odd', 'even'])) {
                $ranges = [
                    'big' => [4,5,6],
                    'small' => [1,2,3],
                    'odd' => [1,3,5],
                    'even' => [2,4,6]
                ];
                $is_winner = in_array($dice_result, $ranges[$bet_type] ?? []);
            } else {
                $is_winner = ($dice_result == $bet_type);
            }
            
            // 计算奖励
            $odds = $core_config['bets'][$bet_type]['odds'] ?? 1;
            $reward = $is_winner ? $bet['bet_amount'] * $odds : 0;
            
            // 更新投注记录
            $stmt = $db->prepare("UPDATE bets SET is_winner = :is_winner, reward = :reward 
                                WHERE id = :bet_id");
            $stmt->bindValue(':is_winner', $is_winner ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':reward', $reward, PDO::PARAM_INT);
            $stmt->bindValue(':bet_id', $bet['id'], PDO::PARAM_INT);
            $stmt->execute();
            
            // 给中奖用户增加魔力值（使用安全更新函数）
            if ($is_winner) {
                safe_mysqli_update(
                    "UPDATE users SET seedbonus = seedbonus + ? WHERE id = ?",
                    [$reward, $bet['user_id']]
                );
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
            // 时间计算与显示（基于统一时间格式）
            const drawTime = "<?php echo $today . ' ' . $core_config['draw_time'] . ':00'; ?>";
            const drawTimestamp = new Date(drawTime).getTime() / 1000;
            const nowTimestamp = <?php echo time(); ?>;
            let remainingSeconds = drawTimestamp - nowTimestamp;
            
            const timerElement = document.getElementById('remaining-time');
            const betTypeBtns = document.querySelectorAll('.bet-type-options .bet-btn');
            const betAmountBtns = document.querySelectorAll('.bet-amount-options .bet-btn');
            
            // 初始化时间显示
            updateTimeDisplay(remainingSeconds);
            
            // 计时器逻辑
            if (remainingSeconds > 0) {
                function updateTimer() {
                    remainingSeconds--;
                    updateTimeDisplay(remainingSeconds);
                    
                    if (remainingSeconds <= 0) {
                        clearInterval(timer);
                        // 时间到后禁用投注按钮
                        document.querySelectorAll('.bet-btn, .submit-btn').forEach(btn => {
                            btn.disabled = true;
                        });
                    }
                }
                
                const timer = setInterval(updateTimer, 1000);
            } else {
                // 时间已过，禁用投注按钮
                document.querySelectorAll('.bet-btn, .submit-btn').forEach(btn => {
                    btn.disabled = true;
                });
            }

            function updateTimeDisplay(seconds) {
                if (seconds <= 0) {
                    timerElement.textContent = "今日投注已截止";
                    return;
                }
                
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                const secs = seconds % 60;
                
                timerElement.textContent = `${hours}小时${minutes}分钟${secs}秒`;
            }
            
            // 投注类型选择
            betTypeBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    betTypeBtns.forEach(b => b.classList.remove('selected'));
                    this.classList.add('selected');
                    document.querySelector('input[name="bet_type"]').value = this.dataset.type;
                });
            });
            
            // 投注金额选择
            betAmountBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    betAmountBtns.forEach(b => b.classList.remove('selected'));
                    this.classList.add('selected');
                    document.querySelector('input[name="bet_amount"]').value = this.dataset.amount;
                });
            });
        });
    </script>
</head>
<body>
    <div class="container">
        <header>
            <h1>每日幸运骰子</h1>
            <p>猜大小、单双或具体点数，赢取魔力值奖励！</p>
        </header>

        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="message success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>当前状态</h2>
            <div class="status-bar">
                <div class="status-item">
                    <strong>当前魔力值：</strong><?php echo format_magic($user['seedbonus']); ?>
                </div>
                <div class="status-item">
                    <strong>今日开奖时间：</strong><?php echo $core_config['draw_time']; ?>
                </div>
                <div class="status-item">
                    <strong>投注剩余时间：</strong><span id="remaining-time"><?php echo format_seconds($remaining_seconds); ?></span>
                </div>
            </div>
            
            <?php if ($today_result): ?>
                <div class="dice-result">今日开奖结果：<?php echo $today_result['dice_result']; ?></div>
            <?php elseif (strtotime($current_time) >= strtotime($core_config['draw_time'])): ?>
                <div class="dice-result">正在开奖中...</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>投注区</h2>
            <form method="post">
                <input type="hidden" name="token" value="<?php echo $token; ?>">
                <input type="hidden" name="bet_type" value="">
                <input type="hidden" name="bet_amount" value="">
                
                <div class="option-group">
                    <h3>选择投注类型</h3>
                    <div class="bet-type-options">
                        <?php foreach ($core_config['bets'] as $type => $info): ?>
                            <div class="bet-btn" data-type="<?php echo $type; ?>">
                                <?php echo $info['name']; ?>
                                <span class="odds-badge">赔率 <?php echo $info['odds']; ?>x</span>
                                <div class="bet-description"><?php echo $info['description']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="option-group">
                    <h3>选择投注金额</h3>
                    <div class="bet-amount-options">
                        <?php foreach ($core_config['bet_amounts'] as $amount => $label): ?>
                            <div class="bet-btn" data-amount="<?php echo $amount; ?>">
                                <?php echo $label; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" class="submit-btn" <?php echo strtotime($current_time) >= strtotime($core_config['draw_time']) ? 'disabled' : ''; ?>>
                    确认投注
                </button>
            </form>
        </div>

        <div class="card">
            <h2>我的今日投注</h2>
            <?php if (empty($today_bets)): ?>
                <p class="info-text">你今天还没有投注，赶在<?php echo $core_config['draw_time']; ?>前投注吧！</p>
            <?php else: ?>
                <div class="bets-list">
                    <?php foreach ($today_bets as $bet): ?>
                        <div class="bet-item <?php echo $today_result ? ($bet['is_winner'] ? 'win' : 'lose') : ''; ?>">
                            <div class="bet-details">
                                <?php echo $core_config['bets'][$bet['bet_type']]['name']; ?> - 
                                <?php echo $core_config['bet_amounts'][$bet['bet_amount']]; ?>
                                <div class="bet-description">投注时间：<?php echo $bet['created_at']; ?></div>
                            </div>
                            <div class="bet-result">
                                <?php if ($today_result): ?>
                                    <?php echo $bet['is_winner'] ? '中奖 +' . format_magic($bet['reward']) : '未中奖'; ?>
                                <?php else: ?>
                                    等待开奖
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>昨日投注结果</h2>
            <?php if ($yesterday_result): ?>
                <p class="info-text">昨日开奖结果：<strong><?php echo $yesterday_result['dice_result']; ?></strong></p>
                
                <?php if (empty($yesterday_bets)): ?>
                    <p class="info-text">你昨天没有投注记录</p>
                <?php else: ?>
                    <div class="bets-list">
                        <?php foreach ($yesterday_bets as $bet): ?>
                            <div class="bet-item <?php echo $bet['is_winner'] ? 'win' : 'lose'; ?>">
                                <div class="bet-details">
                                    <?php echo $core_config['bets'][$bet['bet_type']]['name']; ?> - 
                                    <?php echo $core_config['bet_amounts'][$bet['bet_amount']]; ?>
                                </div>
                                <div class="bet-result">
                                    <?php echo $bet['is_winner'] ? '中奖 +' . format_magic($bet['reward']) : '未中奖'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="info-text">暂无昨日开奖数据</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>历史开奖记录</h2>
            <?php if (empty($historical_results)): ?>
                <p class="info-text">暂无开奖记录</p>
            <?php else: ?>
                <div class="history-list">
                    <?php foreach ($historical_results as $result): ?>
                        <div class="history-item">
                            <div class="history-details">
                                <strong><?php echo $result['draw_date']; ?></strong>
                                <div class="bet-description">开奖时间：<?php echo $result['drawn_at']; ?></div>
                            </div>
                            <div class="history-result"><?php echo $result['dice_result']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>投注统计</h2>
            <div class="stats-container">
                <?php foreach ($core_config['bets'] as $type => $info): ?>
                    <?php $stat = $stats_by_type[$type] ?? ['user_count' => 0, 'total_amount' => 0, 'bet_count' => 0]; ?>
                    <div class="stat-item">
                        <h4>
                            <?php echo $info['name']; ?>
                            <span class="highlight"><?php echo format_magic($stat['total_amount']); ?></span>
                        </h4>
                        <div class="info-text">总投注：<?php echo $stat['bet_count']; ?> 次</div>
                        <div class="progress-container">
                            <div class="progress-bar <?php echo $stat['total_amount'] == $max_amount ? 'top' : ''; ?>" 
                                 style="width: <?php echo calculate_percentage($stat['total_amount'], $max_amount); ?>%"></div>
                        </div>
                        <div class="stat-meta">
                            <span>参与用户：<?php echo $stat['user_count']; ?> 人</span>
                            <span>赔率：<?php echo $info['odds']; ?>x</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>
