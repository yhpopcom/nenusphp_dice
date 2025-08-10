<?php
return [
    // 投注选项配置
    'bets' => [
        'big' => ['name' => '大', 'description' => '4-6点', 'odds' => 2, 'range' => [4,5,6]],
        'small' => ['name' => '小', 'description' => '1-3点', 'odds' => 2, 'range' => [1,2,3]],
        'odd' => ['name' => '单', 'description' => '1,3,5点', 'odds' => 2, 'range' => [1,3,5]],
        'even' => ['name' => '双', 'description' => '2,4,6点', 'odds' => 2, 'range' => [2,4,6]],
        '1' => ['name' => '点数1', 'description' => '精确猜中1点', 'odds' => 6],
        '2' => ['name' => '点数2', 'description' => '精确猜中2点', 'odds' => 6],
        '3' => ['name' => '点数3', 'description' => '精确猜中3点', 'odds' => 6],
        '4' => ['name' => '点数4', 'description' => '精确猜中4点', 'odds' => 6],
        '5' => ['name' => '点数5', 'description' => '精确猜中5点', 'odds' => 6],
        '6' => ['name' => '点数6', 'description' => '精确猜中6点', 'odds' => 6],
    ],
    
    // 投注金额选项
    'bet_amounts' => [
        1000 => '1000魔力',
        5000 => '5000魔力',
        10000 => '10000魔力',
        50000 => '50000魔力',
        100000 => '100000魔力',
    ],
    
    // 每日开奖时间 (小时:分钟)
    'draw_time' => '23:59',
    
    // 数据库文件路径
    'db_path' => __DIR__ . '/dice_game.db',
];
?>
    