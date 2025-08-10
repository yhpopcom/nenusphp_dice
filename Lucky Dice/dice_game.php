<?php
require_once("../include/bittorrent.php");
dbconn();
loggedinorreturn();

// 初始化Session（确保在所有输出前调用）
if (!isset($_SESSION)) {
    session_start();
}

$user_id = $CURUSER['id'];
$user = $CURUSER;

$config = include('dice_config.php');

$error = '';
$result = [];
$magic_changed = false;
$initial_magic = $user['seedbonus'];

// 从Session获取结果并清除
if (isset($_SESSION['dice_result'])) {
    $result = $_SESSION['dice_result'];
    unset($_SESSION['dice_result']);
}

if (isset($_SESSION['magic_changed'])) {
    $magic_changed = $_SESSION['magic_changed'];
    unset($_SESSION['magic_changed']);
}

if (isset($_SESSION['initial_magic'])) {
    $initial_magic = $_SESSION['initial_magic'];
    unset($_SESSION['initial_magic']);
}

if (isset($_SESSION['dice_error'])) {
    $error = $_SESSION['dice_error'];
    unset($_SESSION['dice_error']);
}

// 重新获取用户信息（可能已在Session中更新）
$user_query = sql_query("SELECT * FROM users WHERE id = " . sqlesc($user_id));
$user = mysqli_fetch_assoc($user_query);

// 处理投注
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bet_type']) && isset($_POST['bet_amount'])) {
    $bet_type = $_POST['bet_type'];
    $bet_amount = (int)$_POST['bet_amount'];
    
    // 验证输入
    if (!array_key_exists($bet_type, $config['bets'])) {
        $error = "无效的投注类型";
    } elseif (!in_array($bet_amount, array_keys($config['bet_amounts']))) {
        $error = "无效的投注金额";
    } elseif ($initial_magic < $bet_amount) {
        $error = "魔力值不足，无法投注";
    } else {
        // 检查冷却时间
        $last_roll = isset($_SESSION['last_dice_roll']) ? $_SESSION['last_dice_roll'] : 0;
        $current_time = time();
        
        if ($current_time - $last_roll < $config['cooldown']) {
            $error = "请等待" . ($config['cooldown'] - ($current_time - $last_roll)) . "秒后再试";
        } else {
            try {
                // 记录上次投注时间
                $_SESSION['last_dice_roll'] = $current_time;
                
                // 扣除投注魔力
                sql_query("UPDATE users SET seedbonus = seedbonus - $bet_amount WHERE id = " . sqlesc($user_id));
                
                // 生成随机骰子点数(1-6)
                $dice_result = mt_rand(1, 6);
                
                // 判断是否中奖
                $win = false;
                switch ($bet_type) {
                    case 'big':
                        $win = $dice_result >= 4;
                        break;
                    case 'small':
                        $win = $dice_result <= 3;
                        break;
                    case 'odd':
                        $win = $dice_result % 2 == 1;
                        break;
                    case 'even':
                        $win = $dice_result % 2 == 0;
                        break;
                    default:
                        $win = (int)$bet_type == $dice_result;
                }
                
                // 计算奖励
                $reward = 0;
                if ($win) {
                    $odds = $config['bets'][$bet_type]['odds'];
                    $reward = $bet_amount * $odds;
                    sql_query("UPDATE users SET seedbonus = seedbonus + $reward WHERE id = " . sqlesc($user_id));
                }
                
                // 更新用户信息
                $user_query = sql_query("SELECT * FROM users WHERE id = " . sqlesc($user_id));
                $user = mysqli_fetch_assoc($user_query);
                $magic_changed = true;
                
                // 准备结果信息
                $result = [
                    'dice' => $dice_result,
                    'bet_type' => $config['bets'][$bet_type]['name'],
                    'bet_amount' => $bet_amount,
                    'win' => $win,
                    'reward' => $reward
                ];

                // 存储结果到Session
                $_SESSION['dice_result'] = $result;
                $_SESSION['magic_changed'] = $magic_changed;
                $_SESSION['initial_magic'] = $initial_magic;
                
                // 重定向以防止刷新重复提交
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } catch (Exception $e) {
                $error = "游戏过程中发生错误：" . $e->getMessage();
                $_SESSION['dice_error'] = $error;
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    }
    
    // 如果有错误，存储错误信息并重定向
    if ($error) {
        $_SESSION['dice_error'] = $error;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

function format_magic($amount) {
    return number_format($amount, 0) . ' 魔力';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>幸运骰子</title>
    <style>
        :root {
            --primary: #165DFF;
            --secondary: #36CFC9;
            --accent: #722ED1;
            --light: #F2F3F5;
            --dark: #1D2129;
            --success: #00B42A;
            --danger: #F53F3F;
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
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px 0;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 12px;
            color: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .intro {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .game-area {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
        }

        .dice-display {
            flex: 1;
            min-width: 300px;
            text-align: center;
            padding: 20px;
        }

        .dice {
            width: 150px;
            height: 150px;
            margin: 0 auto 20px;
            background-color: white;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 5rem;
            font-weight: bold;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border: 5px solid var(--primary);
            transition: transform 0.5s ease;
        }

        .dice-rolling {
            animation: roll 1s ease-in-out infinite;
        }

        @keyframes roll {
            0% { transform: rotate(0deg) scale(1); }
            25% { transform: rotate(90deg) scale(1.1); }
            50% { transform: rotate(180deg) scale(1); }
            75% { transform: rotate(270deg) scale(1.1); }
            100% { transform: rotate(360deg) scale(1); }
        }

        .bet-options {
            flex: 1;
            min-width: 300px;
        }

        .option-group {
            margin-bottom: 20px;
        }

        .option-group h3 {
            margin-bottom: 10px;
            color: var(--primary);
        }

        .bet-type-options, .bet-amount-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .bet-btn {
            background: var(--light);
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .bet-btn:hover, .bet-btn.selected {
            background: var(--primary);
            color: white;
        }

        .submit-btn {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            padding: 12px 30px;
            font-size: 1.1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(22, 93, 255, 0.3);
            display: block;
            margin: 20px auto;
        }

        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(22, 93, 255, 0.4);
        }

        .submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .results {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .results h2 {
            color: var(--primary);
            margin-bottom: 15px;
        }

        .result-win {
            color: var(--success);
            font-weight: bold;
        }

        .result-lose {
            color: var(--danger);
            font-weight: bold;
        }

        .user-info {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .user-info p {
            margin: 8px 0;
            font-size: 1.05rem;
        }

        .error {
            color: var(--danger);
            font-weight: bold;
            padding: 15px;
            background: #fff1f0;
            border-radius: 8px;
            margin: 15px 0;
            text-align: center;
        }

        .change-highlight {
            background: linear-gradient(120deg, rgba(54, 207, 201, 0.2) 0%, rgba(54, 207, 201, 0) 100%);
            padding: 2px 4px;
            border-radius: 4px;
        }

        @media (max-width: 768px) {
            header h1 {
                font-size: 2rem;
            }
            
            .game-area {
                flex-direction: column;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const betTypeBtns = document.querySelectorAll('.bet-type-options .bet-btn');
            const betAmountBtns = document.querySelectorAll('.bet-amount-options .bet-btn');
            const submitBtn = document.querySelector('.submit-btn');
            const form = document.querySelector('form');
            const dice = document.querySelector('.dice');
            const resultArea = document.getElementById('result-area');
            
            let selectedBetType = null;
            let selectedBetAmount = null;
            const cooldown = <?php echo $config['cooldown']; ?>;
            
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
                submitBtn.disabled = !(selectedBetType && selectedBetAmount);
            }
            
            // 表单提交处理
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (!selectedBetType || !selectedBetAmount) return;
                
                // 显示骰子滚动动画
                dice.classList.add('dice-rolling');
                dice.textContent = '?';
                
                // 禁用按钮防止重复提交
                submitBtn.disabled = true;
                betTypeBtns.forEach(btn => btn.disabled = true);
                betAmountBtns.forEach(btn => btn.disabled = true);
                
                // 设置表单值
                document.getElementById('bet_type').value = selectedBetType;
                document.getElementById('bet_amount').value = selectedBetAmount;
                
                // 模拟延迟以显示动画
                setTimeout(() => {
                    form.submit();
                }, 1000);
            });
            
            // 冷却时间处理
            <?php if (!empty($_SESSION['last_dice_roll'])): ?>
                const lastRoll = <?php echo $_SESSION['last_dice_roll']; ?>;
                const currentTime = <?php echo time(); ?>;
                const remaining = cooldown - (currentTime - lastRoll);
                
                if (remaining > 0) {
                    disableButtons(remaining);
                }
            <?php endif; ?>
            
            function disableButtons(remaining) {
                submitBtn.disabled = true;
                submitBtn.textContent = `请等待 (${remaining}秒)`;
                
                const timer = setInterval(() => {
                    remaining--;
                    if (remaining <= 0) {
                        clearInterval(timer);
                        submitBtn.disabled = !(selectedBetType && selectedBetAmount);
                        submitBtn.textContent = '掷骰子';
                        betTypeBtns.forEach(btn => btn.disabled = false);
                        betAmountBtns.forEach(btn => btn.disabled = false);
                        return;
                    }
                    submitBtn.textContent = `请等待 (${remaining}秒)`;
                }, 1000);
            }
            
            // 显示结果动画
            <?php if (!empty($result)): ?>
                setTimeout(() => {
                    dice.classList.remove('dice-rolling');
                    dice.textContent = <?php echo $result['dice']; ?>;
                }, 1000);
            <?php endif; ?>
        });
    </script>
</head>
<body>
    <div class="container">
        <header>
            <h1>幸运骰子</h1>
            <p>猜大小、单双或具体点数，赢取丰厚魔力值奖励！</p>
        </header>

        <div class="intro">
            <p>游戏规则：选择投注类型和金额，掷出骰子后若猜对，将获得相应倍数的魔力值奖励。<br>
            赔率说明：大小单双为2倍赔率，猜中具体点数为6倍赔率。<br>
            每次投注后有<?php echo $config['cooldown']; ?>秒冷却时间。</p>
        </div>

        <div class="user-info">
            <h2>我的状态</h2>
            <p>当前魔力值：<?php echo format_magic($user['seedbonus']); ?></p>
            <?php if ($magic_changed): ?>
                <p>魔力值变动：<span class="change-highlight"><?php echo format_magic($initial_magic); ?></span> => <span class="change-highlight"><?php echo format_magic($user['seedbonus']); ?></span></p>
            <?php endif; ?>
        </div>

        <div class="game-area">
            <div class="dice-display">
                <h3>骰子结果</h3>
                <div class="dice">
                    <?php if (empty($result)): ?>
                        ?
                    <?php else: ?>
                        <?php echo $result['dice']; ?>
                    <?php endif; ?>
                </div>
                <p>点击下方按钮开始投注</p>
            </div>

            <div class="bet-options">
                <form method="POST" id="bet-form">
                    <input type="hidden" name="bet_type" id="bet_type">
                    <input type="hidden" name="bet_amount" id="bet_amount">
                    
                    <div class="option-group">
                        <h3>选择投注类型</h3>
                        <div class="bet-type-options">
                            <?php foreach ($config['bets'] as $key => $bet): ?>
                                <button type="button" class="bet-btn" value="<?php echo $key; ?>">
                                    <?php echo $bet['name']; ?> (<?php echo $bet['description']; ?>)
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="option-group">
                        <h3>选择投注金额</h3>
                        <div class="bet-amount-options">
                            <?php foreach ($config['bet_amounts'] as $amount => $label): ?>
                                <button type="button" class="bet-btn" value="<?php echo $amount; ?>">
                                    <?php echo $label; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <button type="submit" class="submit-btn" disabled>掷骰子</button>
                </form>
            </div>
        </div>

        <div class="results" id="result-area">
            <?php if ($error): ?>
                <p class="error"><?php echo htmlspecialchars($error); ?></p>
            <?php elseif (!empty($result)): ?>
                <h2>投注结果</h2>
                <p>你投注了：<?php echo $result['bet_type']; ?> (<?php echo format_magic($result['bet_amount']); ?>)</p>
                <p>骰子点数：<?php echo $result['dice']; ?></p>
                <p class="<?php echo $result['win'] ? 'result-win' : 'result-lose'; ?>">
                    <?php echo $result['win'] ? '恭喜你赢了！获得奖励：' . format_magic($result['reward']) : '很遗憾，你输了'; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
