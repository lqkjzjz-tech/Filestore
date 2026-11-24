<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Dhaka');

/* ========================= CONFIG ========================= */
const BOT_TOKEN = '9095011150:AAHpQAPnzON8zQXZ0UqYzzF42IPU6YWwGxw';
const BOT_USERNAME = '@zebsms_bot';
const ADMIN_ID = 8758365020;
const SUPPORT_USERNAME = '@laib8382';
const ORDER_CHANNEL = '@file_store26';
const BKASH_NUMBER = '01728771355';
const NAGAD_NUMBER = '01728771355';
const POINT_NAME = 'Point';
const DB_FILE = __DIR__ . '/nexora_store.sqlite';

const FORCE_JOIN_CHANNELS = [
    '@file_store26',
    '@filestoreupdate',
    '@xyzoptmethod'
];

/* ===================== CUSTOM BUTTONS ===================== */
/* Compatible with the same platform style system:
   primary / success / danger */
function makeBtn(string $text, string $style = 'primary'): array {
    return ['text' => $text, 'style' => $style];
}

function replyKeyboard(array $rows): string {
    return json_encode([
        'keyboard' => $rows,
        'resize_keyboard' => true,
        'is_persistent' => true
    ], JSON_UNESCAPED_UNICODE);
}

/* ====================== TELEGRAM API ====================== */
function tg(string $method, array $data = []): array {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'description' => 'cURL is not enabled.'];
    }
    $ch = curl_init($url);
    if ($ch === false) return ['ok' => false];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!is_string($response) || $response === '') return ['ok' => false];
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['ok' => false];
}

function sendMessage(int|string $chatId, string $text, ?string $keyboard = null): array {
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    if ($keyboard !== null) $data['reply_markup'] = $keyboard;
    return tg('sendMessage', $data);
}


function sendPurchaseNotification(array $buyer, array $product): void
{
    if (ORDER_CHANNEL === '' || ORDER_CHANNEL === '@YOUR_ORDER_CHANNEL') {
        return;
    }

    $name = htmlspecialchars((string)($buyer['first_name'] ?? 'User'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rawUsername = trim((string)($buyer['username'] ?? ''));
    $username = $rawUsername !== '' ? '@' . ltrim($rawUsername, '@') : 'Not set';
    $username = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $chatId = (string)($buyer['telegram_id'] ?? '');
    $productName = htmlspecialchars((string)($product['name'] ?? 'Product'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $price = number_format((float)($product['price'] ?? 0), 2);

    $text =
        "🛒 <b>NEW PRODUCT PURCHASE</b>\n\n" .
        "📦 Product: <b>{$productName}</b>\n" .
        "💰 Price: <b>{$price} " . POINT_NAME . "</b>\n\n" .
        "👤 Name: <b>{$name}</b>\n" .
        "🆔 Chat ID: <code>{$chatId}</code>\n" .
        "🔗 Username: <b>{$username}</b>\n\n" .
        "🕒 Time: <code>" . date('Y-m-d H:i:s') . "</code>\n" .
        "✅ Status: <b>Purchase Successful</b>";

    sendMessage(ORDER_CHANNEL, $text);
}

function sendDocument(int|string $chatId, string $fileId, string $caption = ''): array {
    return tg('sendDocument', [
        'chat_id' => $chatId,
        'document' => $fileId,
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ]);
}

/* ========================== DB ============================= */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER UNIQUE NOT NULL,
                first_name TEXT DEFAULT '',
                username TEXT DEFAULT '',
                points REAL DEFAULT 0,
                referred_by INTEGER DEFAULT NULL,
                joined_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT DEFAULT '',
                price REAL DEFAULT 0,
                file_id TEXT DEFAULT '',
                category TEXT DEFAULT 'General',
                active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS purchases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                price REAL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS deposits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                method TEXT NOT NULL,
                amount REAL DEFAULT 0,
                trx TEXT DEFAULT '',
                status TEXT DEFAULT 'pending',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS referrals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                referrer_id INTEGER NOT NULL,
                referred_id INTEGER UNIQUE NOT NULL,
                reward REAL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT DEFAULT ''
            );
            CREATE TABLE IF NOT EXISTS daily_bonus_claims (
                telegram_id INTEGER NOT NULL,
                claim_date TEXT NOT NULL,
                amount REAL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (telegram_id, claim_date)
            );
        ");

        if (getSetting('referral_bonus', '') === '') {
            setSetting('referral_bonus', '10');
        }
        if (getSetting('daily_bonus', '') === '') {
            setSetting('daily_bonus', '5');
        }
    }
    return $pdo;
}

function getUser(int $id): ?array {
    $s = db()->prepare("SELECT * FROM users WHERE telegram_id=?");
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ?: null;
}

function ensureUser(int $id, string $first = '', string $username = ''): array {
    $u = getUser($id);
    if (!$u) {
        db()->prepare("INSERT INTO users (telegram_id,first_name,username) VALUES (?,?,?)")
            ->execute([$id, $first, $username]);
    } else {
        db()->prepare("UPDATE users SET first_name=?,username=? WHERE telegram_id=?")
            ->execute([$first, $username, $id]);
    }
    return getUser($id) ?? [];
}

function getSetting(string $key, string $default = ''): string {
    $s = db()->prepare("SELECT value FROM settings WHERE key=?");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function setSetting(string $key, string $value): void {
    db()->prepare("
        INSERT INTO settings (key,value) VALUES (?,?)
        ON CONFLICT(key) DO UPDATE SET value=excluded.value
    ")->execute([$key, $value]);
}

function deleteSetting(string $key): void {
    db()->prepare("DELETE FROM settings WHERE key=?")->execute([$key]);
}

/* ====================== FORCE JOIN ========================= */
function isJoined(int $uid, string $channel): bool {
    $r = tg('getChatMember', ['chat_id'=>$channel, 'user_id'=>$uid]);
    if (!($r['ok'] ?? false)) return false;
    return in_array($r['result']['status'] ?? 'left',
        ['creator','administrator','member','restricted'], true);
}

function checkForceJoin(int $uid): bool {
    foreach (FORCE_JOIN_CHANNELS as $c) {
        if (!isJoined($uid, $c)) return false;
    }
    return true;
}

function joinKeyboard(): string {
    $rows = [];
    foreach (FORCE_JOIN_CHANNELS as $i => $c) {
        $rows[] = [makeBtn('📢 Join Channel ' . ($i + 1), 'primary')];
    }
    $rows[] = [makeBtn('✅ VERIFY JOIN', 'success')];
    return replyKeyboard($rows);
}

function showForceJoin(int|string $chatId): void {
    sendMessage($chatId,
        "🔐 <b>Join Required</b>

" .
        "বট ব্যবহার করার আগে আমাদের ৩টি channel-এ join করুন।

" .
        "সবগুলো join করার পরে <b>✅ VERIFY JOIN</b> চাপুন।",
        joinKeyboard()
    );
}

/* ========================= MENUS ============================ */
function mainKeyboard(int $uid): string {
    $menu = [
        [makeBtn('🛍️ ALL FILES', 'primary')],
        [makeBtn('👤 ACCOUNT', 'primary'), makeBtn('🤝 REFERRAL', 'primary')],
        [makeBtn('🎁 DAILY BONUS', 'success'), makeBtn('💳 DEPOSIT', 'success')],
        [makeBtn('📦 MY PURCHASES', 'primary')],
        [makeBtn('🎧 SUPPORT', 'primary'), makeBtn('ℹ️ INFORMATION', 'primary')]
    ];
    if ($uid === ADMIN_ID) $menu[] = [makeBtn('⚙️ ADMIN PANEL', 'danger')];
    return replyKeyboard($menu);
}

function homeKeyboard(): string {
    return replyKeyboard([[makeBtn('🏠 HOME', 'primary')]]);
}

function depositKeyboard(): string {
    return replyKeyboard([
        [makeBtn('📱 bKash', 'success'), makeBtn('📱 Nagad', 'success')],
        [makeBtn('🏠 HOME', 'primary')]
    ]);
}

function adminKeyboard(): string {
    return replyKeyboard([
        [makeBtn('📊 STATISTICS', 'primary'), makeBtn('👥 USERS', 'primary')],
        [makeBtn('➕ ADD PRODUCT', 'success'), makeBtn('📦 PRODUCTS', 'primary')],
        [makeBtn('🗑️ DELETE PRODUCT', 'danger'), makeBtn('🗑️ DELETE ALL', 'danger')],
        [makeBtn('⚙️ BONUS SETTINGS', 'primary')],
        [makeBtn('💳 PENDING DEPOSITS', 'primary')],
        [makeBtn('📢 BROADCAST', 'primary')],
        [makeBtn('🏠 USER PANEL', 'primary')]
    ]);
}

/* =========================== TEXT =========================== */
function homeText(array $u): string {
    $name = htmlspecialchars((string)($u['first_name'] ?? 'User'));
    $points = number_format((float)($u['points'] ?? 0), 2);
    return "✨ <b>𝐍𝐎𝐕𝐀 𝐒𝐇𝐎𝐏 𝐁𝐃</b>

" .
        "স্বাগতম, <b>{$name}</b>! 👋

" .
        "🛍️ Premium Digital Store
" .
        "📦 আপনার পছন্দের files/products কিনুন
" .
        "💰 Balance/Point ব্যবহার করে instant purchase করুন।

" .
        "💎 আপনার Balance: <b>{$points} " . POINT_NAME . "</b>

" .
        "নিচের menu থেকে একটি option নির্বাচন করুন।";
}

function accountText(array $u): string {
    $username = !empty($u['username']) ? '@'.ltrim((string)$u['username'],'@') : 'Not set';
    return "👤 <b>MY ACCOUNT</b>

" .
        "🆔 ID: <code>{$u['telegram_id']}</code>
" .
        "👤 Name: <b>".htmlspecialchars((string)$u['first_name'])."</b>
" .
        "🔗 Username: <b>".htmlspecialchars($username)."</b>

" .
        "💎 Balance: <b>".number_format((float)$u['points'],2)." ".POINT_NAME."</b>
" .
        "📅 Joined: <b>".htmlspecialchars((string)$u['joined_at'])."</b>";
}

function referralText(array $u): string {
    $link = "https://t.me/".BOT_USERNAME."?start=ref_".$u['telegram_id'];
    $s = db()->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id=?");
    $s->execute([$u['telegram_id']]);
    $count = (int)$s->fetchColumn();
    $bonus = (float)getSetting('referral_bonus', '10');

    return "🤝 <b>REFERRAL PROGRAM</b>

" .
        "🔗 আপনার referral link:
<code>{$link}</code>

" .
        "👥 Total referrals: <b>{$count}</b>
" .
        "🎁 প্রতি successful referral: <b>".number_format($bonus,2)." ".POINT_NAME."</b>

" .
        "বন্ধু আপনার link দিয়ে /start করলে referral bonus আপনার balance-এ যোগ হবে এবং আপনাকে notification দেওয়া হবে।";
}

function dailyBonusAmount(): float {
    return (float)getSetting('daily_bonus', '5');
}

function dailyBonusClaimedToday(int $uid): bool {
    $date = date('Y-m-d');
    $s = db()->prepare("SELECT 1 FROM daily_bonus_claims WHERE telegram_id=? AND claim_date=?");
    $s->execute([$uid, $date]);
    return (bool)$s->fetchColumn();
}

function claimDailyBonus(int $uid): array {
    $amount = dailyBonusAmount();
    if ($amount <= 0) {
        return [false, "❌ Daily Bonus এখন disabled করা আছে।"];
    }

    $date = date('Y-m-d');
    $pdo = db();

    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare("SELECT 1 FROM daily_bonus_claims WHERE telegram_id=? AND claim_date=?");
        $s->execute([$uid, $date]);
        if ($s->fetchColumn()) {
            $pdo->rollBack();
            return [false, "⏳ <b>Daily Bonus Already Claimed</b>

আজকের bonus আপনি ইতিমধ্যে নিয়ে ফেলেছেন।
আগামীকাল আবার নিতে পারবেন।"];
        }

        $pdo->prepare("UPDATE users SET points=points+? WHERE telegram_id=?")
            ->execute([$amount, $uid]);

        $pdo->prepare("INSERT INTO daily_bonus_claims(telegram_id,claim_date,amount) VALUES(?,?,?)")
            ->execute([$uid, $date, $amount]);

        $pdo->commit();

        return [true, "🎁 <b>Daily Bonus Claimed!</b>

💰 পেয়েছেন: <b>".number_format($amount,2)." ".POINT_NAME."</b>

📅 Date: <b>{$date}</b>"];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [false, "❌ Daily Bonus নেওয়া যায়নি।"];
    }
}

function depositText(): string {
    return "💳 <b>DEPOSIT</b>

" .
        "📱 bKash: <code>".BKASH_NUMBER."</code>
" .
        "📱 Nagad: <code>".NAGAD_NUMBER."</code>

" .
        "Payment করার পরে method নির্বাচন করুন।";
}

function infoText(): string {
    return "ℹ️ <b>𝐍𝐎𝐕𝐀 𝐒𝐇𝐎𝐏 𝐁𝐃</b>

" .
        "🛍️ Premium digital products store
" .
        "💳 Easy deposit system
" .
        "📦 Instant purchase system
" .
        "🤝 Referral program";
}

function supportText(): string {
    return "🎧 <b>SUPPORT</b>

" .
        "Payment বা অন্য কোনো সমস্যা হলে যোগাযোগ করুন:

" .
        "👨‍💻 Support: <b>".SUPPORT_USERNAME."</b>";
}

function adminText(): string {
    return "⚙️ <b>NOVA ADMIN PANEL</b>

" .
        "নিচের menu থেকে management option নির্বাচন করুন।";
}

function bonusSettingsText(): string {
    $ref = (float)getSetting('referral_bonus', '10');
    $daily = (float)getSetting('daily_bonus', '5');

    return "⚙️ <b>BONUS SETTINGS</b>

" .
        "🤝 Referral Bonus: <b>".number_format($ref,2)." ".POINT_NAME."</b>
" .
        "🎁 Daily Bonus: <b>".number_format($daily,2)." ".POINT_NAME."</b>

" .
        "নিচের button থেকে যেটা পরিবর্তন করতে চান সেটি নির্বাচন করুন।";
}

/* ======================== PRODUCTS ========================== */
function productsPage(int $page=1): array {
    $per=5; $offset=max(0,($page-1)*$per);
    $s=db()->prepare("SELECT * FROM products WHERE active=1 ORDER BY id DESC LIMIT ? OFFSET ?");
    $s->bindValue(1,$per,PDO::PARAM_INT); $s->bindValue(2,$offset,PDO::PARAM_INT); $s->execute();
    $rows=$s->fetchAll();
    $count=(int)db()->query("SELECT COUNT(*) FROM products WHERE active=1")->fetchColumn();
    return [$rows,max(1,(int)ceil($count/$per))];
}

function productsText(int $page=1): string {
    [$products,$pages]=productsPage($page);
    $text="🛍️ <b>ALL FILES</b>

";
    if (!$products) return $text."বর্তমানে কোনো product available নেই।";
    foreach($products as $i=>$p) {
        $n=$i+1+(($page-1)*5);
        $text.="<b>{$n}.</b> ".htmlspecialchars((string)$p['name'])." — <b>".
            number_format((float)$p['price'],2)." ".POINT_NAME."</b>
";
    }
    return $text."
📄 Page <b>{$page}</b> / <b>{$pages}</b>

".
        "💡 Product কিনতে <code>/buy PRODUCT_ID</code> ব্যবহার করুন।";
}

function productListKeyboard(int $page=1): string {
    [$products,$pages]=productsPage($page);
    $rows=[];
    foreach($products as $p) {
        $rows[]=[makeBtn('📦 #'.$p['id'].' '.$p['name'].' — '.number_format((float)$p['price'],2).' '.POINT_NAME,'primary')];
    }
    if($page<$pages) $rows[]=[makeBtn('NEXT ➡️','primary')];
    if($page>1) $rows[]=[makeBtn('⬅️ BACK','primary')];
    $rows[]=[makeBtn('🏠 HOME','primary')];
    return replyKeyboard($rows);
}

function purchasesText(int $uid): string {
    $s=db()->prepare("SELECT p.*,pr.name FROM purchases p LEFT JOIN products pr ON pr.id=p.product_id WHERE p.telegram_id=? ORDER BY p.id DESC LIMIT 20");
    $s->execute([$uid]); $rows=$s->fetchAll();
    $text="📦 <b>MY PURCHASES</b>

";
    if(!$rows) return $text."আপনি এখনো কোনো product purchase করেননি।";
    foreach($rows as $r) {
        $text.="• <b>".htmlspecialchars((string)($r['name']??'Product'))."</b>
".
            "  💰 ".number_format((float)$r['price'],2)." ".POINT_NAME."
".
            "  📅 ".htmlspecialchars((string)$r['created_at'])."

";
    }
    return $text;
}

/* ========================= HELPERS ========================== */
function isAdmin(int $id): bool { return $id === ADMIN_ID; }
function getUserReferral(int $uid): ?array {
    $s=db()->prepare("SELECT * FROM referrals WHERE referred_id=?"); $s->execute([$uid]);
    $r=$s->fetch(); return $r?:null;
}
function setAdminState(int $id,string $state):void { setSetting('admin_state_'.$id,$state); }
function getAdminState(int $id):string { return getSetting('admin_state_'.$id); }
function clearAdminState(int $id):void { deleteSetting('admin_state_'.$id); }

function buyProduct(int $uid,int $pid):array {
    $pdo=db();
    $s=$pdo->prepare("SELECT * FROM products WHERE id=? AND active=1"); $s->execute([$pid]); $p=$s->fetch();
    if(!$p) return [false,"❌ Product পাওয়া যায়নি।",null];
    $u=getUser($uid); if(!$u) return [false,"❌ User account পাওয়া যায়নি।",null];
    $price=(float)$p['price'];
    if((float)$u['points']<$price) return [false,
        "❌ <b>Insufficient Balance</b>

💎 Balance: <b>".number_format((float)$u['points'],2).
        "</b>
💰 প্রয়োজন: <b>".number_format($price,2)."</b>

প্রথমে Deposit করুন.",null];
    try {
        $pdo->beginTransaction();
        $s=$pdo->prepare("UPDATE users SET points=points-? WHERE telegram_id=? AND points>=?");
        $s->execute([$price,$uid,$price]);
        if($s->rowCount()!==1){$pdo->rollBack();return[false,"❌ Balance update করা যায়নি।",null];}
        $pdo->prepare("INSERT INTO purchases (telegram_id,product_id,price) VALUES (?,?,?)")->execute([$uid,$pid,$price]);
        $pdo->commit(); return [true,'success',$p];
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack(); return[false,"❌ Purchase failed.",null];
    }
}

/* ========================== UPDATE ========================== */
$update=json_decode(file_get_contents('php://input')?:'{}',true);
if(!is_array($update)||!$update){exit('NEXORA STORE PRO is running.');}

if(isset($update['message'])){
    $m=$update['message']; $chatId=(int)($m['chat']['id']??0); $uid=(int)($m['from']['id']??0);
    $first=(string)($m['from']['first_name']??''); $username=(string)($m['from']['username']??'');
    $user=ensureUser($uid,$first,$username); $text=trim((string)($m['text']??''));

    /* START + REFERRAL */
    if($text!==''&&str_starts_with($text,'/start')){
        $parts=preg_split('/\s+/',$text); $param=$parts[1]??'';
        if(str_starts_with($param,'ref_')){
            $ref=(int)substr($param,4);
            if($ref>0&&$ref!==$uid&&!getUserReferral($uid)){
                if(getUser($ref)){
                    $reward=(float)getSetting('referral_bonus','10');

                    $insert=db()->prepare("INSERT OR IGNORE INTO referrals(referrer_id,referred_id,reward) VALUES(?,?,?)");
                    $insert->execute([$ref,$uid,$reward]);

                    if($insert->rowCount()===1){
                        db()->prepare("UPDATE users SET points=points+? WHERE telegram_id=?")->execute([$reward,$ref]);

                        $newUserName=htmlspecialchars($first ?: 'User');
                        sendMessage(
                            $ref,
                            "🎉 <b>New Referral!</b>

".
                            "👤 <b>{$newUserName}</b> আপনার referral link দিয়ে bot-এ join করেছে।

".
                            "🎁 Referral Bonus: <b>".number_format($reward,2)." ".POINT_NAME."</b>

".
                            "💎 আপনার balance-এ bonus যোগ হয়েছে।",
                            mainKeyboard($ref)
                        );
                    }
                }
            }
        }
        if(!checkForceJoin($uid)&&!isAdmin($uid)){showForceJoin($chatId);exit;}
        sendMessage($chatId,homeText(getUser($uid)??[]),mainKeyboard($uid)); exit;
    }

    /* ADMIN COMMAND */
    if($text==='/admin'&&isAdmin($uid)){sendMessage($chatId,adminText(),adminKeyboard());exit;}

    /* FORCE JOIN */
    if(!checkForceJoin($uid)&&!isAdmin($uid)){
        if($text==='✅ VERIFY JOIN'){
            if(checkForceJoin($uid)) sendMessage($chatId,homeText(getUser($uid)??[]),mainKeyboard($uid));
            else sendMessage($chatId,"❌ এখনো সবগুলো channel join করা হয়নি।",joinKeyboard());
            exit;
        }
        if(preg_match('/^📢 Join Channel ([123])$/u',$text,$mm)){
            $i=((int)$mm[1])-1; $c=FORCE_JOIN_CHANNELS[$i]??''; $c=ltrim($c,'@');
            if($c!=='') sendMessage($chatId,"📢 Join করুন:

https://t.me/".$c,joinKeyboard());
            exit;
        }
        showForceJoin($chatId);exit;
    }

    /* ADMIN STATES */
    if(isAdmin($uid)){
        $state=getAdminState($uid);

        if($state==='set_referral_bonus'&&$text!==''){
            if(!is_numeric($text)||(float)$text<0){
                sendMessage($chatId,"❌ Valid amount দিন। উদাহরণ: <code>10</code>");
                exit;
            }
            setSetting('referral_bonus',(string)(float)$text);
            clearAdminState($uid);
            sendMessage($chatId,
                "✅ <b>Referral Bonus Updated</b>

".
                "🤝 প্রতি successful referral: <b>".number_format((float)$text,2)." ".POINT_NAME."</b>",
                adminKeyboard()
            );
            exit;
        }

        if($state==='set_daily_bonus'&&$text!==''){
            if(!is_numeric($text)||(float)$text<0){
                sendMessage($chatId,"❌ Valid amount দিন। উদাহরণ: <code>5</code>");
                exit;
            }
            setSetting('daily_bonus',(string)(float)$text);
            clearAdminState($uid);
            sendMessage($chatId,
                "✅ <b>Daily Bonus Updated</b>

".
                "🎁 Daily Bonus: <b>".number_format((float)$text,2)." ".POINT_NAME."</b>",
                adminKeyboard()
            );
            exit;
        }

        if($state==='delete_product'&&$text!==''){
            if(!ctype_digit($text)){
                sendMessage($chatId,"❌ শুধু product ID দিন। উদাহরণ: <code>12</code>");
                exit;
            }

            $pid=(int)$text;
            $s=db()->prepare("SELECT name FROM products WHERE id=?");
            $s->execute([$pid]);
            $product=$s->fetch();

            if(!$product){
                sendMessage($chatId,"❌ Product #{$pid} পাওয়া যায়নি।");
                exit;
            }

            db()->prepare("DELETE FROM products WHERE id=?")->execute([$pid]);
            clearAdminState($uid);

            sendMessage($chatId,
                "✅ <b>Product Deleted</b>

".
                "🆔 ID: <code>#{$pid}</code>
".
                "📦 ".htmlspecialchars((string)$product['name']),
                adminKeyboard()
            );
            exit;
        }

        if($state==='delete_all_products'&&$text!==''){
            if(strtoupper(trim($text))!=='DELETE ALL'){
                sendMessage($chatId,"❌ Confirmation ভুল। ঠিক এইভাবে লিখুন: <code>DELETE ALL</code>");
                exit;
            }

            $count=(int)db()->query("SELECT COUNT(*) FROM products")->fetchColumn();
            db()->exec("DELETE FROM products");
            clearAdminState($uid);

            sendMessage($chatId,
                "✅ <b>All Products Deleted</b>

".
                "🗑️ Deleted: <b>{$count}</b> products",
                adminKeyboard()
            );
            exit;
        }

        if($state==='broadcast'&&$text!==''){
            $rows=db()->query("SELECT telegram_id FROM users")->fetchAll();$sent=0;
            foreach($rows as $r) if(tg('sendMessage',['chat_id'=>$r['telegram_id'],'text'=>$text,'parse_mode'=>'HTML'])['ok']??false)$sent++;
            clearAdminState($uid);sendMessage($chatId,"📢 <b>Broadcast Complete</b>

Sent: <b>{$sent}</b>",adminKeyboard());exit;
        }

        if($state==='add_name'&&$text!==''){
            setSetting('new_product_name_'.$uid,$text);setAdminState($uid,'add_price');
            sendMessage($chatId,"💰 Product price কত ".POINT_NAME." হবে?

শুধু number পাঠান.");exit;
        }

        if($state==='add_price'&&$text!==''){
            if(!is_numeric($text)||(float)$text<0){sendMessage($chatId,"❌ Valid price দিন।");exit;}
            setSetting('new_product_price_'.$uid,$text);setAdminState($uid,'add_description');
            sendMessage($chatId,"📝 Product description পাঠান.");exit;
        }

        if($state==='add_description'&&$text!==''){
            setSetting('new_product_description_'.$uid,$text);setAdminState($uid,'add_file');
            sendMessage($chatId,"📁 এখন product-এর <b>file/document</b> পাঠান।");exit;
        }

        if($state==='add_file'&&isset($m['document'])){
            $name=getSetting('new_product_name_'.$uid,'Product');
            $price=(float)getSetting('new_product_price_'.$uid,'0');
            $desc=getSetting('new_product_description_'.$uid,'');
            db()->prepare("INSERT INTO products(name,description,price,file_id,category,active) VALUES(?,?,?,?,?,1)")
                ->execute([$name,$desc,$price,$m['document']['file_id'],'General']);
            clearAdminState($uid);
            sendMessage($chatId,"✅ <b>Product Added Successfully!</b>

📦 ".htmlspecialchars($name).
                "
💰 ".number_format($price,2)." ".POINT_NAME,adminKeyboard());exit;
        }

        if(str_starts_with($state,'approve_amount:')&&$text!==''){
            $did=(int)substr($state,15);
            if(!is_numeric($text)||(float)$text<=0){sendMessage($chatId,"❌ সঠিক amount দিন।");exit;}
            $amount=(float)$text;$pdo=db();
            try{
                $pdo->beginTransaction();
                $s=$pdo->prepare("SELECT * FROM deposits WHERE id=? AND status='pending'");$s->execute([$did]);$d=$s->fetch();
                if(!$d){$pdo->rollBack();clearAdminState($uid);sendMessage($chatId,"❌ Deposit পাওয়া যায়নি।");exit;}
                $pdo->prepare("UPDATE users SET points=points+? WHERE telegram_id=?")->execute([$amount,$d['telegram_id']]);
                $pdo->prepare("UPDATE deposits SET status='approved',amount=? WHERE id=? AND status='pending'")->execute([$amount,$did]);
                $pdo->commit();clearAdminState($uid);
                sendMessage((int)$d['telegram_id'],"✅ <b>Deposit Approved</b>

💰 Added: <b>".number_format($amount,2)." ".POINT_NAME."</b>",mainKeyboard((int)$d['telegram_id']));
                sendMessage($chatId,"✅ <b>Deposit Approved Successfully</b>

🆔 #{$did}
💰 ".number_format($amount,2)." ".POINT_NAME,adminKeyboard());
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();clearAdminState($uid);sendMessage($chatId,"❌ Deposit approval failed.",adminKeyboard());}
            exit;
        }
    }

    /* ===================== REPLY BUTTON ACTIONS ===================== */
    if($text==='🛍️ ALL FILES'){sendMessage($chatId,productsText(1),productListKeyboard(1));exit;}
    if($text==='👤 ACCOUNT'){sendMessage($chatId,accountText(getUser($uid)??[]),homeKeyboard());exit;}

    if($text==='🎁 DAILY BONUS'){
        [$ok,$bonusMsg]=claimDailyBonus($uid);
        sendMessage($chatId,$bonusMsg,mainKeyboard($uid));
        exit;
    }

    if($text==='🤝 REFERRAL'){sendMessage($chatId,referralText(getUser($uid)??[]),homeKeyboard());exit;}
    if($text==='💳 DEPOSIT'){sendMessage($chatId,depositText(),depositKeyboard());exit;}

    if($text==='📱 bKash'||$text==='📱 Nagad'){
        $method=$text==='📱 bKash'?'bKash':'Nagad';setSetting('state_'.$uid,'deposit:'.$method);
        $number=$method==='bKash'?BKASH_NUMBER:NAGAD_NUMBER;
        sendMessage($chatId,"💳 <b>{$method} Deposit</b>

📱 Number: <code>{$number}</code>

".
            "Payment করার পরে Transaction ID লিখে পাঠান।",homeKeyboard());exit;
    }

    if($text==='📦 MY PURCHASES'){sendMessage($chatId,purchasesText($uid),homeKeyboard());exit;}
    if($text==='🎧 SUPPORT'){sendMessage($chatId,supportText(),homeKeyboard());exit;}
    if($text==='ℹ️ INFORMATION'){sendMessage($chatId,infoText(),homeKeyboard());exit;}
    if($text==='🏠 HOME'||$text==='🏠 Main Menu'){sendMessage($chatId,homeText(getUser($uid)??[]),mainKeyboard($uid));exit;}

    if($text==='⚙️ ADMIN PANEL'&&isAdmin($uid)){sendMessage($chatId,adminText(),adminKeyboard());exit;}
    if($text==='🏠 USER PANEL'&&isAdmin($uid)){sendMessage($chatId,homeText(getUser($uid)??[]),mainKeyboard($uid));exit;}

    if($text==='📊 STATISTICS'&&isAdmin($uid)){
        $users=(int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $products=(int)db()->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $sales=(int)db()->query("SELECT COUNT(*) FROM purchases")->fetchColumn();
        $rev=(float)db()->query("SELECT COALESCE(SUM(price),0) FROM purchases")->fetchColumn();
        $refBonus=(float)getSetting('referral_bonus','10');
        $dailyBonus=(float)getSetting('daily_bonus','5');
        sendMessage($chatId,"📊 <b>STATISTICS</b>

👥 Users: <b>{$users}</b>
📦 Products: <b>{$products}</b>
🛒 Purchases: <b>{$sales}</b>
💰 Sales: <b>".number_format($rev,2)."</b>

🤝 Referral Bonus: <b>".number_format($refBonus,2)." ".POINT_NAME."</b>
🎁 Daily Bonus: <b>".number_format($dailyBonus,2)." ".POINT_NAME."</b>",adminKeyboard());exit;
    }

    if($text==='👥 USERS'&&isAdmin($uid)){
        $rows=db()->query("SELECT telegram_id,first_name,points FROM users ORDER BY id DESC LIMIT 15")->fetchAll();
        $out="👥 <b>RECENT USERS</b>

";
        foreach($rows as $r)$out.="• <code>{$r['telegram_id']}</code> ".htmlspecialchars((string)$r['first_name'])." — ".number_format((float)$r['points'],2)."
";
        sendMessage($chatId,$out,adminKeyboard());exit;
    }

    if($text==='📦 PRODUCTS'&&isAdmin($uid)){
        $rows=db()->query("SELECT id,name,price,active FROM products ORDER BY id DESC LIMIT 30")->fetchAll();
        $out="📦 <b>PRODUCTS</b>

";
        if(!$rows){
            $out.="কোনো product নেই।";
            sendMessage($chatId,$out,adminKeyboard());
            exit;
        }

        foreach($rows as $r){
            $out.="<code>#{$r['id']}</code> ".htmlspecialchars((string)$r['name'])." — ".
                number_format((float)$r['price'],2)." ".POINT_NAME.($r['active']?' ✅':' ❌')."
";
        }

        $rowsKb=[];
        foreach($rows as $r){
            $rowsKb[]=[makeBtn('❌ DELETE #'.$r['id'],'danger')];
        }
        $rowsKb[]=[makeBtn('🗑️ DELETE ALL PRODUCTS','danger')];
        $rowsKb[]=[makeBtn('🏠 ADMIN PANEL','primary')];

        sendMessage($chatId,$out,replyKeyboard($rowsKb));exit;
    }

    if($text==='➕ ADD PRODUCT'&&isAdmin($uid)){
        setAdminState($uid,'add_name');sendMessage($chatId,"➕ <b>ADD PRODUCT</b>

প্রথমে product-এর <b>name</b> পাঠান।",homeKeyboard());exit;
    }

    if($text==='⚙️ BONUS SETTINGS'&&isAdmin($uid)){
        sendMessage($chatId,bonusSettingsText(),replyKeyboard([
            [makeBtn('🤝 SET REFERRAL BONUS','success')],
            [makeBtn('🎁 SET DAILY BONUS','success')],
            [makeBtn('🏠 ADMIN PANEL','primary')]
        ]));
        exit;
    }

    if($text==='🤝 SET REFERRAL BONUS'&&isAdmin($uid)){
        setAdminState($uid,'set_referral_bonus');
        sendMessage($chatId,
            "🤝 <b>SET REFERRAL BONUS</b>

".
            "প্রতি successful referral-এ কত ".POINT_NAME." দিতে চান?

".
            "উদাহরণ: <code>10</code>",
            homeKeyboard()
        );
        exit;
    }

    if($text==='🎁 SET DAILY BONUS'&&isAdmin($uid)){
        setAdminState($uid,'set_daily_bonus');
        sendMessage($chatId,
            "🎁 <b>SET DAILY BONUS</b>

".
            "প্রতিদিন user কত ".POINT_NAME." পাবে?

".
            "উদাহরণ: <code>5</code>
".
            "Disable করতে <code>0</code> দিন।",
            homeKeyboard()
        );
        exit;
    }

    if($text==='🗑️ DELETE PRODUCT'&&isAdmin($uid)){
        sendMessage($chatId,
            "🗑️ <b>DELETE PRODUCT</b>

".
            "যে product delete করতে চান তার ID লিখুন।

".
            "উদাহরণ: <code>12</code>",
            homeKeyboard()
        );
        setAdminState($uid,'delete_product');
        exit;
    }

    if($text==='🗑️ DELETE ALL'&&isAdmin($uid)){
        sendMessage($chatId,
            "⚠️ <b>DELETE ALL PRODUCTS</b>

".
            "সব product permanently delete করতে নিশ্চিত হলে <code>DELETE ALL</code> লিখুন।",
            homeKeyboard()
        );
        setAdminState($uid,'delete_all_products');
        exit;
    }

    if($text==='🗑️ DELETE ALL PRODUCTS'&&isAdmin($uid)){
        sendMessage($chatId,
            "⚠️ <b>CONFIRM DELETE ALL</b>

".
            "সব product delete করতে <code>DELETE ALL</code> লিখুন।",
            homeKeyboard()
        );
        setAdminState($uid,'delete_all_products');
        exit;
    }

    if(preg_match('/^❌ DELETE #(\d+)$/u',$text,$mm)&&isAdmin($uid)){
        $pid=(int)$mm[1];
        $s=db()->prepare("SELECT name FROM products WHERE id=?");
        $s->execute([$pid]);
        $product=$s->fetch();

        if(!$product){
            sendMessage($chatId,"❌ Product #{$pid} পাওয়া যায়নি।",adminKeyboard());
            exit;
        }

        db()->prepare("DELETE FROM products WHERE id=?")->execute([$pid]);
        sendMessage($chatId,
            "✅ <b>Product Deleted</b>

".
            "🆔 ID: <code>#{$pid}</code>
".
            "📦 ".htmlspecialchars((string)$product['name']),
            adminKeyboard()
        );
        exit;
    }

    if($text==='🏠 ADMIN PANEL'&&isAdmin($uid)){
        clearAdminState($uid);
        sendMessage($chatId,adminText(),adminKeyboard());
        exit;
    }

    if($text==='📢 BROADCAST'&&isAdmin($uid)){
        setAdminState($uid,'broadcast');sendMessage($chatId,"📢 <b>BROADCAST</b>

যে message সবাইকে পাঠাতে চান সেটি এখন পাঠান।",homeKeyboard());exit;
    }

    if($text==='💳 PENDING DEPOSITS'&&isAdmin($uid)){
        $rows=db()->query("SELECT * FROM deposits WHERE status='pending' ORDER BY id DESC LIMIT 20")->fetchAll();
        if(!$rows){sendMessage($chatId,"💳 <b>PENDING DEPOSITS</b>

কোনো pending deposit নেই।",adminKeyboard());exit;}
        foreach($rows as $d){
            sendMessage($chatId,"💳 <b>PENDING DEPOSIT</b>

🆔 Request: <b>#{$d['id']}</b>
👤 User: <code>{$d['telegram_id']}</code>
".
                "📱 Method: <b>".htmlspecialchars((string)$d['method'])."</b>
🔑 TXID: <code>".htmlspecialchars((string)$d['trx'])."</code>",
                replyKeyboard([[makeBtn('✅ APPROVE #'.$d['id'],'success'),makeBtn('❌ REJECT #'.$d['id'],'danger')],[makeBtn('🏠 USER PANEL','primary')]]));
        }
        exit;
    }

    if(preg_match('/^✅ APPROVE #(\d+)$/u',$text,$mm)&&isAdmin($uid)){
        $did=(int)$mm[1];$s=db()->prepare("SELECT * FROM deposits WHERE id=? AND status='pending'");$s->execute([$did]);$d=$s->fetch();
        if(!$d){sendMessage($chatId,"❌ Deposit পাওয়া যায়নি।",adminKeyboard());exit;}
        setAdminState($uid,'approve_amount:'.$did);
        sendMessage($chatId,"✅ <b>APPROVE DEPOSIT #{$did}</b>

👤 User: <code>{$d['telegram_id']}</code>
📱 Method: <b>{$d['method']}</b>
🔑 TXID: <code>{$d['trx']}</code>

💰 কত Point add করবেন? শুধু amount লিখুন.",homeKeyboard());exit;
    }

    if(preg_match('/^❌ REJECT #(\d+)$/u',$text,$mm)&&isAdmin($uid)){
        $did=(int)$mm[1];$s=db()->prepare("SELECT * FROM deposits WHERE id=? AND status='pending'");$s->execute([$did]);$d=$s->fetch();
        if(!$d){sendMessage($chatId,"❌ Deposit পাওয়া যায়নি।",adminKeyboard());exit;}
        db()->prepare("UPDATE deposits SET status='rejected' WHERE id=? AND status='pending'")->execute([$did]);
        sendMessage((int)$d['telegram_id'],"❌ <b>Deposit Rejected</b>

🆔 Request: <b>#{$did}</b>");
        sendMessage($chatId,"❌ <b>Deposit #{$did} Rejected</b>",adminKeyboard());exit;
    }

    /* Deposit TXID */
    $state=getSetting('state_'.$uid,'');
    if(str_starts_with($state,'deposit:')&&$text!==''){
        $method=substr($state,8);
        db()->prepare("INSERT INTO deposits(telegram_id,method,amount,trx,status) VALUES(?,?,?,?,?)")
            ->execute([$uid,$method,0,$text,'pending']);
        deleteSetting('state_'.$uid);
        sendMessage($chatId,"✅ <b>Deposit Request Submitted</b>

📱 Method: <b>{$method}</b>
🔑 TXID: <code>".htmlspecialchars($text)."</code>

Admin verification-এর পরে balance update হবে.",mainKeyboard($uid));
        sendMessage(ADMIN_ID,"💳 <b>NEW DEPOSIT REQUEST</b>

👤 User: <code>{$uid}</code>
📱 Method: <b>{$method}</b>
🔑 TXID: <code>".htmlspecialchars($text)."</code>

Pending Deposits থেকে verify করুন.",adminKeyboard());
        exit;
    }

    /* Buy by command: /buy 12 */
    if(preg_match('/^\/buy\s+(\d+)$/i',$text,$mm)){
        [$ok,$msg,$product]=buyProduct($uid,(int)$mm[1]);
        if(!$ok){sendMessage($chatId,$msg,mainKeyboard($uid));exit;}
        sendMessage($chatId,"🎉 <b>Purchase Successful!</b>

📦 ".htmlspecialchars((string)$product['name'])."
💰 Paid: <b>".number_format((float)$product['price'],2)." ".POINT_NAME."</b>",mainKeyboard($uid));
        if(!empty($product['file_id'])) sendDocument($chatId,$product['file_id'],"✅ <b>".htmlspecialchars((string)$product['name'])."</b>

Thank you for your purchase.");
        exit;
    }
}

http_response_code(200);
echo 'OK';
?>