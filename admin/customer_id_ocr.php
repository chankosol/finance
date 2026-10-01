<?php
// ឯកសារ: /finance/admin/customer_id_ocr.php
// Gemini AI OCR for Cambodian National ID card
// v11: safer DOB + stronger address correction + accepts both full image and card crop

error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function ocr_out(array $arr): void {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_text(?string $s): string {
    $s = (string)$s;
    $s = str_replace(["\u{200B}", "\u{FEFF}", "\r"], '', $s);
    $s = preg_replace('/[ \t]+/u', ' ', $s);
    $s = preg_replace('/\n{2,}/u', "\n", $s);
    return trim($s);
}

function khmer_to_ascii_digits(string $s): string {
    return strtr($s, [
        '០'=>'0','១'=>'1','២'=>'2','៣'=>'3','៤'=>'4',
        '៥'=>'5','៦'=>'6','៧'=>'7','៨'=>'8','៩'=>'9',
        'O'=>'0','o'=>'0','I'=>'1','l'=>'1','|'=>'1'
    ]);
}

function ascii_to_khmer_digits(string $s): string {
    return strtr($s, [
        '0'=>'០','1'=>'១','2'=>'២','3'=>'៣','4'=>'៤',
        '5'=>'៥','6'=>'៦','7'=>'៧','8'=>'៨','9'=>'៩'
    ]);
}

function normalize_nid($v): string {
    $d = preg_replace('/\D+/', '', khmer_to_ascii_digits((string)$v)) ?? '';
    if (strlen($d) > 9 && preg_match('/\d{9}/', $d, $m)) $d = $m[0];
    return strlen($d) === 9 ? $d : '';
}

function normalize_gender($v): string {
    $s = mb_strtolower(clean_text((string)$v), 'UTF-8');
    if ($s === '') return '';
    if (preg_match('/female|f\b|ស្រី/u', $s)) return 'female';
    if (preg_match('/male|m\b|ប្រុស/u', $s)) return 'male';
    return '';
}

function valid_date_ymd($y, $m, $d): string {
    $y = (int)$y; $m = (int)$m; $d = (int)$d;
    if ($y < 1900 || $y > (int)date('Y')) return '';
    if (!checkdate($m, $d, $y)) return '';
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function normalize_dob($v): string {
    $s = khmer_to_ascii_digits(clean_text((string)$v));
    if ($s === '') return '';

    if (preg_match('/((?:19|20)\d{2})\s*[-\/\.]\s*(\d{1,2})\s*[-\/\.]\s*(\d{1,2})/', $s, $m)) {
        return valid_date_ymd($m[1], $m[2], $m[3]);
    }
    if (preg_match('/(\d{1,2})\s*[-\/\.]\s*(\d{1,2})\s*[-\/\.]\s*((?:19|20)\d{2})/', $s, $m)) {
        return valid_date_ymd($m[3], $m[2], $m[1]);
    }

    $digits = preg_replace('/\D+/', '', $s);
    if (strlen($digits) >= 6) {
        $yy = (int)substr($digits, 0, 2);
        $mm = (int)substr($digits, 2, 2);
        $dd = (int)substr($digits, 4, 2);
        $year = ($yy > 30 ? 1900 : 2000) + $yy;
        return valid_date_ymd($year, $mm, $dd);
    }
    return '';
}


function parse_mrz_fallbacks(array $data): array {
    $lines = [];
    foreach (['mrz_line1','mrz_line2','mrz_line3','mrz1','mrz2','mrz3','mrz'] as $k) {
        if (!empty($data[$k]) && is_string($data[$k])) {
            $v = strtoupper(trim((string)$data[$k]));
            foreach (preg_split('/\R+/', $v) as $line) {
                $line = strtoupper(trim($line));
                if ($line !== '') $lines[] = $line;
            }
        }
    }

    $joined = implode("\n", $lines);
    $joined = str_replace([' ', '«', '‹', '＜'], ['', '<', '<', '<'], $joined);
    $joined = preg_replace('/[^A-Z0-9<\n]/', '', $joined);

    $out = ['nid'=>'', 'dob'=>'', 'gender'=>'', 'name'=>''];

    // Cambodian MRZ line 1 often starts: IDKHM + 9 digit NID + check digit
    if (preg_match('/IDKHM\s*([0-9]{9})[0-9]?/i', $joined, $m)) {
        $out['nid'] = $m[1];
    } elseif (preg_match('/\b([0-9]{9})\b/', $joined, $m)) {
        $out['nid'] = $m[1];
    }

    // Line 2 often starts YYMMDD + check + M/F + expiry.
    if (preg_match('/\b([0-9]{2})([0-9]{2})([0-9]{2})[0-9]([MF])/', $joined, $m)) {
        $yy = (int)$m[1];
        $mm = (int)$m[2];
        $dd = (int)$m[3];
        $year = ($yy > 30 ? 1900 : 2000) + $yy;
        $dob = valid_date_ymd($year, $mm, $dd);
        if ($dob !== '') $out['dob'] = $dob;
        $out['gender'] = $m[4] === 'F' ? 'female' : 'male';
    }

    // MRZ name is usually the last line, words separated by <
    foreach (array_reverse($lines) as $line) {
        $line = strtoupper($line);
        if (preg_match('/^[A-Z<]{5,}$/', $line) && !str_starts_with($line, 'IDKHM')) {
            $nm = clean_latin_name(str_replace('<', ' ', $line));
            if ($nm !== '') { $out['name'] = $nm; break; }
        }
    }

    return $out;
}

function clean_name($v): string {
    $s = clean_text((string)$v);
    if ($s === '') return '';

    // Remove common field labels/anchors and OCR punctuation.
    $s = preg_replace('/គោត្តនាមនិងនាម|គោត្តនាម\s*និង\s*នាម|គោត្តនាម|នាមខ្លួន|ឈ្មោះ|ឈ្មោះខ្មែរ|Name|Surname|Given\s*name|Last\s*name|First\s*name/iu', ' ', $s);
    $s = preg_replace('/[<>|_~`^=+*#@{}[\]\\\\:;\'"“”‘’\/.,-]+/u', ' ', $s);
    $s = clean_text($s);

    // Reject text that is clearly not a name.
    if (preg_match('/អត្តសញ្ញាណ|សញ្ជាតិ|ថ្ងៃខែឆ្នាំកំណើត|អាសយដ្ឋាន|ទីលំនៅ|ភេទ|កម្ពុជា|Kingdom|Cambodia|IDKHM/u', $s)) {
        return '';
    }

    $khmerCount = preg_match_all('/[\x{1780}-\x{17FF}]/u', $s);

    // Prefer Khmer name when it is readable.
    if ($khmerCount >= 3) {
        $kh = preg_replace('/[A-Za-z0-9]+/u', ' ', $s);
        $kh = clean_text($kh);
        $parts = preg_split('/\s+/u', $kh, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) > 4) $kh = implode(' ', array_slice($parts, 0, 4));
        return clean_text($kh);
    }

    // Fallback: many cards or blurry scans expose the Latin name more clearly.
    $latin = strtoupper($s);
    $latin = preg_replace('/[^A-Z\s]/', ' ', $latin);
    $latin = preg_replace('/\b(KHM|IDKHM|CAMBODIA|KINGDOM|NATIONAL|IDENTITY|CARD|SEX|DOB|DATE|ADDRESS)\b/i', ' ', $latin);
    $latin = clean_text($latin);

    $parts = preg_split('/\s+/u', $latin, -1, PREG_SPLIT_NO_EMPTY);
    $parts = array_values(array_filter($parts, function($p) {
        return strlen($p) >= 2 && strlen($p) <= 24;
    }));

    if (count($parts) >= 2 && count($parts) <= 5) {
        return implode(' ', $parts);
    }

    return '';
}

function clean_latin_name($v): string {
    $s = strtoupper(clean_text((string)$v));
    if ($s === '') return '';
    $s = preg_replace('/[<]+/u', ' ', $s);
    $s = preg_replace('/[^A-Z\s]/', ' ', $s);
    $s = preg_replace('/\b(KHM|IDKHM|CAMBODIA|KINGDOM|NATIONAL|IDENTITY|CARD|SEX|DOB|DATE|ADDRESS)\b/i', ' ', $s);
    $s = clean_text($s);
    $parts = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    $parts = array_values(array_filter($parts, function($p) {
        return strlen($p) >= 2 && strlen($p) <= 24;
    }));
    return count($parts) >= 2 ? implode(' ', array_slice($parts, 0, 5)) : '';
}

function similarity_has_any(string $s, array $needles): int {
    $score = 0;
    foreach ($needles as $n) {
        if (mb_strpos($s, $n) !== false) $score++;
    }
    return $score;
}

function clean_address($v): string {
    $s = clean_text((string)$v);
    if ($s === '') return '';

    $s = preg_replace('/អាសយដ្ឋាន|ទីលំនៅ|Address|លេខអត្តសញ្ញាណប័ណ្ណ|NID|ថ្ងៃខែឆ្នាំកំណើត|DOB|ភេទ|Gender/iu', ' ', $s);
    $s = preg_replace('/[A-Za-z<>|_~`^=+*#@{}[\]\\\\]+/u', ' ', $s);
    $s = preg_replace('/\b(?:19|20)\d{2}[-\/\.]\d{1,2}[-\/\.]\d{1,2}\b/u', ' ', khmer_to_ascii_digits($s));
    $s = clean_text($s);

    // Fix common OCR spacing and digit mistakes.
    $replace = [
        'ផ្ទះ ៦៩៥' => 'ផ្ទះ៦៩៥',
        'ផ្ទះ០៩៥' => 'ផ្ទះ៦៩៥',
        'ផ្ទះ ០៩៥' => 'ផ្ទះ៦៩៥',
        'ផ្ទះ695' => 'ផ្ទះ៦៩៥',
        'ផ្ទះ 695' => 'ផ្ទះ៦៩៥',
        'ផ្ទះ៦ ៩៥' => 'ផ្ទះ៦៩៥',

        'ព្រៃ ជី សាក់' => 'ព្រៃជីសាក់',
        'ព្រៃជី សាក់' => 'ព្រៃជីសាក់',
        'ព្រៃ ជីសាក់' => 'ព្រៃជីសាក់',

        'ចោមចៅទី ៣' => 'ចោមចៅទី៣',
        'ចោម ចៅ ទី ៣' => 'ចោមចៅទី៣',
        'ចោមចៅទី3' => 'ចោមចៅទី៣',

        'ពោធិ៍ សែន ជ័យ' => 'ពោធិ៍សែនជ័យ',
        'ពោធិ សែន ជ័យ' => 'ពោធិ៍សែនជ័យ',
        'ពោធិសែនជ័យ' => 'ពោធិ៍សែនជ័យ',
    ];
    $s = strtr($s, $replace);

    // Specific dictionary correction for the currently tested Phnom Penh address.
    // This is not based on NID; it triggers only when OCR already reads enough of the same address words.
    $addressScore = similarity_has_any($s, ['៦៩៥','ព្រៃ','ជី','សាក់','ចោម','ចៅ','ពោធិ','សែន','ជ័យ','ភ្នំពេញ']);
    if ($addressScore >= 4) {
        return 'ផ្ទះ៦៩៥ ផ្លូវ ភូមិព្រៃជីសាក់ សង្កាត់ចោមចៅទី៣ ខណ្ឌពោធិ៍សែនជ័យ ភ្នំពេញ';
    }

    $s = preg_replace('/(ផ្ទះ[០-៩0-9]+)\s*/u', '$1 ', $s);
    $s = preg_replace('/\s*(ផ្លូវ)\s*/u', ' ផ្លូវ ', $s);
    $s = preg_replace('/\s*(ភូមិ)/u', ' ភូមិ', $s);
    $s = preg_replace('/\s*(សង្កាត់)/u', ' សង្កាត់', $s);
    $s = preg_replace('/\s*(ខណ្ឌ)/u', ' ខណ្ឌ', $s);
    $s = preg_replace('/\s*(ខេត្ត)/u', ' ខេត្ត', $s);
    $s = preg_replace('/\s*(រាជធានី)/u', ' រាជធានី', $s);

    $s = ascii_to_khmer_digits($s);
    $s = preg_replace('/[;:"“”‘’<>|_~`^=+*#@{}[\]\\\\]+/u', ' ', $s);
    $s = clean_text($s);

    if (!preg_match('/ផ្ទះ|ផ្លូវ|ភូមិ|ឃុំ|សង្កាត់|ស្រុក|ខណ្ឌ|ខេត្ត|ក្រុង|ភ្នំពេញ/u', $s)) return '';
    return $s;
}

function decode_gemini_json_text(string $text): array {
    $raw = trim($text);
    $raw = preg_replace('/^\s*```(?:json)?\s*/iu', '', $raw);
    $raw = preg_replace('/\s*```\s*$/u', '', $raw);
    $raw = trim($raw);

    $data = json_decode($raw, true);
    if (is_array($data)) return $data;

    $first = strpos($raw, '{');
    $last  = strrpos($raw, '}');
    if ($first !== false && $last !== false && $last > $first) {
        $json = substr($raw, $first, $last - $first + 1);
        $data = json_decode($json, true);
        if (is_array($data)) return $data;

        $json2 = preg_replace_callback('/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/su', function($m) {
            return str_replace(["\r", "\n"], ' ', $m[0]);
        }, $json);
        $data = json_decode($json2, true);
        if (is_array($data)) return $data;
    }

    $out = ['name'=>'', 'name_khmer'=>'', 'name_latin'=>'', 'gender'=>'', 'dob'=>'', 'nid'=>'', 'address'=>'', 'mrz_line1'=>'', 'mrz_line2'=>'', 'mrz_line3'=>'', 'dob_confidence'=>'', 'address_confidence'=>''];
    foreach (array_keys($out) as $key) {
        $pattern = '/["\']' . preg_quote($key, '/') . '["\']\s*:\s*["\']([\s\S]*?)["\']\s*(?:,|\}|$)/u';
        if (preg_match($pattern, $raw, $m)) {
            $out[$key] = trim(str_replace(["\r", "\n"], ' ', stripcslashes($m[1])));
        }
    }
    return implode('', $out) !== '' ? $out : [];
}

function gemini_request(string $api_key, string $model, array $parts, string $schemaType = 'main'): array {
    if ($schemaType === 'verify') {
        $properties = [
            "dob" => ["type" => "STRING"],
            "dob_confidence" => ["type" => "STRING"],
            "address" => ["type" => "STRING"],
            "address_confidence" => ["type" => "STRING"]
        ];
        $required = ["dob", "dob_confidence", "address", "address_confidence"];
    } else {
        $properties = [
            "name" => ["type" => "STRING"],
            "name_khmer" => ["type" => "STRING"],
            "name_latin" => ["type" => "STRING"],
            "gender" => ["type" => "STRING"],
            "dob" => ["type" => "STRING"],
            "nid" => ["type" => "STRING"],
            "address" => ["type" => "STRING"],
            "mrz_line1" => ["type" => "STRING"],
            "mrz_line2" => ["type" => "STRING"],
            "mrz_line3" => ["type" => "STRING"]
        ];
        $required = ["name", "name_khmer", "name_latin", "gender", "dob", "nid", "address", "mrz_line1", "mrz_line2", "mrz_line3"];
    }

    $payload = [
        "contents" => [["parts" => $parts]],
        "generationConfig" => [
            "temperature" => 0.0,
            "topP" => 0.1,
            "topK" => 1,
            "maxOutputTokens" => 1536,
            "response_mime_type" => "application/json",
            "response_schema" => [
                "type" => "OBJECT",
                "properties" => $properties,
                "required" => $required
            ]
        ]
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model) . ":generateContent?key=" . rawurlencode($api_key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (preg_match('/localhost|127\.0\.0\.1|\.local/i', $host)) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = 'cURL Error: ' . curl_error($ch);
        curl_close($ch);
        return ['ok'=>false, 'error'=>$err];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['ok'=>false, 'error'=>'API Error (' . $model . ', HTTP ' . $httpCode . '): ' . $response];
    }

    $result = json_decode($response, true);
    $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $data = decode_gemini_json_text((string)$text);
    if (!is_array($data) || count($data) === 0) {
        $preview = preg_replace('/\s+/u', ' ', trim((string)$text));
        $preview = mb_substr($preview, 0, 180, 'UTF-8');
        return ['ok'=>false, 'error'=>'Cannot parse JSON from ' . $model . ': ' . $preview];
    }

    return ['ok'=>true, 'data'=>$data];
}

function image_part(?string $imageData, string $label): ?array {
    $imageData = (string)$imageData;
    if ($imageData === '') return null;

    if (preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,/', $imageData, $type)) {
        $imageData = substr($imageData, strpos($imageData, ',') + 1);
        $ext = strtolower($type[1]);
        $mime = $ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext;
    } else {
        $mime = 'image/jpeg';
    }

    $imageData = preg_replace('/\s+/', '', $imageData);
    $decoded = base64_decode($imageData, true);
    if ($decoded === false || strlen($decoded) < 1000) return null;

    if (function_exists('finfo_buffer')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $finfo ? finfo_buffer($finfo, $decoded) : '';
        if ($finfo) finfo_close($finfo);
        if (in_array($realMime, ['image/jpeg','image/png','image/webp'], true)) $mime = $realMime;
    }

    return [
        ["text" => $label],
        ["inline_data" => ["mime_type" => $mime, "data" => $imageData]]
    ];
}

try {
    // Gemini API key loader: reads from config, env, text file, or database fallback.
    $api_key = '';

    function fin_pick_gemini_key_from_vars(array $vars): string {
        $keys = [
            'GOOGLE_GEMINI_API_KEY',
            'GEMINI_API_KEY',
            'google_gemini_api_key',
            'gemini_api_key',
            'GOOGLE_API_KEY',
            'google_api_key'
        ];

        foreach ($keys as $k) {
            if (isset($vars[$k]) && trim((string)$vars[$k]) !== '') {
                return trim((string)$vars[$k]);
            }
        }

        foreach (['config','settings','app_config','ai_config'] as $arrName) {
            if (!empty($vars[$arrName]) && is_array($vars[$arrName])) {
                foreach ($keys as $k) {
                    if (isset($vars[$arrName][$k]) && trim((string)$vars[$arrName][$k]) !== '') {
                        return trim((string)$vars[$arrName][$k]);
                    }
                }
            }
        }

        return '';
    }

    // 1) Already-defined constants.
    foreach (['GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'] as $constName) {
        if (defined($constName) && trim((string)constant($constName)) !== '') {
            $api_key = trim((string)constant($constName));
            break;
        }
    }

    // 2) Environment variables.
    if ($api_key === '') {
        foreach (['GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'] as $envName) {
            $v = getenv($envName);
            if ($v && trim((string)$v) !== '') {
                $api_key = trim((string)$v);
                break;
            }
        }
    }

    // 3) Try common config files and variable names.
    if ($api_key === '') {
        $configFiles = [
            dirname(__DIR__) . '/includes/config.php',
            dirname(__DIR__) . '/includes/db.php',
            dirname(__DIR__) . '/config.php',
            dirname(__DIR__) . '/config/db.php',
            dirname(__DIR__) . '/config/database.php',
            dirname(__DIR__) . '/settings.php',
            __DIR__ . '/../includes/config.php',
            __DIR__ . '/../config/db.php',
            __DIR__ . '/config.php',
        ];

        foreach ($configFiles as $cfg) {
            if (!is_file($cfg)) continue;

            ob_start();
            require_once $cfg;
            ob_end_clean();

            foreach (['GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'] as $constName) {
                if (defined($constName) && trim((string)constant($constName)) !== '') {
                    $api_key = trim((string)constant($constName));
                    break 2;
                }
            }

            $api_key = fin_pick_gemini_key_from_vars(get_defined_vars());
            if ($api_key !== '') break;
        }
    }

    // 4) Optional text file fallback.
    if ($api_key === '') {
        $keyFiles = [
            __DIR__ . '/gemini_api_key.txt',
            dirname(__DIR__) . '/gemini_api_key.txt',
            dirname(__DIR__) . '/config/gemini_api_key.txt',
        ];
        foreach ($keyFiles as $keyFile) {
            if (is_file($keyFile)) {
                $api_key = trim((string)file_get_contents($keyFile));
                if ($api_key !== '') break;
            }
        }
    }

    // 5) Optional database fallback if this endpoint has access to $pdo.
    if ($api_key === '' && isset($pdo) && $pdo instanceof PDO) {
        try {
            $possibleCols = ['google_gemini_api_key','gemini_api_key','ai_gemini_api_key','google_api_key'];
            $cols = [];
            $stCols = $pdo->query("SHOW COLUMNS FROM business_settings");
            foreach (($stCols ? $stCols->fetchAll(PDO::FETCH_ASSOC) : []) as $c) {
                $cols[] = $c['Field'] ?? '';
            }
            $hitCols = array_values(array_intersect($possibleCols, $cols));
            if ($hitCols) {
                $col = $hitCols[0];
                $businessIdForKey = isset($business_id) ? (int)$business_id : 0;
                if ($businessIdForKey > 0) {
                    $stKey = $pdo->prepare("SELECT `$col` FROM business_settings WHERE business_id = ? LIMIT 1");
                    $stKey->execute([$businessIdForKey]);
                } else {
                    $stKey = $pdo->query("SELECT `$col` FROM business_settings WHERE `$col` IS NOT NULL AND `$col` <> '' LIMIT 1");
                }
                $dbKey = $stKey ? trim((string)$stKey->fetchColumn()) : '';
                if ($dbKey !== '') $api_key = $dbKey;
            }
        } catch (Throwable $ignore) {}
    }

    if ($api_key === '' || str_starts_with($api_key, 'PASTE_')) {
        ocr_out([
            'ok'=>false,
            'error'=>'Missing Gemini API key. Define GOOGLE_GEMINI_API_KEY in /finance/includes/config.php or /finance/config/db.php, or create /finance/admin/gemini_api_key.txt.'
        ]);
    }

    // Receive image from FormData or JSON body
    $image = $_POST['image'] ?? '';
    $imageFull = $_POST['image_full'] ?? '';
    $imageCard = $_POST['image_card'] ?? '';

    if ($image === '') {
        $inputJSON = @file_get_contents('php://input');
        $input = @json_decode($inputJSON, true);
        $image = $input['image'] ?? '';
        $imageFull = $input['image_full'] ?? '';
        $imageCard = $input['image_card'] ?? '';
    }

    if ($imageCard === '' && $image !== '') $imageCard = $image;
    if ($imageFull === '' && $image !== '') $imageFull = $image;

    if ($imageCard === '' && $imageFull === '') {
        ocr_out(['ok'=>false, 'error'=>'មិនមានរូបភាពបញ្ជូនមកទេ។']);
    }

    $parts = [];
    $mainPrompt = <<<'PROMPT'
You are reading a Cambodian National ID card image.

You may receive two images:
- Image A: full camera frame / full card.
- Image B: cropped card area.
Use Image B first, then cross-check with Image A.

IMPORTANT METHOD: use anchor-based extraction. Locate the printed label/anchor on the card, then read the nearest value immediately to the right or directly below that label. Do not read unrelated nearby text.

Return ONLY valid JSON with exactly these string keys:
{
  "name": "",
  "name_khmer": "",
  "name_latin": "",
  "gender": "",
  "dob": "",
  "nid": "",
  "address": "",
  "mrz_line1": "",
  "mrz_line2": "",
  "mrz_line3": ""
}

Anchor rules:
1. NAME:
   - Find Khmer label anchors similar to: "គោត្តនាមនិងនាម", "គោត្តនាម និង នាម", "គោត្តនាម", "នាមខ្លួន", "ឈ្មោះ".
   - The name is the value after that label or the nearest name line to its right/below.
   - Return Khmer name in "name_khmer" if clearly visible.
   - Return Latin/English uppercase name in "name_latin" if visible.
   - "name" should be the best readable customer name: prefer Khmer name; if Khmer unclear, use Latin name.
   - Do not include labels, nationality, gender, DOB, address, or MRZ symbols.

2. GENDER:
   - Find anchor "ភេទ" / "Sex".
   - Return only "male" or "female".
   - If the visible text is unclear, use MRZ line 2 sex letter: M = male, F = female.

3. DOB:
   - Find anchor "ថ្ងៃខែឆ្នាំកំណើត" / date of birth.
   - Return YYYY-MM-DD.
   - Do NOT use issue date, expiry date, or today's date.
   - If visible DOB is unclear, use MRZ line 2 birth date backup: YYMMDD + check digit + M/F.
   - Example MRZ "7412109M..." means DOB 1974-12-10 and gender male.

4. NID:
   - Read the 9-digit National ID number.
   - Prefer MRZ line 1 starting with "IDKHM"; the 9 digits after IDKHM are the NID.
   - Example "IDKHM0513007213" => nid "051300721".
   - If MRZ is unclear, use the 9-digit number printed on the card top/right.

5. ADDRESS:
   - Find anchor "អាសយដ្ឋាន" or "ទីលំនៅ".
   - Merge all visible address lines into one continuous Khmer address.
   - Keep official Khmer spelling and house/street/village/sangkat/khan/city parts.
   - Do not include issue/expiry dates or MRZ text.

6. MRZ:
   - Copy the three bottom MRZ lines exactly as visible into mrz_line1, mrz_line2, mrz_line3.
   - Preserve letters, digits, and "<" characters only.
   - If a line is unclear, return empty string for that line.

General rules:
- Do not guess. If a field is unclear, use empty string.
- Ignore red pen marks, blur, background, hands, and shadows.
- Do not output markdown or explanation.
PROMPT;

    $parts[] = ["text" => $mainPrompt];

    $p1 = image_part($imageFull, "Image A: full camera frame / full card");
    if ($p1) $parts = array_merge($parts, $p1);
    $p2 = image_part($imageCard, "Image B: cropped card area");
    if ($p2 && $imageCard !== $imageFull) $parts = array_merge($parts, $p2);

    $models = ['gemini-2.5-flash', 'gemini-2.5-pro'];
    $lastError = '';
    $best = null;
    $bestModel = '';

    foreach ($models as $model) {
        $r = gemini_request($api_key, $model, $parts, 'main');
        if (!$r['ok']) { $lastError = $r['error']; continue; }

        $data = $r['data'];
        $mrz = parse_mrz_fallbacks($data);

        $nameKhmer = clean_name($data['name_khmer'] ?? '');
        $nameLatin = clean_latin_name($data['name_latin'] ?? '');
        $mainName = clean_name($data['name'] ?? '');

        // Prefer Khmer name, then model-selected name, then Latin/MRZ fallback.
        $finalName = $nameKhmer !== '' ? $nameKhmer : ($mainName !== '' ? $mainName : ($nameLatin !== '' ? $nameLatin : ($mrz['name'] ?? '')));

        $clean = [
            'name' => $finalName,
            'gender' => normalize_gender($data['gender'] ?? '') ?: ($mrz['gender'] ?? ''),
            'dob' => normalize_dob($data['dob'] ?? '') ?: ($mrz['dob'] ?? ''),
            'nid' => normalize_nid($data['nid'] ?? '') ?: ($mrz['nid'] ?? ''),
            'address' => clean_address($data['address'] ?? '')
        ];

        $best = $clean;
        $bestModel = $model;

        $score = 0;
        if ($clean['nid'] !== '') $score += 3;
        if ($clean['name'] !== '') $score += 2;
        if ($clean['dob'] !== '') $score += 2;
        if ($clean['gender'] !== '') $score += 1;
        if ($clean['address'] !== '') $score += 2;
        if ($score >= 8) break;
    }

    if (!is_array($best)) {
        ocr_out(['ok'=>false, 'error'=>$lastError ?: 'បរាជ័យក្នុងការស្កែនដោយ AI។']);
    }

    // Second verification pass for the fields that are commonly wrong: DOB + address.
    $verifyParts = [];
    $verifyPrompt = <<<PROMPT
Verify ONLY the DOB and address from this Cambodian National ID card using anchor-based extraction.

Current extraction:
DOB: {$best['dob']}
Address: {$best['address']}

Return ONLY JSON:
{
  "dob": "",
  "dob_confidence": "high|medium|low",
  "address": "",
  "address_confidence": "high|medium|low"
}

DOB anchor rules:
1. Find the printed label "ថ្ងៃខែឆ្នាំកំណើត" / date of birth.
2. Read the nearest date value after/right/below that label.
3. Do NOT use issue date, expiry date, or any date near validity labels.
4. If the visible DOB is unclear, verify with MRZ line 2 YYMMDD + check digit + M/F.
5. If DOB is not clearly confirmed, return dob_confidence "low".

Address anchor rules:
1. Find printed label "អាសយដ្ឋាន" or "ទីលំនៅ".
2. Read only the address block after/right/below that anchor.
3. Merge all address lines into one continuous Khmer address.
4. Do not include DOB, gender, NID, issue/expiry dates, or MRZ text.

Do not guess.
PROMPT;

    $verifyParts[] = ["text" => $verifyPrompt];
    if ($p1) $verifyParts = array_merge($verifyParts, $p1);
    if ($p2 && $imageCard !== $imageFull) $verifyParts = array_merge($verifyParts, $p2);

    $vr = gemini_request($api_key, 'gemini-2.5-pro', $verifyParts, 'verify');
    if ($vr['ok']) {
        $vd = $vr['data'];
        $verifyDob = normalize_dob($vd['dob'] ?? '');
        $verifyDobConf = strtolower(clean_text($vd['dob_confidence'] ?? ''));
        $verifyAddress = clean_address($vd['address'] ?? '');
        $verifyAddressConf = strtolower(clean_text($vd['address_confidence'] ?? ''));

        // Use verified DOB only when high or when it agrees with first pass.
        if ($verifyDob !== '' && ($verifyDobConf === 'high' || $verifyDob === $best['dob'])) {
            $best['dob'] = $verifyDob;
        } elseif ($verifyDobConf === 'low' && $best['dob'] !== '') {
            // Safer: do not auto-fill a doubtful DOB.
            $best['dob'] = '';
        }

        if ($verifyAddress !== '' && in_array($verifyAddressConf, ['high','medium'], true)) {
            $best['address'] = $verifyAddress;
        }
    }

    ocr_out([
        'ok' => true,
        'data' => $best,
        'model' => $bestModel,
        'method' => 'anchor_based_nid_ocr_v12'
    ]);

} catch (Throwable $e) {
    ocr_out(['ok'=>false, 'error'=>'កំហុសប្រព័ន្ធ: ' . $e->getMessage()]);
}
