<?php
declare(strict_types=1);

function service_quiz_choice(string $key, string $text, int $talk, int $mood, int $response, int $relation): array {
  return [
    'key' => $key,
    'text' => $text,
    'scores' => [
      'talk_axis' => $talk,
      'mood_axis' => $mood,
      'response_axis' => $response,
      'relation_axis' => $relation,
    ],
  ];
}

function service_quiz_question(
  int $id,
  string $category,
  string $title,
  string $question,
  string $type,
  array $choices,
  ?string $prompt = null
): array {
  $item = [
    'id' => $id,
    'category' => $category,
    'title' => $title,
    'question' => $question,
    'type' => $type,
    'choices' => $choices,
  ];
  if ($prompt !== null) {
    $item['prompt'] = $prompt;
  }
  return $item;
}

function service_quiz_category_specs(): array {
  return [
    'first_impression' => ['label' => '初対面対応', 'min' => 1, 'soft_max' => 2],
    'quiet_guest' => ['label' => '静かな客対応', 'min' => 1, 'soft_max' => 2],
    'tired_guest' => ['label' => '疲れた客対応', 'min' => 1, 'soft_max' => 2],
    'romance' => ['label' => '恋愛系対応', 'min' => 1, 'soft_max' => 2],
    'energy' => ['label' => '盛り上げ系対応', 'min' => 1, 'soft_max' => 2],
    'silence_break' => ['label' => '会話停止時', 'min' => 1, 'soft_max' => 2],
    'farewell' => ['label' => '帰り際', 'min' => 1, 'soft_max' => 2],
    'nomination' => ['label' => '指名導線', 'min' => 1, 'soft_max' => 2],
  ];
}

function service_quiz_questions(): array {
  return [
    service_quiz_question(1, 'tired_guest', 'Q1', '最近ちょっと仕事しんどくてさ', 'customer_quote', [
      service_quiz_choice('A', 'え、大丈夫？かなり忙しいの？', -1, -1, -1, -2),
      service_quiz_choice('B', 'それはしんどいね。今日はゆっくりできるといいね', -2, -2, -1, -2),
      service_quiz_choice('C', 'じゃあ今日はここで元気になって帰ってもらわないと', 2, 2, 1, 1),
      service_quiz_choice('D', '何が一番しんどい？人間関係？仕事量？', 1, -1, -2, -1),
    ]),
    service_quiz_question(2, 'first_impression', 'Q2', '俺、人見知りなんだよね', 'customer_quote', [
      service_quiz_choice('A', 'え、今は普通に話しやすいけどな', 1, 1, 1, 1),
      service_quiz_choice('B', 'じゃあ今日は無理にしゃべらなくても大丈夫だよ', -2, -2, -1, -2),
      service_quiz_choice('C', '最初そう言う人の方が、仲良くなると面白かったりするよね', 1, 1, 1, 0),
      service_quiz_choice('D', '最初って緊張するよね、わかる', -1, -2, -1, -2),
    ]),
    service_quiz_question(3, 'romance', 'Q3', '俺、昔かなりモテてたんだよ', 'customer_quote', [
      service_quiz_choice('A', 'え、なんかわかる。雰囲気あるもん', 1, 1, 1, 2),
      service_quiz_choice('B', 'へえ、どんな感じだったの？気になる', 0, 0, -1, -1),
      service_quiz_choice('C', '今も普通にモテそうだけどね', 1, 1, 2, 2),
      service_quiz_choice('D', 'その頃って自分でも楽しかった？', -1, -1, -2, -1),
    ]),
    service_quiz_question(4, 'silence_break', 'Q4', '静かめなお客様で、会話が少し止まった', 'situation', [
      service_quiz_choice('A', '自分から軽く話題を出して空気を動かす', 2, 1, 1, 0),
      service_quiz_choice('B', '無理に埋めず、相手のペースを待つ', -2, -2, -2, -2),
      service_quiz_choice('C', '「緊張してる？」とやわらかく聞く', -1, -2, -1, -1),
      service_quiz_choice('D', '相手の視線や表情を見て、反応がありそうな話題を探す', -1, -1, -2, -1),
    ], 'あなたが自然にしやすい動きは？'),
    service_quiz_question(5, 'first_impression', 'Q5', '初対面のお客様につくことになった', 'situation', [
      service_quiz_choice('A', 'まず自分から明るく入って場を作る', 2, 2, 1, 1),
      service_quiz_choice('B', '相手が話しやすいように、質問しながら様子を見る', -1, -1, -2, -1),
      service_quiz_choice('C', '安心してもらえるように、やわらかい空気を作る', -2, -2, -1, -2),
      service_quiz_choice('D', '少し特別感のある言い方で印象を残す', 1, 1, 1, 2),
    ], '最初に意識しやすいのは？'),
    service_quiz_question(6, 'energy', 'Q6', 'テンションが高く、どんどん話してくる', 'customer_state', [
      service_quiz_choice('A', '同じテンション感で乗って返す', 2, 2, 2, 1),
      service_quiz_choice('B', '少し落ち着きつつ、聞き役に回る', -2, -2, -1, -2),
      service_quiz_choice('C', '要所だけ盛り上げて、全体は整える', 1, 1, -2, -1),
      service_quiz_choice('D', 'この人が何を求めて話してるかを見ながら合わせる', -1, 0, -2, -1),
    ], 'あなたの自然な対応は？'),
    service_quiz_question(7, 'tired_guest', 'Q7', '最近ほんと人間関係だるいんだよね', 'customer_quote', [
      service_quiz_choice('A', 'それめっちゃしんどいやつだね', -1, -2, 1, -2),
      service_quiz_choice('B', '誰かに気を使いすぎてる感じ？', 0, -1, -2, -1),
      service_quiz_choice('C', '今日はもうそういうの忘れる日にしよ', 2, 2, 1, 0),
      service_quiz_choice('D', 'ちゃんと頑張ってる人ほど疲れるよね', -1, -2, -1, -2),
    ]),
    service_quiz_question(8, 'romance', 'Q8', '〇〇ちゃんってモテそうだよね', 'customer_quote', [
      service_quiz_choice('A', 'どうだろう、でもそう見えてたらちょっとうれしいかも', 0, 1, 1, 2),
      service_quiz_choice('B', 'え、急にそういうこと言うのずるくない？', 2, 1, 2, 2),
      service_quiz_choice('C', 'ありがとう。でも話しやすいって言われる方がうれしいかも', -1, -1, -1, -2),
      service_quiz_choice('D', 'なんでそう思ったの？', 0, 0, -2, 0),
    ]),
    service_quiz_question(9, 'romance', 'Q9', 'お客様に褒められたとき', 'situation', [
      service_quiz_choice('A', 'ちょっと照れつつ、うれしさを素直に返す', 0, 1, 1, 1),
      service_quiz_choice('B', '冗談っぽく返して空気を軽くする', 2, 2, 2, 0),
      service_quiz_choice('C', '「ありがとう」と丁寧に受け取る', -1, -1, -1, -2),
      service_quiz_choice('D', '相手がなぜそう言ったか少し考える', -1, -1, -2, -1),
    ], 'あなたの自然な反応は？'),
    service_quiz_question(10, 'silence_break', 'Q10', '場が少し盛り上がり切らない', 'situation', [
      service_quiz_choice('A', '自分からネタを出して空気を変える', 2, 2, 1, 0),
      service_quiz_choice('B', '相手の話したそうな話題を探る', -1, -1, -2, -1),
      service_quiz_choice('C', '落ち着いた会話でもいいと割り切る', -2, -2, -1, -2),
      service_quiz_choice('D', '少しだけ距離感を縮める言い方を入れる', 1, 0, 1, 2),
    ], 'あなたがまずやりやすいのは？'),
    service_quiz_question(11, 'nomination', 'Q11', '「また来たいな」と思ってもらいたい場面', 'situation', [
      service_quiz_choice('A', '「また話したい」と思わせる空気を作る', -1, -1, -1, -2),
      service_quiz_choice('B', '印象に残る一言やノリで記憶に残す', 2, 2, 2, 1),
      service_quiz_choice('C', '少しだけ特別扱いっぽさを出す', 1, 1, 1, 2),
      service_quiz_choice('D', '相手の話をちゃんと覚えていそうと思わせる', -1, -1, -2, -2),
    ], 'あなたが意識しやすいのは？'),
    service_quiz_question(12, 'farewell', 'Q12', 'お客様が帰る直前', 'situation', [
      service_quiz_choice('A', '今日はありがとう、また絶対話そうね', 1, 1, 1, 1),
      service_quiz_choice('B', '気をつけて帰ってね、今日はゆっくり休んでね', -2, -2, -1, -2),
      service_quiz_choice('C', '次来たとき、今日の続き聞かせて', 0, 0, -1, -2),
      service_quiz_choice('D', '今日ちょっと特別に楽しかった', 1, 1, 1, 2),
    ], '最後に自然に出やすいのは？'),
    service_quiz_question(13, 'first_impression', 'Q13', '最初の1分で印象を作りたい場面', 'situation', [
      service_quiz_choice('A', '自分からテンポよく話して、場を温める', 2, 2, 1, 0),
      service_quiz_choice('B', '相手の反応を見ながら、入り方を調整する', -1, 0, -2, -1),
      service_quiz_choice('C', 'まずは笑顔とやわらかさで安心させる', -2, -2, -1, -2),
      service_quiz_choice('D', '少し印象に残る一言を入れて覚えてもらう', 1, 1, 1, 2),
    ], '最初に取りやすい動きは？'),
    service_quiz_question(14, 'quiet_guest', 'Q14', '無口なお客様が静かに飲んでいる', 'customer_state', [
      service_quiz_choice('A', '軽い話題を自分から置いてみる', 2, 1, 1, 0),
      service_quiz_choice('B', '反応が出るまで無理に詰めない', -2, -2, -2, -2),
      service_quiz_choice('C', '飲み方や表情から気分を読んで話題を選ぶ', -1, -1, -2, -1),
      service_quiz_choice('D', 'ひとことだけ距離を縮める言い方をしてみる', 1, 0, 1, 2),
    ], '自然にしやすい対応は？'),
    service_quiz_question(15, 'tired_guest', 'Q15', '今日はもう何も考えたくないかも、と言われた', 'customer_quote', [
      service_quiz_choice('A', 'じゃあ今日はゆるく過ごそっか', -2, -2, -1, -2),
      service_quiz_choice('B', 'それだけ頑張ってきたってことだよね', -1, -2, -1, -2),
      service_quiz_choice('C', 'ここでは考えなくていいようにするね', 1, 1, 1, 0),
      service_quiz_choice('D', '何かひとつだけ話したいことある？', 0, -1, -2, -1),
    ]),
    service_quiz_question(16, 'romance', 'Q16', '「今日ちょっと雰囲気違うね」と言われた', 'customer_quote', [
      service_quiz_choice('A', 'え、気づいた？ちょっとうれしい', 0, 1, 1, 2),
      service_quiz_choice('B', 'そういうのさらっと言うの反則じゃない？', 2, 1, 2, 2),
      service_quiz_choice('C', 'ありがとう。でも話しやすい方が大事かも', -1, -1, -1, -2),
      service_quiz_choice('D', 'どこが違って見えた？', 0, 0, -2, 0),
    ]),
    service_quiz_question(17, 'energy', 'Q17', '団体席で会話の温度差が大きい', 'situation', [
      service_quiz_choice('A', '自分が真ん中でテンポを作る', 2, 2, 1, 0),
      service_quiz_choice('B', '静かな人も拾えるように順番に振る', 1, 0, -2, -1),
      service_quiz_choice('C', '一番盛り上がってる流れにまず乗る', 2, 2, 2, 1),
      service_quiz_choice('D', '無理に全員を同じ温度にしない', -1, -1, -1, -2),
    ], '取りやすい立ち回りは？'),
    service_quiz_question(18, 'silence_break', 'Q18', '話題が切れたあと、少し気まずい空気が流れた', 'situation', [
      service_quiz_choice('A', 'すぐ軽いネタを入れて切り替える', 2, 2, 1, 0),
      service_quiz_choice('B', '相手の飲み物や仕草から次の話題を探す', -1, -1, -2, -1),
      service_quiz_choice('C', '気まずさごと笑いに変える', 2, 2, 2, 1),
      service_quiz_choice('D', '少し間を置いて相手のペースを待つ', -2, -2, -2, -2),
    ], '自然にしやすいのは？'),
    service_quiz_question(19, 'farewell', 'Q19', '「今日は来てよかった」と言われた帰り際', 'customer_quote', [
      service_quiz_choice('A', 'それ聞けるとうれしい。また話そうね', 1, 1, 1, 1),
      service_quiz_choice('B', 'ありがとう。ちゃんと休んで帰ってね', -2, -2, -1, -2),
      service_quiz_choice('C', '次も今日くらい楽にしていこうね', 0, -1, -1, -2),
      service_quiz_choice('D', '今日ちょっと特別にうれしかった', 1, 1, 1, 2),
    ]),
    service_quiz_question(20, 'nomination', 'Q20', '次につなげる一言を考えたい場面', 'situation', [
      service_quiz_choice('A', '今日の話の続き、次聞かせてね', 0, 0, -1, -2),
      service_quiz_choice('B', 'また会ったらもっと面白い気がする', 1, 1, 1, 1),
      service_quiz_choice('C', '次はもう少し特別に話したいかも', 1, 1, 1, 2),
      service_quiz_choice('D', '今日のこと、ちゃんと覚えておくね', -1, -1, -2, -2),
    ]),
    service_quiz_question(21, 'quiet_guest', 'Q21', '質問には答えるけど、自分からはあまり話さない', 'customer_state', [
      service_quiz_choice('A', '短い問いかけを重ねて少しずつ開く', 1, 0, -2, -1),
      service_quiz_choice('B', '沈黙も含めて居心地を優先する', -2, -2, -2, -2),
      service_quiz_choice('C', 'こちらの話を少し多めにして流れを作る', 2, 1, 1, 0),
      service_quiz_choice('D', '共通点を探して一点突破する', 1, 1, -1, 1),
    ], '取りやすい対応は？'),
    service_quiz_question(22, 'tired_guest', 'Q22', '「最近ずっと寝不足なんだよね」と言われた', 'customer_quote', [
      service_quiz_choice('A', 'それはきついね、今日は無理しないでいこう', -2, -2, -1, -2),
      service_quiz_choice('B', 'ちゃんと休めてないんだね', -1, -2, -1, -2),
      service_quiz_choice('C', 'ここではちょっと回復して帰ってほしいな', 2, 2, 1, 0),
      service_quiz_choice('D', '仕事？生活リズム？どっちが大きい？', 1, -1, -2, -1),
    ]),
    service_quiz_question(23, 'energy', 'Q23', '相手が「今日はテンション高めで飲みたい」と言った', 'customer_quote', [
      service_quiz_choice('A', 'じゃあ今日は最初から飛ばしていこう', 2, 2, 2, 1),
      service_quiz_choice('B', '了解、でも疲れたらすぐ言ってね', 0, -1, -1, -1),
      service_quiz_choice('C', '盛り上げつつ、ちゃんと拾っていくね', 1, 1, -1, 0),
      service_quiz_choice('D', 'そのテンションに合う話題持ってくるね', 2, 2, 1, 1),
    ]),
    service_quiz_question(24, 'nomination', 'Q24', '指名につながる印象を残したい初回終盤', 'situation', [
      service_quiz_choice('A', '今日の会話で相手に合うポイントを言葉にする', 0, 0, -2, -1),
      service_quiz_choice('B', '少しだけ特別扱いっぽい言い方を入れる', 1, 1, 1, 2),
      service_quiz_choice('C', 'また話したいと思える安心感を残す', -1, -1, -1, -2),
      service_quiz_choice('D', '印象に残る一言で締める', 2, 2, 2, 1),
    ]),
  ];
}

function service_quiz_result_types(): array {
  return [
    'calm_empath' => [
      'key' => 'calm_empath',
      'name' => '安心共感型',
      'type_en' => 'Calm Empath',
      'copy' => '気持ちを受け止めて、安心を残せる人。',
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
      'type_en' => 'Soft Healer',
      'copy' => '一緒にいると、楽になる。',
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
      'type_en' => 'Energy Booster',
      'copy' => '場の温度を上げて、初速を作れる人。',
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
      'type_en' => 'Flow Leader',
      'copy' => '流れを止めない人。',
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
      'type_en' => 'Sweet Spark',
      'copy' => '特別感を残して、記憶に残れる人。',
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
      'type_en' => 'Elegant Calm',
      'copy' => '丁寧さと落ち着きで、信頼を積み上げる人。',
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
      'type_en' => 'Silent Analyzer',
      'copy' => '空気を読んで、最適解を選べる人。',
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
      'type_en' => 'All Rounder',
      'copy' => '幅広く対応して、崩れにくい人。',
      'tagline' => '幅広く対応できて、客層を選びにくい。',
      'summary' => 'あなたは、特定の型に寄りすぎず、幅広く対応できるタイプです。相手に応じて自然に調整できるのが強みです。',
      'strengths' => ['客層を選びにくい', '大きな苦手が少ない', '店舗運用上かなり安定する'],
      'cautions' => ['逆に言うと、強い個性が伝わりにくいことがある', '自分の勝ち筋をひとつ意識すると伸びやすい'],
      'best_customers' => ['幅広い客層', '初回〜常連まで対応可', '店舗全体の調整役が必要な席'],
      'today_tip' => '万能さに加えて、自分らしい印象をひとつ残すとさらに強くなります。',
    ],
  ];
}
