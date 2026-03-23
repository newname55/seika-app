<?php
declare(strict_types=1);

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

// 招待トークン
$invite = (string)($_GET['invite'] ?? '');
if ($invite === '') {
  http_response_code(400);
  exit('invalid invite');
}

// QR生成URL
function qr_url(string $text, int $size = 320): string {
  return 'https://api.qrserver.com/v1/create-qr-code/?'
       . http_build_query([
           'size' => $size . 'x' . $size,
           'data' => $text,
         ]);
}

// QRに埋め込むURL（LINEログイン開始）
$inviteUrl =
  'https://ss5456ds1fds2f1dsf.asuscomm.com'
  . '/wbss/public/line_login_start.php'
  . '?invite=' . urlencode($invite);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>キャスト招待QR</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{
  box-sizing:border-box;
}
body{
  margin:0;
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  background:#f4f1eb;
  color:#111;
  font-family:"Hiragino Sans","Yu Gothic","Meiryo",system-ui,sans-serif;
  padding:24px;
}
.sheet{
  position:relative;
  width:100%;
  max-width:980px;
  min-height:560px;
  overflow:hidden;
  border:4px solid #111;
  border-radius:22px;
  background:linear-gradient(90deg,#fff100 0%,#ffc21b 38%,#ff9e1c 68%,#f56728 100%);
  box-shadow:0 28px 80px rgba(0,0,0,.18);
}
.content{
  position:relative;
  z-index:2;
  padding:24px 24px 20px;
}
.title{
  margin:0;
  font-size:64px;
  line-height:1.02;
  font-weight:900;
  letter-spacing:.01em;
}
.lead{
  margin:18px 0 0;
  font-size:44px;
  line-height:1.18;
  font-weight:800;
}
.invite{
  margin-top:22px;
  font-size:42px;
  line-height:1.1;
  font-weight:500;
}
.main{
  margin-top:22px;
  display:grid;
  grid-template-columns:260px 1fr;
  gap:28px;
  align-items:start;
  max-width:620px;
}
.qr-box{
  background:#fff;
  border:4px solid #111;
  border-radius:24px;
  padding:12px;
  width:260px;
  box-shadow:0 8px 24px rgba(0,0,0,.08);
}
.qr-box img{
  display:block;
  width:100%;
  height:auto;
}
.copy{
  padding-top:14px;
  font-size:48px;
  line-height:1.18;
  font-weight:900;
  word-break:keep-all;
}
.copy .line{
  display:block;
}
.url{
  margin-top:24px;
  max-width:560px;
  font-size:18px;
  line-height:1.25;
  word-break:break-all;
}
.mascot{
  position:absolute;
  right:-25px;
  bottom:-70px;
  width:min(50vw,450px);
  max-width:450px;
  z-index:1;
  pointer-events:none;
}
.mascot img{
  display:block;
  width:100%;
  height:auto;
}
@media (max-width: 1200px){
  .sheet{
    min-height:auto;
  }
  .title{
    font-size:58px;
  }
  .lead{
    font-size:38px;
  }
  .invite{
    font-size:38px;
  }
  .copy{
    font-size:42px;
  }
  .url{
    font-size:17px;
    max-width:520px;
    padding-right:180px;
  }
  .mascot{
    width:min(50vw,450px);
  }
}
@media (max-width: 900px){
  body{
    min-height:100dvh;
    padding:8px;
  }
  .sheet{
    width:min(100%, 430px);
    min-height:calc(80dvh - 16px);
    border-width:4px;
    border-radius:22px;
  }
  .content{
    display:flex;
    flex-direction:column;
    align-items:center;
    padding:18px 16px 6px;
    text-align:center;
  }
  .title{
    font-size:38px;
  }
  .lead{
    margin-top:12px;
    font-size:26px;
    line-height:1.25;
  }
  .invite{
    margin-top:14px;
    font-size:28px;
  }
  .main{
    margin-top:14px;
    grid-template-columns:1fr;
    gap:8px;
    max-width:none;
    width:100%;
    justify-items:center;
  }
  .qr-box{
    width:min(100%, 250px);
    margin:0 auto;
    padding:10px;
    border-width:4px;
    border-radius:20px;
  }
  .copy{
    padding-top:0;
    font-size:23px;
    text-align:center;
    line-height:1.2;
    font-weight:900;
  }
  .url{
    margin-top:4px;
    max-width:300px;
    padding-right:0;
    font-size:15px;
    line-height:1.15;
    text-align:left;
  }
  .mascot{
    position:static;
    width:min(100%, 300px);
    margin:-35px auto -30px;
  }
}
</style>
</head>
<body>
  <div class="sheet">
    <div class="content">
      <h1 class="title">WBSS キャスト招待</h1>
      <div class="lead">QR コードをスマホで読み取ってください</div>
      <div class="invite">招待コード: <?= h($invite) ?></div>
      <div class="main">
        <div class="qr-box">
          <img src="<?= h(qr_url($inviteUrl)) ?>" alt="招待QR">
        </div>
        <div class="copy">
          <span class="line">LINEで</span>
          <span class="line">ログインするダケ</span>
        </div>
      </div>
      <div class="url"><?= h($inviteUrl) ?></div>
    </div>
    <div class="mascot">
      <img src="images/omotedake_renkei.png" alt="オモテダケ">
    </div>
  </div>
</body>
</html>
