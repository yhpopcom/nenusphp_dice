<?php
return [
    // 核心配置（基础功能必须）
    'core' => [
        // 时间格式规范（统一系统时间处理格式）
        'datetime_format' => 'Y-m-d H:i:s',
        'date_format' => 'Y-m-d',
        'time_format' => '23:59',
        
        // 投注选项配置（补充描述完整性）
        'bets' => [
            'big' => [
                'name' => '大',
                'description' => '骰子点数为4、5、6点时中奖，赔率2倍',
                'odds' => 2,
                'range' => [4,5,6]
            ],
            'small' => [
                'name' => '小',
                'description' => '骰子点数为1、2、3点时中奖，赔率2倍',
                'odds' => 2,
                'range' => [1,2,3]
            ],
            'odd' => [
                'name' => '单',
                'description' => '骰子点数为1、3、5（奇数）时中奖，赔率2倍',
                'odds' => 2,
                'range' => [1,3,5]
            ],
            'even' => [
                'name' => '双',
                'description' => '骰子点数为2、4、6（偶数）时中奖，赔率2倍',
                'odds' => 2,
                'range' => [2,4,6]
            ],
            '1' => [
                'name' => '点数1',
                'description' => '精确猜中骰子点数为1时中奖，赔率6倍',
                'odds' => 6
            ],
            '2' => [
                'name' => '点数2',
                'description' => '精确猜中骰子点数为2时中奖，赔率6倍',
                'odds' => 6
            ],
            '3' => [
                'name' => '点数3',
                'description' => '精确猜中骰子点数为3时中奖，赔率6倍',
                'odds' => 6
            ],
            '4' => [
                'name' => '点数4',
                'description' => '精确猜中骰子点数为4时中奖，赔率6倍',
                'odds' => 6
            ],
            '5' => [
                'name' => '点数5',
                'description' => '精确猜中骰子点数为5时中奖，赔率6倍',
                'odds' => 6
            ],
            '6' => [
                'name' => '点数6',
                'description' => '精确猜中骰子点数为6时中奖，赔率6倍',
                'odds' => 6
            ],
        ],
        
        // 每日开奖时间（基于time_format）
        'draw_time' => '23:59',
        
        // 数据库文件路径
        'db_path' => __DIR__ . '/dice_game.db',
    ],
    
    // 扩展配置（功能限制与调试）
    'extensions' => [
        // 投注限制配置
        'bet_limits' => [
            'max_daily_bets' => 10,        // 单用户每日最大投注次数
            'max_single_bet' => 100000,    // 单注最大金额（魔力值）
            'min_single_bet' => 1000,      // 单注最小金额（魔力值）
            'max_daily_total' => 500000    // 单用户每日累计最大投注金额
        ],
        
        // 调试模式开关
        'debug_mode' => false,           // true=显示详细错误信息，false=生产模式
    ]
];
?>
