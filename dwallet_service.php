<?php
/**
 * ============================================================================
 * DWALLET SERVICE v3.2.0 — Production Single File (PHP + HTML Web UI)
 * ============================================================================
 *
 * CARA DEPLOY:
 *   Letakkan file ini di mana saja yang bisa diakses web server.
 *   Tidak perlu .htaccess, tidak perlu framework routing.
 *
 * ROUTING (tidak bergantung URL path):
 *   API  → POST/GET ?action=cashin      (Content-Type: application/json)
 *   UI   → GET  (tanpa action, atau ?ui) → Web Admin Dashboard
 *   Framework mode → define('DWALLET_ACTION','cashin'); require_once file ini
 *
 * Contoh URL API:
 *   http://assist.gw.sis1.net/mod/dwallet/v1/api/?action=balance
 *   http://assist.gw.sis1.net/mod/dwallet/v1/api/?action=cashin
 *   http://assist.gw.sis1.net/mod/dwallet/v1/api/index.php?action=cashin
 *
 * Contoh URL Web UI:
 *   http://assist.gw.sis1.net/mod/dwallet/v1/api/
 *   http://assist.gw.sis1.net/mod/dwallet/v1/api/?ui
 *
 * @version  3.2.0
 * @base-url http://assist.gw.sis1.net
 * ============================================================================
 */

declare(strict_types=1);

// ============================================================================
// ██  KONFIGURASI PRODUKSI — EDIT BAGIAN INI SAJA  ██
// Tidak ada env var. Tidak ada .env. Semua hardcoded di sini.
// ============================================================================

// ── Database ─────────────────────────────────────────────────────────────────
const CFG_DB_HOST    = '10.1.11.21';
const CFG_DB_PORT    = '3306';
const CFG_DB_NAME    = 'assist_gw';
const CFG_DB_USER    = 'Assist';
const CFG_DB_PASS    = 'Irac';
const CFG_DB_CHARSET = 'utf8mb4';

// ── Timezone ──────────────────────────────────────────────────────────────────
const CFG_TIMEZONE   = 'Asia/Jakarta';

// ── Web UI Admin Credentials (HTTP Basic Auth) ───────────────────────────────
const CFG_UI_USER    = 'admin';
const CFG_UI_PASS    = 'DWallet@2025!';   // GANTI sebelum production

// ── Batas Transaksi (IDR) ────────────────────────────────────────────────────
const CFG_MIN_TRX       =         1_000;
const CFG_MAX_CASHIN    = 1_000_000_000;
const CFG_MAX_CASHOUT   =   500_000_000;
const CFG_MAX_TRANSFER  =   500_000_000;
const CFG_MAX_PAYMENT   =    50_000_000;

// ── Fee Default (IDR) ────────────────────────────────────────────────────────
const CFG_FEE_CASHOUT   = 3_500;
const CFG_FEE_TRANSFER  = 0;

// ── Payment Processor ────────────────────────────────────────────────────────
// Kosongkan URL → stub mode (selalu sukses)
const CFG_PP_URL        = '';   // 'https://api.fastpay.id'
const CFG_PP_TOKEN      = '';   // Bearer token payment processor
const CFG_PP_CB_SECRET  = '';   // HMAC-SHA256 secret untuk callback

// ── Prefix Nomor Faktur ──────────────────────────────────────────────────────
const CFG_PFX_CASHIN    = 'CIN';
const CFG_PFX_CASHOUT   = 'CUT';
const CFG_PFX_TRANSFER  = 'TRF';
const CFG_PFX_PAYMENT   = 'PAY';
const CFG_PFX_REFUND    = 'RFD';

// ── COA (Chart of Account) ───────────────────────────────────────────────────
const CFG_COA_ASET      = '111001';
const CFG_COA_LIAB      = '211001';
const CFG_COA_FEE       = '411001';
const CFG_COA_EXPENSE   = '511001';

// ── Versi ─────────────────────────────────────────────────────────────────────
const DWALLET_VERSION   = '3.2.0';
const DWALLET_TIMEZONE  = CFG_TIMEZONE;

// ── Alias konstanta internal ──────────────────────────────────────────────────
define('DB_HOST',    CFG_DB_HOST);
define('DB_PORT',    CFG_DB_PORT);
define('DB_NAME',    CFG_DB_NAME);
define('DB_USER',    CFG_DB_USER);
define('DB_PASS',    CFG_DB_PASS);
define('DB_CHARSET', CFG_DB_CHARSET);

define('MIN_TRX_AMOUNT',      CFG_MIN_TRX);
define('MAX_CASHIN_AMOUNT',   CFG_MAX_CASHIN);
define('MAX_CASHOUT_AMOUNT',  CFG_MAX_CASHOUT);
define('MAX_TRANSFER_AMOUNT', CFG_MAX_TRANSFER);
define('MAX_PAYMENT_AMOUNT',  CFG_MAX_PAYMENT);
define('FEE_CASHOUT_DEFAULT', CFG_FEE_CASHOUT);
define('FEE_TRANSFER_INT',    CFG_FEE_TRANSFER);

define('PREFIX_CASHIN',   CFG_PFX_CASHIN);
define('PREFIX_CASHOUT',  CFG_PFX_CASHOUT);
define('PREFIX_TRANSFER', CFG_PFX_TRANSFER);
define('PREFIX_PAYMENT',  CFG_PFX_PAYMENT);
define('PREFIX_REFUND',   CFG_PFX_REFUND);

// Status transaksi — sesuai pulsa_penjualan.Status
define('ST_PENDING',    'P');
define('ST_SUCCESS',    'S');
define('ST_FAILED',     'G');
define('ST_CANCELLED',  'D');
define('ST_PROCESSING', 'R');

// ============================================================================
// BOOTSTRAP
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors',     '1');
date_default_timezone_set(DWALLET_TIMEZONE);

// ============================================================================
// ── DETEKSI MODE: API vs WEB UI ─────────────────────────────────────────────
//
// Prioritas action:
//   1. PHP constant DWALLET_ACTION  (framework: define sebelum require)
//   2. $_REQUEST['action']          (GET/POST param ?action=xxx)
//   3. PATH_INFO suffix             (/index.php/cashin)
//   4. REQUEST_URI suffix           (/api/cashin → 'cashin')
//   5. Tidak ada action             → tampilkan Web UI
// ============================================================================

function _dwDetectAction(): string
{
    // 1. Constant (framework mode)
    if (defined('DWALLET_ACTION') && DWALLET_ACTION !== '') {
        return strtolower(trim(DWALLET_ACTION));
    }
    // 2. Query/POST param ?action=xxx
    $a = trim($_REQUEST['action'] ?? '');
    if ($a !== '') return strtolower($a);
    // 3. PATH_INFO: /index.php/cashin
    $pi = trim($_SERVER['PATH_INFO'] ?? '');
    if ($pi !== '') {
        $seg = strtolower(trim(explode('/', ltrim($pi, '/'))[0]));
        if ($seg !== '') return $seg;
    }
    // 4. REQUEST_URI: strip known prefix, ambil segment terakhir
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $uri = preg_replace('#^/?(?:mod/dwallet/v[0-9.]+/)?api/?#', '', ltrim($uri, '/'));
    $seg = strtolower(trim(explode('/', explode('?', $uri)[0])[0]));
    if ($seg !== '' && $seg !== 'index.php') return $seg;
    // 5. Tidak ada action → Web UI
    return 'ui';
}

$DWALLET_ACTION = _dwDetectAction();

// ── Tentukan apakah ini request API atau UI ───────────────────────────────────
$DWALLET_API_ACTIONS = [
    'balance','cashin','cashout','transfer','payment',
    'refund','inquiry','history','callback','health','install',
];
$DWALLET_IS_API = in_array($DWALLET_ACTION, $DWALLET_API_ACTIONS, true);

// ── Untuk API: set header JSON sekarang ──────────────────────────────────────
if ($DWALLET_IS_API) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-DWallet-Version: ' . DWALLET_VERSION);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Request-ID, X-Callback-Signature');
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204); exit;
    }
}

// ============================================================================
// DATABASE LAYER — PDO Singleton
// ============================================================================

final class DB
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone='+07:00'",
            ]);
        }
        return self::$pdo;
    }

    public static function begin(): void    { self::conn()->beginTransaction(); }
    public static function commit(): void   { self::conn()->commit(); }
    public static function rollback(): void { if (self::conn()->inTransaction()) self::conn()->rollBack(); }
    public static function inTx(): bool     { return self::conn()->inTransaction(); }

    public static function fetch(string $q, array $p = []): array
    { $s = self::conn()->prepare($q); $s->execute($p); return $s->fetchAll(); }

    public static function first(string $q, array $p = []): ?array
    { $s = self::conn()->prepare($q); $s->execute($p); $r = $s->fetch(); return $r ?: null; }

    public static function scalar(string $q, array $p = []): mixed
    { $s = self::conn()->prepare($q); $s->execute($p); $r = $s->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }

    public static function insert(string $q, array $p = []): int
    { $s = self::conn()->prepare($q); $s->execute($p); return (int)self::conn()->lastInsertId(); }

    public static function exec(string $q, array $p = []): int
    { $s = self::conn()->prepare($q); $s->execute($p); return $s->rowCount(); }
}

// ============================================================================
// RESPONSE — JSON helper
// ============================================================================

final class Res
{
    public static function ok(array $data = [], string $msg = 'OK', int $code = 200): never
    {
        http_response_code($code);
        echo json_encode(['success' => true, 'message' => $msg, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function err(string $msg, int $code = 400, array $errs = []): never
    {
        http_response_code($code);
        $p = ['success' => false, 'message' => $msg];
        if ($errs) $p['errors'] = $errs;
        echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ============================================================================
// REQUEST
// ============================================================================

final class Req
{
    private static ?array $body = null;

    public static function body(): array
    {
        if (self::$body === null) {
            $raw = file_get_contents('php://input') ?: '';
            // Coba JSON dulu, fallback ke $_POST
            $decoded = json_decode($raw, true);
            self::$body = is_array($decoded) ? $decoded : ($_POST ?: []);
        }
        return self::$body;
    }

    public static function q(): array      { return $_GET ?? []; }
    public static function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }

    public static function ip(): string
    {
        foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k)
            if (!empty($_SERVER[$k])) return explode(',', $_SERVER[$k])[0];
        return '0.0.0.0';
    }

    public static function header(string $name): string
    {
        return $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '';
    }
}

// ============================================================================
// LOG
// ============================================================================

final class Log
{
    public static function info(string $m, array $c = []): void  { self::w('INFO',  $m, $c); }
    public static function warn(string $m, array $c = []): void  { self::w('WARN',  $m, $c); }
    public static function error(string $m, array $c = []): void { self::w('ERROR', $m, $c); }

    public static function audit(string $mod, string $act, string $desc, int $uid = 0): void
    {
        try {
            DB::insert(
                "INSERT INTO activity_log (user_id,module,action,description,ip_address,created_at)
                 VALUES (?,?,?,?,?,NOW())",
                [$uid, $mod, $act, $desc, Req::ip()]
            );
        } catch (Throwable) {}
    }

    private static function w(string $lvl, string $m, array $c): void
    {
        try {
            DB::insert(
                "INSERT INTO dwallet_audit_logs (level,message,context,ip_address,created_at)
                 VALUES (?,?,?,?,NOW())",
                [$lvl, $m, $c ? json_encode($c, JSON_UNESCAPED_UNICODE) : null, Req::ip()]
            );
        } catch (Throwable) {}
        error_log("[DWallet][$lvl] $m" . ($c ? ' ' . json_encode($c) : ''));
    }
}

// ============================================================================
// AUTH — Bearer token dari customer.api_token
// ============================================================================

final class Auth
{
    public static function check(): array
    {
        $h = Req::header('Authorization');
        if (!str_starts_with($h, 'Bearer '))
            Res::err('Autentikasi diperlukan. Sertakan Authorization: Bearer <token>.', 401);
        $token = trim(substr($h, 7));
        if (!$token) Res::err('Token tidak boleh kosong.', 401);

        $c = DB::first(
            "SELECT id, Kode, KodePro, name, email, api_token, status
             FROM customer WHERE api_token = ? LIMIT 1",
            [$token]
        );
        if (!$c) Res::err('Token tidak valid.', 401);
        if (isset($c['status']) && strtolower((string)$c['status']) !== 'active')
            Res::err('Akun tidak aktif.', 403);
        return $c;
    }
}

// ============================================================================
// NUMBERING — Nomor faktur atomik via tabel nomorfaktur
// ============================================================================

final class Numbering
{
    public static function next(string $prefix): string
    {
        DB::insert("INSERT INTO nomorfaktur (KodePro, Tanggal) VALUES ('DW', NOW())");
        $id = (int) DB::scalar("SELECT MAX(ID) FROM nomorfaktur WHERE KodePro = 'DW'");
        DB::exec("DELETE FROM nomorfaktur WHERE KodePro = 'DW' AND ID < ?", [$id]);
        return $prefix . str_pad((string)$id, 10, '0', STR_PAD_LEFT);
    }
}

// ============================================================================
// SETTING — key/value dari tabel setting (in-memory cache)
// ============================================================================

final class Setting
{
    private static array $cache = [];

    public static function get(string $key, mixed $def = null): mixed
    {
        if (!array_key_exists($key, self::$cache)) {
            $r = DB::first("SELECT setting_value FROM setting WHERE setting_key = ? LIMIT 1", [$key]);
            self::$cache[$key] = $r ? $r['setting_value'] : null;
        }
        return self::$cache[$key] ?? $def;
    }
}

// ============================================================================
// COA — Chart of Account (setting table override, fallback ke CFG_)
// ============================================================================

final class Coa
{
    public static function aset(): string    { return Setting::get('dwallet.coa_aset',    CFG_COA_ASET);    }
    public static function liab(): string    { return Setting::get('dwallet.coa_liab',    CFG_COA_LIAB);    }
    public static function fee(): string     { return Setting::get('dwallet.coa_fee',     CFG_COA_FEE);     }
    public static function expense(): string { return Setting::get('dwallet.coa_expense', CFG_COA_EXPENSE); }
}

// ============================================================================
// WALLET — saldo di dwallet_wallets + sync ke log_deposit
// ============================================================================

final class Wallet
{
    public static function getOrCreate(string $code): array
    {
        $w = DB::first("SELECT * FROM dwallet_wallets WHERE customer_code = ? LIMIT 1", [$code]);
        if (!$w) {
            $an = 'AW' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            DB::insert(
                "INSERT INTO dwallet_wallets
                     (customer_code, account_number, balance, hold_balance, status, created_at, updated_at)
                 VALUES (?, ?, 0.00, 0.00, 'active', NOW(), NOW())",
                [$code, $an]
            );
            $w = DB::first("SELECT * FROM dwallet_wallets WHERE customer_code = ? LIMIT 1", [$code]);
        }
        return $w;
    }

    public static function credit(string $code, float $amt): void
    {
        DB::exec(
            "UPDATE dwallet_wallets SET balance = balance + ?, updated_at = NOW() WHERE customer_code = ?",
            [$amt, $code]
        );
        self::sync($code);
    }

    public static function debit(string $code, float $amt): void
    {
        $n = DB::exec(
            "UPDATE dwallet_wallets SET balance = balance - ?, updated_at = NOW()
             WHERE customer_code = ? AND balance >= ?",
            [$amt, $code, $amt]
        );
        if ($n === 0) throw new RuntimeException('Saldo tidak mencukupi.');
        self::sync($code);
    }

    public static function hold(string $code, float $amt): void
    {
        $n = DB::exec(
            "UPDATE dwallet_wallets
             SET balance = balance - ?, hold_balance = hold_balance + ?, updated_at = NOW()
             WHERE customer_code = ? AND balance >= ?",
            [$amt, $amt, $code, $amt]
        );
        if ($n === 0) throw new RuntimeException('Saldo tidak mencukupi untuk hold.');
    }

    public static function releaseHold(string $code, float $amt): void
    {
        DB::exec(
            "UPDATE dwallet_wallets
             SET hold_balance = GREATEST(0, hold_balance - ?), updated_at = NOW()
             WHERE customer_code = ?",
            [$amt, $code]
        );
    }

    /** Sync ke log_deposit — Jenis = 'S' sesuai data aktual DB */
    public static function sync(string $code): void
    {
        $w = DB::first("SELECT balance FROM dwallet_wallets WHERE customer_code = ? LIMIT 1", [$code]);
        if (!$w) return;
        $now = date('Y-m-d H:i:s');
        DB::exec(
            "INSERT INTO log_deposit (Kode, Jenis, SaldoPPOB, SaldoPPOBDia, LastUpdate)
             VALUES (?, 'S', ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 SaldoPPOB    = VALUES(SaldoPPOB),
                 SaldoPPOBDia = VALUES(SaldoPPOBDia),
                 LastUpdate   = VALUES(LastUpdate)",
            [$code, $w['balance'], $w['balance'], $now]
        );
    }
}

// ============================================================================
// JOURNAL — Double-entry bookkeeping
// ============================================================================

final class Journal
{
    public static function post(string $faktur, string $jenis, array $entries): void
    {
        $today = date('Y-m-d');
        foreach ($entries as $i => $e) {
            DB::insert(
                "INSERT INTO dwallet_journal
                     (faktur, jenis_transaksi, rekening, urut, debet, kredit, keterangan, tgl, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$faktur, $jenis, $e['rek'], $i + 1,
                 $e['debet'] ?? 0, $e['kredit'] ?? 0, $e['ket'] ?? null, $today]
            );
        }
    }

    public static function cashIn(float $a): array { return [
        ['rek' => Coa::aset(), 'debet' => $a,  'kredit' => 0,  'ket' => 'Penerimaan Cash In'],
        ['rek' => Coa::liab(), 'debet' => 0,   'kredit' => $a, 'ket' => 'Kewajiban wallet'],
    ]; }

    public static function cashOut(float $a, float $fee): array {
        $e = [
            ['rek' => Coa::liab(), 'debet' => $a + $fee, 'kredit' => 0,   'ket' => 'Penarikan saldo'],
            ['rek' => Coa::aset(), 'debet' => 0,          'kredit' => $a,  'ket' => 'Pengeluaran kas'],
        ];
        if ($fee > 0) $e[] = ['rek' => Coa::fee(), 'debet' => 0, 'kredit' => $fee, 'ket' => 'Biaya CashOut'];
        return $e;
    }

    public static function transfer(float $a, float $fee): array {
        $e = [
            ['rek' => Coa::liab(), 'debet' => $a + $fee, 'kredit' => 0,  'ket' => 'Debit pengirim'],
            ['rek' => Coa::liab(), 'debet' => 0,          'kredit' => $a, 'ket' => 'Kredit penerima'],
        ];
        if ($fee > 0) $e[] = ['rek' => Coa::fee(), 'debet' => 0, 'kredit' => $fee, 'ket' => 'Biaya transfer'];
        return $e;
    }

    public static function payment(float $a, float $fee): array {
        $e = [
            ['rek' => Coa::liab(),    'debet' => $a + $fee, 'kredit' => 0,  'ket' => 'Debit pembayaran'],
            ['rek' => Coa::expense(), 'debet' => 0,          'kredit' => $a, 'ket' => 'Biaya produk PPOB'],
        ];
        if ($fee > 0) $e[] = ['rek' => Coa::fee(), 'debet' => 0, 'kredit' => $fee, 'ket' => 'Biaya layanan'];
        return $e;
    }

    public static function reversal(float $a, string $jenis): array {
        return $jenis === 'CASHIN'
            ? [['rek' => Coa::liab(), 'debet' => $a, 'kredit' => 0,  'ket' => 'Reversal CashIn'],
               ['rek' => Coa::aset(), 'debet' => 0,  'kredit' => $a, 'ket' => 'Pengembalian kas']]
            : [['rek' => Coa::aset(), 'debet' => $a, 'kredit' => 0,  'ket' => "Refund $jenis"],
               ['rek' => Coa::liab(), 'debet' => 0,  'kredit' => $a, 'ket' => 'Kredit wallet']];
    }
}

// ============================================================================
// TRANSACTION — CRUD dwallet_transactions
// ============================================================================

final class Trx
{
    public static function create(array $d): int
    {
        return DB::insert(
            "INSERT INTO dwallet_transactions
                 (faktur, jenis, kode_sender, kode_receiver, amount, fee, gross_amount,
                  keterangan, ref_number, status, note, meta, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())",
            [
                $d['faktur'], $d['jenis'],
                $d['kode_sender']   ?? null, $d['kode_receiver'] ?? null,
                $d['amount'],        $d['fee']         ?? 0,   $d['gross_amount'],
                $d['keterangan']    ?? null, $d['ref_number']    ?? null,
                $d['status']        ?? ST_PENDING,
                $d['note']          ?? null,
                isset($d['meta']) ? json_encode($d['meta'], JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }

    public static function updateStatus(string $f, string $s, ?string $n = null): void
    {
        DB::exec(
            "UPDATE dwallet_transactions SET status=?, note=COALESCE(?,note), updated_at=NOW() WHERE faktur=?",
            [$s, $n, $f]
        );
    }

    public static function byRef(string $ref, string $jenis): ?array
    {
        return DB::first(
            "SELECT * FROM dwallet_transactions WHERE ref_number=? AND jenis=? ORDER BY id DESC LIMIT 1",
            [$ref, $jenis]
        );
    }

    public static function byFaktur(string $f): ?array
    {
        return DB::first("SELECT * FROM dwallet_transactions WHERE faktur=? LIMIT 1", [$f]);
    }

    public static function history(string $code, int $page, int $pp,
        ?string $jenis = null, ?string $dari = null, ?string $sampai = null): array
    {
        $w = "(kode_sender=? OR kode_receiver=?)";
        $p = [$code, $code];
        if ($jenis)  { $w .= " AND jenis=?";             $p[] = $jenis;  }
        if ($dari)   { $w .= " AND DATE(created_at)>=?"; $p[] = $dari;   }
        if ($sampai) { $w .= " AND DATE(created_at)<=?"; $p[] = $sampai; }
        $total  = (int) DB::scalar("SELECT COUNT(*) FROM dwallet_transactions WHERE $w", $p);
        $offset = ($page - 1) * $pp;
        $rows   = DB::fetch(
            "SELECT * FROM dwallet_transactions WHERE $w ORDER BY created_at DESC LIMIT $pp OFFSET $offset",
            $p
        );
        return ['data' => $rows, 'total' => $total, 'page' => $page,
                'per_page' => $pp, 'total_page' => (int)ceil($total / $pp)];
    }
}

// ============================================================================
// RATE LIMITER
// ============================================================================

final class RateLimit
{
    public static function check(string $key, int $max = 10, int $win = 60): void
    {
        try {
            DB::exec(
                "DELETE FROM dwallet_rate_limit WHERE rate_key=? AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
                [$key, $win]
            );
            $cnt = (int) DB::scalar("SELECT COUNT(*) FROM dwallet_rate_limit WHERE rate_key=?", [$key]);
            if ($cnt >= $max) Res::err('Terlalu banyak permintaan. Coba lagi.', 429);
            DB::insert("INSERT INTO dwallet_rate_limit (rate_key, created_at) VALUES (?, NOW())", [$key]);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), "doesn't exist")) return;
            throw $e;
        }
    }
}

// ============================================================================
// VALIDATOR
// ============================================================================

final class V
{
    private array $errors = [];
    private array $data   = [];
    private array $src;

    public function __construct(array $src) { $this->src = $src; }

    public static function req(string ...$fields): self
    {
        $v = new self(Req::body());
        foreach ($fields as $f) {
            $val = $v->src[$f] ?? null;
            if ($val === null || $val === '') $v->errors[] = "Field '$f' wajib diisi.";
            else $v->data[$f] = $val;
        }
        return $v;
    }

    public function amount(string $f, float $min = MIN_TRX_AMOUNT, float $max = PHP_FLOAT_MAX): self
    {
        $val = (float)($this->src[$f] ?? 0);
        if ($val < $min)  $this->errors[] = "Amount minimal Rp " . number_format($min, 0, ',', '.');
        elseif ($val > $max) $this->errors[] = "Amount maksimal Rp " . number_format($max, 0, ',', '.');
        else $this->data[$f] = $val;
        return $this;
    }

    public function fails(): bool    { return !empty($this->errors); }
    public function errors(): array  { return $this->errors; }
    public function get(string $f, mixed $def = null): mixed
    {
        return $this->data[$f] ?? $this->src[$f] ?? $def;
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function statusLabel(string $s): string
{
    return match($s) {
        ST_PENDING    => 'PENDING',    ST_SUCCESS  => 'SUCCESS',
        ST_FAILED     => 'FAILED',     ST_CANCELLED => 'CANCELLED',
        ST_PROCESSING => 'PROCESSING', default      => 'UNKNOWN',
    };
}

function fmtTrx(array $t): array
{
    return [
        'faktur'       => $t['faktur'],        'jenis'        => $t['jenis'],
        'ref_number'   => $t['ref_number'],    'amount'       => (float)$t['amount'],
        'fee'          => (float)$t['fee'],    'gross_amount' => (float)$t['gross_amount'],
        'status'       => statusLabel($t['status']), 'status_kode' => $t['status'],
        'created_at'   => $t['created_at'],
    ];
}

function rupiah(float $n): string { return 'Rp ' . number_format($n, 0, ',', '.'); }

function disburse(string $bank, string $rek, string $nama, float $amt, string $faktur): array
{
    if (empty(CFG_PP_URL)) {
        return ['success' => true,
                'provider_ref' => 'STUB-' . strtoupper(bin2hex(random_bytes(4))),
                'message' => 'Stub mode aktif'];
    }
    $payload = json_encode([
        'external_id' => $faktur, 'bank_code' => $bank,
        'account_number' => $rek, 'account_name' => $nama,
        'amount' => (int)$amt, 'narasi' => "DWallet CashOut $faktur",
    ]);
    $ch = curl_init(CFG_PP_URL . '/disburse');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => $payload, CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . CFG_PP_TOKEN,
            'Content-Type: application/json',
            'X-External-ID: ' . $faktur,
        ],
    ]);
    $raw = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
    if ($errno || !$raw) return ['success' => false, 'message' => 'Koneksi payment processor gagal.'];
    $r = json_decode($raw, true) ?? [];
    return [
        'success'      => in_array($r['status'] ?? '', ['SUCCESS', 'COMPLETED', 'ACCEPTED']),
        'provider_ref' => $r['transaction_id'] ?? $r['ref'] ?? null,
        'message'      => $r['message'] ?? $r['responseMessage'] ?? null,
    ];
}

// ============================================================================
// API HANDLERS
// ============================================================================

function apiBalance(): void
{
    $cust = Auth::check();
    $code = $cust['KodePro'] ?: $cust['Kode'];
    $w    = Wallet::getOrCreate($code);
    $ld   = DB::first(
        "SELECT SaldoPPOB, LastUpdate FROM log_deposit WHERE Kode = ? AND Jenis = 'S' LIMIT 1",
        [$code]
    );
    Res::ok([
        'customer_code'     => $code,
        'customer_name'     => $cust['name'],
        'account_number'    => $w['account_number'],
        'balance'           => (float)$w['balance'],
        'hold_balance'      => (float)$w['hold_balance'],
        'available_balance' => (float)$w['balance'],
        'wallet_status'     => $w['status'],
        'log_deposit_saldo' => $ld ? (float)$ld['SaldoPPOB'] : null,
        'last_updated'      => $w['updated_at'],
    ]);
}

function apiCashIn(): void
{
    $cust = Auth::check();
    $code = $cust['KodePro'] ?: $cust['Kode'];
    $v    = V::req('amount', 'ref_number', 'channel')
             ->amount('amount', MIN_TRX_AMOUNT, MAX_CASHIN_AMOUNT);
    if ($v->fails()) Res::err('Validasi gagal.', 422, $v->errors());

    $amount = (float)$v->get('amount');
    $ref    = trim((string)$v->get('ref_number'));
    $ch     = trim((string)$v->get('channel'));

    if (Trx::byRef($ref, 'CASHIN')) Res::ok(fmtTrx(Trx::byRef($ref, 'CASHIN')), 'Sudah diproses.');
    RateLimit::check("cashin:$code", 20, 60);
    $faktur = Numbering::next(PREFIX_CASHIN);

    DB::begin();
    try {
        Trx::create(['faktur' => $faktur, 'jenis' => 'CASHIN', 'kode_receiver' => $code,
            'amount' => $amount, 'fee' => 0, 'gross_amount' => $amount,
            'keterangan' => "Cash In via $ch", 'ref_number' => $ref,
            'status' => ST_SUCCESS, 'meta' => ['channel' => $ch]]);
        Wallet::credit($code, $amount);
        Journal::post($faktur, 'CASHIN', Journal::cashIn($amount));
        DB::commit();
    } catch (Throwable $e) { DB::rollback(); Log::error("CashIn FAIL: " . $e->getMessage()); throw $e; }

    Log::info("CashIn OK: $faktur | $code | Rp$amount");
    Log::audit('DWallet', 'cashin', "CashIn $faktur Rp$amount", (int)$cust['id']);
    Res::ok(array_merge(fmtTrx(Trx::byFaktur($faktur)), ['channel' => $ch, 'waktu' => date('c')]),
        'Cash In berhasil.', 201);
}

function apiCashOut(): void
{
    $cust = Auth::check();
    $code = $cust['KodePro'] ?: $cust['Kode'];
    $v    = V::req('amount', 'ref_number', 'bank_code', 'no_rekening', 'nama_penerima')
             ->amount('amount', MIN_TRX_AMOUNT, MAX_CASHOUT_AMOUNT);
    if ($v->fails()) Res::err('Validasi gagal.', 422, $v->errors());

    $amount = (float)$v->get('amount');
    $ref    = trim((string)$v->get('ref_number'));
    $bank   = strtoupper(trim((string)$v->get('bank_code')));
    $norek  = trim((string)$v->get('no_rekening'));
    $nama   = trim((string)$v->get('nama_penerima'));
    $ket    = trim(Req::body()['keterangan'] ?? '');

    if (Trx::byRef($ref, 'CASHOUT')) Res::ok(fmtTrx(Trx::byRef($ref, 'CASHOUT')), 'Sudah diproses.');
    RateLimit::check("cashout:$code", 5, 60);

    $fee    = (float)(Setting::get('dwallet.fee_cashout') ?? FEE_CASHOUT_DEFAULT);
    $gross  = $amount + $fee;
    $faktur = Numbering::next(PREFIX_CASHOUT);

    DB::begin();
    try {
        Wallet::debit($code, $gross);
        Trx::create(['faktur' => $faktur, 'jenis' => 'CASHOUT', 'kode_sender' => $code,
            'amount' => $amount, 'fee' => $fee, 'gross_amount' => $gross,
            'keterangan' => $ket ?: "CashOut ke $bank/$norek", 'ref_number' => $ref,
            'status' => ST_PROCESSING,
            'meta' => ['bank_code' => $bank, 'no_rekening' => $norek, 'nama_penerima' => $nama]]);
        Journal::post($faktur, 'CASHOUT', Journal::cashOut($amount, $fee));
        DB::insert(
            "INSERT INTO pulsa_penjualan
                 (KodePro,NomorDepositCustomer,NomorTujuan,Produk,Status,JenisTrx,Harga,Keterangan,Tgl)
             VALUES (?,?,?,'CASHOUT',?,'13',?,?,NOW())",
            [$code, $faktur, $norek, ST_PROCESSING, $gross, "CashOut $bank/$norek a/n $nama"]
        );
        DB::commit();
    } catch (Throwable $e) { DB::rollback(); Log::error("CashOut FAIL: " . $e->getMessage()); throw $e; }

    $dr = disburse($bank, $norek, $nama, $amount, $faktur);
    if ($dr['success']) {
        Trx::updateStatus($faktur, ST_SUCCESS, $dr['provider_ref']);
        DB::exec("UPDATE pulsa_penjualan SET Status=? WHERE NomorDepositCustomer=?", [ST_SUCCESS, $faktur]);
    } else {
        Wallet::credit($code, $gross);
        Trx::updateStatus($faktur, ST_FAILED, $dr['message']);
        DB::exec("UPDATE pulsa_penjualan SET Status=? WHERE NomorDepositCustomer=?", [ST_FAILED, $faktur]);
        Res::err('Penarikan gagal: ' . ($dr['message'] ?? 'Error payment processor.'), 502);
    }

    Log::info("CashOut OK: $faktur | $code | Rp$amount");
    Log::audit('DWallet', 'cashout', "CashOut $faktur Rp$amount → $bank/$norek", (int)$cust['id']);
    Res::ok(array_merge(fmtTrx(Trx::byFaktur($faktur)), [
        'bank_code' => $bank, 'no_rekening' => $norek, 'nama_penerima' => $nama,
        'fee' => $fee, 'provider_ref' => $dr['provider_ref'] ?? null, 'waktu' => date('c'),
    ]), 'Cash Out berhasil.', 201);
}

function apiTransfer(): void
{
    $cust  = Auth::check();
    $code  = $cust['KodePro'] ?: $cust['Kode'];
    $v     = V::req('amount', 'kode_penerima', 'ref_number')
              ->amount('amount', MIN_TRX_AMOUNT, MAX_TRANSFER_AMOUNT);
    if ($v->fails()) Res::err('Validasi gagal.', 422, $v->errors());

    $amount = (float)$v->get('amount');
    $kodeP  = trim((string)$v->get('kode_penerima'));
    $ref    = trim((string)$v->get('ref_number'));
    $ket    = trim(Req::body()['keterangan'] ?? '');

    if ($kodeP === $code) Res::err('Tidak dapat transfer ke diri sendiri.', 422);
    $pen = DB::first("SELECT id, KodePro, name FROM customer WHERE KodePro=? LIMIT 1", [$kodeP]);
    if (!$pen) Res::err("Customer '$kodeP' tidak ditemukan.", 404);

    if (Trx::byRef($ref, 'TRANSFER')) Res::ok(fmtTrx(Trx::byRef($ref, 'TRANSFER')), 'Sudah diproses.');
    RateLimit::check("transfer:$code", 10, 60);

    $fee    = FEE_TRANSFER_INT;
    $gross  = $amount + $fee;
    $faktur = Numbering::next(PREFIX_TRANSFER);

    DB::begin();
    try {
        Wallet::debit($code, $gross);
        Wallet::credit($kodeP, $amount);
        Trx::create(['faktur' => $faktur, 'jenis' => 'TRANSFER',
            'kode_sender' => $code, 'kode_receiver' => $kodeP,
            'amount' => $amount, 'fee' => $fee, 'gross_amount' => $gross,
            'keterangan' => $ket ?: "Transfer ke $kodeP", 'ref_number' => $ref,
            'status' => ST_SUCCESS]);
        Journal::post($faktur, 'TRANSFER', Journal::transfer($amount, $fee));
        DB::commit();
    } catch (Throwable $e) { DB::rollback(); Log::error("Transfer FAIL: " . $e->getMessage()); throw $e; }

    Log::info("Transfer OK: $faktur | $code→$kodeP | Rp$amount");
    Log::audit('DWallet', 'transfer', "Transfer $faktur Rp$amount → $kodeP", (int)$cust['id']);
    Res::ok(array_merge(fmtTrx(Trx::byFaktur($faktur)),
        ['kode_penerima' => $kodeP, 'nama_penerima' => $pen['name'], 'waktu' => date('c')]),
        'Transfer berhasil.', 201);
}

function apiPayment(): void
{
    $cust   = Auth::check();
    $code   = $cust['KodePro'] ?: $cust['Kode'];
    $v      = V::req('amount', 'ref_number', 'kode_produk', 'nomor_tujuan')
               ->amount('amount', MIN_TRX_AMOUNT, MAX_PAYMENT_AMOUNT);
    if ($v->fails()) Res::err('Validasi gagal.', 422, $v->errors());

    $amount = (float)$v->get('amount');
    $ref    = trim((string)$v->get('ref_number'));
    $produk = trim((string)$v->get('kode_produk'));
    $tujuan = trim((string)$v->get('nomor_tujuan'));
    $ket    = trim(Req::body()['keterangan'] ?? '');

    if (Trx::byRef($ref, 'PAYMENT')) Res::ok(fmtTrx(Trx::byRef($ref, 'PAYMENT')), 'Sudah diproses.');
    RateLimit::check("payment:$code", 10, 60);

    $fee    = 0.0;
    $gross  = $amount + $fee;
    $faktur = Numbering::next(PREFIX_PAYMENT);

    DB::begin();
    try {
        Wallet::debit($code, $gross);
        Trx::create(['faktur' => $faktur, 'jenis' => 'PAYMENT', 'kode_sender' => $code,
            'amount' => $amount, 'fee' => $fee, 'gross_amount' => $gross,
            'keterangan' => $ket ?: "Payment $produk/$tujuan", 'ref_number' => $ref,
            'status' => ST_PROCESSING,
            'meta' => ['kode_produk' => $produk, 'nomor_tujuan' => $tujuan]]);
        Journal::post($faktur, 'PAYMENT', Journal::payment($amount, $fee));
        DB::insert(
            "INSERT INTO pulsa_penjualan
                 (KodePro,NomorDepositCustomer,NomorTujuan,Produk,Status,JenisTrx,Harga,Keterangan,Tgl)
             VALUES (?,?,?,?,?,'12',?,?,NOW())",
            [$code, $faktur, $tujuan, $produk, ST_PROCESSING, $gross, "Payment $produk $tujuan"]
        );
        DB::commit();
    } catch (Throwable $e) { DB::rollback(); Log::error("Payment FAIL: " . $e->getMessage()); throw $e; }

    Log::info("Payment OK: $faktur | $code | $produk/$tujuan");
    Log::audit('DWallet', 'payment', "Payment $faktur $produk Rp$amount", (int)$cust['id']);
    Res::ok(array_merge(fmtTrx(Trx::byFaktur($faktur)),
        ['kode_produk' => $produk, 'nomor_tujuan' => $tujuan, 'waktu' => date('c')]),
        'Payment berhasil diproses.', 201);
}

function apiRefund(): void
{
    $cust  = Auth::check();
    $code  = $cust['KodePro'] ?: $cust['Kode'];
    $v     = V::req('faktur_asal', 'alasan');
    if ($v->fails()) Res::err('Validasi gagal.', 422, $v->errors());

    $fakturAsal = trim((string)$v->get('faktur_asal'));
    $alasan     = trim((string)$v->get('alasan'));
    $trxAsal    = Trx::byFaktur($fakturAsal);
    if (!$trxAsal) Res::err("Transaksi '$fakturAsal' tidak ditemukan.", 404);
    if (!in_array($trxAsal['jenis'], ['PAYMENT', 'CASHIN'], true))
        Res::err("Jenis '{$trxAsal['jenis']}' tidak dapat di-refund.", 422);

    $ks = $trxAsal['kode_sender'] ?? $trxAsal['kode_receiver'];
    if ($ks !== $code) Res::err('Tidak dapat refund transaksi milik customer lain.', 403);
    if (!in_array($trxAsal['status'], [ST_SUCCESS, ST_PROCESSING], true))
        Res::err('Status tidak memungkinkan refund.', 422);

    $exRef = DB::first(
        "SELECT * FROM dwallet_transactions WHERE ref_number=? AND jenis='REFUND' LIMIT 1",
        [$fakturAsal]
    );
    if ($exRef) Res::ok(fmtTrx($exRef), 'Refund sudah pernah diproses.');

    $refAmt = (float)$trxAsal['gross_amount'];
    $kodeC  = $trxAsal['kode_sender'] ?? $trxAsal['kode_receiver'];
    $faktur = Numbering::next(PREFIX_REFUND);

    DB::begin();
    try {
        Wallet::credit($kodeC, $refAmt);
        Trx::create(['faktur' => $faktur, 'jenis' => 'REFUND', 'kode_receiver' => $kodeC,
            'amount' => $refAmt, 'fee' => 0, 'gross_amount' => $refAmt,
            'keterangan' => "Refund $fakturAsal: $alasan", 'ref_number' => $fakturAsal,
            'status' => ST_SUCCESS,
            'meta' => ['faktur_asal' => $fakturAsal, 'alasan' => $alasan]]);
        Trx::updateStatus($fakturAsal, ST_CANCELLED, "Refund via $faktur");
        Journal::post($faktur, 'REFUND', Journal::reversal($refAmt, $trxAsal['jenis']));
        DB::exec("UPDATE pulsa_penjualan SET Status=? WHERE NomorDepositCustomer=?",
                 [ST_CANCELLED, $fakturAsal]);
        DB::commit();
    } catch (Throwable $e) {
        DB::rollback();
        Log::error("Refund FAIL: " . $e->getMessage(), ['faktur' => $faktur, 'asal' => $fakturAsal]);
        throw $e;
    }

    Log::info("Refund OK: $faktur ← $fakturAsal | Rp$refAmt");
    Log::audit('DWallet', 'refund', "Refund $faktur ← $fakturAsal Rp$refAmt", (int)$cust['id']);
    Res::ok(array_merge(fmtTrx(Trx::byFaktur($faktur)),
        ['faktur_asal' => $fakturAsal, 'refund_amount' => $refAmt, 'alasan' => $alasan, 'waktu' => date('c')]),
        'Refund berhasil.', 201);
}

function apiInquiry(): void
{
    Auth::check();
    $faktur = trim($_GET['faktur'] ?? '');
    $ref    = trim($_GET['ref_number'] ?? '');
    if (!$faktur && !$ref) Res::err("Sertakan 'faktur' atau 'ref_number'.", 422);

    $trx = $faktur
        ? Trx::byFaktur($faktur)
        : DB::first("SELECT * FROM dwallet_transactions WHERE ref_number=? ORDER BY id DESC LIMIT 1", [$ref]);
    if (!$trx) Res::err('Transaksi tidak ditemukan.', 404);

    $meta    = json_decode($trx['meta'] ?? '{}', true) ?? [];
    $journal = DB::fetch(
        "SELECT rekening, urut, debet, kredit, keterangan, tgl
         FROM dwallet_journal WHERE faktur=? ORDER BY urut ASC",
        [$trx['faktur']]
    );
    Res::ok([
        'faktur'        => $trx['faktur'],       'jenis'        => $trx['jenis'],
        'ref_number'    => $trx['ref_number'],   'kode_sender'  => $trx['kode_sender'],
        'kode_receiver' => $trx['kode_receiver'],'amount'       => (float)$trx['amount'],
        'fee'           => (float)$trx['fee'],   'gross_amount' => (float)$trx['gross_amount'],
        'keterangan'    => $trx['keterangan'],   'status'       => statusLabel($trx['status']),
        'status_kode'   => $trx['status'],       'note'         => $trx['note'],
        'meta'          => $meta,                'journal'      => $journal,
        'created_at'    => $trx['created_at'],   'updated_at'   => $trx['updated_at'],
    ]);
}

function apiHistory(): void
{
    $cust   = Auth::check();
    $code   = $cust['KodePro'] ?: $cust['Kode'];
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $pp     = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $jenis  = strtoupper($_GET['jenis'] ?? '');
    if ($jenis && !in_array($jenis, ['CASHIN','CASHOUT','TRANSFER','PAYMENT','REFUND'], true))
        Res::err('Jenis tidak valid.', 422);

    $res = Trx::history($code, $page, $pp, $jenis ?: null, $_GET['dari'] ?? null, $_GET['sampai'] ?? null);
    $res['data'] = array_map(fn($row) => [
        'faktur'       => $row['faktur'],      'jenis'        => $row['jenis'],
        'arah'         => $row['kode_sender'] === $code ? 'KELUAR' : 'MASUK',
        'lawan_kode'   => $row['kode_sender'] === $code ? $row['kode_receiver'] : $row['kode_sender'],
        'amount'       => (float)$row['amount'], 'fee' => (float)$row['fee'],
        'gross_amount' => (float)$row['gross_amount'], 'keterangan' => $row['keterangan'],
        'ref_number'   => $row['ref_number'],  'status'      => statusLabel($row['status']),
        'status_kode'  => $row['status'],      'created_at'  => $row['created_at'],
    ], $res['data']);
    Res::ok($res);
}

function apiCallback(): void
{
    $raw    = file_get_contents('php://input') ?: '';
    $body   = json_decode($raw, true) ?? [];
    $secret = Setting::get('payment_processor_callback_secret', '') ?: CFG_PP_CB_SECRET;

    if (!empty($secret)) {
        $sig = Req::header('X-Callback-Signature');
        if (!hash_equals(hash_hmac('sha256', $raw, $secret), $sig))
            Res::err('Signature tidak valid.', 401);
    }

    $faktur  = $body['faktur'] ?? $body['external_id'] ?? $body['trx_id'] ?? null;
    $statRaw = strtoupper($body['status'] ?? '');
    $provRef = $body['provider_ref'] ?? $body['transaction_id'] ?? null;
    $corrId  = $body['correlation_id'] ?? $faktur ?? ('CB-' . time());

    if (!$faktur || !$statRaw) Res::err("Field 'faktur' dan 'status' wajib.", 422);

    try {
        DB::insert(
            "INSERT INTO dwallet_sync_results (correlation_id, response_payload, created_at)
             VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE response_payload=VALUES(response_payload)",
            [$corrId, json_encode($body, JSON_UNESCAPED_UNICODE)]
        );
    } catch (Throwable) {}
    try {
        DB::insert(
            "INSERT INTO dwallet_callbacks (faktur, provider, payload, received_at) VALUES (?,?,?,NOW())",
            [$faktur, $body['provider'] ?? 'UNKNOWN', json_encode($body, JSON_UNESCAPED_UNICODE)]
        );
    } catch (Throwable) {}

    $trx = Trx::byFaktur($faktur);
    if (!$trx) Res::ok([], 'Callback diterima (faktur tidak ditemukan).');
    if (in_array($trx['status'], [ST_SUCCESS, ST_CANCELLED], true)) Res::ok([], 'Sudah final.');

    $dbSt = match($statRaw) {
        'SUCCESS','COMPLETED','PAID','BERHASIL' => ST_SUCCESS,
        'FAILED','REJECTED','GAGAL','ERROR'     => ST_FAILED,
        default => null,
    };
    if ($dbSt) {
        Trx::updateStatus($faktur, $dbSt, $provRef ?? $statRaw);
        if ($dbSt === ST_FAILED && $trx['jenis'] === 'CASHOUT' && $trx['status'] === ST_PROCESSING)
            Wallet::credit($trx['kode_sender'], (float)$trx['gross_amount']);
        if ($trx['jenis'] === 'PAYMENT')
            DB::exec("UPDATE pulsa_penjualan SET Status=? WHERE NomorDepositCustomer=?", [$dbSt, $faktur]);
    }
    Res::ok(['faktur' => $faktur, 'status_processed' => $statRaw]);
}

function apiHealth(): void
{
    $ok = false;
    try { DB::scalar('SELECT 1'); $ok = true; } catch (Throwable) {}
    http_response_code($ok ? 200 : 503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => $ok ? 'healthy' : 'degraded',
        'version' => DWALLET_VERSION,
        'db'      => ['host' => CFG_DB_HOST, 'name' => CFG_DB_NAME, 'connected' => $ok],
        'timezone'=> DWALLET_TIMEZONE,
        'time'    => date('c'),
        'server'  => gethostname(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function apiInstall(): void
{
    $secret  = $_GET['secret'] ?? $_POST['secret'] ?? '';
    $allowed = $secret === CFG_UI_PASS
            || (!empty(Setting::get('dwallet_install_secret')) && $secret === Setting::get('dwallet_install_secret'));
    if (!$allowed) Res::err('Akses ditolak. Sertakan ?secret=<password>', 403);

    $statements = [
        "CREATE TABLE IF NOT EXISTS `dwallet_wallets` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_code` VARCHAR(30) NOT NULL,
            `account_number` VARCHAR(20) NOT NULL,
            `balance` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            `hold_balance` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_customer_code` (`customer_code`),
            UNIQUE KEY `uq_account_number` (`account_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `dwallet_transactions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `faktur` VARCHAR(30) NOT NULL,
            `jenis` ENUM('CASHIN','CASHOUT','TRANSFER','PAYMENT','REFUND') NOT NULL,
            `kode_sender` VARCHAR(30) NULL, `kode_receiver` VARCHAR(30) NULL,
            `amount` DECIMAL(20,2) NOT NULL, `fee` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            `gross_amount` DECIMAL(20,2) NOT NULL, `keterangan` VARCHAR(255) NULL,
            `ref_number` VARCHAR(64) NULL,
            `status` CHAR(1) NOT NULL DEFAULT 'P',
            `note` TEXT NULL, `meta` JSON NULL,
            `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_faktur` (`faktur`),
            INDEX `idx_ref` (`ref_number`,`jenis`),
            INDEX `idx_sender` (`kode_sender`), INDEX `idx_recv` (`kode_receiver`),
            INDEX `idx_status` (`status`), INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `dwallet_journal` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `faktur` VARCHAR(30) NOT NULL, `jenis_transaksi` VARCHAR(20) NOT NULL,
            `rekening` VARCHAR(20) NOT NULL, `urut` SMALLINT NOT NULL,
            `debet` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            `kredit` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            `keterangan` VARCHAR(255) NULL, `tgl` DATE NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), INDEX `idx_faktur` (`faktur`), INDEX `idx_tgl` (`tgl`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `dwallet_callbacks` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `faktur` VARCHAR(30) NOT NULL, `provider` VARCHAR(30) NOT NULL,
            `payload` JSON NULL, `received_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), INDEX `idx_faktur` (`faktur`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `dwallet_rate_limit` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rate_key` VARCHAR(60) NOT NULL, `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), INDEX `idx_key` (`rate_key`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "INSERT IGNORE INTO `setting` (setting_key, setting_value, created_at, updated_at) VALUES
            ('dwallet.coa_aset',                  '" . CFG_COA_ASET    . "', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
            ('dwallet.coa_liab',                  '" . CFG_COA_LIAB    . "', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
            ('dwallet.coa_fee',                   '" . CFG_COA_FEE     . "', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
            ('dwallet.coa_expense',               '" . CFG_COA_EXPENSE . "', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
            ('dwallet.fee_cashout',               '" . CFG_FEE_CASHOUT . "', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
            ('payment_processor_url',             '" . CFG_PP_URL      . "', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
            ('payment_processor_token',           '" . CFG_PP_TOKEN    . "', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
            ('payment_processor_callback_secret', '" . CFG_PP_CB_SECRET. "', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())",
    ];

    $results = [];
    foreach ($statements as $sql) {
        try {
            DB::insert($sql);
            preg_match('/(?:TABLE IF NOT EXISTS|INTO) `?(\w+)`?/', $sql, $m);
            $results[] = ['object' => $m[1] ?? 'setting', 'status' => 'OK'];
        } catch (Throwable $e) {
            preg_match('/(?:TABLE IF NOT EXISTS|INTO) `?(\w+)`?/', $sql, $m);
            $results[] = ['object' => $m[1] ?? '?', 'status' => 'ERROR: ' . $e->getMessage()];
        }
    }
    Res::ok(['results' => $results], 'Instalasi skema selesai.');
}

// ============================================================================
// DISPATCH API
// ============================================================================

if ($DWALLET_IS_API) {
    $method = Req::method();
    $map = [
        'balance'  => ['apiBalance',  ['GET','POST']],
        'cashin'   => ['apiCashIn',   ['POST']],
        'cashout'  => ['apiCashOut',  ['POST']],
        'transfer' => ['apiTransfer', ['POST']],
        'payment'  => ['apiPayment',  ['POST']],
        'refund'   => ['apiRefund',   ['POST']],
        'inquiry'  => ['apiInquiry',  ['GET','POST']],
        'history'  => ['apiHistory',  ['GET','POST']],
        'callback' => ['apiCallback', ['POST']],
        'health'   => ['apiHealth',   ['GET','POST']],
        'install'  => ['apiInstall',  ['GET','POST']],
    ];

    [$fn, $allowed] = $map[$DWALLET_ACTION];
    if (!in_array($method, $allowed, true)) {
        // Toleransi satu-arah untuk framework mode
        if (!(count($allowed) === 1 && (
            ($allowed[0] === 'POST' && $method === 'GET') ||
            ($allowed[0] === 'GET'  && $method === 'POST')
        ))) {
            header('Allow: ' . implode(', ', $allowed));
            Res::err("Method $method tidak diizinkan.", 405);
        }
    }

    try {
        $fn();
    } catch (PDOException $e) {
        Log::error('DBError: ' . $e->getMessage());
        if (DB::inTx()) DB::rollback();
        Res::err('Kesalahan database. Hubungi administrator.', 500);
    } catch (RuntimeException $e) {
        if (DB::inTx()) DB::rollback();
        Res::err($e->getMessage(), 422);
    } catch (Throwable $e) {
        Log::error('Unhandled: ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
        if (DB::inTx()) DB::rollback();
        Res::err('Kesalahan internal. Coba beberapa saat lagi.', 500);
    }
    exit;
}

// ============================================================================
// WEB UI — Admin Dashboard (HTTP Basic Auth)
// ============================================================================

$uiUser = $_SERVER['PHP_AUTH_USER'] ?? '';
$uiPass = $_SERVER['PHP_AUTH_PW']   ?? '';
if ($uiUser !== CFG_UI_USER || $uiPass !== CFG_UI_PASS) {
    header('WWW-Authenticate: Basic realm="DWallet Admin v' . DWALLET_VERSION . '"');
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(401);
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">
    <title>DWallet Admin — Login</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f4f8}
    .box{background:#fff;padding:40px;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.1);text-align:center;max-width:360px}
    h2{color:#1e3a5f;margin-bottom:8px} p{color:#64748b;font-size:14px}</style></head>
    <body><div class="box"><h2>💳 DWallet Admin</h2><p>Masukkan username dan password admin untuk melanjutkan.</p>
    <p style="margin-top:20px;font-size:12px;color:#94a3b8">v' . DWALLET_VERSION . '</p></div></body></html>';
    exit;
}

// ── Ambil data untuk dashboard ─────────────────────────────────────────────
$dbOk      = false;
$dbErr     = '';
$stats     = ['trx_today' => 0, 'trx_total' => 0, 'wallet_total' => 0,
              'vol_today' => 0.0, 'vol_total' => 0.0, 'pending_count' => 0];
$recentTrx = [];
$topWallet = [];

try {
    DB::scalar('SELECT 1');
    $dbOk = true;

    $stats['trx_today']     = (int)DB::scalar("SELECT COUNT(*) FROM dwallet_transactions WHERE DATE(created_at)=CURDATE()");
    $stats['trx_total']     = (int)DB::scalar("SELECT COUNT(*) FROM dwallet_transactions");
    $stats['wallet_total']  = (int)DB::scalar("SELECT COUNT(*) FROM dwallet_wallets");
    $stats['vol_today']     = (float)DB::scalar("SELECT COALESCE(SUM(gross_amount),0) FROM dwallet_transactions WHERE DATE(created_at)=CURDATE() AND status='S'");
    $stats['vol_total']     = (float)DB::scalar("SELECT COALESCE(SUM(gross_amount),0) FROM dwallet_transactions WHERE status='S'");
    $stats['pending_count'] = (int)DB::scalar("SELECT COUNT(*) FROM dwallet_transactions WHERE status IN ('P','R')");

    $recentTrx = DB::fetch(
        "SELECT t.faktur, t.jenis, t.kode_sender, t.kode_receiver,
                t.amount, t.fee, t.gross_amount, t.status, t.ref_number, t.created_at,
                c.name as sender_name
         FROM dwallet_transactions t
         LEFT JOIN customer c ON c.KodePro = t.kode_sender
         ORDER BY t.created_at DESC LIMIT 15"
    );

    $topWallet = DB::fetch(
        "SELECT w.customer_code, w.balance, w.account_number, w.status, c.name
         FROM dwallet_wallets w
         LEFT JOIN customer c ON c.KodePro = w.customer_code
         ORDER BY w.balance DESC LIMIT 10"
    );
} catch (Throwable $e) {
    $dbErr = $e->getMessage();
}

// ── Helper badge ──────────────────────────────────────────────────────────
$stBadge = function(string $s): string {
    $map = [
        'P' => ['PENDING',    '#f59e0b', '#fef3c7'],
        'S' => ['SUCCESS',    '#059669', '#d1fae5'],
        'G' => ['FAILED',     '#dc2626', '#fee2e2'],
        'D' => ['CANCELLED',  '#6b7280', '#f3f4f6'],
        'R' => ['PROCESSING', '#2563eb', '#dbeafe'],
    ];
    [$lbl, $color, $bg] = $map[$s] ?? ['UNKNOWN', '#9ca3af', '#f9fafb'];
    return "<span style='background:$bg;color:$color;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.3px'>$lbl</span>";
};
$jBadge = function(string $j): string {
    $map = [
        'CASHIN'   => ['#059669','#d1fae5'],
        'CASHOUT'  => ['#dc2626','#fee2e2'],
        'TRANSFER' => ['#2563eb','#dbeafe'],
        'PAYMENT'  => ['#d97706','#fef3c7'],
        'REFUND'   => ['#7c3aed','#ede9fe'],
    ];
    [$c, $bg] = $map[$j] ?? ['#6b7280','#f3f4f6'];
    return "<span style='background:$bg;color:$c;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700'>$j</span>";
};

// ── Bangun baris tabel transaksi ──────────────────────────────────────────
$trxRows = '';
foreach ($recentTrx as $t) {
    $kode  = htmlspecialchars($t['kode_sender'] ?: $t['kode_receiver'] ?: '-');
    $nama  = htmlspecialchars($t['sender_name'] ?: '-');
    $amt   = rupiah((float)$t['amount']);
    $gross = rupiah((float)$t['gross_amount']);
    $tgl   = date('d/m/y H:i', strtotime($t['created_at']));
    $ref   = htmlspecialchars($t['ref_number'] ?: '-');
    $trxRows .= "<tr>
        <td><code style='font-size:11px;color:#1e3a5f'>{$t['faktur']}</code></td>
        <td>{$jBadge($t['jenis'])}</td>
        <td><span style='font-weight:600'>$kode</span><br><small style='color:#94a3b8'>$nama</small></td>
        <td style='text-align:right;font-weight:600'>$amt</td>
        <td style='text-align:right;color:#64748b'>$gross</td>
        <td>{$stBadge($t['status'])}</td>
        <td style='color:#94a3b8;font-size:11px'>$tgl</td>
    </tr>";
}

// ── Baris top wallet ──────────────────────────────────────────────────────
$walletRows = '';
foreach ($topWallet as $w) {
    $kode  = htmlspecialchars($w['customer_code']);
    $nama  = htmlspecialchars($w['name'] ?: '-');
    $bal   = rupiah((float)$w['balance']);
    $acc   = htmlspecialchars($w['account_number']);
    $st    = $w['status'] === 'active'
           ? "<span style='color:#059669;font-weight:700'>● Aktif</span>"
           : "<span style='color:#dc2626;font-weight:700'>● {$w['status']}</span>";
    $walletRows .= "<tr>
        <td><span style='font-weight:600'>$kode</span><br><small style='color:#94a3b8'>$nama</small></td>
        <td><code style='font-size:11px'>$acc</code></td>
        <td style='text-align:right;font-weight:700;color:#1e3a5f'>$bal</td>
        <td>$st</td>
    </tr>";
}

// ── Nilai-nilai untuk template ─────────────────────────────────────────────
$ver       = DWALLET_VERSION;
$dbBadge   = $dbOk
    ? "<span style='background:#d1fae5;color:#059669;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700'>✅ Connected</span>"
    : "<span style='background:#fee2e2;color:#dc2626;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700'>❌ " . htmlspecialchars($dbErr) . "</span>";
$now       = date('d/m/Y H:i:s');
$volToday  = rupiah($stats['vol_today']);
$volTotal  = rupiah($stats['vol_total']);

// ── Deteksi URL base untuk link API docs ──────────────────────────────────
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'assist.gw.sis1.net';
$script  = $_SERVER['SCRIPT_NAME'] ?? '/mod/dwallet/v1/api/index.php';
$baseUrl = $scheme . '://' . $host . $script;

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DWallet Admin v<?= $ver ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f4f8;color:#1a202c;min-height:100vh}

/* Sidebar */
.sb{position:fixed;top:0;left:0;width:220px;height:100vh;background:linear-gradient(175deg,#1e3a5f 0%,#0c2340 100%);overflow-y:auto;z-index:200;display:flex;flex-direction:column}
.sb-logo{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,.08)}
.sb-logo h1{font-size:17px;font-weight:800;color:#fff;letter-spacing:.3px}
.sb-logo small{color:rgba(255,255,255,.4);font-size:11px;display:block;margin-top:3px}
.nav-grp{padding:12px 0 2px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,.3)}
a.nav{display:flex;align-items:center;gap:9px;padding:9px 18px;font-size:13px;color:rgba(255,255,255,.65);text-decoration:none;border-left:3px solid transparent;transition:all .15s;cursor:pointer}
a.nav:hover,a.nav.on{background:rgba(255,255,255,.07);color:#fff;border-left-color:#60a5fa}
a.nav .ic{width:17px;text-align:center;font-size:14px}

/* Main */
.main{margin-left:220px;padding:22px;min-height:100vh}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.topbar h2{font-size:19px;font-weight:700;color:#1e3a5f}
.topbar .meta{font-size:11px;color:#94a3b8;text-align:right}

/* Stat grid */
.sg{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
@media(max-width:960px){.sg{grid-template-columns:repeat(2,1fr)}}
.sc{background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.06);position:relative;overflow:hidden}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent,#3b82f6)}
.sc .lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:8px}
.sc .val{font-size:24px;font-weight:800;color:#1e3a5f}
.sc .sub{font-size:11px;color:#94a3b8;margin-top:4px}
.sc .ico{position:absolute;right:16px;top:50%;transform:translateY(-50%);font-size:32px;opacity:.12}

/* Card */
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:18px;overflow:hidden}
.ch{padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.ch h3{font-size:14px;font-weight:700;color:#1e3a5f}
.cb{padding:18px}

/* Table */
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{background:#f8fafc;padding:9px 11px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;border-bottom:2px solid #e2e8f0;white-space:nowrap}
.tbl td{padding:9px 11px;border-bottom:1px solid #f8fafc;vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:#fafcff}
.tbl-wrap{overflow-x:auto}

/* Form */
.fg{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
@media(max-width:640px){.fg{grid-template-columns:1fr}}
.fl label{display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px}
.fl input,.fl select,.fl textarea{width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;color:#1a202c;outline:none;transition:border .15s;background:#fff}
.fl input:focus,.fl select:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.08)}
.fl.full{grid-column:1/-1}

/* Buttons */
.btn{padding:8px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s}
.btn-blue{background:#1e3a5f;color:#fff} .btn-blue:hover{background:#2d5986}
.btn-green{background:#059669;color:#fff} .btn-green:hover{background:#047857}
.btn-red{background:#dc2626;color:#fff} .btn-red:hover{background:#b91c1c}
.btn-amber{background:#d97706;color:#fff} .btn-amber:hover{background:#b45309}
.btn-indigo{background:#4f46e5;color:#fff} .btn-indigo:hover{background:#4338ca}
.btn-cyan{background:#0891b2;color:#fff} .btn-cyan:hover{background:#0e7490}
.btn-violet{background:#7c3aed;color:#fff} .btn-violet:hover{background:#6d28d9}
.btn-slate{background:#64748b;color:#fff} .btn-slate:hover{background:#475569}

/* Result box */
.rb{margin-top:12px;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:10px;font-size:12px;font-family:'Courier New',monospace;max-height:340px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;display:none;border-left:4px solid #3b82f6;line-height:1.5}
.rb.ok{border-left-color:#059669} .rb.err{border-left-color:#dc2626}

/* Alerts */
.al{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px;display:flex;align-items:flex-start;gap:8px}
.al-blue{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.al-amber{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.al-green{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}

/* Info rows */
.ir{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
.ir:last-child{border-bottom:none} .ir .k{color:#64748b} .ir .v{font-weight:600;text-align:right}

/* Tabs */
.tbbar{display:flex;flex-wrap:wrap;gap:3px;margin-bottom:14px}
.tb{padding:6px 14px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;background:#f1f5f9;color:#475569;border:none;transition:all .15s}
.tb.on,.tb:hover{background:#1e3a5f;color:#fff}
.tc{display:none} .tc.on{display:block}

/* Sections */
.sec{display:none} .sec.on{display:block}

/* DB info table */
.dbt{width:100%;border-collapse:collapse;font-size:12px}
.dbt th{background:#f8fafc;padding:7px 10px;text-align:left;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;border-bottom:2px solid #e2e8f0;font-size:10px}
.dbt td{padding:7px 10px;border-bottom:1px solid #f8fafc;vertical-align:top}
.dbt tr:hover td{background:#fafcff}
code{background:#f1f5f9;padding:1px 6px;border-radius:4px;font-size:11px;font-family:'Courier New',monospace}
pre.api{background:#0f172a;color:#e2e8f0;padding:14px;border-radius:8px;font-size:12px;overflow-x:auto;line-height:1.6;margin-top:8px}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sb">
  <div class="sb-logo">
    <h1>💳 DWallet Admin</h1>
    <small>v<?= $ver ?> · <?= DWALLET_TIMEZONE ?></small>
  </div>

  <div class="nav-grp">Overview</div>
  <a class="nav on" onclick="go('dashboard',this)"><span class="ic">📊</span>Dashboard</a>

  <div class="nav-grp">Transaksi</div>
  <a class="nav" onclick="go('balance',this)"><span class="ic">💰</span>Balance</a>
  <a class="nav" onclick="go('cashin',this)"><span class="ic">⬇️</span>Cash In</a>
  <a class="nav" onclick="go('cashout',this)"><span class="ic">⬆️</span>Cash Out</a>
  <a class="nav" onclick="go('transfer',this)"><span class="ic">↔️</span>Transfer</a>
  <a class="nav" onclick="go('payment',this)"><span class="ic">🛒</span>Payment</a>
  <a class="nav" onclick="go('refund',this)"><span class="ic">↩️</span>Refund</a>

  <div class="nav-grp">Lookup</div>
  <a class="nav" onclick="go('inquiry',this)"><span class="ic">🔍</span>Inquiry</a>
  <a class="nav" onclick="go('history',this)"><span class="ic">📜</span>History</a>

  <div class="nav-grp">Sistem</div>
  <a class="nav" onclick="go('config',this)"><span class="ic">⚙️</span>Konfigurasi</a>
  <a class="nav" onclick="go('dbinfo',this)"><span class="ic">🗄️</span>Database</a>
  <a class="nav" onclick="go('install',this)"><span class="ic">🔧</span>Install Schema</a>
  <a class="nav" onclick="go('apidoc',this)"><span class="ic">📖</span>API Docs</a>
</aside>

<!-- MAIN -->
<main class="main">
<div class="topbar">
  <h2 id="sec-title">📊 Dashboard</h2>
  <div class="meta"><?= $now ?><br><?= htmlspecialchars($baseUrl) ?></div>
</div>

<!-- ══ DASHBOARD ══ -->
<div id="sec-dashboard" class="sec on">
  <div class="sg">
    <div class="sc" style="--accent:#3b82f6">
      <div class="ico">📋</div>
      <div class="lbl">Transaksi Hari Ini</div>
      <div class="val"><?= number_format($stats['trx_today']) ?></div>
      <div class="sub">Total: <?= number_format($stats['trx_total']) ?> transaksi</div>
    </div>
    <div class="sc" style="--accent:#059669">
      <div class="ico">💳</div>
      <div class="lbl">Wallet Aktif</div>
      <div class="val"><?= number_format($stats['wallet_total']) ?></div>
      <div class="sub">Customer terdaftar</div>
    </div>
    <div class="sc" style="--accent:#d97706">
      <div class="ico">💵</div>
      <div class="lbl">Volume Hari Ini</div>
      <div class="val" style="font-size:17px;margin-top:3px"><?= $volToday ?></div>
      <div class="sub">Total: <?= $volTotal ?></div>
    </div>
    <div class="sc" style="--accent:<?= $dbOk ? '#059669' : '#dc2626' ?>">
      <div class="ico">🗄️</div>
      <div class="lbl">Status Database</div>
      <div class="val" style="font-size:14px;margin-top:6px"><?= $dbBadge ?></div>
      <div class="sub"><?= CFG_DB_HOST ?> / <?= CFG_DB_NAME ?></div>
    </div>
  </div>

  <?php if ($stats['pending_count'] > 0): ?>
  <div class="al al-amber">⚠️ Ada <strong><?= $stats['pending_count'] ?> transaksi</strong> dengan status PENDING/PROCESSING.</div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
    <div class="card">
      <div class="ch">
        <h3>📋 Transaksi Terbaru</h3>
        <button class="btn btn-slate" onclick="location.reload()">🔄 Refresh</button>
      </div>
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr><th>Faktur</th><th>Jenis</th><th>Customer</th><th style="text-align:right">Amount</th><th style="text-align:right">Gross</th><th>Status</th><th>Waktu</th></tr></thead>
          <tbody><?= $trxRows ?: '<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px">Belum ada transaksi</td></tr>' ?></tbody>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="ch"><h3>💳 Top Wallet</h3></div>
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr><th>Customer</th><th>Account</th><th style="text-align:right">Saldo</th><th>Status</th></tr></thead>
          <tbody><?= $walletRows ?: '<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:20px">Belum ada wallet</td></tr>' ?></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ══ BALANCE ══ -->
<div id="sec-balance" class="sec">
  <div class="card"><div class="ch"><h3>💰 Cek Saldo Wallet</h3></div><div class="cb">
    <div class="al al-blue">📡 POST <code><?= htmlspecialchars($baseUrl) ?>?action=balance</code></div>
    <div class="fg">
      <div class="fl full"><label>Bearer Token *</label><input id="b-tok" placeholder="api_token dari tabel customer"></div>
    </div><br>
    <button class="btn btn-blue" onclick="call('balance')">💰 Cek Saldo</button>
    <div class="rb" id="r-balance"></div>
  </div></div>
</div>

<!-- ══ CASH IN ══ -->
<div id="sec-cashin" class="sec">
  <div class="card"><div class="ch"><h3>⬇️ Cash In — Top-up Saldo</h3></div><div class="cb">
    <div class="al al-blue">📡 POST <code><?= htmlspecialchars($baseUrl) ?>?action=cashin</code></div>
    <div class="fg">
      <div class="fl full"><label>Bearer Token *</label><input id="ci-tok" placeholder="api_token customer"></div>
      <div class="fl"><label>Amount (IDR) * — Min: <?= rupiah(CFG_MIN_TRX) ?></label><input id="ci-amt" type="number" placeholder="100000" min="1000"></div>
      <div class="fl"><label>Ref Number * <small style="color:#94a3b8">(idempotency key, unik)</small></label>
        <div style="display:flex;gap:6px"><input id="ci-ref" placeholder="REF-001"><button class="btn btn-slate" onclick="autoRef('ci-ref')" style="white-space:nowrap;padding:8px 10px">🎲</button></div></div>
      <div class="fl"><label>Channel *</label>
        <select id="ci-ch"><option value="">-- Pilih --</option>
          <option value="TRANSFER_BANK">Transfer Bank</option><option value="VA">Virtual Account</option>
          <option value="QRIS">QRIS</option><option value="TUNAI">Tunai</option><option value="LAINNYA">Lainnya</option>
        </select></div>
    </div><br>
    <button class="btn btn-green" onclick="call('cashin')">⬇️ Proses Cash In</button>
    <div class="rb" id="r-cashin"></div>
  </div></div>
</div>

<!-- ══ CASH OUT ══ -->
<div id="sec-cashout" class="sec">
  <div class="card"><div class="ch"><h3>⬆️ Cash Out — Penarikan ke Bank</h3></div><div class="cb">
    <div class="al al-blue">📡 POST <code><?= htmlspecialchars($baseUrl) ?>?action=cashout</code></div>
    <div class="al al-amber">⚠️ Fee CashOut: <strong><?= rupiah(CFG_FEE_CASHOUT) ?></strong> (dari setting dwallet.fee_cashout). Gross = Amount + Fee.</div>
    <div class="fg">
      <div class="fl full"><label>Bearer Token *</label><input id="co-tok" placeholder="api_token customer"></div>
      <div class="fl"><label>Amount (IDR) * — Min: <?= rupiah(CFG_MIN_TRX) ?></label><input id="co-amt" type="number" placeholder="500000" min="1000"></div>
      <div class="fl"><label>Ref Number *</label>
        <div style="display:flex;gap:6px"><input id="co-ref" placeholder="CO-001"><button class="btn btn-slate" onclick="autoRef('co-ref')" style="padding:8px 10px">🎲</button></div></div>
      <div class="fl"><label>Kode Bank *</label>
        <select id="co-bank"><option value="">-- Pilih Bank --</option>
          <option value="BCA">BCA</option><option value="BNI">BNI</option><option value="BRI">BRI</option>
          <option value="MANDIRI">Mandiri</option><option value="BSI">BSI</option>
          <option value="PERMATA">Permata</option><option value="CIMB">CIMB Niaga</option>
          <option value="DANAMON">Danamon</option><option value="BTN">BTN</option><option value="OTHER">Lainnya</option>
        </select></div>
      <div class="fl"><label>No. Rekening *</label><input id="co-rek" placeholder="1234567890" type="tel"></div>
      <div class="fl"><label>Nama Penerima *</label><input id="co-nama" placeholder="JOHN DOE" style="text-transform:uppercase"></div>
      <div class="fl full"><label>Keterangan</label><input id="co-ket" placeholder="Penarikan gaji bulan ini"></div>
    </div><br>
    <button class="btn btn-red" onclick="call('cashout')">⬆️ Proses Cash Out</button>
    <div class="rb" id="r-cashout"></div>
  </div></div>
</div>

<!-- ══ TRANSFER ══ -->
<div id="sec-transfer" class="sec">
  <div class="card"><div class="ch"><h3>↔️ Transfer Antar Wallet</h3></div><div class="cb">
    <div class="al al-blue">📡 POST <code><?= htmlspecialchars($baseUrl) ?>?action=transfer</code></div>
    <div class="al al-green">✅ Fee transfer internal: <strong>Rp 0</strong>. Status langsung SUCCESS.</div>
    <div class="fg">
      <div class="fl full"><label>Bearer Token * (Pengirim)</label><input id="tf-tok" placeholder="api_token customer pengirim"></div>
      <div class="fl"><label>Amount (IDR) *</label><input id="tf-amt" type="number" placeholder="50000" min="1000"></div>
      <div class="fl"><label>Kode Penerima * (KodePro)</label><input id="tf-kode" placeholder="A-000301"></div>
      <div class="fl"><label>Ref Number *</label>
        <div style="display:flex;gap:6px"><input id="tf-ref" placeholder="TF-001"><button class="btn btn-slate" onclick="autoRef('tf-ref')" style="padding:8px 10px">🎲</button></div></div>
      <div class="fl full"><label>Keterangan</label><input id="tf-ket" placeholder="Titip bayar listrik"></div>
    </div><br>
    <button class="btn btn-cyan" onclick="call('transfer')">↔️ Proses Transfer</button>
    <div class="rb" id="r-transfer"></div>
  </div></div>
</div>

<!-- ══ PAYMENT ══ -->
<div id="sec-payment" class="sec">
  <div class="card"><div class="ch"><h3>🛒 Payment PPOB</h3></div><div class="cb">
    <div class="al al-blue">📡 POST <code><?= htmlspecialchars($baseUrl) ?>?action=payment</code> — JenisTrx=12</div>
    <div class="fg">
      <div class="fl full"><label>Bearer Token *</label><input id="py-tok" placeholder="api_token customer"></div>
      <div class="fl"><label>Amount (IDR) *</label><input id="py-amt" type="number" placeholder="100000" min="1000"></div>
      <div class="fl"><label>Ref Number *</label>
        <div style="display:flex;gap:6px"><input id="py-ref" placeholder="PAY-001"><button class="btn btn-slate" onclick="autoRef('py-ref')" style="padding:8px 10px">🎲</button></div></div>
      <div class="fl"><label>Kode Produk *</label><input id="py-prod" placeholder="PLN50 / TSEL10K / BPJS"></div>
      <div class="fl"><label>Nomor Tujuan *</label><input id="py-tuj" placeholder="08123456789" type="tel"></div>
      <div class="fl full"><label>Keterangan</label><input id="py-ket" placeholder="Tagihan listrik token 50k"></div>
    </div><br>
    <button class="btn btn-amber" onclick="call('payment')">🛒 Proses Payment</button>
    <div class="rb" id="r-payment"></div>
  </div></div>
</div>

<!-- ══ REFUND ══ -->
<div id="sec-refund" class="sec">
  <div class="card"><div class="ch"><h3>↩️ Refund / Reversal</h3></div><div class="cb">
    <div class="al al-blue">📡 POST <code><?= htmlspecialchars($baseUrl) ?>?action=refund</code></div>
    <div class="al al-amber">⚠️ Hanya PAYMENT &amp; CASHIN yang bisa di-refund. Status asal harus S atau R. Idempoten per faktur_asal.</div>
    <div class="fg">
      <div class="fl full"><label>Bearer Token *</label><input id="rf-tok" placeholder="api_token customer"></div>
      <div class="fl"><label>Faktur Asal *</label><input id="rf-fak" placeholder="PAY0000000001"></div>
      <div class="fl"><label>Alasan Refund *</label><input id="rf-alasan" placeholder="Produk gagal diproses"></div>
    </div><br>
    <button class="btn btn-violet" onclick="call('refund')">↩️ Proses Refund</button>
    <div class="rb" id="r-refund"></div>
  </div></div>
</div>

<!-- ══ INQUIRY ══ -->
<div id="sec-inquiry" class="sec">
  <div class="card"><div class="ch"><h3>🔍 Inquiry Status Transaksi</h3></div><div class="cb">
    <div class="al al-blue">📡 GET <code><?= htmlspecialchars($baseUrl) ?>?action=inquiry&amp;faktur=xxx</code></div>
    <div class="fg">
      <div class="fl full"><label>Bearer Token *</label><input id="iq-tok" placeholder="api_token customer"></div>
      <div class="fl"><label>Nomor Faktur</label><input id="iq-fak" placeholder="CIN0000000001"></div>
      <div class="fl"><label>Atau Ref Number</label><input id="iq-ref" placeholder="REF-001 (alternatif)"></div>
    </div><br>
    <button class="btn btn-blue" onclick="call('inquiry')">🔍 Cari Transaksi</button>
    <div class="rb" id="r-inquiry"></div>
  </div></div>
</div>

<!-- ══ HISTORY ══ -->
<div id="sec-history" class="sec">
  <div class="card"><div class="ch"><h3>📜 Riwayat Transaksi</h3></div><div class="cb">
    <div class="al al-blue">📡 GET <code><?= htmlspecialchars($baseUrl) ?>?action=history</code></div>
    <div class="fg">
      <div class="fl full"><label>Bearer Token *</label><input id="hs-tok" placeholder="api_token customer"></div>
      <div class="fl"><label>Jenis</label>
        <select id="hs-jenis"><option value="">-- Semua --</option>
          <option value="CASHIN">CASHIN</option><option value="CASHOUT">CASHOUT</option>
          <option value="TRANSFER">TRANSFER</option><option value="PAYMENT">PAYMENT</option>
          <option value="REFUND">REFUND</option>
        </select></div>
      <div class="fl"><label>Page</label><input id="hs-page" type="number" value="1" min="1"></div>
      <div class="fl"><label>Per Page (max 100)</label><input id="hs-pp" type="number" value="20" min="1" max="100"></div>
      <div class="fl"><label>Dari (YYYY-MM-DD)</label><input id="hs-dari" type="date"></div>
      <div class="fl"><label>Sampai (YYYY-MM-DD)</label><input id="hs-sampai" type="date"></div>
    </div><br>
    <button class="btn btn-blue" onclick="call('history')">📜 Ambil History</button>
    <div class="rb" id="r-history"></div>
  </div></div>
</div>

<!-- ══ KONFIGURASI ══ -->
<div id="sec-config" class="sec">
  <div class="card"><div class="ch"><h3>⚙️ Konfigurasi Sistem (Hardcoded)</h3></div><div class="cb">
    <div class="al al-amber">⚠️ Edit konstanta <code>CFG_*</code> di baris 18–72 file <code>dwallet_service.php</code> untuk mengubah konfigurasi.</div>
    <div class="ir"><span class="k">Versi</span><span class="v"><?= DWALLET_VERSION ?></span></div>
    <div class="ir"><span class="k">Timezone</span><span class="v"><?= DWALLET_TIMEZONE ?></span></div>
    <div class="ir"><span class="k">DB Host : Port</span><span class="v"><?= CFG_DB_HOST ?> : <?= CFG_DB_PORT ?></span></div>
    <div class="ir"><span class="k">DB Name</span><span class="v"><?= CFG_DB_NAME ?></span></div>
    <div class="ir"><span class="k">DB User</span><span class="v"><?= CFG_DB_USER ?></span></div>
    <div class="ir"><span class="k">Status DB</span><span class="v"><?= $dbBadge ?></span></div>
    <div class="ir"><span class="k">Payment Processor URL</span><span class="v"><?= empty(CFG_PP_URL) ? '<span style="color:#d97706">Stub Mode (kosong)</span>' : htmlspecialchars(CFG_PP_URL) ?></span></div>
    <div class="ir"><span class="k">PP Callback Secret</span><span class="v"><?= empty(CFG_PP_CB_SECRET) ? '<span style="color:#d97706">Tidak aktif</span>' : '<span style="color:#059669">Aktif</span>' ?></span></div>
    <div class="ir"><span class="k">Min Transaksi</span><span class="v"><?= rupiah(CFG_MIN_TRX) ?></span></div>
    <div class="ir"><span class="k">Max Cash In</span><span class="v"><?= rupiah(CFG_MAX_CASHIN) ?></span></div>
    <div class="ir"><span class="k">Max Cash Out</span><span class="v"><?= rupiah(CFG_MAX_CASHOUT) ?></span></div>
    <div class="ir"><span class="k">Max Transfer</span><span class="v"><?= rupiah(CFG_MAX_TRANSFER) ?></span></div>
    <div class="ir"><span class="k">Max Payment</span><span class="v"><?= rupiah(CFG_MAX_PAYMENT) ?></span></div>
    <div class="ir"><span class="k">Fee Cash Out</span><span class="v"><?= rupiah(CFG_FEE_CASHOUT) ?></span></div>
    <div class="ir"><span class="k">Fee Transfer Internal</span><span class="v"><?= rupiah(CFG_FEE_TRANSFER) ?></span></div>
    <div class="ir"><span class="k">COA Aset</span><span class="v"><?= CFG_COA_ASET ?></span></div>
    <div class="ir"><span class="k">COA Liabilitas</span><span class="v"><?= CFG_COA_LIAB ?></span></div>
    <div class="ir"><span class="k">COA Fee</span><span class="v"><?= CFG_COA_FEE ?></span></div>
    <div class="ir"><span class="k">COA Expense</span><span class="v"><?= CFG_COA_EXPENSE ?></span></div>
    <br>
    <button class="btn btn-blue" onclick="call('health')">🏥 Health Check API</button>
    <div class="rb" id="r-health"></div>
  </div></div>
</div>

<!-- ══ DATABASE INFO ══ -->
<div id="sec-dbinfo" class="sec">
  <div class="card"><div class="ch"><h3>🗄️ Informasi Database</h3></div><div class="cb">
    <?php
    $dbTables = [];
    if ($dbOk) {
        try {
            $dbTables = DB::fetch("SHOW TABLE STATUS WHERE Name LIKE 'dwallet%' OR Name IN
                ('customer','nomorfaktur','pulsa_penjualan','log_deposit','activity_log','setting')
                ORDER BY Name");
        } catch (Throwable) {}
    }
    if ($dbTables): ?>
    <div class="tbl-wrap"><table class="dbt">
      <thead><tr><th>Tabel</th><th>Engine</th><th style="text-align:right">Rows</th><th style="text-align:right">Data Size</th><th>Collation</th><th>Updated</th></tr></thead>
      <tbody>
      <?php foreach ($dbTables as $t):
        $sz = $t['Data_length'] > 1048576
            ? round($t['Data_length']/1048576, 1) . ' MB'
            : round($t['Data_length']/1024, 1) . ' KB';
        $isNew = str_starts_with($t['Name'], 'dwallet_');
        $style = $isNew ? 'color:#1e3a5f;font-weight:700' : 'color:#475569';
      ?>
      <tr>
        <td><span style="<?= $style ?>"><?= htmlspecialchars($t['Name']) ?><?= $isNew ? ' <span style="background:#dbeafe;color:#1d4ed8;padding:1px 5px;border-radius:4px;font-size:10px;font-weight:700">NEW</span>' : '' ?></span></td>
        <td><?= htmlspecialchars($t['Engine'] ?? '-') ?></td>
        <td style="text-align:right"><?= number_format((int)($t['Rows'] ?? 0)) ?></td>
        <td style="text-align:right"><?= $sz ?></td>
        <td style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($t['Collation'] ?? '-') ?></td>
        <td style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($t['Update_time'] ?? '-') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?>
    <div class="al al-amber">⚠️ <?= $dbOk ? 'Tidak ada tabel ditemukan.' : 'Database tidak terhubung: ' . htmlspecialchars($dbErr) ?></div>
    <?php endif; ?>
  </div></div>
</div>

<!-- ══ INSTALL ══ -->
<div id="sec-install" class="sec">
  <div class="card"><div class="ch"><h3>🔧 Install Schema Tabel Baru</h3></div><div class="cb">
    <div class="al al-amber">⚠️ Jalankan <strong>sekali saja</strong> saat pertama deploy. Menggunakan <code>CREATE TABLE IF NOT EXISTS</code> — aman diulang.</div>
    <p style="font-size:13px;color:#475569;margin-bottom:14px">Akan membuat tabel:
      <code>dwallet_wallets</code>, <code>dwallet_transactions</code>, <code>dwallet_journal</code>,
      <code>dwallet_callbacks</code>, <code>dwallet_rate_limit</code> + insert default ke <code>setting</code>.
    </p>
    <button class="btn btn-red" onclick="doInstall()">🔧 Jalankan Install Schema</button>
    <div class="rb" id="r-install"></div>
  </div></div>
</div>

<!-- ══ API DOCS ══ -->
<div id="sec-apidoc" class="sec">
  <div class="card"><div class="ch"><h3>📖 API Reference</h3></div><div class="cb">
    <div class="al al-blue">🌐 Base URL: <code><?= htmlspecialchars($baseUrl) ?></code> — tambahkan <code>?action=xxx</code></div>
    <div class="tbbar">
      <button class="tb on" onclick="tab(this,'td-auth')">Auth</button>
      <button class="tb" onclick="tab(this,'td-balance')">Balance</button>
      <button class="tb" onclick="tab(this,'td-cashin')">CashIn</button>
      <button class="tb" onclick="tab(this,'td-cashout')">CashOut</button>
      <button class="tb" onclick="tab(this,'td-transfer')">Transfer</button>
      <button class="tb" onclick="tab(this,'td-payment')">Payment</button>
      <button class="tb" onclick="tab(this,'td-refund')">Refund</button>
      <button class="tb" onclick="tab(this,'td-inquiry')">Inquiry</button>
      <button class="tb" onclick="tab(this,'td-history')">History</button>
      <button class="tb" onclick="tab(this,'td-callback')">Callback</button>
      <button class="tb" onclick="tab(this,'td-status')">Status</button>
    </div>

    <div id="td-auth" class="tc on"><pre class="api">// Autentikasi Bearer Token
Authorization: Bearer &lt;api_token&gt;

// api_token = customer.api_token (MD5 32-char)
// Contoh: Authorization: Bearer de1487ac24851aa147add1d386a3e3fb

// Semua endpoint wajib Bearer kecuali: /health, /callback</pre></div>

    <div id="td-balance" class="tc"><pre class="api">POST <?= htmlspecialchars($baseUrl) ?>?action=balance
Authorization: Bearer &lt;token&gt;
Content-Type: application/json
{}

// Response 200
{ "success":true, "data": {
    "customer_code":     "A-000300",
    "customer_name":     "John Doe",
    "account_number":    "AW12345678",
    "balance":           1500000.00,
    "hold_balance":      0.00,
    "available_balance": 1500000.00,
    "wallet_status":     "active",
    "log_deposit_saldo": 1500000.00
}}</pre></div>

    <div id="td-cashin" class="tc"><pre class="api">POST <?= htmlspecialchars($baseUrl) ?>?action=cashin
{ "amount": 100000, "ref_number": "REF-001", "channel": "TRANSFER_BANK" }
// amount   : integer IDR, min <?= rupiah(CFG_MIN_TRX) ?>
// ref_number: string unik (idempotency key)
// channel  : TRANSFER_BANK | VA | QRIS | TUNAI | LAINNYA
// Response 201 → status: SUCCESS, faktur: CINxxxxxxxxxx</pre></div>

    <div id="td-cashout" class="tc"><pre class="api">POST <?= htmlspecialchars($baseUrl) ?>?action=cashout
{ "amount": 500000, "ref_number": "CO-001",
  "bank_code": "BCA", "no_rekening": "1234567890",
  "nama_penerima": "JOHN DOE", "keterangan": "..." }
// Fee: <?= rupiah(CFG_FEE_CASHOUT) ?> → gross = amount + fee
// Status awal: PROCESSING → callback ubah ke SUCCESS/FAILED
// JenisTrx=13 di pulsa_penjualan</pre></div>

    <div id="td-transfer" class="tc"><pre class="api">POST <?= htmlspecialchars($baseUrl) ?>?action=transfer
{ "amount": 50000, "kode_penerima": "A-000301",
  "ref_number": "TF-001", "keterangan": "..." }
// Fee internal: Rp 0 | Status: langsung SUCCESS</pre></div>

    <div id="td-payment" class="tc"><pre class="api">POST <?= htmlspecialchars($baseUrl) ?>?action=payment
{ "amount": 100000, "ref_number": "PAY-001",
  "kode_produk": "PLN50", "nomor_tujuan": "12345678901",
  "keterangan": "..." }
// JenisTrx=12 di pulsa_penjualan
// Status awal: PROCESSING → callback ubah ke SUCCESS/FAILED</pre></div>

    <div id="td-refund" class="tc"><pre class="api">POST <?= htmlspecialchars($baseUrl) ?>?action=refund
{ "faktur_asal": "PAY0000000001", "alasan": "Produk gagal" }
// Hanya PAYMENT & CASHIN | Status asal: S atau R
// Idempoten: satu refund per faktur_asal</pre></div>

    <div id="td-inquiry" class="tc"><pre class="api">GET <?= htmlspecialchars($baseUrl) ?>?action=inquiry&amp;faktur=CIN0000000001
GET <?= htmlspecialchars($baseUrl) ?>?action=inquiry&amp;ref_number=REF-001
// Response menyertakan: detail transaksi + dwallet_journal entries</pre></div>

    <div id="td-history" class="tc"><pre class="api">GET <?= htmlspecialchars($baseUrl) ?>?action=history
  &amp;page=1 &amp;per_page=20 &amp;jenis=CASHIN
  &amp;dari=2025-06-01 &amp;sampai=2025-06-30
// Response: { data:[...], total, page, per_page, total_page }</pre></div>

    <div id="td-callback" class="tc"><pre class="api">POST <?= htmlspecialchars($baseUrl) ?>?action=callback
// Tidak perlu Bearer. HMAC optional jika CFG_PP_CB_SECRET diisi.
{ "faktur": "CUT0000000001", "status": "SUCCESS",
  "provider_ref": "PP-TRX-999", "provider": "DANAMON" }
// status: SUCCESS|FAILED|COMPLETED|PAID|BERHASIL|REJECTED|GAGAL</pre></div>

    <div id="td-status" class="tc">
      <table class="dbt" style="margin-bottom:14px">
        <thead><tr><th>Kode</th><th>Label</th><th>Keterangan</th></tr></thead>
        <tbody>
          <tr><td><code>P</code></td><td style="color:#d97706;font-weight:700">PENDING</td><td>Baru dibuat</td></tr>
          <tr><td><code>S</code></td><td style="color:#059669;font-weight:700">SUCCESS</td><td>Berhasil</td></tr>
          <tr><td><code>G</code></td><td style="color:#dc2626;font-weight:700">FAILED</td><td>Gagal</td></tr>
          <tr><td><code>D</code></td><td style="color:#6b7280;font-weight:700">CANCELLED</td><td>Dibatalkan/Refund</td></tr>
          <tr><td><code>R</code></td><td style="color:#2563eb;font-weight:700">PROCESSING</td><td>Menunggu callback</td></tr>
        </tbody>
      </table>
      <table class="dbt">
        <thead><tr><th>HTTP</th><th>Keterangan</th></tr></thead>
        <tbody>
          <tr><td><code>200</code></td><td>OK</td></tr><tr><td><code>201</code></td><td>Created</td></tr>
          <tr><td><code>401</code></td><td>Unauthorized</td></tr><tr><td><code>403</code></td><td>Forbidden</td></tr>
          <tr><td><code>404</code></td><td>Not Found</td></tr><tr><td><code>405</code></td><td>Method Not Allowed</td></tr>
          <tr><td><code>422</code></td><td>Validasi gagal</td></tr><tr><td><code>429</code></td><td>Rate limit</td></tr>
          <tr><td><code>500</code></td><td>Server Error</td></tr><tr><td><code>502</code></td><td>Payment processor error</td></tr>
          <tr><td><code>503</code></td><td>DB tidak tersedia</td></tr>
        </tbody>
      </table>
    </div>
  </div></div>
</div>

</main>

<script>
const BASE = '<?= addslashes($baseUrl) ?>';

// ── Navigation ─────────────────────────────────────────────────────────────
const TITLES = {
  dashboard:'📊 Dashboard', balance:'💰 Balance', cashin:'⬇️ Cash In',
  cashout:'⬆️ Cash Out', transfer:'↔️ Transfer', payment:'🛒 Payment',
  refund:'↩️ Refund', inquiry:'🔍 Inquiry', history:'📜 History',
  config:'⚙️ Konfigurasi', dbinfo:'🗄️ Database', install:'🔧 Install Schema', apidoc:'📖 API Docs',
};
function go(id, el) {
  document.querySelectorAll('.sec').forEach(s => s.classList.remove('on'));
  document.querySelectorAll('.nav').forEach(n => n.classList.remove('on'));
  document.getElementById('sec-' + id).classList.add('on');
  document.getElementById('sec-title').textContent = TITLES[id] || id;
  if (el) el.classList.add('on');
}
function tab(el, id) {
  const p = el.closest('.cb');
  p.querySelectorAll('.tc').forEach(t => t.classList.remove('on'));
  p.querySelectorAll('.tb').forEach(t => t.classList.remove('on'));
  document.getElementById(id).classList.add('on');
  el.classList.add('on');
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function g(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }
function autoRef(id) { document.getElementById(id).value = 'REF-' + Date.now(); }
function showResult(id, data, ok) {
  const el = document.getElementById('r-' + id);
  el.style.display = 'block';
  el.className = 'rb ' + (ok ? 'ok' : 'err');
  el.textContent = JSON.stringify(data, null, 2);
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ── API Call ─────────────────────────────────────────────────────────────────
async function call(action) {
  let token = '', body = {}, method = 'POST', qs = '';

  if (action === 'balance')   { token = g('b-tok'); }
  else if (action === 'cashin')    { token = g('ci-tok'); body = { amount: +g('ci-amt'), ref_number: g('ci-ref'), channel: g('ci-ch') }; }
  else if (action === 'cashout')   { token = g('co-tok'); body = { amount: +g('co-amt'), ref_number: g('co-ref'), bank_code: g('co-bank'), no_rekening: g('co-rek'), nama_penerima: g('co-nama').toUpperCase(), keterangan: g('co-ket') }; }
  else if (action === 'transfer')  { token = g('tf-tok'); body = { amount: +g('tf-amt'), kode_penerima: g('tf-kode'), ref_number: g('tf-ref'), keterangan: g('tf-ket') }; }
  else if (action === 'payment')   { token = g('py-tok'); body = { amount: +g('py-amt'), ref_number: g('py-ref'), kode_produk: g('py-prod'), nomor_tujuan: g('py-tuj'), keterangan: g('py-ket') }; }
  else if (action === 'refund')    { token = g('rf-tok'); body = { faktur_asal: g('rf-fak'), alasan: g('rf-alasan') }; }
  else if (action === 'inquiry')   { token = g('iq-tok'); method = 'GET'; const f=g('iq-fak'),r=g('iq-ref'); qs = f ? '&faktur='+encodeURIComponent(f) : '&ref_number='+encodeURIComponent(r); }
  else if (action === 'history')   { token = g('hs-tok'); method = 'GET'; const p = new URLSearchParams(); if(g('hs-page')) p.set('page',g('hs-page')); if(g('hs-pp')) p.set('per_page',g('hs-pp')); if(g('hs-jenis')) p.set('jenis',g('hs-jenis')); if(g('hs-dari')) p.set('dari',g('hs-dari')); if(g('hs-sampai')) p.set('sampai',g('hs-sampai')); qs = '&'+p.toString(); }
  else if (action === 'health')    { method = 'GET'; }

  if (!token && action !== 'health') {
    showResult(action, { error: 'Bearer Token wajib diisi' }, false); return;
  }

  const url  = BASE + '?action=' + action + qs;
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json', ...(token ? { 'Authorization': 'Bearer ' + token } : {}) },
    ...(method === 'POST' ? { body: JSON.stringify(body) } : {}),
  };

  const rb = document.getElementById('r-' + action);
  if (rb) { rb.style.display = 'block'; rb.className = 'rb'; rb.textContent = '⏳ Loading...'; }

  try {
    const resp = await fetch(url, opts);
    const data = await resp.json().catch(() => ({ _raw: 'Non-JSON response' }));
    showResult(action, data, resp.ok);
  } catch (e) {
    showResult(action, { error: e.message, url }, false);
  }
}

// ── Install ──────────────────────────────────────────────────────────────────
async function doInstall() {
  if (!confirm('⚠️ Jalankan install schema?\nPastikan database sudah terhubung.')) return;
  const pw = prompt('Masukkan password admin (CFG_UI_PASS):');
  if (!pw) return;
  const rb = document.getElementById('r-install');
  rb.style.display = 'block'; rb.className = 'rb'; rb.textContent = '⏳ Installing...';
  try {
    const r = await fetch(BASE + '?action=install&secret=' + encodeURIComponent(pw));
    const d = await r.json();
    showResult('install', d, r.ok);
  } catch(e) { showResult('install', { error: e.message }, false); }
}
</script>
</body>
</html>
