<?php
/**
 * ============================================================================
 * DWALLET TRANSACTION SERVICE — Production Single File v3.1.0
 * ============================================================================
 *
 * Base URL  : http://assist.gw.sis1.net
 * Gateway   : /mod/dwallet/v1/api/*
 * Web UI    : /mod/dwallet/v1/api/ui   (admin dashboard)
 * Auth API  : Bearer Token = customer.api_token (MD5 32-char)
 * Auth UI   : HTTP Basic (admin / password dikonfigurasi di bawah)
 *
 * KONFIGURASI SEMUA HARDCODED DI BLOK "KONFIGURASI PRODUKSI" DI BAWAH.
 * Tidak ada env var, tidak ada .env file, tidak ada external config.
 *
 * Deploy path:
 *   app/Module/DWallet/V1/Service/dwallet_service.php
 *   app/Module/DWallet/V1/Controller/DWalletApiController.php
 *   app/Module/DWallet/Config/assist.php
 *
 * @version  3.1.0
 * @base-url http://assist.gw.sis1.net
 * ============================================================================
 */

declare(strict_types=1);

// ============================================================================
// ██████  KONFIGURASI PRODUKSI — EDIT BAGIAN INI SAJA  ██████
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

// ── Web UI Admin Credentials ─────────────────────────────────────────────────
const CFG_UI_USER    = 'admin';
const CFG_UI_PASS    = 'DWallet@2025!';   // GANTI sebelum deploy

// ── Batas Transaksi (IDR) ────────────────────────────────────────────────────
const CFG_MIN_TRX         =         1_000;
const CFG_MAX_CASHIN      = 1_000_000_000;
const CFG_MAX_CASHOUT     =   500_000_000;
const CFG_MAX_TRANSFER    =   500_000_000;
const CFG_MAX_PAYMENT     =    50_000_000;

// ── Fee Default (IDR) ────────────────────────────────────────────────────────
const CFG_FEE_CASHOUT     = 3_500;
const CFG_FEE_TRANSFER    = 0;

// ── Payment Processor ────────────────────────────────────────────────────────
// Kosongkan URL → stub mode (selalu sukses, untuk staging)
const CFG_PP_URL          = '';           // contoh: 'https://api.fastpay.id'
const CFG_PP_TOKEN        = '';           // Bearer token payment processor
const CFG_PP_CB_SECRET    = '';           // HMAC-SHA256 secret untuk callback

// ── Nomor Faktur Prefix ──────────────────────────────────────────────────────
const CFG_PFX_CASHIN   = 'CIN';
const CFG_PFX_CASHOUT  = 'CUT';
const CFG_PFX_TRANSFER = 'TRF';
const CFG_PFX_PAYMENT  = 'PAY';
const CFG_PFX_REFUND   = 'RFD';

// ── Status (jangan diubah — sesuai pulsa_penjualan.Status) ──────────────────
const ST_PENDING    = 'P';
const ST_SUCCESS    = 'S';
const ST_FAILED     = 'G';
const ST_CANCELLED  = 'D';
const ST_PROCESSING = 'R';

// ── COA Default (bisa di-override via setting table) ─────────────────────────
const CFG_COA_ASET    = '111001';
const CFG_COA_LIAB    = '211001';
const CFG_COA_FEE     = '411001';
const CFG_COA_EXPENSE = '511001';

// ── Versi ─────────────────────────────────────────────────────────────────────
const DWALLET_VERSION  = '3.1.0';
const DWALLET_TIMEZONE = CFG_TIMEZONE;

// ── DB aliases (tetap kompatibel dengan kode internal) ───────────────────────
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
define('FEE_TRANSFER_INTERNAL', CFG_FEE_TRANSFER);

define('PREFIX_CASHIN',   CFG_PFX_CASHIN);
define('PREFIX_CASHOUT',  CFG_PFX_CASHOUT);
define('PREFIX_TRANSFER', CFG_PFX_TRANSFER);
define('PREFIX_PAYMENT',  CFG_PFX_PAYMENT);
define('PREFIX_REFUND',   CFG_PFX_REFUND);

define('JENIS_EMONEY',       '12');
define('JENIS_TRANSFERDANA', '13');
define('JENIS_VA',           '14');

// ============================================================================
// BOOTSTRAP
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors',     '1');
date_default_timezone_set(DWALLET_TIMEZONE);

// ============================================================================
// DATABASE LAYER — PDO Singleton
// ============================================================================

final class DB
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
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

    public static function fetch(string $sql, array $p = []): array
    {
        $s = self::conn()->prepare($sql); $s->execute($p); return $s->fetchAll();
    }
    public static function first(string $sql, array $p = []): ?array
    {
        $s = self::conn()->prepare($sql); $s->execute($p);
        $r = $s->fetch(); return $r === false ? null : $r;
    }
    public static function scalar(string $sql, array $p = []): mixed
    {
        $s = self::conn()->prepare($sql); $s->execute($p);
        $r = $s->fetch(PDO::FETCH_NUM); return $r === false ? null : $r[0];
    }
    public static function insert(string $sql, array $p = []): int
    {
        $s = self::conn()->prepare($sql); $s->execute($p);
        return (int) self::conn()->lastInsertId();
    }
    public static function exec(string $sql, array $p = []): int
    {
        $s = self::conn()->prepare($sql); $s->execute($p); return $s->rowCount();
    }
}

// ============================================================================
// RESPONSE HELPER
// ============================================================================

final class Res
{
    public static function ok(array $data = [], string $msg = 'OK', int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>true,'message'=>$msg,'data'=>$data],
                         JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    public static function err(string $msg, int $code = 400, array $errs = []): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        $p = ['success'=>false,'message'=>$msg];
        if ($errs) $p['errors'] = $errs;
        echo json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ============================================================================
// REQUEST HELPER
// ============================================================================

final class Req
{
    private static ?array $body = null;

    public static function body(): array
    {
        if (self::$body === null) {
            $raw = file_get_contents('php://input') ?: '';
            self::$body = json_decode($raw, true) ?? [];
        }
        return self::$body;
    }
    public static function q(): array    { return $_GET ?? []; }
    public static function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        return $pos !== false ? substr($uri, 0, $pos) : $uri;
    }
    public static function ip(): string
    {
        foreach (['HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k)
            if (!empty($_SERVER[$k])) return explode(',', $_SERVER[$k])[0];
        return '0.0.0.0';
    }
    public static function header(string $name): string
    {
        return $_SERVER['HTTP_' . strtoupper(str_replace('-','_',$name))] ?? '';
    }
}

// ============================================================================
// LOG
// ============================================================================

final class Log
{
    public static function info(string $m, array $c=[]): void  { self::w('INFO',  $m, $c); }
    public static function warn(string $m, array $c=[]): void  { self::w('WARN',  $m, $c); }
    public static function error(string $m, array $c=[]): void { self::w('ERROR', $m, $c); }

    public static function audit(string $mod, string $act, string $desc, int $uid=0): void
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
        error_log("[DWallet][$lvl] $m" . ($c ? ' '.json_encode($c) : ''));
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
        if (!str_starts_with($h, 'Bearer ')) Res::err('Autentikasi diperlukan.', 401);
        $token = trim(substr($h, 7));
        if (!$token) Res::err('Token kosong.', 401);
        $c = DB::first(
            "SELECT id,Kode,KodePro,name,email,api_token,status FROM customer WHERE api_token=? LIMIT 1",
            [$token]
        );
        if (!$c) Res::err('Token tidak valid.', 401);
        if (isset($c['status']) && strtolower($c['status']) !== 'active')
            Res::err('Akun tidak aktif.', 403);
        return $c;
    }
}

// ============================================================================
// NUMBERING — Atomic nomor faktur via nomorfaktur table
// ============================================================================

final class Numbering
{
    public static function next(string $prefix): string
    {
        DB::insert("INSERT INTO nomorfaktur (KodePro,Tanggal) VALUES ('DW',NOW())");
        $id = (int) DB::scalar("SELECT MAX(ID) FROM nomorfaktur WHERE KodePro='DW'");
        DB::exec("DELETE FROM nomorfaktur WHERE KodePro='DW' AND ID<?", [$id]);
        return $prefix . str_pad((string)$id, 10, '0', STR_PAD_LEFT);
    }
}

// ============================================================================
// SETTING — Dari tabel setting (cache in-memory)
// ============================================================================

final class Setting
{
    private static array $cache = [];
    public static function get(string $key, mixed $def=null): mixed
    {
        if (!array_key_exists($key, self::$cache)) {
            $r = DB::first("SELECT setting_value FROM setting WHERE setting_key=? LIMIT 1", [$key]);
            self::$cache[$key] = $r ? $r['setting_value'] : null;
        }
        return self::$cache[$key] ?? $def;
    }
}

// ============================================================================
// COA — Chart of Account (setting table → fallback ke konstanta)
// ============================================================================

final class Coa
{
    public static function aset(): string    { return Setting::get('dwallet.coa_aset',    CFG_COA_ASET);    }
    public static function liab(): string    { return Setting::get('dwallet.coa_liab',    CFG_COA_LIAB);    }
    public static function fee(): string     { return Setting::get('dwallet.coa_fee',     CFG_COA_FEE);     }
    public static function expense(): string { return Setting::get('dwallet.coa_expense', CFG_COA_EXPENSE); }
}

// ============================================================================
// WALLET — saldo di dwallet_wallets + sync log_deposit
// ============================================================================

final class Wallet
{
    public static function getOrCreate(string $code): array
    {
        $w = DB::first("SELECT * FROM dwallet_wallets WHERE customer_code=? LIMIT 1", [$code]);
        if (!$w) {
            $an = 'AW' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            DB::insert(
                "INSERT INTO dwallet_wallets
                     (customer_code,account_number,balance,hold_balance,status,created_at,updated_at)
                 VALUES (?,?,0.00,0.00,'active',NOW(),NOW())",
                [$code, $an]
            );
            $w = DB::first("SELECT * FROM dwallet_wallets WHERE customer_code=? LIMIT 1", [$code]);
        }
        return $w;
    }

    public static function credit(string $code, float $amt): void
    {
        DB::exec("UPDATE dwallet_wallets SET balance=balance+?,updated_at=NOW() WHERE customer_code=?",
                 [$amt, $code]);
        self::sync($code);
    }

    public static function debit(string $code, float $amt): void
    {
        $n = DB::exec(
            "UPDATE dwallet_wallets SET balance=balance-?,updated_at=NOW()
             WHERE customer_code=? AND balance>=?",
            [$amt, $code, $amt]
        );
        if ($n === 0) throw new RuntimeException('Saldo tidak mencukupi.');
        self::sync($code);
    }

    public static function hold(string $code, float $amt): void
    {
        $n = DB::exec(
            "UPDATE dwallet_wallets
             SET balance=balance-?,hold_balance=hold_balance+?,updated_at=NOW()
             WHERE customer_code=? AND balance>=?",
            [$amt, $amt, $code, $amt]
        );
        if ($n === 0) throw new RuntimeException('Saldo tidak mencukupi untuk hold.');
    }

    public static function releaseHold(string $code, float $amt): void
    {
        DB::exec(
            "UPDATE dwallet_wallets
             SET hold_balance=GREATEST(0,hold_balance-?),updated_at=NOW()
             WHERE customer_code=?",
            [$amt, $code]
        );
    }

    /** Sync ke log_deposit — Jenis='S' sesuai data aktual DB */
    public static function sync(string $code): void
    {
        $w = DB::first("SELECT balance FROM dwallet_wallets WHERE customer_code=? LIMIT 1", [$code]);
        if (!$w) return;
        $now = date('Y-m-d H:i:s');
        DB::exec(
            "INSERT INTO log_deposit (Kode, Jenis, SaldoPPOB, SaldoPPOBDia, LastUpdate)
             VALUES (?, 'S', ?, ?, ?)
             ON DUPLICATE KEY UPDATE SaldoPPOB    = VALUES(SaldoPPOB),
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
                     (faktur,jenis_transaksi,rekening,urut,debet,kredit,keterangan,tgl,created_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())",
                [$faktur,$jenis,$e['rek'],$i+1,$e['debet']??0,$e['kredit']??0,$e['ket']??null,$today]
            );
        }
    }

    public static function entriesCashIn(float $a): array
    {
        return [
            ['rek'=>Coa::aset(),'debet'=>$a,'kredit'=>0,  'ket'=>'Penerimaan Cash In'],
            ['rek'=>Coa::liab(),'debet'=>0, 'kredit'=>$a, 'ket'=>'Kewajiban wallet customer'],
        ];
    }
    public static function entriesCashOut(float $a, float $fee): array
    {
        $e = [
            ['rek'=>Coa::liab(),'debet'=>$a+$fee,'kredit'=>0,   'ket'=>'Penarikan saldo customer'],
            ['rek'=>Coa::aset(),'debet'=>0,       'kredit'=>$a, 'ket'=>'Pengeluaran kas'],
        ];
        if ($fee>0) $e[] = ['rek'=>Coa::fee(),'debet'=>0,'kredit'=>$fee,'ket'=>'Biaya admin CashOut'];
        return $e;
    }
    public static function entriesTransfer(float $a, float $fee): array
    {
        $e = [
            ['rek'=>Coa::liab(),'debet'=>$a+$fee,'kredit'=>0,  'ket'=>'Debit wallet pengirim'],
            ['rek'=>Coa::liab(),'debet'=>0,       'kredit'=>$a,'ket'=>'Kredit wallet penerima'],
        ];
        if ($fee>0) $e[] = ['rek'=>Coa::fee(),'debet'=>0,'kredit'=>$fee,'ket'=>'Biaya transfer'];
        return $e;
    }
    public static function entriesPayment(float $a, float $fee): array
    {
        $e = [
            ['rek'=>Coa::liab(),   'debet'=>$a+$fee,'kredit'=>0,  'ket'=>'Debit wallet pembayaran'],
            ['rek'=>Coa::expense(),'debet'=>0,       'kredit'=>$a,'ket'=>'Biaya produk PPOB'],
        ];
        if ($fee>0) $e[] = ['rek'=>Coa::fee(),'debet'=>0,'kredit'=>$fee,'ket'=>'Biaya layanan'];
        return $e;
    }
    public static function entriesReversal(float $a, string $jenis): array
    {
        return $jenis === 'CASHIN'
            ? [['rek'=>Coa::liab(),'debet'=>$a,'kredit'=>0,  'ket'=>'Reversal Cash In'],
               ['rek'=>Coa::aset(),'debet'=>0, 'kredit'=>$a, 'ket'=>'Pengembalian kas']]
            : [['rek'=>Coa::aset(),'debet'=>$a,'kredit'=>0,  'ket'=>"Refund $jenis"],
               ['rek'=>Coa::liab(),'debet'=>0, 'kredit'=>$a, 'ket'=>'Kredit wallet customer']];
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
                 (faktur,jenis,kode_sender,kode_receiver,amount,fee,gross_amount,
                  keterangan,ref_number,status,note,meta,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())",
            [
                $d['faktur'], $d['jenis'],
                $d['kode_sender']   ?? null, $d['kode_receiver'] ?? null,
                $d['amount'],        $d['fee'] ?? 0, $d['gross_amount'],
                $d['keterangan']    ?? null, $d['ref_number']    ?? null,
                $d['status']        ?? ST_PENDING,
                $d['note']          ?? null,
                isset($d['meta']) ? json_encode($d['meta'], JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }
    public static function updateStatus(string $f, string $s, ?string $n=null): void
    {
        DB::exec(
            "UPDATE dwallet_transactions SET status=?,note=COALESCE(?,note),updated_at=NOW() WHERE faktur=?",
            [$s, $n, $f]
        );
    }
    public static function findByRef(string $ref, string $jenis): ?array
    {
        return DB::first(
            "SELECT * FROM dwallet_transactions WHERE ref_number=? AND jenis=? ORDER BY id DESC LIMIT 1",
            [$ref, $jenis]
        );
    }
    public static function findByFaktur(string $f): ?array
    {
        return DB::first("SELECT * FROM dwallet_transactions WHERE faktur=? LIMIT 1", [$f]);
    }
    public static function history(
        string $code, int $page, int $pp,
        ?string $jenis=null, ?string $dari=null, ?string $sampai=null
    ): array {
        $w = "(kode_sender=? OR kode_receiver=?)"; $p = [$code, $code];
        if ($jenis)  { $w .= " AND jenis=?";                $p[] = $jenis;  }
        if ($dari)   { $w .= " AND DATE(created_at)>=?";    $p[] = $dari;   }
        if ($sampai) { $w .= " AND DATE(created_at)<=?";    $p[] = $sampai; }
        $total  = (int) DB::scalar("SELECT COUNT(*) FROM dwallet_transactions WHERE $w", $p);
        $offset = ($page-1)*$pp;
        $rows   = DB::fetch(
            "SELECT * FROM dwallet_transactions WHERE $w ORDER BY created_at DESC LIMIT $pp OFFSET $offset",
            $p
        );
        return ['data'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$pp,
                'total_page'=>(int)ceil($total/$pp)];
    }
}

// ============================================================================
// RATE LIMITER — Berbasis tabel dwallet_rate_limit
// ============================================================================

final class RateLimit
{
    public static function check(string $key, int $max=10, int $win=60): void
    {
        try {
            DB::exec(
                "DELETE FROM dwallet_rate_limit WHERE rate_key=? AND created_at<DATE_SUB(NOW(),INTERVAL ? SECOND)",
                [$key, $win]
            );
            $cnt = (int) DB::scalar("SELECT COUNT(*) FROM dwallet_rate_limit WHERE rate_key=?", [$key]);
            if ($cnt >= $max) Res::err('Terlalu banyak permintaan. Coba lagi.', 429);
            DB::insert("INSERT INTO dwallet_rate_limit (rate_key,created_at) VALUES (?,NOW())", [$key]);
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
            if ($val === null || $val === '') $v->errors[] = "Field '{$f}' wajib diisi.";
            else $v->data[$f] = $val;
        }
        return $v;
    }
    public function amount(string $f, float $min=MIN_TRX_AMOUNT, float $max=PHP_FLOAT_MAX): self
    {
        $val = (float)($this->src[$f] ?? 0);
        if ($val < $min) $this->errors[] = "Amount minimal Rp ".number_format($min,0,',','.');
        elseif ($val > $max) $this->errors[] = "Amount maksimal Rp ".number_format($max,0,',','.');
        else $this->data[$f] = $val;
        return $this;
    }
    public function fails(): bool   { return !empty($this->errors); }
    public function errors(): array { return $this->errors; }
    public function get(string $f, mixed $def=null): mixed
    {
        return $this->data[$f] ?? $this->src[$f] ?? $def;
    }
}

// ============================================================================
// HELPERS
// ============================================================================

function statusLabel(string $s): string
{
    return match($s) {
        ST_PENDING=>'PENDING', ST_SUCCESS=>'SUCCESS', ST_FAILED=>'FAILED',
        ST_CANCELLED=>'CANCELLED', ST_PROCESSING=>'PROCESSING', default=>'UNKNOWN'
    };
}
function fmtTrx(array $t): array
{
    return [
        'faktur'      =>$t['faktur'],     'jenis'       =>$t['jenis'],
        'ref_number'  =>$t['ref_number'], 'amount'      =>(float)$t['amount'],
        'fee'         =>(float)$t['fee'], 'gross_amount'=>(float)$t['gross_amount'],
        'status'      =>statusLabel($t['status']), 'status_kode'=>$t['status'],
        'created_at'  =>$t['created_at'],
    ];
}
function rupiah(float $n): string { return 'Rp '.number_format($n, 0, ',', '.'); }

/**
 * Disbursement ke payment processor (Danamon SNAP / Fastpay / dll).
 * Jika CFG_PP_URL kosong → stub mode (selalu sukses).
 */
function disburse(string $bank, string $rek, string $nama, float $amt, string $faktur): array
{
    if (empty(CFG_PP_URL)) {
        return [
            'success'     => true,
            'provider_ref'=> 'STUB-'.strtoupper(bin2hex(random_bytes(4))),
            'message'     => 'Stub mode — CFG_PP_URL belum dikonfigurasi',
        ];
    }
    $payload = json_encode([
        'external_id'=>$faktur, 'bank_code'=>$bank,
        'account_number'=>$rek, 'account_name'=>$nama,
        'amount'=>(int)$amt, 'narasi'=>"DWallet CashOut $faktur",
    ]);
    $ch = curl_init(CFG_PP_URL.'/disburse');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>30,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.CFG_PP_TOKEN,
            'Content-Type: application/json',
            'X-External-ID: '.$faktur,
        ],
    ]);
    $raw = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
    if ($errno || !$raw) return ['success'=>false,'message'=>'Koneksi ke payment processor gagal.'];
    $r = json_decode($raw, true) ?? [];
    return [
        'success'     => in_array($r['status']??'', ['SUCCESS','COMPLETED','ACCEPTED']),
        'provider_ref'=> $r['transaction_id'] ?? $r['ref'] ?? null,
        'message'     => $r['message'] ?? $r['responseMessage'] ?? null,
    ];
}

// ============================================================================
// API HANDLERS
// ============================================================================

function handleBalance(array $p): void
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

function handleCashIn(array $p): void
{
    $cust = Auth::check();
    $code = $cust['KodePro'] ?: $cust['Kode'];
    $v = V::req('amount','ref_number','channel')->amount('amount', MIN_TRX_AMOUNT, MAX_CASHIN_AMOUNT);
    if ($v->fails()) Res::err('Validasi gagal.', 422, $v->errors());

    $amount    = (float)$v->get('amount');
    $refNumber = trim((string)$v->get('ref_number'));
    $channel   = trim((string)$v->get('channel'));

    $ex = Trx::findByRef($refNumber, 'CASHIN');
    if ($ex) Res::ok(fmtTrx($ex), 'Transaksi sudah diproses sebelumnya.');

    RateLimit::check("cashin:$code", 20, 60);
    $faktur = Numbering::next(PREFIX_CASHIN);

    DB::begin();
    try {
        Trx::create(['faktur'=>$faktur,'jenis'=>'CASHIN','kode_receiver'=>$code,
            'amount'=>$amount,'fee'=>0,'gross_amount'=>$amount,
            'keterangan'=>"Cash In via $channel",'ref_number'=>$refNumber,
            'status'=>ST_SUCCESS,'meta'=>['channel'=>$channel]]);
        Wallet::credit($code, $amount);
        Journal::post($faktur,'CASHIN',Journal::entriesCashIn($amount));
        DB::commit();
    } catch (Throwable $e) { DB::rollback(); Log::error("CashIn FAIL: ".$e->getMessage()); throw $e; }

    Log::info("CashIn OK: $faktur | $code | Rp$amount");
    Log::audit('DWallet','cashin',"CashIn $faktur Rp$amount",(int)$cust['id']);
    $trx = Trx::findByFaktur($faktur);
    Res::ok(array_merge(fmtTrx($trx),['channel'=>$channel,'waktu'=>date('c')]), 'Cash In berhasil.', 201);
}

function handleCashOut(array $p): void
{
    $cust = Auth::check();
    $code = $cust['KodePro'] ?: $cust['Kode'];
    $v = V::req('amount','ref_number','bank_code','no_rekening','nama_penerima')
          ->amount('amount', MIN_TRX_AMOUNT, MAX_CASHOUT_AMOUNT);
    if ($v->fails()) Res::err('Validasi gagal.', 422, $v->errors());

    $amount       = (float)$v->get('amount');
    $refNumber    = trim((string)$v->get('ref_number'));
    $bankCode     = strtoupper(trim((string)$v->get('bank_code')));
    $noRek        = trim((string)$v->get('no_rekening'));
    $namaPenerima = trim((string)$v->get('nama_penerima'));
    $ket          = trim(Req::body()['keterangan'] ?? '');

    $ex = Trx::findByRef($refNumber, 'CASHOUT');
    if ($ex) Res::ok(fmtTrx($ex), 'Transaksi sudah diproses sebelumnya.');

    RateLimit::check("cashout:$code", 5, 60);
    $fee  = (float)(Setting::get('dwallet.fee_cashout') ?? FEE_CASHOUT_DEFAULT);
    $gross= $amount + $fee;
    $faktur = Numbering::next(PREFIX_CASHOUT);

    DB::begin();
    try {
        Wallet::debit($code, $gross);
        Trx::create(['faktur'=>$faktur,'jenis'=>'CASHOUT','kode_sender'=>$code,
            'amount'=>$amount,'fee'=>$fee,'gross_amount'=>$gross,
            'keterangan'=>$ket?:"CashOut ke $bankCode/$noRek",'ref_number'=>$refNumber,
            'status'=>ST_PROCESSING,
            'meta'=>['bank_code'=>$bankCode,'no_rekening'=>$noRek,'nama_penerima'=>$namaPenerima]]);
        Journal::post($faktur,'CASHOUT',Journal::entriesCashOut($amount,$fee));
        DB::insert(
            "INSERT INTO pulsa_penjualan
                 (KodePro,NomorDepositCustomer,NomorTujuan,Produk,Status,JenisTrx,Harga,Keterangan,Tgl)
             VALUES (?,?,?,'CASHOUT',?,'13',?,?,NOW())",
            [$code,$faktur,$noRek,ST_PROCESSING,$gross,"CashOut $bankCode/$noRek a/n $namaPenerima"]
        );
        DB::commit();
    } catch (Throwable $e) { DB::rollback(); Log::error("CashOut FAIL: ".$e->getMessage()); throw $e; }

    $dr = disburse($bankCode, $noRek, $namaPenerima, $amount, $faktur);
    if ($dr['success']) {
        Trx::updateStatus($faktur, ST_SUCCESS, $dr['provider_ref']);
        DB::exec("UPDATE pulsa_penjualan SET Status=? WHERE NomorDepositCustomer=?", [ST_SUCCESS,$faktur]);
    } else {
        Wallet::credit($code, $gross);
        Trx::updateStatus($faktur, ST_FAILED, $dr['message']);
        DB::exec("UPDATE pulsa_penjualan SET Status=? WHERE NomorDepositCustomer=?", [ST_FAILED,$faktur]);
        Res::err('Penarikan gagal: '.($dr['message']??'Gagal menghubungi payment processor.'), 502);
    }

    Log::info("CashOut OK: $faktur | $code | Rp$amount");
    Log::audit('DWallet','cashout',"CashOut $faktur Rp$amount → $bankCode/$noRek",(int)$cust['id']);
    $trx = Trx::findByFaktur($faktur);
    Res::ok(array_merge(fmtTrx($trx),[
        'bank_code'=>$bankCode,'no_rekening'=>$noRek,'nama_penerima'=>$namaPenerima,
        'fee'=>$fee,'provider_ref'=>$dr['provider_ref']??null,'waktu'=>date('c'),
    ]), 'Cash Out berhasil.', 201);
}

function handleTransfer(array $p): void
{
    $cust = Auth::check();
    $code = $cust['KodePro'] ?: $cust['Kode'];
    $v = V::req('amount','kode_penerima','ref_number')->amount('amount', MIN_TRX_AMOUNT, MAX_TRANSFER_AMOUNT);
    if ($v->fails()) Res::err('Validasi gagal.', 422, $v->errors());

    $amount  = (float)$v->get('amount');
    $kodeP   = trim((string)$v->get('kode_penerima'));
    $ref     = trim((string)$v->get('ref_number'));
    $ket     = trim(Req::body()['keterangan'] ?? '');

    if ($kodeP === $code) Res::err('Tidak dapat transfer ke diri sendiri.', 422);
    $pen = DB::first("SELECT id,KodePro,name FROM customer WHERE KodePro=? LIMIT 1", [$kodeP]);
    if (!$pen) Res::err("Customer penerima '$kodeP' tidak ditemukan.", 404);

    $ex = Trx::findByRef($ref, 'TRANSFER');
    if ($ex) Res::ok(fmtTrx($ex), 'Transaksi sudah diproses sebelumnya.');

    RateLimit::check("transfer:$code", 10, 60);
    $fee    = FEE_TRANSFER_INTERNAL;
    $gross  = $amount + $fee;
    $faktur = Numbering::next(PREFIX_TRANSFER);

    DB::begin();
    try {
        Wallet::debit($code, $gross);
        Wallet::credit($kodeP, $amount);
        Trx::create(['faktur'=>$faktur,'jenis'=>'TRANSFER','kode_sender'=>$code,'kode_receiver'=>$kodeP,
            'amount'=>$amount,'fee'=>$fee,'gross_amount'=>$gross,
            'keterangan'=>$ket?:"Transfer ke $kodeP",'ref_number'=>$ref,'status'=>ST_SUCCESS]);
        Journal::post($faktur,'TRANSFER',Journal::entriesTransfer($amount,$fee));
        DB::commit();
    } catch (Throwable $e) { DB::rollback(); Log::error("Transfer FAIL: ".$e->getMessage()); throw $e; }

    Log::info("Transfer OK: $faktur | $code→$kodeP | Rp$amount");
    Log::audit('DWallet','transfer',"Transfer $faktur Rp$amount → $kodeP",(int)$cust['id']);
    $trx = Trx::findByFaktur($faktur);
    Res::ok(array_merge(fmtTrx($trx),[
        'kode_penerima'=>$kodeP,'nama_penerima'=>$pen['name'],'waktu'=>date('c'),
    ]), 'Transfer berhasil.', 201);
}

function handlePayment(array $p): void
{
    $cust = Auth::check();
    $code = $cust['KodePro'] ?: $cust['Kode'];
    $v = V::req('amount','ref_number','kode_produk','nomor_tujuan')
          ->amount('amount', MIN_TRX_AMOUNT, MAX_PAYMENT_AMOUNT);
    if ($v->fails()) Res::err('Validasi gagal.', 422, $v->errors());

    $amount  = (float)$v->get('amount');
    $ref     = trim((string)$v->get('ref_number'));
    $produk  = trim((string)$v->get('kode_produk'));
    $tujuan  = trim((string)$v->get('nomor_tujuan'));
    $ket     = trim(Req::body()['keterangan'] ?? '');

    $ex = Trx::findByRef($ref, 'PAYMENT');
    if ($ex) Res::ok(fmtTrx($ex), 'Transaksi sudah diproses sebelumnya.');

    RateLimit::check("payment:$code", 10, 60);
    $fee    = 0.0;
    $gross  = $amount + $fee;
    $faktur = Numbering::next(PREFIX_PAYMENT);

    DB::begin();
    try {
        Wallet::debit($code, $gross);
        Trx::create(['faktur'=>$faktur,'jenis'=>'PAYMENT','kode_sender'=>$code,
            'amount'=>$amount,'fee'=>$fee,'gross_amount'=>$gross,
            'keterangan'=>$ket?:"Payment $produk/$tujuan",'ref_number'=>$ref,
            'status'=>ST_PROCESSING,'meta'=>['kode_produk'=>$produk,'nomor_tujuan'=>$tujuan]]);
        Journal::post($faktur,'PAYMENT',Journal::entriesPayment($amount,$fee));
        DB::insert(
            "INSERT INTO pulsa_penjualan
                 (KodePro,NomorDepositCustomer,NomorTujuan,Produk,Status,JenisTrx,Harga,Keterangan,Tgl)
             VALUES (?,?,?,?,?,'12',?,?,NOW())",
            [$code,$faktur,$tujuan,$produk,ST_PROCESSING,$gross,"Payment $produk $tujuan"]
        );
        DB::commit();
    } catch (Throwable $e) { DB::rollback(); Log::error("Payment FAIL: ".$e->getMessage()); throw $e; }

    Log::info("Payment OK: $faktur | $code | $produk/$tujuan");
    Log::audit('DWallet','payment',"Payment $faktur $produk Rp$amount",(int)$cust['id']);
    $trx = Trx::findByFaktur($faktur);
    Res::ok(array_merge(fmtTrx($trx),[
        'kode_produk'=>$produk,'nomor_tujuan'=>$tujuan,'waktu'=>date('c'),
    ]), 'Payment berhasil diproses.', 201);
}

function handleRefund(array $p): void
{
    $cust = Auth::check();
    $code = $cust['KodePro'] ?: $cust['Kode'];
    $v = V::req('faktur_asal','alasan');
    if ($v->fails()) Res::err('Validasi gagal.', 422, $v->errors());

    $fakturAsal = trim((string)$v->get('faktur_asal'));
    $alasan     = trim((string)$v->get('alasan'));
    $trxAsal    = Trx::findByFaktur($fakturAsal);
    if (!$trxAsal) Res::err("Transaksi '$fakturAsal' tidak ditemukan.", 404);
    if (!in_array($trxAsal['jenis'], ['PAYMENT','CASHIN'], true))
        Res::err("Jenis '{$trxAsal['jenis']}' tidak dapat di-refund.", 422);
    $ks = $trxAsal['kode_sender'] ?? $trxAsal['kode_receiver'];
    if ($ks !== $code) Res::err('Tidak dapat refund transaksi milik customer lain.', 403);
    if (!in_array($trxAsal['status'], [ST_SUCCESS, ST_PROCESSING], true))
        Res::err('Status transaksi tidak memungkinkan refund.', 422);

    $exRef = DB::first(
        "SELECT * FROM dwallet_transactions WHERE ref_number=? AND jenis='REFUND' LIMIT 1",
        [$fakturAsal]
    );
    if ($exRef) Res::ok(fmtTrx($exRef), 'Refund sudah pernah diproses.');

    $refundAmt  = (float)$trxAsal['gross_amount'];
    $faktur     = Numbering::next(PREFIX_REFUND);
    $kodeCustomer = $trxAsal['kode_sender'] ?? $trxAsal['kode_receiver'];

    DB::begin();
    try {
        Wallet::credit($kodeCustomer, $refundAmt);
        Trx::create(['faktur'=>$faktur,'jenis'=>'REFUND','kode_receiver'=>$kodeCustomer,
            'amount'=>$refundAmt,'fee'=>0,'gross_amount'=>$refundAmt,
            'keterangan'=>"Refund $fakturAsal: $alasan",'ref_number'=>$fakturAsal,
            'status'=>ST_SUCCESS,'meta'=>['faktur_asal'=>$fakturAsal,'alasan'=>$alasan]]);
        Trx::updateStatus($fakturAsal, ST_CANCELLED, "Refund via $faktur");
        Journal::post($faktur,'REFUND',Journal::entriesReversal($refundAmt,$trxAsal['jenis']));
        DB::exec("UPDATE pulsa_penjualan SET Status=? WHERE NomorDepositCustomer=?",
                 [ST_CANCELLED,$fakturAsal]);
        DB::commit();
    } catch (Throwable $e) {
        DB::rollback();
        Log::error("Refund FAIL: ".$e->getMessage(),['faktur'=>$faktur,'asal'=>$fakturAsal]);
        throw $e;
    }

    Log::info("Refund OK: $faktur | asal: $fakturAsal | Rp$refundAmt");
    Log::audit('DWallet','refund',"Refund $faktur ← $fakturAsal Rp$refundAmt",(int)$cust['id']);
    $trx = Trx::findByFaktur($faktur);
    Res::ok(array_merge(fmtTrx($trx),[
        'faktur_asal'=>$fakturAsal,'refund_amount'=>$refundAmt,'alasan'=>$alasan,'waktu'=>date('c'),
    ]), 'Refund berhasil.', 201);
}

function handleInquiry(array $p): void
{
    Auth::check();
    $q      = Req::q();
    $faktur = trim($q['faktur'] ?? '');
    $ref    = trim($q['ref_number'] ?? '');
    if (!$faktur && !$ref) Res::err("Sertakan 'faktur' atau 'ref_number'.", 422);

    $trx = $faktur
        ? Trx::findByFaktur($faktur)
        : DB::first("SELECT * FROM dwallet_transactions WHERE ref_number=? ORDER BY id DESC LIMIT 1",[$ref]);
    if (!$trx) Res::err('Transaksi tidak ditemukan.', 404);

    $meta    = json_decode($trx['meta'] ?? '{}', true) ?? [];
    $journal = DB::fetch(
        "SELECT rekening,urut,debet,kredit,keterangan,tgl FROM dwallet_journal
         WHERE faktur=? ORDER BY urut ASC",
        [$trx['faktur']]
    );
    Res::ok([
        'faktur'       =>$trx['faktur'],  'jenis'        =>$trx['jenis'],
        'ref_number'   =>$trx['ref_number'],'kode_sender'=>$trx['kode_sender'],
        'kode_receiver'=>$trx['kode_receiver'],'amount'  =>(float)$trx['amount'],
        'fee'          =>(float)$trx['fee'],'gross_amount'=>(float)$trx['gross_amount'],
        'keterangan'   =>$trx['keterangan'],'status'      =>statusLabel($trx['status']),
        'status_kode'  =>$trx['status'],  'note'          =>$trx['note'],
        'meta'         =>$meta,           'journal'       =>$journal,
        'created_at'   =>$trx['created_at'],'updated_at' =>$trx['updated_at'],
    ]);
}

function handleHistory(array $p): void
{
    $cust = Auth::check();
    $code = $cust['KodePro'] ?: $cust['Kode'];
    $q    = Req::q();
    $page = max(1,(int)($q['page']??1));
    $pp   = min(100,max(1,(int)($q['per_page']??20)));
    $jenis= strtoupper($q['jenis']??'');
    if ($jenis && !in_array($jenis,['CASHIN','CASHOUT','TRANSFER','PAYMENT','REFUND'],true))
        Res::err('Jenis tidak valid.',422);

    $res = Trx::history($code,$page,$pp,$jenis?:null,$q['dari']??null,$q['sampai']??null);
    $res['data'] = array_map(function($row) use ($code) {
        return [
            'faktur'      =>$row['faktur'],   'jenis'      =>$row['jenis'],
            'arah'        =>($row['kode_sender']===$code)?'KELUAR':'MASUK',
            'lawan_kode'  =>($row['kode_sender']===$code)?$row['kode_receiver']:$row['kode_sender'],
            'amount'      =>(float)$row['amount'],'fee'=>(float)$row['fee'],
            'gross_amount'=>(float)$row['gross_amount'],'keterangan'=>$row['keterangan'],
            'ref_number'  =>$row['ref_number'],'status'=>statusLabel($row['status']),
            'status_kode' =>$row['status'],'created_at'=>$row['created_at'],
        ];
    }, $res['data']);
    Res::ok($res);
}

function handleCallback(array $p): void
{
    $raw    = file_get_contents('php://input') ?: '';
    $body   = json_decode($raw, true) ?? [];
    $secret = Setting::get('payment_processor_callback_secret', '') ?: CFG_PP_CB_SECRET;

    if (!empty($secret)) {
        $sig = Req::header('X-Callback-Signature');
        if (!hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
            Log::warn('Callback signature invalid',['ip'=>Req::ip()]);
            Res::err('Signature tidak valid.', 401);
        }
    }
    $faktur  = $body['faktur']??$body['external_id']??$body['trx_id']??null;
    $statRaw = strtoupper($body['status']??'');
    $provRef = $body['provider_ref']??$body['transaction_id']??null;
    $provider= $body['provider']??'UNKNOWN';
    $corrId  = $body['correlation_id']??$faktur??('CB-'.time());
    if (!$faktur||!$statRaw) Res::err("Field 'faktur' dan 'status' wajib.", 422);

    try {
        DB::insert(
            "INSERT INTO dwallet_sync_results (correlation_id,response_payload,created_at)
             VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE response_payload=VALUES(response_payload)",
            [$corrId, json_encode($body, JSON_UNESCAPED_UNICODE)]
        );
    } catch (Throwable) {}
    try {
        DB::insert(
            "INSERT INTO dwallet_callbacks (faktur,provider,payload,received_at) VALUES (?,?,?,NOW())",
            [$faktur, $provider, json_encode($body, JSON_UNESCAPED_UNICODE)]
        );
    } catch (Throwable) {}

    $trx = Trx::findByFaktur($faktur);
    if (!$trx) { Log::warn("CB faktur not found: $faktur"); Res::ok([],'CB diterima (faktur tidak ditemukan).'); }
    if (in_array($trx['status'],[ST_SUCCESS,ST_CANCELLED],true)) Res::ok([],'CB diterima (sudah final).');

    $dbStatus = match($statRaw) {
        'SUCCESS','COMPLETED','PAID','BERHASIL' => ST_SUCCESS,
        'FAILED','REJECTED','GAGAL','ERROR'     => ST_FAILED,
        default => null,
    };
    if ($dbStatus) {
        Trx::updateStatus($faktur,$dbStatus,$provRef??$statRaw);
        if ($dbStatus===ST_FAILED && $trx['jenis']==='CASHOUT' && $trx['status']===ST_PROCESSING) {
            Wallet::credit($trx['kode_sender'],(float)$trx['gross_amount']);
            Log::info("CashOut reversed via CB: $faktur");
        }
        if ($trx['jenis']==='PAYMENT')
            DB::exec("UPDATE pulsa_penjualan SET Status=? WHERE NomorDepositCustomer=?",[$dbStatus,$faktur]);
    }
    Log::info("CB processed: $faktur → $statRaw");
    Res::ok(['faktur'=>$faktur,'status_processed'=>$statRaw]);
}

function handleHealth(array $p): void
{
    $ok = false;
    try { DB::scalar('SELECT 1'); $ok = true; } catch (Throwable) {}
    http_response_code($ok?200:503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' =>$ok?'healthy':'degraded','version'=>DWALLET_VERSION,
        'db'     =>['connected'=>$ok],'timezone'=>DWALLET_TIMEZONE,
        'time'   =>date('c'),'server'=>gethostname(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function handleInstall(array $p): void
{
    $secret = $_GET['secret'] ?? '';
    $env    = Setting::get('dwallet_install_secret','') ?: '';
    // Fallback: jika belum ada di setting, izinkan dengan password UI
    $allowed = (!empty($env) && $secret===$env) || $secret===CFG_UI_PASS;
    if (!$allowed) Res::err('Akses ditolak. Sertakan ?secret=<password>', 403);

    $ddl = [
        "CREATE TABLE IF NOT EXISTS `dwallet_wallets` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_code` VARCHAR(30) NOT NULL COMMENT 'KodePro dari tabel customer',
            `account_number` VARCHAR(20) NOT NULL,
            `balance` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            `hold_balance` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_customer_code` (`customer_code`),
            UNIQUE KEY `uq_account_number` (`account_number`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `dwallet_transactions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `faktur` VARCHAR(30) NOT NULL,
            `jenis` ENUM('CASHIN','CASHOUT','TRANSFER','PAYMENT','REFUND') NOT NULL,
            `kode_sender` VARCHAR(30) NULL, `kode_receiver` VARCHAR(30) NULL,
            `amount` DECIMAL(20,2) NOT NULL, `fee` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            `gross_amount` DECIMAL(20,2) NOT NULL, `keterangan` VARCHAR(255) NULL,
            `ref_number` VARCHAR(64) NULL,
            `status` CHAR(1) NOT NULL DEFAULT 'P'
                COMMENT 'P=Pending,S=Sukses,G=Gagal,D=Dibatalkan,R=Processing',
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
            PRIMARY KEY (`id`), INDEX `idx_faktur` (`faktur`),
            INDEX `idx_rekening` (`rekening`), INDEX `idx_tgl` (`tgl`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `dwallet_callbacks` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `faktur` VARCHAR(30) NOT NULL, `provider` VARCHAR(30) NOT NULL,
            `payload` JSON NULL, `received_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), INDEX `idx_faktur` (`faktur`),
            INDEX `idx_recv` (`received_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `dwallet_rate_limit` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rate_key` VARCHAR(60) NOT NULL, `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), INDEX `idx_key` (`rate_key`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "INSERT IGNORE INTO `setting` (setting_key,setting_value,created_at,updated_at) VALUES
            ('dwallet.coa_aset',   '".CFG_COA_ASET."',   UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
            ('dwallet.coa_liab',   '".CFG_COA_LIAB."',   UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
            ('dwallet.coa_fee',    '".CFG_COA_FEE."',    UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
            ('dwallet.coa_expense','".CFG_COA_EXPENSE."',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
            ('dwallet.fee_cashout','".CFG_FEE_CASHOUT."',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
            ('payment_processor_url',           '".CFG_PP_URL."',       UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
            ('payment_processor_token',         '".CFG_PP_TOKEN."',     UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
            ('payment_processor_callback_secret','".CFG_PP_CB_SECRET."',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())",
    ];

    $results = [];
    foreach ($ddl as $sql) {
        try {
            DB::insert($sql);
            preg_match('/(?:TABLE IF NOT EXISTS|INTO) `(\w+)`/', $sql, $m);
            $results[] = ['object'=>$m[1]??'setting','status'=>'OK'];
        } catch (Throwable $e) {
            preg_match('/(?:TABLE IF NOT EXISTS|INTO) `(\w+)`/', $sql, $m);
            $results[] = ['object'=>$m[1]??'?','status'=>'ERROR: '.$e->getMessage()];
        }
    }
    Res::ok(['results'=>$results], 'Instalasi skema selesai.');
}

// ============================================================================
// WEB UI — Admin Dashboard (Basic Auth protected)
// ============================================================================

function handleUI(): void
{
    // ── Basic Auth ─────────────────────────────────────────────────────────
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW']   ?? '';
    if ($user !== CFG_UI_USER || $pass !== CFG_UI_PASS) {
        header('WWW-Authenticate: Basic realm="DWallet Admin"');
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(401);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px">
              <h2>🔒 DWallet Admin — Login Diperlukan</h2>
              <p>Masukkan username dan password admin.</p></body></html>';
        exit;
    }

    // ── Statistik ringkas untuk dashboard ─────────────────────────────────
    $stats = ['trx_today'=>0,'trx_total'=>0,'wallet_total'=>0,'vol_today'=>0.0];
    $recentTrx = [];
    $dbStatus  = false;
    $dbError   = '';
    try {
        DB::scalar('SELECT 1');
        $dbStatus = true;
        $stats['trx_today']   = (int)DB::scalar("SELECT COUNT(*) FROM dwallet_transactions WHERE DATE(created_at)=CURDATE()");
        $stats['trx_total']   = (int)DB::scalar("SELECT COUNT(*) FROM dwallet_transactions");
        $stats['wallet_total']= (int)DB::scalar("SELECT COUNT(*) FROM dwallet_wallets");
        $stats['vol_today']   = (float)DB::scalar("SELECT COALESCE(SUM(gross_amount),0) FROM dwallet_transactions WHERE DATE(created_at)=CURDATE() AND status='S'") ;
        $recentTrx = DB::fetch(
            "SELECT faktur,jenis,kode_sender,kode_receiver,amount,fee,gross_amount,status,created_at
             FROM dwallet_transactions ORDER BY created_at DESC LIMIT 20"
        );
    } catch (Throwable $e) { $dbError = $e->getMessage(); }

    $ver   = DWALLET_VERSION;
    $tz    = DWALLET_TIMEZONE;
    $host  = CFG_DB_HOST;
    $db    = CFG_DB_NAME;
    $now   = date('d/m/Y H:i:s');
    $ppUrl = CFG_PP_URL ?: '(stub mode)';
    $dbBadge = $dbStatus
        ? '<span class="badge ok">✅ Connected</span>'
        : '<span class="badge err">❌ '.htmlspecialchars($dbError).'</span>';

    // ── Status badge helper ────────────────────────────────────────────────
    $sbadge = function(string $s): string {
        $map = [
            'P'=>['PENDING',  '#f59e0b'],
            'S'=>['SUCCESS',  '#10b981'],
            'G'=>['FAILED',   '#ef4444'],
            'D'=>['CANCELLED','#6b7280'],
            'R'=>['PROCESSING','#3b82f6'],
        ];
        [$label,$color] = $map[$s] ?? ['UNKNOWN','#9ca3af'];
        return "<span style='background:$color;color:#fff;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600'>$label</span>";
    };

    // ── Tabel transaksi terbaru ─────────────────────────────────────────────
    $rows = '';
    foreach ($recentTrx as $t) {
        $amt = 'Rp '.number_format((float)$t['amount'],0,',','.');
        $gross = 'Rp '.number_format((float)$t['gross_amount'],0,',','.');
        $date  = date('d/m H:i', strtotime($t['created_at']));
        $pihak = $t['kode_sender'] ?: $t['kode_receiver'];
        $rows .= "<tr>
            <td><code style='font-size:11px'>{$t['faktur']}</code></td>
            <td><span class='jenis-{$t['jenis']}'>{$t['jenis']}</span></td>
            <td>{$pihak}</td>
            <td style='text-align:right'>{$amt}</td>
            <td style='text-align:right'>{$gross}</td>
            <td>{$sbadge($t['status'])}</td>
            <td style='color:#6b7280;font-size:12px'>{$date}</td>
        </tr>";
    }

    $volFmt = 'Rp '.number_format($stats['vol_today'],0,',','.');

    header('Content-Type: text/html; charset=utf-8');
    http_response_code(200);
    echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DWallet Admin v{$ver}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f4f8;color:#1a202c;min-height:100vh}
/* ── Layout ── */
.sidebar{position:fixed;top:0;left:0;width:230px;height:100vh;background:linear-gradient(160deg,#1e3a5f 0%,#0f2942 100%);color:#fff;padding:0;overflow-y:auto;z-index:100}
.sidebar-logo{padding:24px 20px 16px;border-bottom:1px solid rgba(255,255,255,.1)}
.sidebar-logo h1{font-size:18px;font-weight:700;letter-spacing:.5px}
.sidebar-logo .ver{font-size:11px;color:rgba(255,255,255,.5);margin-top:3px}
.nav-section{padding:12px 0 4px 16px;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,.35)}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 20px;cursor:pointer;font-size:13px;color:rgba(255,255,255,.75);transition:all .15s;text-decoration:none;border-left:3px solid transparent}
.nav-item:hover,.nav-item.active{background:rgba(255,255,255,.08);color:#fff;border-left-color:#60a5fa}
.nav-item .icon{width:18px;text-align:center;font-size:15px}
.main{margin-left:230px;padding:24px;min-height:100vh}
/* ── Header ── */
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.topbar h2{font-size:20px;font-weight:700;color:#1e3a5f}
.topbar .meta{font-size:12px;color:#64748b}
/* ── Stat cards ── */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
@media(max-width:900px){.stats{grid-template-columns:repeat(2,1fr)}}
.stat-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.07)}
.stat-card .label{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.stat-card .value{font-size:26px;font-weight:700;color:#1e3a5f}
.stat-card .sub{font-size:11px;color:#94a3b8;margin-top:4px}
.stat-card .icon-wrap{float:right;margin-top:-4px;font-size:28px;opacity:.3}
/* ── Cards ── */
.card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center}
.card-header h3{font-size:15px;font-weight:600;color:#1e3a5f}
.card-body{padding:20px}
/* ── Table ── */
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{background:#f8fafc;padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #e2e8f0}
.tbl td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.tbl tr:hover td{background:#fafcff}
/* ── Jenis badges ── */
.jenis-CASHIN{background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.jenis-CASHOUT{background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.jenis-TRANSFER{background:#dbeafe;color:#2563eb;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.jenis-PAYMENT{background:#fef3c7;color:#d97706;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.jenis-REFUND{background:#f3e8ff;color:#9333ea;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
/* ── Form & misc ── */
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
@media(max-width:700px){.form-grid{grid-template-columns:1fr}}
.form-group label{display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px}
.form-group input,.form-group select,.form-group textarea{
    width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;
    font-size:13px;color:#1a202c;background:#fff;outline:none;transition:border .15s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.btn{padding:9px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:#1e3a5f;color:#fff} .btn-primary:hover{background:#2d5986}
.btn-success{background:#16a34a;color:#fff} .btn-success:hover{background:#15803d}
.btn-warning{background:#d97706;color:#fff} .btn-warning:hover{background:#b45309}
.btn-danger {background:#dc2626;color:#fff} .btn-danger:hover{background:#b91c1c}
.btn-info   {background:#0891b2;color:#fff} .btn-info:hover{background:#0e7490}
.btn-purple {background:#7c3aed;color:#fff} .btn-purple:hover{background:#6d28d9}
.result-box{margin-top:14px;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:10px;font-size:12px;font-family:'Courier New',monospace;max-height:350px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;display:none}
.badge{padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
.badge.ok{background:#dcfce7;color:#16a34a} .badge.err{background:#fee2e2;color:#dc2626}
.info-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
.info-row:last-child{border:none} .info-row .k{color:#64748b} .info-row .v{font-weight:600;text-align:right}
.tab-bar{display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap}
.tab{padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;background:#f1f5f9;color:#475569;border:none;transition:all .15s}
.tab.active,.tab:hover{background:#1e3a5f;color:#fff}
.tab-content{display:none} .tab-content.active{display:block}
.alert{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:12px}
.alert-info{background:#dbeafe;color:#1e40af} .alert-warn{background:#fef3c7;color:#92400e}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px}
/* ── Sections ── */
.section{display:none} .section.active{display:block}
/* ── Responsive ── */
@media(max-width:768px){.sidebar{width:100%;height:auto;position:relative}.main{margin-left:0}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <h1>💳 DWallet Admin</h1>
    <div class="ver">v{$ver} · {$tz}</div>
  </div>

  <div class="nav-section">Overview</div>
  <a class="nav-item active" onclick="showSection('dashboard',this)">
    <span class="icon">📊</span> Dashboard
  </a>

  <div class="nav-section">Transaksi</div>
  <a class="nav-item" onclick="showSection('balance',this)">
    <span class="icon">💰</span> Balance
  </a>
  <a class="nav-item" onclick="showSection('cashin',this)">
    <span class="icon">⬇️</span> Cash In
  </a>
  <a class="nav-item" onclick="showSection('cashout',this)">
    <span class="icon">⬆️</span> Cash Out
  </a>
  <a class="nav-item" onclick="showSection('transfer',this)">
    <span class="icon">↔️</span> Transfer
  </a>
  <a class="nav-item" onclick="showSection('payment',this)">
    <span class="icon">🛒</span> Payment
  </a>
  <a class="nav-item" onclick="showSection('refund',this)">
    <span class="icon">↩️</span> Refund
  </a>

  <div class="nav-section">Lookup</div>
  <a class="nav-item" onclick="showSection('inquiry',this)">
    <span class="icon">🔍</span> Inquiry
  </a>
  <a class="nav-item" onclick="showSection('history',this)">
    <span class="icon">📜</span> History
  </a>

  <div class="nav-section">Sistem</div>
  <a class="nav-item" onclick="showSection('config',this)">
    <span class="icon">⚙️</span> Konfigurasi
  </a>
  <a class="nav-item" onclick="showSection('install',this)">
    <span class="icon">🔧</span> Install Schema
  </a>
  <a class="nav-item" onclick="showSection('apidoc',this)">
    <span class="icon">📖</span> API Docs
  </a>
</aside>

<!-- MAIN CONTENT -->
<main class="main">

  <!-- ── TOPBAR ── -->
  <div class="topbar">
    <h2 id="section-title">📊 Dashboard</h2>
    <div class="meta">⏰ {$now} &nbsp;|&nbsp; 🌐 http://assist.gw.sis1.net</div>
  </div>

  <!-- ══════════════════ DASHBOARD ══════════════════ -->
  <div id="sec-dashboard" class="section active">
    <div class="stats">
      <div class="stat-card">
        <div class="icon-wrap">📋</div>
        <div class="label">Transaksi Hari Ini</div>
        <div class="value">{$stats['trx_today']}</div>
        <div class="sub">Total: {$stats['trx_total']}</div>
      </div>
      <div class="stat-card">
        <div class="icon-wrap">💳</div>
        <div class="label">Wallet Aktif</div>
        <div class="value">{$stats['wallet_total']}</div>
        <div class="sub">Customer terdaftar</div>
      </div>
      <div class="stat-card">
        <div class="icon-wrap">💵</div>
        <div class="label">Volume Hari Ini</div>
        <div class="value" style="font-size:18px">{$volFmt}</div>
        <div class="sub">Status: Sukses</div>
      </div>
      <div class="stat-card">
        <div class="icon-wrap">🗄️</div>
        <div class="label">Database</div>
        <div class="value" style="font-size:14px;margin-top:6px">{$dbBadge}</div>
        <div class="sub">{$host} / {$db}</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3>📋 20 Transaksi Terbaru</h3>
        <button class="btn btn-primary" onclick="location.reload()">🔄 Refresh</button>
      </div>
      <div style="overflow-x:auto">
        <table class="tbl">
          <thead><tr>
            <th>Faktur</th><th>Jenis</th><th>Kode</th>
            <th style="text-align:right">Amount</th><th style="text-align:right">Gross</th>
            <th>Status</th><th>Waktu</th>
          </tr></thead>
          <tbody>{$rows}</tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ══════════════════ BALANCE ══════════════════ -->
  <div id="sec-balance" class="section">
    <div class="card">
      <div class="card-header"><h3>💰 Cek Saldo Wallet</h3></div>
      <div class="card-body">
        <div class="alert alert-info">POST /mod/dwallet/v1/api/balance — Memerlukan Bearer Token</div>
        <div class="form-grid">
          <div class="form-group"><label>Bearer Token *</label>
            <input id="b-token" placeholder="api_token dari tabel customer"></div>
        </div>
        <br>
        <button class="btn btn-primary" onclick="apiCall('balance')">💰 Cek Saldo</button>
        <div class="result-box" id="res-balance"></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════ CASH IN ══════════════════ -->
  <div id="sec-cashin" class="section">
    <div class="card">
      <div class="card-header"><h3>⬇️ Cash In (Top-up Saldo)</h3></div>
      <div class="card-body">
        <div class="alert alert-info">POST /mod/dwallet/v1/api/cashin</div>
        <div class="form-grid">
          <div class="form-group"><label>Bearer Token *</label>
            <input id="ci-token" placeholder="api_token customer"></div>
          <div class="form-group"><label>Amount (IDR) *</label>
            <input id="ci-amount" type="number" placeholder="100000" min="1000"></div>
          <div class="form-group"><label>Ref Number * (unik)</label>
            <input id="ci-ref" placeholder="TRX-001" oninput="if(!this.value)this.value='TRX-'+Date.now()"></div>
          <div class="form-group"><label>Channel *</label>
            <input id="ci-channel" placeholder="TRANSFER_BANK / VA / QRIS"></div>
        </div>
        <br>
        <button class="btn btn-success" onclick="genRef('ci-ref')">🎲 Auto Ref</button>&nbsp;
        <button class="btn btn-primary" onclick="apiCall('cashin')">⬇️ Proses Cash In</button>
        <div class="result-box" id="res-cashin"></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════ CASH OUT ══════════════════ -->
  <div id="sec-cashout" class="section">
    <div class="card">
      <div class="card-header"><h3>⬆️ Cash Out (Penarikan)</h3></div>
      <div class="card-body">
        <div class="alert alert-info">POST /mod/dwallet/v1/api/cashout — Fee: Rp <?= number_format(CFG_FEE_CASHOUT,0,',','.') ?></div>
        <div class="form-grid">
          <div class="form-group"><label>Bearer Token *</label>
            <input id="co-token" placeholder="api_token customer"></div>
          <div class="form-group"><label>Amount (IDR) *</label>
            <input id="co-amount" type="number" placeholder="500000" min="1000"></div>
          <div class="form-group"><label>Ref Number *</label>
            <input id="co-ref" placeholder="CO-001"></div>
          <div class="form-group"><label>Kode Bank *</label>
            <select id="co-bank">
              <option value="">-- Pilih Bank --</option>
              <option value="BCA">BCA</option><option value="BNI">BNI</option>
              <option value="BRI">BRI</option><option value="MANDIRI">Mandiri</option>
              <option value="BSI">BSI</option><option value="PERMATA">Permata</option>
              <option value="CIMB">CIMB Niaga</option><option value="DANAMON">Danamon</option>
              <option value="OTHER">Lainnya</option>
            </select></div>
          <div class="form-group"><label>No. Rekening *</label>
            <input id="co-norek" placeholder="1234567890"></div>
          <div class="form-group"><label>Nama Penerima *</label>
            <input id="co-nama" placeholder="JOHN DOE"></div>
          <div class="form-group"><label>Keterangan</label>
            <input id="co-ket" placeholder="Penarikan gaji"></div>
        </div>
        <br>
        <button class="btn btn-success" onclick="genRef('co-ref')">🎲 Auto Ref</button>&nbsp;
        <button class="btn btn-danger" onclick="apiCall('cashout')">⬆️ Proses Cash Out</button>
        <div class="result-box" id="res-cashout"></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════ TRANSFER ══════════════════ -->
  <div id="sec-transfer" class="section">
    <div class="card">
      <div class="card-header"><h3>↔️ Transfer Antar Wallet</h3></div>
      <div class="card-body">
        <div class="alert alert-info">POST /mod/dwallet/v1/api/transfer — Fee internal: Rp 0</div>
        <div class="form-grid">
          <div class="form-group"><label>Bearer Token *</label>
            <input id="tf-token" placeholder="api_token pengirim"></div>
          <div class="form-group"><label>Amount (IDR) *</label>
            <input id="tf-amount" type="number" placeholder="50000" min="1000"></div>
          <div class="form-group"><label>Kode Penerima * (KodePro)</label>
            <input id="tf-kode" placeholder="A-000301"></div>
          <div class="form-group"><label>Ref Number *</label>
            <input id="tf-ref" placeholder="TF-001"></div>
          <div class="form-group"><label>Keterangan</label>
            <input id="tf-ket" placeholder="Titip bayar listrik"></div>
        </div>
        <br>
        <button class="btn btn-success" onclick="genRef('tf-ref')">🎲 Auto Ref</button>&nbsp;
        <button class="btn btn-info" onclick="apiCall('transfer')">↔️ Proses Transfer</button>
        <div class="result-box" id="res-transfer"></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════ PAYMENT ══════════════════ -->
  <div id="sec-payment" class="section">
    <div class="card">
      <div class="card-header"><h3>🛒 Payment PPOB</h3></div>
      <div class="card-body">
        <div class="alert alert-info">POST /mod/dwallet/v1/api/payment — JenisTrx=12 (eMoney)</div>
        <div class="form-grid">
          <div class="form-group"><label>Bearer Token *</label>
            <input id="py-token" placeholder="api_token customer"></div>
          <div class="form-group"><label>Amount (IDR) *</label>
            <input id="py-amount" type="number" placeholder="100000" min="1000"></div>
          <div class="form-group"><label>Ref Number *</label>
            <input id="py-ref" placeholder="PAY-001"></div>
          <div class="form-group"><label>Kode Produk *</label>
            <input id="py-produk" placeholder="PLN50 / TSEL10K / BPJS"></div>
          <div class="form-group"><label>Nomor Tujuan *</label>
            <input id="py-tujuan" placeholder="08123456789 / 12345678901"></div>
          <div class="form-group"><label>Keterangan</label>
            <input id="py-ket" placeholder="Tagihan listrik bulan ini"></div>
        </div>
        <br>
        <button class="btn btn-success" onclick="genRef('py-ref')">🎲 Auto Ref</button>&nbsp;
        <button class="btn btn-warning" onclick="apiCall('payment')">🛒 Proses Payment</button>
        <div class="result-box" id="res-payment"></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════ REFUND ══════════════════ -->
  <div id="sec-refund" class="section">
    <div class="card">
      <div class="card-header"><h3>↩️ Refund / Reversal</h3></div>
      <div class="card-body">
        <div class="alert alert-warn">⚠️ Hanya PAYMENT dan CASHIN yang bisa di-refund. Status asal harus S atau R.</div>
        <div class="form-grid">
          <div class="form-group"><label>Bearer Token *</label>
            <input id="rf-token" placeholder="api_token customer"></div>
          <div class="form-group"><label>Faktur Asal *</label>
            <input id="rf-faktur" placeholder="PAY0000000001 / CIN0000000001"></div>
          <div class="form-group" style="grid-column:1/-1"><label>Alasan Refund *</label>
            <input id="rf-alasan" placeholder="Produk gagal diproses / Salah input"></div>
        </div>
        <br>
        <button class="btn btn-purple" onclick="apiCall('refund')">↩️ Proses Refund</button>
        <div class="result-box" id="res-refund"></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════ INQUIRY ══════════════════ -->
  <div id="sec-inquiry" class="section">
    <div class="card">
      <div class="card-header"><h3>🔍 Inquiry Status Transaksi</h3></div>
      <div class="card-body">
        <div class="alert alert-info">GET /mod/dwallet/v1/api/inquiry?faktur= atau ?ref_number=</div>
        <div class="form-grid">
          <div class="form-group"><label>Bearer Token *</label>
            <input id="iq-token" placeholder="api_token customer"></div>
          <div class="form-group"><label>Nomor Faktur</label>
            <input id="iq-faktur" placeholder="CIN0000000001"></div>
          <div class="form-group"><label>Atau: Ref Number</label>
            <input id="iq-ref" placeholder="TRX-001 (alternatif)"></div>
        </div>
        <br>
        <button class="btn btn-primary" onclick="apiCall('inquiry')">🔍 Cari Transaksi</button>
        <div class="result-box" id="res-inquiry"></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════ HISTORY ══════════════════ -->
  <div id="sec-history" class="section">
    <div class="card">
      <div class="card-header"><h3>📜 Riwayat Transaksi</h3></div>
      <div class="card-body">
        <div class="alert alert-info">GET /mod/dwallet/v1/api/history</div>
        <div class="form-grid">
          <div class="form-group"><label>Bearer Token *</label>
            <input id="hs-token" placeholder="api_token customer"></div>
          <div class="form-group"><label>Jenis (opsional)</label>
            <select id="hs-jenis">
              <option value="">-- Semua --</option>
              <option value="CASHIN">CASHIN</option><option value="CASHOUT">CASHOUT</option>
              <option value="TRANSFER">TRANSFER</option><option value="PAYMENT">PAYMENT</option>
              <option value="REFUND">REFUND</option>
            </select></div>
          <div class="form-group"><label>Dari (YYYY-MM-DD)</label>
            <input id="hs-dari" type="date"></div>
          <div class="form-group"><label>Sampai (YYYY-MM-DD)</label>
            <input id="hs-sampai" type="date"></div>
          <div class="form-group"><label>Page</label>
            <input id="hs-page" type="number" value="1" min="1"></div>
          <div class="form-group"><label>Per Page</label>
            <input id="hs-pp" type="number" value="20" min="1" max="100"></div>
        </div>
        <br>
        <button class="btn btn-primary" onclick="apiCall('history')">📜 Ambil History</button>
        <div class="result-box" id="res-history"></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════ KONFIGURASI ══════════════════ -->
  <div id="sec-config" class="section">
    <div class="card">
      <div class="card-header"><h3>⚙️ Konfigurasi Sistem (Hardcoded)</h3></div>
      <div class="card-body">
        <div class="alert alert-warn">⚠️ Semua konfigurasi hardcoded di file. Edit blok <code>KONFIGURASI PRODUKSI</code> untuk mengubah nilai.</div>
        <br>
        <div class="info-row"><span class="k">Versi</span><span class="v">{$ver}</span></div>
        <div class="info-row"><span class="k">Timezone</span><span class="v">{$tz}</span></div>
        <div class="info-row"><span class="k">Database Host</span><span class="v">{$host}:{$db}</span></div>
        <div class="info-row"><span class="k">Database Name</span><span class="v">{$db}</span></div>
        <div class="info-row"><span class="k">DB User</span><span class="v"><?= CFG_DB_USER ?></span></div>
        <div class="info-row"><span class="k">Status DB</span><span class="v">{$dbBadge}</span></div>
        <div class="info-row"><span class="k">Payment Processor URL</span><span class="v">{$ppUrl}</span></div>
        <div class="info-row"><span class="k">PP Callback Secret</span><span class="v"><?= empty(CFG_PP_CB_SECRET) ? '<span style="color:#ef4444">Tidak dikonfigurasi</span>' : '<span style="color:#16a34a">Aktif</span>' ?></span></div>
        <div class="info-row"><span class="k">Min Transaksi</span><span class="v"><?= rupiah(CFG_MIN_TRX) ?></span></div>
        <div class="info-row"><span class="k">Max Cash In</span><span class="v"><?= rupiah(CFG_MAX_CASHIN) ?></span></div>
        <div class="info-row"><span class="k">Max Cash Out</span><span class="v"><?= rupiah(CFG_MAX_CASHOUT) ?></span></div>
        <div class="info-row"><span class="k">Max Transfer</span><span class="v"><?= rupiah(CFG_MAX_TRANSFER) ?></span></div>
        <div class="info-row"><span class="k">Max Payment</span><span class="v"><?= rupiah(CFG_MAX_PAYMENT) ?></span></div>
        <div class="info-row"><span class="k">Fee CashOut Default</span><span class="v"><?= rupiah(CFG_FEE_CASHOUT) ?></span></div>
        <div class="info-row"><span class="k">Fee Transfer Internal</span><span class="v"><?= rupiah(CFG_FEE_TRANSFER) ?></span></div>
        <div class="info-row"><span class="k">COA Aset</span><span class="v"><?= CFG_COA_ASET ?></span></div>
        <div class="info-row"><span class="k">COA Liabilitas</span><span class="v"><?= CFG_COA_LIAB ?></span></div>
        <div class="info-row"><span class="k">COA Fee</span><span class="v"><?= CFG_COA_FEE ?></span></div>
        <div class="info-row"><span class="k">COA Expense</span><span class="v"><?= CFG_COA_EXPENSE ?></span></div>
        <br>
        <button class="btn btn-primary" onclick="apiCall('health')">🏥 Cek Health API</button>
        <div class="result-box" id="res-health"></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════ INSTALL ══════════════════ -->
  <div id="sec-install" class="section">
    <div class="card">
      <div class="card-header"><h3>🔧 Install Schema Tabel Baru</h3></div>
      <div class="card-body">
        <div class="alert alert-warn">⚠️ Jalankan sekali saja saat pertama deploy. Menggunakan <code>CREATE TABLE IF NOT EXISTS</code> — aman dijalankan ulang.</div>
        <p style="font-size:13px;color:#475569;margin-bottom:16px">
          Akan membuat tabel: <code>dwallet_wallets</code>, <code>dwallet_transactions</code>,
          <code>dwallet_journal</code>, <code>dwallet_callbacks</code>, <code>dwallet_rate_limit</code>
          + insert default setting ke tabel <code>setting</code>.
        </p>
        <button class="btn btn-danger" onclick="runInstall()">🔧 Jalankan Install Schema</button>
        <div class="result-box" id="res-install"></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════ API DOCS ══════════════════ -->
  <div id="sec-apidoc" class="section">
    <div class="card">
      <div class="card-header"><h3>📖 API Reference</h3></div>
      <div class="card-body">

        <div class="tab-bar">
          <button class="tab active" onclick="showTab(this,'tab-auth')">Auth</button>
          <button class="tab" onclick="showTab(this,'tab-balance')">Balance</button>
          <button class="tab" onclick="showTab(this,'tab-cashin')">Cash In</button>
          <button class="tab" onclick="showTab(this,'tab-cashout')">Cash Out</button>
          <button class="tab" onclick="showTab(this,'tab-transfer')">Transfer</button>
          <button class="tab" onclick="showTab(this,'tab-payment')">Payment</button>
          <button class="tab" onclick="showTab(this,'tab-refund')">Refund</button>
          <button class="tab" onclick="showTab(this,'tab-inquiry')">Inquiry</button>
          <button class="tab" onclick="showTab(this,'tab-history')">History</button>
          <button class="tab" onclick="showTab(this,'tab-callback')">Callback</button>
          <button class="tab" onclick="showTab(this,'tab-status')">Status Codes</button>
        </div>

        <div id="tab-auth" class="tab-content active">
          <h4 style="margin-bottom:10px;color:#1e3a5f">Autentikasi Bearer Token</h4>
          <p style="font-size:13px;color:#475569;margin-bottom:12px">
            Semua endpoint (kecuali <code>/health</code> dan <code>/callback</code>) memerlukan header:
          </p>
          <div class="result-box" style="display:block">Authorization: Bearer &lt;api_token&gt;

Contoh:
Authorization: Bearer de1487ac24851aa147add1d386a3e3fb

api_token diambil dari kolom customer.api_token (MD5 32 karakter)</div>
        </div>

        <div id="tab-balance" class="tab-content">
          <h4 style="margin-bottom:10px;color:#1e3a5f">POST /mod/dwallet/v1/api/balance</h4>
          <div class="result-box" style="display:block">// Request
POST http://assist.gw.sis1.net/mod/dwallet/v1/api/balance
Authorization: Bearer &lt;token&gt;
Content-Type: application/json
{}

// Response 200
{
  "success": true, "message": "OK",
  "data": {
    "customer_code":     "A-000300",
    "customer_name":     "John Doe",
    "account_number":    "AW12345678",
    "balance":           1500000.00,
    "hold_balance":      0.00,
    "available_balance": 1500000.00,
    "wallet_status":     "active",
    "log_deposit_saldo": 1500000.00,
    "last_updated":      "2025-06-15 10:30:00"
  }
}</div>
        </div>

        <div id="tab-cashin" class="tab-content">
          <h4 style="margin-bottom:10px;color:#1e3a5f">POST /mod/dwallet/v1/api/cashin</h4>
          <div class="result-box" style="display:block">// Request Body (JSON)
{
  "amount":     100000,          // number, min 1.000
  "ref_number": "TRX-12345",    // string, unik (idempotency key)
  "channel":    "TRANSFER_BANK" // string: TRANSFER_BANK / VA / QRIS
}

// Response 201
{
  "success": true, "message": "Cash In berhasil.",
  "data": {
    "faktur":       "CIN0000000001",
    "jenis":        "CASHIN",
    "ref_number":   "TRX-12345",
    "amount":       100000.00,
    "fee":          0.00,
    "gross_amount": 100000.00,
    "status":       "SUCCESS",
    "status_kode":  "S",
    "channel":      "TRANSFER_BANK",
    "waktu":        "2025-06-15T10:30:00+07:00"
  }
}</div>
        </div>

        <div id="tab-cashout" class="tab-content">
          <h4 style="margin-bottom:10px;color:#1e3a5f">POST /mod/dwallet/v1/api/cashout</h4>
          <div class="result-box" style="display:block">// Request Body (JSON)
{
  "amount":        500000,      // number, min 1.000
  "ref_number":    "CO-12345",  // string, unik
  "bank_code":     "BCA",       // string: BCA / BNI / BRI / MANDIRI / dll
  "no_rekening":   "1234567890",// string
  "nama_penerima": "JOHN DOE",  // string
  "keterangan":    "Penarikan" // string, opsional
}

// Fee: Rp <?= number_format(CFG_FEE_CASHOUT,0,',','.') ?> (dari setting dwallet.fee_cashout)
// gross_amount = amount + fee
// Status awal: R (Processing) → callback ubah ke S/G</div>
        </div>

        <div id="tab-transfer" class="tab-content">
          <h4 style="margin-bottom:10px;color:#1e3a5f">POST /mod/dwallet/v1/api/transfer</h4>
          <div class="result-box" style="display:block">// Request Body (JSON)
{
  "amount":        50000,      // number, min 1.000
  "kode_penerima": "A-000301", // string, KodePro customer penerima
  "ref_number":    "TF-12345", // string, unik
  "keterangan":    "Titip"    // string, opsional
}

// Fee internal: Rp 0
// Status: langsung S (Sukses)</div>
        </div>

        <div id="tab-payment" class="tab-content">
          <h4 style="margin-bottom:10px;color:#1e3a5f">POST /mod/dwallet/v1/api/payment</h4>
          <div class="result-box" style="display:block">// Request Body (JSON)
{
  "amount":       100000,         // number
  "ref_number":   "PAY-12345",    // string, unik
  "kode_produk":  "PLN50",        // string, kode produk PPOB
  "nomor_tujuan": "12345678901",  // string, nomor pelanggan/HP
  "keterangan":   "Listrik"      // string, opsional
}

// JenisTrx=12 di pulsa_penjualan
// Status awal: R (Processing) → callback ubah ke S/G</div>
        </div>

        <div id="tab-refund" class="tab-content">
          <h4 style="margin-bottom:10px;color:#1e3a5f">POST /mod/dwallet/v1/api/refund</h4>
          <div class="result-box" style="display:block">// Request Body (JSON)
{
  "faktur_asal": "PAY0000000001",    // faktur transaksi yang akan di-refund
  "alasan":      "Produk gagal"     // string, alasan refund
}

// Batasan:
// - Hanya PAYMENT dan CASHIN yang bisa di-refund
// - Status asal harus S (Sukses) atau R (Processing)
// - Hanya bisa refund milik sendiri (sesuai token)
// - Idempotent: refund untuk faktur_asal yang sama hanya diproses sekali</div>
        </div>

        <div id="tab-inquiry" class="tab-content">
          <h4 style="margin-bottom:10px;color:#1e3a5f">GET /mod/dwallet/v1/api/inquiry</h4>
          <div class="result-box" style="display:block">// Query Parameters (salah satu wajib)
GET /mod/dwallet/v1/api/inquiry?faktur=CIN0000000001
GET /mod/dwallet/v1/api/inquiry?ref_number=TRX-12345

// Response menyertakan detail transaksi + jurnal akuntansi</div>
        </div>

        <div id="tab-history" class="tab-content">
          <h4 style="margin-bottom:10px;color:#1e3a5f">GET /mod/dwallet/v1/api/history</h4>
          <div class="result-box" style="display:block">// Query Parameters (semua opsional)
GET /mod/dwallet/v1/api/history
  ?page=1           // default: 1
  &per_page=20      // default: 20, max: 100
  &jenis=CASHIN     // CASHIN|CASHOUT|TRANSFER|PAYMENT|REFUND
  &dari=2025-06-01  // tanggal mulai (YYYY-MM-DD)
  &sampai=2025-06-30 // tanggal akhir (YYYY-MM-DD)

// Response: { data: [...], total, page, per_page, total_page }</div>
        </div>

        <div id="tab-callback" class="tab-content">
          <h4 style="margin-bottom:10px;color:#1e3a5f">POST /mod/dwallet/v1/api/callback</h4>
          <div class="result-box" style="display:block">// Webhook dari payment processor. Tidak perlu Bearer token.
// Jika CFG_PP_CB_SECRET diset, request harus menyertakan:
// Header: X-Callback-Signature: &lt;HMAC-SHA256(body, secret)&gt;

// Request Body
{
  "faktur":          "CUT0000000001",
  "status":          "SUCCESS",   // SUCCESS|FAILED|COMPLETED|PAID|REJECTED
  "provider_ref":    "PP-TRX-999",
  "provider":        "DANAMON",
  "correlation_id":  "CB-unique-id"  // opsional
}</div>
        </div>

        <div id="tab-status" class="tab-content">
          <h4 style="margin-bottom:10px;color:#1e3a5f">Kode Status Transaksi</h4>
          <table class="tbl">
            <thead><tr><th>Kode</th><th>Label</th><th>Keterangan</th></tr></thead>
            <tbody>
              <tr><td><code>P</code></td><td><span class='jenis-TRANSFER'>PENDING</span></td><td>Baru dibuat, menunggu proses</td></tr>
              <tr><td><code>S</code></td><td><span class='jenis-CASHIN'>SUCCESS</span></td><td>Berhasil diproses</td></tr>
              <tr><td><code>G</code></td><td><span class='jenis-CASHOUT'>FAILED</span></td><td>Gagal</td></tr>
              <tr><td><code>D</code></td><td><span class='jenis-REFUND'>CANCELLED</span></td><td>Dibatalkan / di-refund</td></tr>
              <tr><td><code>R</code></td><td><span class='jenis-PAYMENT'>PROCESSING</span></td><td>Dalam proses (menunggu callback)</td></tr>
            </tbody>
          </table>
          <br>
          <h4 style="margin-bottom:10px;color:#1e3a5f">HTTP Status Codes</h4>
          <table class="tbl">
            <thead><tr><th>HTTP</th><th>Keterangan</th></tr></thead>
            <tbody>
              <tr><td><code>200</code></td><td>OK — data ditemukan</td></tr>
              <tr><td><code>201</code></td><td>Created — transaksi berhasil dibuat</td></tr>
              <tr><td><code>400</code></td><td>Bad Request — request tidak valid</td></tr>
              <tr><td><code>401</code></td><td>Unauthorized — token tidak valid</td></tr>
              <tr><td><code>403</code></td><td>Forbidden — tidak memiliki akses</td></tr>
              <tr><td><code>404</code></td><td>Not Found — data tidak ditemukan</td></tr>
              <tr><td><code>405</code></td><td>Method Not Allowed</td></tr>
              <tr><td><code>422</code></td><td>Unprocessable — validasi gagal</td></tr>
              <tr><td><code>429</code></td><td>Too Many Requests — rate limit</td></tr>
              <tr><td><code>500</code></td><td>Internal Server Error</td></tr>
              <tr><td><code>502</code></td><td>Bad Gateway — payment processor error</td></tr>
              <tr><td><code>503</code></td><td>Service Unavailable — DB tidak tersedia</td></tr>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>

</main>

<script>
const BASE = window.location.origin + '/mod/dwallet/v1/api';

// ── Navigation ──────────────────────────────────────────────────────────────
const titles = {
  dashboard:'📊 Dashboard', balance:'💰 Balance', cashin:'⬇️ Cash In',
  cashout:'⬆️ Cash Out', transfer:'↔️ Transfer', payment:'🛒 Payment',
  refund:'↩️ Refund', inquiry:'🔍 Inquiry', history:'📜 History',
  config:'⚙️ Konfigurasi', install:'🔧 Install Schema', apidoc:'📖 API Docs',
};
function showSection(id, el) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('sec-'+id).classList.add('active');
  document.getElementById('section-title').textContent = titles[id] || id;
  if (el) el.classList.add('active');
}
function showTab(el, id) {
  el.closest('.card-body').querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  el.closest('.card-body').querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  el.classList.add('active');
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function genRef(id) {
  document.getElementById(id).value = 'REF-' + Date.now();
}
function showResult(id, data, ok) {
  const el = document.getElementById('res-'+id);
  el.style.display = 'block';
  el.style.borderLeft = ok ? '4px solid #10b981' : '4px solid #ef4444';
  el.textContent = JSON.stringify(data, null, 2);
  el.scrollIntoView({behavior:'smooth', block:'nearest'});
}
function v(id) {
  const el = document.getElementById(id);
  return el ? el.value.trim() : '';
}

// ── API Call ─────────────────────────────────────────────────────────────────
async function apiCall(action) {
  let token='', body={}, method='POST', query='';

  switch(action) {
    case 'balance':
      token = v('b-token'); break;
    case 'cashin':
      token = v('ci-token');
      body = { amount: parseFloat(v('ci-amount')), ref_number: v('ci-ref'), channel: v('ci-channel') }; break;
    case 'cashout':
      token = v('co-token');
      body = { amount: parseFloat(v('co-amount')), ref_number: v('co-ref'),
               bank_code: v('co-bank'), no_rekening: v('co-norek'),
               nama_penerima: v('co-nama'), keterangan: v('co-ket') }; break;
    case 'transfer':
      token = v('tf-token');
      body = { amount: parseFloat(v('tf-amount')), kode_penerima: v('tf-kode'),
               ref_number: v('tf-ref'), keterangan: v('tf-ket') }; break;
    case 'payment':
      token = v('py-token');
      body = { amount: parseFloat(v('py-amount')), ref_number: v('py-ref'),
               kode_produk: v('py-produk'), nomor_tujuan: v('py-tujuan'),
               keterangan: v('py-ket') }; break;
    case 'refund':
      token = v('rf-token');
      body = { faktur_asal: v('rf-faktur'), alasan: v('rf-alasan') }; break;
    case 'inquiry':
      token = v('iq-token'); method = 'GET';
      const fk = v('iq-faktur'), rf = v('iq-ref');
      query = fk ? '?faktur='+encodeURIComponent(fk) : '?ref_number='+encodeURIComponent(rf); break;
    case 'history':
      token = v('hs-token'); method = 'GET';
      const p = new URLSearchParams();
      if (v('hs-page'))   p.set('page',   v('hs-page'));
      if (v('hs-pp'))     p.set('per_page',v('hs-pp'));
      if (v('hs-jenis'))  p.set('jenis',  v('hs-jenis'));
      if (v('hs-dari'))   p.set('dari',   v('hs-dari'));
      if (v('hs-sampai')) p.set('sampai', v('hs-sampai'));
      query = '?' + p.toString(); break;
    case 'health':
      method = 'GET'; break;
  }

  if (!token && action !== 'health') {
    showResult(action === 'health' ? 'health' : action, {error:'Bearer Token wajib diisi'}, false);
    return;
  }

  const url = BASE + '/' + action + (query||'');
  const opts = {
    method,
    headers: { 'Content-Type':'application/json', ...(token?{'Authorization':'Bearer '+token}:{}) },
    ...(method==='POST' ? {body: JSON.stringify(body)} : {}),
  };

  const resId = action;
  const el = document.getElementById('res-'+resId);
  if (el) { el.style.display='block'; el.textContent = '⏳ Loading...'; }

  try {
    const resp = await fetch(url, opts);
    const data = await resp.json().catch(() => ({raw: await resp.text()}));
    showResult(resId, data, resp.ok);
  } catch(e) {
    showResult(resId, {error: e.message, hint:'Pastikan URL benar: '+url}, false);
  }
}

// ── Install ───────────────────────────────────────────────────────────────────
async function runInstall() {
  if (!confirm('⚠️ Yakin ingin menjalankan install schema? Pastikan database terhubung.')) return;
  const pass = prompt('Masukkan password admin untuk konfirmasi:');
  if (!pass) return;
  const url = BASE + '/install?secret=' + encodeURIComponent(pass);
  document.getElementById('res-install').style.display = 'block';
  document.getElementById('res-install').textContent = '⏳ Installing...';
  try {
    const r = await fetch(url);
    const d = await r.json();
    showResult('install', d, r.ok);
  } catch(e) {
    showResult('install', {error: e.message}, false);
  }
}
</script>
</body>
</html>
HTML;
    exit;
}

// ============================================================================
// MAIN DISPATCH
// ============================================================================
//
// Mode A — Framework (Assistindo MVC via DWalletApiController):
//   define('DWALLET_ACTION', 'cashin');
//   require_once '/path/to/dwallet_service.php';
//
// Mode B — Standalone (URL routing):
//   http://assist.gw.sis1.net/mod/dwallet/v1/api/cashin
//   http://assist.gw.sis1.net/mod/dwallet/v1/api/ui   ← Web UI
// ============================================================================

/**
 * Ekstrak action dari REQUEST_URI.
 * '/mod/dwallet/v1/api/cashin' → 'cashin'
 */
function _dwalletExtractAction(string $path): string
{
    $path = preg_replace('#^/?mod/dwallet/v[0-9.]+/api/?#', '', ltrim($path, '/'));
    return strtolower(trim(explode('/', explode('?', $path)[0])[0]));
}

$_dwalletActionMap = [
    'balance'  => ['handleBalance',  ['POST']],
    'cashin'   => ['handleCashIn',   ['POST']],
    'cashout'  => ['handleCashOut',  ['POST']],
    'transfer' => ['handleTransfer', ['POST']],
    'payment'  => ['handlePayment',  ['POST']],
    'refund'   => ['handleRefund',   ['POST']],
    'inquiry'  => ['handleInquiry',  ['GET', 'POST']],
    'history'  => ['handleHistory',  ['GET', 'POST']],
    'callback' => ['handleCallback', ['POST']],
    'health'   => ['handleHealth',   ['GET', 'POST']],
    'install'  => ['handleInstall',  ['GET']],
    'ui'       => ['handleUI',       ['GET', 'POST']],
];

// ── Deteksi action ──────────────────────────────────────────────────────────
if (defined('DWALLET_ACTION') && DWALLET_ACTION !== '') {
    $_dwalletAction = strtolower(trim(DWALLET_ACTION));
// getenv intentionally removed — no env var support in production
} elseif (false) {
    $_dwalletAction = '';  // dead branch — env var support disabled
} else {
    $_dwalletAction = _dwalletExtractAction(Req::path());
}

$_dwalletMethod = Req::method();

// ── Untuk Web UI: set header HTML sebelum Basic Auth check ─────────────────
if ($_dwalletAction === 'ui') {
    handleUI();
}

// ── API endpoints: set JSON header ─────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-DWallet-Version: ' . DWALLET_VERSION);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Request-ID, X-Callback-Signature');

if ($_dwalletMethod === 'OPTIONS') {
    http_response_code(204); exit;
}

// ── Dispatch ────────────────────────────────────────────────────────────────
if (isset($_dwalletActionMap[$_dwalletAction])) {
    [$_fn, $_allowed] = $_dwalletActionMap[$_dwalletAction];

    if (!in_array($_dwalletMethod, $_allowed, true)) {
        // Toleransi method mismatch satu-arah di mode framework
        if (!(count($_allowed)===1 && (
            ($_allowed[0]==='GET'&&$_dwalletMethod==='POST') ||
            ($_allowed[0]==='POST'&&$_dwalletMethod==='GET')
        ))) {
            header('Allow: '.implode(', ', $_allowed));
            Res::err('Method '.$_dwalletMethod.' tidak diizinkan.', 405);
        }
    }

    try {
        $_fn([]);
    } catch (PDOException $e) {
        Log::error('DBError: '.$e->getMessage());
        if (DB::inTx()) DB::rollback();
        Res::err('Kesalahan database. Hubungi administrator.', 500);
    } catch (RuntimeException $e) {
        if (DB::inTx()) DB::rollback();
        Res::err($e->getMessage(), 422);
    } catch (Throwable $e) {
        Log::error('Unhandled: '.$e->getMessage(),['file'=>basename($e->getFile()),'line'=>$e->getLine()]);
        if (DB::inTx()) DB::rollback();
        Res::err('Kesalahan internal. Coba beberapa saat lagi.', 500);
    }
} else {
    Res::err('Endpoint tidak ditemukan.', 404);
}
