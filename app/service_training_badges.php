<?php

return [
    [
        'key' => 'first_done',
        'name' => 'はじめの一歩',
        'description' => '今日のミッションを最初に1回やり切った称号です。',
        'rarity' => 1,
        'condition' => [
            'type' => 'mission_total_done',
            'threshold' => 1,
        ],
    ],
    [
        'key' => 'streak_3',
        'name' => '3日連続チャレンジ',
        'description' => '小さな行動を3日つなげた、習慣化の入口です。',
        'rarity' => 1,
        'condition' => [
            'type' => 'streak_days',
            'threshold' => 3,
        ],
    ],
    [
        'key' => 'streak_7',
        'name' => '習慣化の芽',
        'description' => '7日連続でミッションに向き合えた、かなり良い流れです。',
        'rarity' => 2,
        'condition' => [
            'type' => 'streak_days',
            'threshold' => 7,
        ],
    ],
    [
        'key' => 'manners_seed',
        'name' => '所作のたね',
        'description' => '基本マナーの積み重ねが、接客の土台として育ってきています。',
        'rarity' => 2,
        'condition' => [
            'type' => 'category_done',
            'category' => 'basic_manners',
            'threshold' => 3,
        ],
    ],
    [
        'key' => 'entry_smile',
        'name' => '入り上手',
        'description' => '会話の入り方で空気を作れる力が伸びている証です。',
        'rarity' => 2,
        'condition' => [
            'type' => 'category_done',
            'category' => 'conversation_entry',
            'threshold' => 3,
        ],
    ],
    [
        'key' => 'air_reader',
        'name' => '空気よみ職人',
        'description' => '先回りや空気読みの精度が、接客に活き始めています。',
        'rarity' => 3,
        'condition' => [
            'type' => 'category_done',
            'category' => 'air_reading',
            'threshold' => 3,
        ],
    ],
    [
        'key' => 'silent_keeper',
        'name' => '間の使い手',
        'description' => '沈黙を怖がらず、心地よい間に変える力の証です。',
        'rarity' => 3,
        'condition' => [
            'type' => 'category_done',
            'category' => 'silence',
            'threshold' => 3,
        ],
    ],
    [
        'key' => 'closing_touch',
        'name' => '余韻メーカー',
        'description' => '帰り際のひとことや締め方が、印象に残る形になっています。',
        'rarity' => 2,
        'condition' => [
            'type' => 'category_done',
            'category' => 'closing',
            'threshold' => 3,
        ],
    ],
    [
        'key' => 'calm_master',
        'name' => '安心感マスター',
        'description' => '安心させる接客スタイルが、はっきり強みとして出ています。',
        'rarity' => 3,
        'condition' => [
            'type' => 'quiz_score',
            'axis' => 'mood_axis',
            'direction' => 'negative',
            'threshold' => 4,
        ],
    ],
    [
        'key' => 'lead_master',
        'name' => '主導リーダー',
        'description' => '自分から流れを作る接客が、明確な持ち味になっています。',
        'rarity' => 3,
        'condition' => [
            'type' => 'quiz_score',
            'axis' => 'talk_axis',
            'direction' => 'positive',
            'threshold' => 4,
        ],
    ],
    [
        'key' => 'observe_master',
        'name' => '観察の達人',
        'description' => '相手の反応や空気を丁寧に拾う力が高い証です。',
        'rarity' => 4,
        'secret' => true,
        'condition' => [
            'type' => 'quiz_score',
            'axis' => 'response_axis',
            'direction' => 'negative',
            'threshold' => 4,
        ],
    ],
    [
        'key' => 'spark_master',
        'name' => '特別感クリエイター',
        'description' => '印象に残る特別感を自然に出せる、少しレアな強みです。',
        'rarity' => 4,
        'secret' => true,
        'condition' => [
            'type' => 'quiz_score',
            'axis' => 'relation_axis',
            'direction' => 'positive',
            'threshold' => 4,
        ],
    ],
];
