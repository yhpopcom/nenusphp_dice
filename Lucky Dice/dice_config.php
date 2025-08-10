<?php
return [
    // 投注选项配置
    'bets' => [
        'big' => ['name' => '大', 'description' => '4-6点', 'odds' => 2],
        'small' => ['name' => '小', 'description' => '1-3点', 'odds' => 2],
        'odd' => ['name' => '单', 'description' => '1,3,5点', 'odds' => 2],
        'even' => ['name' => '双', 'description' => '2,4,6点', 'odds' => 2],
        '1' => ['name' => '点数1', 'description' => '精确猜中1点', 'odds' => 6],
        '2' => ['name' => '点数2', 'description' => '精确猜中2点', 'odds' => 6],
        '3' => ['name' => '点数3', 'description' => '精确猜中3点', 'odds' => 6],
        '4' => ['name' => '点数4', 'description' => '精确猜中4点', 'odds' => 6],
        '5' => ['name' => '点数5', 'description' => '精确猜中5点', 'odds' => 6],
        '6' => ['name' => '点数6', 'description' => '精确猜中6点', 'odds' => 6],
    ],
    
    // 投注金额选项
    'bet_amounts' => [
        100 => '100魔力',
        500 => '500魔力',
        1000 => '1000魔力',
        5000 => '5000魔力',
        10000 => '10000魔力',
    ],
    
    // 冷却时间(秒)
    'cooldown' => 5,
];