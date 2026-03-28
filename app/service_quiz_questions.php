<?php
declare(strict_types=1);

function service_quiz_questions(): array {
  return [
    [
      'id' => 1,
      'title' => 'Q1',
      'question' => '最近ちょっと仕事しんどくてさ',
      'type' => 'customer_quote',
      'choices' => [
        [
          'key' => 'A',
          'text' => 'え、大丈夫？かなり忙しいの？',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -1, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'B',
          'text' => 'それはしんどいね。今日はゆっくりできるといいね',
          'scores' => ['talk_axis' => -2, 'mood_axis' => -2, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'C',
          'text' => 'じゃあ今日はここで元気になって帰ってもらわないと',
          'scores' => ['talk_axis' => 2, 'mood_axis' => 2, 'response_axis' => 1, 'relation_axis' => 1],
        ],
        [
          'key' => 'D',
          'text' => '何が一番しんどい？人間関係？仕事量？',
          'scores' => ['talk_axis' => 1, 'mood_axis' => -1, 'response_axis' => -2, 'relation_axis' => -1],
        ],
      ],
    ],
    [
      'id' => 2,
      'title' => 'Q2',
      'question' => '俺、人見知りなんだよね',
      'type' => 'customer_quote',
      'choices' => [
        [
          'key' => 'A',
          'text' => 'え、今は普通に話しやすいけどな',
          'scores' => ['talk_axis' => 1, 'mood_axis' => 1, 'response_axis' => 1, 'relation_axis' => 1],
        ],
        [
          'key' => 'B',
          'text' => 'じゃあ今日は無理にしゃべらなくても大丈夫だよ',
          'scores' => ['talk_axis' => -2, 'mood_axis' => -2, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'C',
          'text' => '最初そう言う人の方が、仲良くなると面白かったりするよね',
          'scores' => ['talk_axis' => 1, 'mood_axis' => 1, 'response_axis' => 1, 'relation_axis' => 0],
        ],
        [
          'key' => 'D',
          'text' => '最初って緊張するよね、わかる',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -2, 'response_axis' => -1, 'relation_axis' => -2],
        ],
      ],
    ],
    [
      'id' => 3,
      'title' => 'Q3',
      'question' => '俺、昔かなりモテてたんだよ',
      'type' => 'customer_quote',
      'choices' => [
        [
          'key' => 'A',
          'text' => 'え、なんかわかる。雰囲気あるもん',
          'scores' => ['talk_axis' => 1, 'mood_axis' => 1, 'response_axis' => 1, 'relation_axis' => 2],
        ],
        [
          'key' => 'B',
          'text' => 'へえ、どんな感じだったの？気になる',
          'scores' => ['talk_axis' => 0, 'mood_axis' => 0, 'response_axis' => -1, 'relation_axis' => -1],
        ],
        [
          'key' => 'C',
          'text' => '今も普通にモテそうだけどね',
          'scores' => ['talk_axis' => 1, 'mood_axis' => 1, 'response_axis' => 2, 'relation_axis' => 2],
        ],
        [
          'key' => 'D',
          'text' => 'その頃って自分でも楽しかった？',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -1, 'response_axis' => -2, 'relation_axis' => -1],
        ],
      ],
    ],
    [
      'id' => 4,
      'title' => 'Q4',
      'question' => '静かめなお客様で、会話が少し止まった',
      'type' => 'situation',
      'prompt' => 'あなたが自然にしやすい動きは？',
      'choices' => [
        [
          'key' => 'A',
          'text' => '自分から軽く話題を出して空気を動かす',
          'scores' => ['talk_axis' => 2, 'mood_axis' => 1, 'response_axis' => 1, 'relation_axis' => 0],
        ],
        [
          'key' => 'B',
          'text' => '無理に埋めず、相手のペースを待つ',
          'scores' => ['talk_axis' => -2, 'mood_axis' => -2, 'response_axis' => -2, 'relation_axis' => -2],
        ],
        [
          'key' => 'C',
          'text' => '「緊張してる？」とやわらかく聞く',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -2, 'response_axis' => -1, 'relation_axis' => -1],
        ],
        [
          'key' => 'D',
          'text' => '相手の視線や表情を見て、反応がありそうな話題を探す',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -1, 'response_axis' => -2, 'relation_axis' => -1],
        ],
      ],
    ],
    [
      'id' => 5,
      'title' => 'Q5',
      'question' => '初対面のお客様につくことになった',
      'type' => 'situation',
      'prompt' => '最初に意識しやすいのは？',
      'choices' => [
        [
          'key' => 'A',
          'text' => 'まず自分から明るく入って場を作る',
          'scores' => ['talk_axis' => 2, 'mood_axis' => 2, 'response_axis' => 1, 'relation_axis' => 1],
        ],
        [
          'key' => 'B',
          'text' => '相手が話しやすいように、質問しながら様子を見る',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -1, 'response_axis' => -2, 'relation_axis' => -1],
        ],
        [
          'key' => 'C',
          'text' => '安心してもらえるように、やわらかい空気を作る',
          'scores' => ['talk_axis' => -2, 'mood_axis' => -2, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'D',
          'text' => '少し特別感のある言い方で印象を残す',
          'scores' => ['talk_axis' => 1, 'mood_axis' => 1, 'response_axis' => 1, 'relation_axis' => 2],
        ],
      ],
    ],
    [
      'id' => 6,
      'title' => 'Q6',
      'question' => 'テンションが高く、どんどん話してくる',
      'type' => 'customer_state',
      'prompt' => 'あなたの自然な対応は？',
      'choices' => [
        [
          'key' => 'A',
          'text' => '同じテンション感で乗って返す',
          'scores' => ['talk_axis' => 2, 'mood_axis' => 2, 'response_axis' => 2, 'relation_axis' => 1],
        ],
        [
          'key' => 'B',
          'text' => '少し落ち着きつつ、聞き役に回る',
          'scores' => ['talk_axis' => -2, 'mood_axis' => -2, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'C',
          'text' => '要所だけ盛り上げて、全体は整える',
          'scores' => ['talk_axis' => 1, 'mood_axis' => 1, 'response_axis' => -2, 'relation_axis' => -1],
        ],
        [
          'key' => 'D',
          'text' => 'この人が何を求めて話してるかを見ながら合わせる',
          'scores' => ['talk_axis' => -1, 'mood_axis' => 0, 'response_axis' => -2, 'relation_axis' => -1],
        ],
      ],
    ],
    [
      'id' => 7,
      'title' => 'Q7',
      'question' => '最近ほんと人間関係だるいんだよね',
      'type' => 'customer_quote',
      'choices' => [
        [
          'key' => 'A',
          'text' => 'それめっちゃしんどいやつだね',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -2, 'response_axis' => 1, 'relation_axis' => -2],
        ],
        [
          'key' => 'B',
          'text' => '誰かに気を使いすぎてる感じ？',
          'scores' => ['talk_axis' => 0, 'mood_axis' => -1, 'response_axis' => -2, 'relation_axis' => -1],
        ],
        [
          'key' => 'C',
          'text' => '今日はもうそういうの忘れる日にしよ',
          'scores' => ['talk_axis' => 2, 'mood_axis' => 2, 'response_axis' => 1, 'relation_axis' => 0],
        ],
        [
          'key' => 'D',
          'text' => 'ちゃんと頑張ってる人ほど疲れるよね',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -2, 'response_axis' => -1, 'relation_axis' => -2],
        ],
      ],
    ],
    [
      'id' => 8,
      'title' => 'Q8',
      'question' => '〇〇ちゃんってモテそうだよね',
      'type' => 'customer_quote',
      'choices' => [
        [
          'key' => 'A',
          'text' => 'どうだろう、でもそう見えてたらちょっとうれしいかも',
          'scores' => ['talk_axis' => 0, 'mood_axis' => 1, 'response_axis' => 1, 'relation_axis' => 2],
        ],
        [
          'key' => 'B',
          'text' => 'え、急にそういうこと言うのずるくない？',
          'scores' => ['talk_axis' => 2, 'mood_axis' => 1, 'response_axis' => 2, 'relation_axis' => 2],
        ],
        [
          'key' => 'C',
          'text' => 'ありがとう。でも話しやすいって言われる方がうれしいかも',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -1, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'D',
          'text' => 'なんでそう思ったの？',
          'scores' => ['talk_axis' => 0, 'mood_axis' => 0, 'response_axis' => -2, 'relation_axis' => 0],
        ],
      ],
    ],
    [
      'id' => 9,
      'title' => 'Q9',
      'question' => 'お客様に褒められたとき',
      'type' => 'situation',
      'prompt' => 'あなたの自然な反応は？',
      'choices' => [
        [
          'key' => 'A',
          'text' => 'ちょっと照れつつ、うれしさを素直に返す',
          'scores' => ['talk_axis' => 0, 'mood_axis' => 1, 'response_axis' => 1, 'relation_axis' => 1],
        ],
        [
          'key' => 'B',
          'text' => '冗談っぽく返して空気を軽くする',
          'scores' => ['talk_axis' => 2, 'mood_axis' => 2, 'response_axis' => 2, 'relation_axis' => 0],
        ],
        [
          'key' => 'C',
          'text' => '「ありがとう」と丁寧に受け取る',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -1, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'D',
          'text' => '相手がなぜそう言ったか少し考える',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -1, 'response_axis' => -2, 'relation_axis' => -1],
        ],
      ],
    ],
    [
      'id' => 10,
      'title' => 'Q10',
      'question' => '場が少し盛り上がり切らない',
      'type' => 'situation',
      'prompt' => 'あなたがまずやりやすいのは？',
      'choices' => [
        [
          'key' => 'A',
          'text' => '自分からネタを出して空気を変える',
          'scores' => ['talk_axis' => 2, 'mood_axis' => 2, 'response_axis' => 1, 'relation_axis' => 0],
        ],
        [
          'key' => 'B',
          'text' => '相手の話したそうな話題を探る',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -1, 'response_axis' => -2, 'relation_axis' => -1],
        ],
        [
          'key' => 'C',
          'text' => '落ち着いた会話でもいいと割り切る',
          'scores' => ['talk_axis' => -2, 'mood_axis' => -2, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'D',
          'text' => '少しだけ距離感を縮める言い方を入れる',
          'scores' => ['talk_axis' => 1, 'mood_axis' => 0, 'response_axis' => 1, 'relation_axis' => 2],
        ],
      ],
    ],
    [
      'id' => 11,
      'title' => 'Q11',
      'question' => '「また来たいな」と思ってもらいたい場面',
      'type' => 'situation',
      'prompt' => 'あなたが意識しやすいのは？',
      'choices' => [
        [
          'key' => 'A',
          'text' => '「また話したい」と思わせる空気を作る',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -1, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'B',
          'text' => '印象に残る一言やノリで記憶に残す',
          'scores' => ['talk_axis' => 2, 'mood_axis' => 2, 'response_axis' => 2, 'relation_axis' => 1],
        ],
        [
          'key' => 'C',
          'text' => '少しだけ特別扱いっぽさを出す',
          'scores' => ['talk_axis' => 1, 'mood_axis' => 1, 'response_axis' => 1, 'relation_axis' => 2],
        ],
        [
          'key' => 'D',
          'text' => '相手の話をちゃんと覚えていそうと思わせる',
          'scores' => ['talk_axis' => -1, 'mood_axis' => -1, 'response_axis' => -2, 'relation_axis' => -2],
        ],
      ],
    ],
    [
      'id' => 12,
      'title' => 'Q12',
      'question' => 'お客様が帰る直前',
      'type' => 'situation',
      'prompt' => '最後に自然に出やすいのは？',
      'choices' => [
        [
          'key' => 'A',
          'text' => '今日はありがとう、また絶対話そうね',
          'scores' => ['talk_axis' => 1, 'mood_axis' => 1, 'response_axis' => 1, 'relation_axis' => 1],
        ],
        [
          'key' => 'B',
          'text' => '気をつけて帰ってね、今日はゆっくり休んでね',
          'scores' => ['talk_axis' => -2, 'mood_axis' => -2, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'C',
          'text' => '次来たとき、今日の続き聞かせて',
          'scores' => ['talk_axis' => 0, 'mood_axis' => 0, 'response_axis' => -1, 'relation_axis' => -2],
        ],
        [
          'key' => 'D',
          'text' => '今日ちょっと特別に楽しかった',
          'scores' => ['talk_axis' => 1, 'mood_axis' => 1, 'response_axis' => 1, 'relation_axis' => 2],
        ],
      ],
    ],
  ];
}

function service_quiz_result_types(): array {
  return [
    'calm_empath' => [
      'key' => 'calm_empath',
      'name' => '安心共感型',
      'tagline' => '気持ちを受け止めて、安心感を残せる。',
      'summary' => 'あなたは、相手の気持ちを受け止めて安心感を作るのが得意なタイプです。無理に盛り上げるより、「この子と話すと落ち着く」と思ってもらうことで強さが出ます。',
      'strengths' => ['共感が自然', '緊張を下げやすい', 'リピートにつながる信頼を作りやすい'],
      'cautions' => ['無理にテンションを上げすぎると魅力が薄れやすい', '自分から押しすぎない方が良さが出る'],
      'best_customers' => ['疲れているお客様', '話を聞いてほしいお客様', '店に慣れていない初回来店客'],
      'today_tip' => 'まずは一言共感してから質問を入れると、あなたの良さが出やすいです。',
    ],
    'soft_healer' => [
      'key' => 'soft_healer',
      'name' => '癒し安定型',
      'tagline' => 'やわらかい空気で、居心地を作れる。',
      'summary' => 'あなたは、場をやわらかくして居心地を作るタイプです。強い押しではなく、安心して一緒にいられる空気で魅力が出ます。',
      'strengths' => ['空気を落ち着かせる', '無理のない接客ができる', '長く話すほど印象が良くなりやすい'],
      'cautions' => ['印象が弱くなりすぎないよう、ひとつ記憶に残る要素を入れると強い', '受け身だけで終わらない工夫があるとさらに伸びる'],
      'best_customers' => ['落ち着いたお客様', 'ひとりで来るお客様', '緊張しやすいお客様'],
      'today_tip' => '安心感に、少しだけ印象に残る言葉を足すとバランスが良くなります。',
    ],
    'energy_booster' => [
      'key' => 'energy_booster',
      'name' => '盛り上げ型',
      'tagline' => '場の温度を上げて、初速を作れる。',
      'summary' => 'あなたは、場の温度を上げて空気を明るくできるタイプです。第一印象や短時間の席で特に強さが出やすいです。',
      'strengths' => ['明るい空気を作れる', '初対面でも入りやすい', '団体やテンション高めの席に強い'],
      'cautions' => ['相手が静かなタイプのときは少し温度調整するとより良い', '盛り上げることだけに寄りすぎると浅く見えやすい'],
      'best_customers' => ['ノリの良いお客様', '団体客', '会話のテンポが速いお客様'],
      'today_tip' => '盛り上げたあとに一度だけ相手に寄せる質問を入れると、深さが出ます。',
    ],
    'flow_leader' => [
      'key' => 'flow_leader',
      'name' => '主導リード型',
      'tagline' => '流れを作って、会話を止めずに進められる。',
      'summary' => 'あなたは、自分から会話を前に進められるタイプです。席の流れを止めず、相手を迷わせない強さがあります。',
      'strengths' => ['会話を引っ張れる', 'テンポを作れる', '困った空気を立て直しやすい'],
      'cautions' => ['相手の話す余白を少し残すと、押しの強さが魅力に変わりやすい', '主導しすぎると相手によっては疲れさせることがある'],
      'best_customers' => ['優柔不断なお客様', '受け身なお客様', '会話のきっかけが少ないお客様'],
      'today_tip' => '引っ張るだけでなく、相手の言葉を一度拾ってから次へ進めると完成度が上がります。',
    ],
    'sweet_spark' => [
      'key' => 'sweet_spark',
      'name' => '恋愛感演出型',
      'tagline' => '特別感をつくって、記憶に残せる。',
      'summary' => 'あなたは、特別感やドキッとする空気を作れるタイプです。印象に残りやすく、指名導線に強さが出やすいです。',
      'strengths' => ['相手の記憶に残りやすい', '特別扱いの演出ができる', '再来店のきっかけを作りやすい'],
      'cautions' => ['強く出しすぎると軽く見られることがある', '信頼感とのバランスを持つとさらに強い'],
      'best_customers' => ['恋愛感を楽しみたいお客様', 'わかりやすい反応を返してくれるお客様', '指名動機が感情寄りのお客様'],
      'today_tip' => '特別感のあとに、少しだけ丁寧さを足すと深みが出ます。',
    ],
    'elegant_calm' => [
      'key' => 'elegant_calm',
      'name' => '大人安心型',
      'tagline' => '丁寧さと落ち着きで、信頼を積み重ねる。',
      'summary' => 'あなたは、落ち着きと丁寧さで信頼を積み重ねるタイプです。派手さより、上品さや安心感で評価されやすいです。',
      'strengths' => ['丁寧で安定感がある', '年齢層高めのお客様にも合わせやすい', '長く付き合う関係を作りやすい'],
      'cautions' => ['少しだけ親しみやすさを見せると距離が縮まりやすい', '静かすぎると印象が薄くなる場合がある'],
      'best_customers' => ['落ち着いたお客様', '役職者・経営者層', '派手すぎない接客を好むお客様'],
      'today_tip' => '丁寧さはそのままで、ひとつだけ笑顔の崩しを入れると親しみが増します。',
    ],
    'silent_analyzer' => [
      'key' => 'silent_analyzer',
      'name' => '観察サポート型',
      'tagline' => '空気を読みながら、最適な返しを選べる。',
      'summary' => 'あなたは、相手の様子や空気を見ながら最適な返しを探れるタイプです。派手さよりも、合わせる精度の高さが武器です。',
      'strengths' => ['空気を読む力が高い', '相手ごとに接客を変えやすい', '事故が少なく安定しやすい'],
      'cautions' => ['考えすぎて受け身になりすぎると魅力が伝わりにくい', 'たまに自分から印象を残す動きも必要'],
      'best_customers' => ['相手を見てほしいお客様', '会話の温度差が大きいお客様', '接客の雑さを嫌うお客様'],
      'today_tip' => '観察のあと、ひとつだけ自分から印象に残る返しを入れると強くなります。',
    ],
    'all_rounder' => [
      'key' => 'all_rounder',
      'name' => 'バランス型',
      'tagline' => '幅広く対応できて、客層を選びにくい。',
      'summary' => 'あなたは、特定の型に寄りすぎず、幅広く対応できるタイプです。相手に応じて自然に調整できるのが強みです。',
      'strengths' => ['客層を選びにくい', '大きな苦手が少ない', '店舗運用上かなり安定する'],
      'cautions' => ['逆に言うと、強い個性が伝わりにくいことがある', '自分の勝ち筋をひとつ意識すると伸びやすい'],
      'best_customers' => ['幅広い客層', '初回〜常連まで対応可', '店舗全体の調整役が必要な席'],
      'today_tip' => '万能さに加えて、自分らしい印象をひとつ残すとさらに強くなります。',
    ],
  ];
}
