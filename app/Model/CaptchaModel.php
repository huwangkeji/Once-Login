<?php
/**
 * 图形验证码模型（人机验证）
 * 支持样式：
 *   slide   - 滑动拼图（拖动滑块与缺口对齐）
 *   digits  - 纯数字 4 位
 *   mixed   - 数字 + 字母组合 4 位
 *   click   - 文字点选（按顺序点击图中文字）
 *   icon    - 图标选择（点选图中所有指定图标）
 *   passive - 无感验证（采集行为数据评分，低分自动回退到 captcha_fallback 样式）
 * token 落库(captcha_tokens)，单次有效，5 分钟过期，最多尝试 5 次
 *
 * 兼容性说明：
 *   slide/digits/mixed 由 GD 渲染为 PNG data-uri；
 *   click/icon 由服务端生成 SVG data-uri（文字与图形均由客户端字体/渲染器绘制，
 *   不依赖服务器字体，IE9+/全部现代浏览器均可显示）；
 *   passive 不下发任何图片，仅依赖前端行为采集（Pointer Events，降级 mouse/touch）。
 */
class CaptchaModel
{
    const TTL       = 300;   // 有效期 5 分钟
    const MAX_TRY   = 5;     // 最大尝试次数
    const SLIDE_TOL = 10;    // 滑动容差(px)（L3：14px 过宽，收紧防盲猜命中）
    const BG_W      = 300;   // 滑动背景宽
    const BG_H      = 150;   // 滑动背景高
    const HOLE_SIZE = 50;    // 缺口/滑块边长

    /* 无感验证行为评分阈值 */
    const PASSIVE_MIN_MS    = 600;   // 完成时间下限（ms），过快按机器处理
    const PASSIVE_MIN_MOVE  = 5;     // 指针移动采样下限
    const PASSIVE_MIN_ENT   = 25;    // 移动方向熵下限（0-100）

    /** 图形验证码支持样式（后台设置页选项，顺序即展示顺序） */
    public static function styles()
    {
        return [
            'slide'   => '滑动拼图',
            'click'   => '文字点选',
            'icon'    => '图标选择',
            'passive' => '无感验证',
            'digits'  => '纯数字',
            'mixed'   => '数字+字母组合',
        ];
    }

    /** 无感验证不通过时的回退样式可选值（不含 passive 自身） */
    public static function fallbackStyles()
    {
        $all = self::styles();
        unset($all['passive']);
        return $all;
    }

    public static function ensureTable()
    {
        Database::getInstance()->exec(
            "CREATE TABLE IF NOT EXISTS captcha_tokens (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                token      VARCHAR(64) NOT NULL UNIQUE,
                style      VARCHAR(16) NOT NULL DEFAULT 'digits',
                answer     VARCHAR(128) NOT NULL,
                salt       VARCHAR(64) NOT NULL,
                ip         VARCHAR(64) NOT NULL DEFAULT '',
                try_count  INT NOT NULL DEFAULT 0,
                used       INT NOT NULL DEFAULT 0,
                expires_at INT NOT NULL DEFAULT 0,
                created_at INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * 生成图形验证码
     * @param string $style 指定样式，留空使用后台配置 captcha_style
     * @return array ['token'=>.., 'style'=>.., 'image'=>..(digits/mixed),
     *               'svg'=>..,'prompt'=>..,'tiles'=>..(click/icon),
     *               'bg'=>..,'piece'=>..(slide)]
     */
    public static function issue($style = '')
    {
        $style = $style !== '' ? $style : (string)Config::get('captcha_style', 'slide');
        if (!array_key_exists($style, self::styles())) {
            $style = 'slide';
        }
        // slide/digits/mixed 依赖 GD 渲染；click/icon/passive 为纯文本/无图，不依赖 GD
        if (in_array($style, ['slide', 'digits', 'mixed'], true) && !function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('服务器未启用 GD 扩展，无法生成图形验证码');
        }
        switch ($style) {
            case 'click':
                return self::issueClick();
            case 'icon':
                return self::issueIcon();
            case 'passive':
                return self::issuePassive();
            case 'slide':
                return self::issueSlide();
            default:
                return self::issueImage($style);
        }
    }

    // ==================== 生成：字符图（digits/mixed） ====================

    private static function issueImage($style)
    {
        self::ensureTable();
        $chars = $style === 'digits' ? '0123456789' : '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $w = 140; $h = 48;
        $img = imagecreatetruecolor($w, $h);
        // 背景
        $bgColor = imagecolorallocate($img, random_int(235, 250), random_int(238, 252), random_int(240, 255));
        imagefilledrectangle($img, 0, 0, $w, $h, $bgColor);
        // 干扰线 / 噪点
        for ($i = 0; $i < 5; $i++) {
            $c = imagecolorallocate($img, random_int(60, 200), random_int(60, 200), random_int(60, 200));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }
        for ($i = 0; $i < 60; $i++) {
            $c = imagecolorallocate($img, random_int(0, 220), random_int(0, 220), random_int(0, 220));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }
        // 字符
        $font = self::fontFile();
        $len  = strlen($code);
        $x = 14;
        for ($i = 0; $i < $len; $i++) {
            $c = imagecolorallocate($img, random_int(20, 110), random_int(30, 120), random_int(40, 130));
            if ($font) {
                $size = random_int(22, 26);
                $y = random_int(6, $h - $size);
                imagettftext($img, $size, random_int(-18, 18), $x, $y + $size, $c, $font, $code[$i]);
            } else {
                $y = random_int(8, $h - 24);
                imagestring($img, 5, $x, $y, $code[$i], $c);
            }
            $x += 30;
        }
        $png = self::pngData($img);
        @imagedestroy($img);

        $token = self::storeToken($style, $code);
        return ['token' => $token, 'style' => $style, 'image' => $png, 'mime' => 'image/png'];
    }

    // ==================== 生成：滑动拼图 ====================

    private static function issueSlide()
    {
        self::ensureTable();
        $w = self::BG_W; $h = self::BG_H; $s = self::HOLE_SIZE;
        $img = imagecreatetruecolor($w, $h);
        // 背景：渐变 + 随机块
        self::paintBg($img, $w, $h);
        // 缺口位置：水平留边
        $x = random_int($s + 10, $w - $s - 10);
        $y = random_int(30, $h - $s - 10);
        // 抠出滑块图（从背景上挖，再给边框）
        $piece = imagecreatetruecolor($s, $s);
        imagecopy($piece, $img, 0, 0, $x, $y, $s, $s);
        // 缺口填充白色 + 描边
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, $x, $y, $x + $s - 1, $y + $s - 1, $white);
        $red = imagecolorallocate($img, 230, 80, 70);
        imagerectangle($img, $x, $y, $x + $s - 1, $y + $s - 1, $red);
        // 滑块描边
        $blue = imagecolorallocate($piece, 70, 130, 230);
        imagerectangle($piece, 0, 0, $s - 1, $s - 1, $blue);

        $bgPng = self::pngData($img);
        $piecePng = self::pngData($piece);
        @imagedestroy($img);
        @imagedestroy($piece);

        // 答案存「缺口左边缘 x」——即拼图块应停靠的目标左坐标。
        // 滑块可移动范围为 [0, w - s]，而缺口 x ∈ [s+10, w-s-10] 始终落在该范围内，
        // 因此无论缺口出现在何处用户都一定能拖到正确位置（L2：旧实现存 $x+intdiv($s,2)
        // 即孔中心，最大可达 w-s/2-10，会超出滑块 max(w-s-10) 导致无解）。
        $token = self::storeToken('slide', $x);
        // 安全：响应中绝不返回 hole_x（答案）——否则脚本可直接读取答案绕过人机验证；
        // 仅返回 hole_y（纵向位置），前端用它把拼图块定位到与缺口同一水平带。
        return [
            'token'  => $token,
            'style'  => 'slide',
            'bg'     => $bgPng,
            'piece'  => $piecePng,
            'mime'   => 'image/png',
            'size'   => $s,
            'width'  => $w,
            'height' => $h,
            'hole_y' => $y,
        ];
    }

    // ==================== 生成：文字点选（click） ====================

    /** 点选验证码字符池（取《千字文》前段，字形辨识度高） */
    const CLICK_CHARS = '天地玄黄宇宙洪荒日月盈昃辰宿列张寒来暑往秋收冬藏闰余成岁律吕调阳云腾致雨露结为霜金生丽水玉出昆冈剑号巨阙珠称夜光果珍李柰菜重芥姜';

    private static function issueClick()
    {
        self::ensureTable();
        // 4 个目标文字 + 4 个干扰文字，随机打乱后落入 4x2 网格
        $pool = self::mbChars(self::CLICK_CHARS);
        $targets = self::pickDistinct($pool, 4);
        $rest = array_values(array_diff($pool, $targets));
        $decoys = self::pickDistinct($rest, 4);
        $cells = array_merge($targets, $decoys);
        self::shuffle($cells);

        // 答案 = 4 个目标文字在网格中的下标（按提示顺序）
        $idx = [];
        foreach ($targets as $ch) {
            $idx[] = array_search($ch, $cells, true);
        }
        $answer = implode(',', $idx);

        $svg = self::clickSvg($cells);
        $token = self::storeToken('click', $answer);
        return [
            'token'  => $token,
            'style'  => 'click',
            'svg'    => $svg,
            'prompt' => '请按顺序点击：' . implode(' ', $targets),
            'tiles'  => count($cells),
            'cols'   => 4,
            'rows'   => 2,
            'mime'   => 'image/svg+xml',
        ];
    }

    private static function clickSvg(array $cells)
    {
        $cw = 80; $ch = 80; $cols = 4; $rows = 2;
        $w = $cw * $cols; $h = $ch * $rows;
        $s = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';
        $s .= '<rect width="' . $w . '" height="' . $h . '" fill="#eef3fb"/>';
        $i = 0;
        foreach ($cells as $c) {
            $x = ($i % $cols) * $cw; $y = intdiv($i, $cols) * $ch;
            $rot = random_int(-14, 14);
            $col = sprintf('#%02x%02x%02x', random_int(30, 90), random_int(60, 130), random_int(120, 200));
            $s .= '<text x="' . ($x + $cw / 2) . '" y="' . ($y + $ch / 2 + 9) . '" font-size="38" font-family="\'Microsoft YaHei\',\'PingFang SC\',\'Noto Sans CJK SC\',sans-serif" font-weight="600" text-anchor="middle" fill="' . $col . '" transform="rotate(' . $rot . ' ' . ($x + $cw / 2) . ' ' . ($y + $ch / 2) . ')">' . $c . '</text>';
            $i++;
        }
        // 少量干扰线，增加机器识别难度（不影响人眼）
        for ($k = 0; $k < 4; $k++) {
            $s .= '<line x1="' . random_int(0, $w) . '" y1="' . random_int(0, $h) . '" x2="' . random_int(0, $w) . '" y2="' . random_int(0, $h) . '" stroke="rgba(100,130,180,.25)" stroke-width="1"/>';
        }
        $s .= '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($s);
    }

    // ==================== 生成：图标选择（icon） ====================

    /** 内置图标（纯 SVG 路径，无字体/图片依赖） */
    private static function iconShapes()
    {
        return [
            'circle'   => ['label' => '圆形',   'path' => '<circle cx="30" cy="30" r="22"/>'],
            'square'   => ['label' => '方形',   'path' => '<rect x="10" y="10" width="40" height="40" rx="4"/>'],
            'triangle' => ['label' => '三角形', 'path' => '<polygon points="30,8 52,50 8,50"/>'],
            'star'     => ['label' => '五角星', 'path' => '<polygon points="30,6 37,23 55,23 40,34 46,52 30,41 14,52 20,34 5,23 23,23"/>'],
            'heart'    => ['label' => '心形',   'path' => '<path d="M30 48 C10 34 6 22 14 14 C21 8 28 12 30 18 C32 12 39 8 46 14 C54 22 50 34 30 48 Z"/>'],
            'diamond'  => ['label' => '菱形',   'path' => '<polygon points="30,6 52,30 30,54 8,30"/>'],
            'hexagon'  => ['label' => '六边形', 'path' => '<polygon points="30,6 50,18 50,42 30,54 10,42 10,18"/>'],
            'cross'    => ['label' => '十字形', 'path' => '<polygon points="22,8 38,8 38,22 52,22 52,38 38,38 38,52 22,52 22,38 8,38 8,22 22,22"/>'],
        ];
    }

    private static function issueIcon()
    {
        self::ensureTable();
        $shapes = self::iconShapes();
        $keys = array_keys($shapes);
        self::shuffle($keys);
        $targetKey = $keys[0];
        $targets = random_int(2, 3);           // 目标图标数量
        $cells = array_fill(0, $targets, $targetKey);
        // 干扰图标：从其余形状中取不重复的若干种
        $others = array_slice($keys, 1);
        self::shuffle($others);
        $decoys = array_slice($others, 0, 8 - $targets);
        foreach ($decoys as $d) { $cells[] = $d; }
        self::shuffle($cells);

        $idx = [];
        foreach ($cells as $i => $k) {
            if ($k === $targetKey) { $idx[] = $i; }
        }
        sort($idx);
        $answer = implode(',', $idx);

        $svg = self::iconSvg($cells, $shapes);
        $token = self::storeToken('icon', $answer);
        return [
            'token'  => $token,
            'style'  => 'icon',
            'svg'    => $svg,
            'prompt' => '请点选图中所有的【' . $shapes[$targetKey]['label'] . '】（共 ' . $targets . ' 个）',
            'tiles'  => count($cells),
            'cols'   => 4,
            'rows'   => 2,
            'count'  => $targets,
            'mime'   => 'image/svg+xml',
        ];
    }

    private static function iconSvg(array $cells, array $shapes)
    {
        $cw = 72; $ch = 72; $cols = 4; $rows = 2;
        $w = $cw * $cols; $h = $ch * $rows;
        $palette = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#06b6d4', '#f43f5e', '#84cc16'];
        $s = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';
        $s .= '<rect width="' . $w . '" height="' . $h . '" fill="#eef3fb"/>';
        $i = 0;
        foreach ($cells as $key) {
            $x = ($i % $cols) * $cw; $y = intdiv($i, $cols) * $ch;
            $fill = $palette[random_int(0, count($palette) - 1)];
            $s .= '<g transform="translate(' . $x . ',' . $y . ')"><g fill="' . $fill . '" fill-opacity=".85" stroke="' . $fill . '" stroke-width="1.5">'
                 . $shapes[$key]['path'] . '</g></g>';
            $i++;
        }
        $s .= '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($s);
    }

    // ==================== 生成：无感验证（passive） ====================

    private static function issuePassive()
    {
        self::ensureTable();
        // 无感验证不下发图片；answer 预置占位，真正答案由前端回传行为数据 JSON
        $token = self::storeToken('passive', '0');
        return [
            'token'     => $token,
            'style'     => 'passive',
            'mode'      => 'trajectory',   // 前端据此切换到「轨迹采集」模式
            'collect_ms' => (int)Config::get('passive_collect_ms', 1600), // 采集窗口
            'max_points' => (int)Config::get('passive_max_points', 120),  // 轨迹最大采样点
            'prompt'    => '正在检测当前环境安全性，请稍候…',
            'mime'      => 'none',
        ];
    }

    /**
     * 无感验证行为评分
     * @param string $answer 前端回传 JSON：{t:耗时ms, mv:指针移动次数, ent:方向熵0-100, touch:0/1}
     * @return bool
     */
    private static function passiveScore($answer)
    {
        $d = json_decode($answer, true);
        if (!is_array($d)) { return false; }
        // 兼容两套前端格式：旧版聚合字段 {t,mv,ent,touch} 与新版原始轨迹 {traj,env}
        if (isset($d['traj']) && is_array($d['traj'])) {
            $pts = $d['traj'];
            $n   = count($pts);
            $t   = $n ? ((int)end($pts)[0] - (int)$pts[0][0]) : 0;
            $mv  = $n;
            $dirs = [];
            $prev = -1;
            for ($i = 1; $i < $n; $i++) {
                $dx = $pts[$i][1] - $pts[$i - 1][1];
                $dy = $pts[$i][2] - $pts[$i - 1][2];
                if ($dx || $dy) {
                    $b = (int)floor((atan2($dy, $dx) + M_PI) / (M_PI / 4)) % 8;
                    $dirs[$b] = 1;
                    $prev = $b;
                }
            }
            $ent   = count($dirs) / 8 * 100;
            $touch = !empty($d['env']['touch']) ? 1 : 0;
        } else {
            $t    = isset($d['t']) ? intval($d['t']) : 0;
            $mv   = isset($d['mv']) ? intval($d['mv']) : 0;
            $ent  = isset($d['ent']) ? intval($d['ent']) : 0;
            $touch = !empty($d['touch']) ? 1 : 0;
        }
        if ($t < self::PASSIVE_MIN_MS) { return false; }
        // 触摸设备没有 mousemove，阈值放宽（touchmove 计入 mv）
        if ($mv < ($touch ? 3 : self::PASSIVE_MIN_MOVE)) { return false; }
        if ($ent < self::PASSIVE_MIN_ENT) { return false; }
        return true;
    }

    // ==================== 校验 ====================

    /**
     * 校验图形验证码
     * @param string $token
     * @param string $answer slide 传拼图块停靠目标左坐标 x(数字串)；digits/mixed 传字符；
     *                      click 传按顺序的下标 "2,0,3,1"；icon 传全部目标下标 "1,4,6"；
     *                      passive 传行为数据 JSON
     * @param bool   $consume 成功后是否置为已使用（登录等最终校验传 true；前端预检传 false）
     */
    public static function verify($token, $answer, $consume = true)
    {
        self::ensureTable();
        $token = trim((string)$token);
        $answer = trim((string)$answer);
        if ($token === '' || $answer === '') {
            return [false, '请先完成图形验证'];
        }
        $db = Database::getInstance();
        $row = $db->one("SELECT * FROM captcha_tokens WHERE token=? ORDER BY id DESC LIMIT 1", [$token]);
        if (!$row) {
            return [false, '验证码已失效，请刷新'];
        }
        if (intval($row['used'])) {
            return [false, '验证码已使用，请刷新'];
        }
        if (intval($row['expires_at']) < time()) {
            return [false, '验证码已过期，请刷新'];
        }
        if (intval($row['try_count']) >= self::MAX_TRY) {
            return [false, '尝试次数过多，请刷新'];
        }
        $ok = false;
        switch ($row['style']) {
            case 'slide':
                // L3: 答案必须是纯数字，杜绝 "15abc" 这类隐式转 int 的绕过
                if (ctype_digit($answer)) {
                    $ok = abs(intval($answer) - intval($row['answer'])) <= self::SLIDE_TOL;
                }
                break;
            case 'click':
            case 'icon':
                // 点选类：下标集合，逗号分隔，顺序不敏感时由存储端保证（click 已按提示顺序存）
                if (preg_match('/^\d+(,\d+)*$/', $answer)) {
                    $ok = hash_equals(hash('sha256', $answer . $row['salt']), $row['answer']);
                }
                break;
            case 'passive':
                // 无感验证：接入 RiskEngine 做风险评分与分级拦截
                if (Config::get('passive_enabled', 1)) {
                    $ctx = [
                        'ip'       => Request::ip(),
                        'tokenAge' => time() - intval($row['created_at']),
                        'ipFreq'   => RiskEngine::ipFreq(Request::ip()),
                        'serverTz' => (int)(date('Z') / 60),
                    ];
                    $r = RiskEngine::evaluate($answer, $ctx);
                    $ok = $r['ok'];
                    if (!$ok) {
                        $db->update('captcha_tokens', ['try_count' => intval($row['try_count']) + 1], 'id=?', [$row['id']]);
                        // 分级：block → 专用哨兵串（前端阻断）；challenge → 回退交互式
                        if ($r['decision'] === 'block') {
                            return [false, '__passive_block__'];
                        }
                        return [false, '__passive_fail__'];
                    }
                    // 通过：下发信任凭证（HMAC 签名 Cookie），实现跨页无感放行
                    RiskEngine::issueTrustCookie(Request::ip());
                } else {
                    // 引擎未启用：回退到旧版简单阈值判定
                    $ok = self::passiveScore($answer);
                }
                break;
            default:
                $ok = hash_equals(hash('sha256', strtolower($answer) . $row['salt']), $row['answer']);
        }
        if (!$ok) {
            $db->update('captcha_tokens', ['try_count' => intval($row['try_count']) + 1], 'id=?', [$row['id']]);
            // 无感验证未通过时给出专用提示，前端据此自动回退到交互式验证码
            if ($row['style'] === 'passive') {
                return [false, '__passive_fail__'];
            }
            return [false, '验证码不正确'];
        }
        if ($consume) {
            $db->update('captcha_tokens', ['used' => 1], 'id=?', [$row['id']]);
        }
        return [true, 'ok'];
    }

    // ==================== 内部 ====================

    private static function storeToken($style, $answer)
    {
        $token = rand_str(32);
        $salt  = rand_str(8);
        // slide 样式答案是需要容差比较的整数坐标，passive 存占位符，直接存明文；
        // 其余样式答案与 salt 拼接后存哈希，校验时 hash_equals 比对
        $stored = in_array($style, ['slide', 'passive'], true)
            ? (string)$answer
            : hash('sha256', (string)$answer . $salt);
        Database::getInstance()->insert('captcha_tokens', [
            'token'      => $token,
            'style'      => $style,
            'answer'     => $stored,
            'salt'       => $salt,
            'ip'         => Request::ip(),
            'try_count'  => 0,
            'used'       => 0,
            'expires_at' => time() + self::TTL,
            'created_at' => time(),
        ]);
        return $token;
    }

    /** 字符串按字符拆为数组（兼容无 mbstring 环境：按 UTF-8 3 字节手动切分） */
    private static function mbChars($str)
    {
        if (function_exists('mb_str_split')) {
            return mb_str_split($str);
        }
        $out = [];
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($str[$i]);
            $n = ($b & 0xE0) === 0xC0 ? 2 : (($b & 0xF0) === 0xE0 ? 3 : 1);
            $out[] = substr($str, $i, $n);
            $i += $n - 1;
        }
        return $out;
    }

    /** 从池中随机取 n 个不重复元素 */
    private static function pickDistinct(array $pool, $n)
    {
        $pool = array_values($pool);
        $out = [];
        while (count($out) < $n && $pool) {
            $k = random_int(0, count($pool) - 1);
            $out[] = $pool[$k];
            array_splice($pool, $k, 1);
        }
        return $out;
    }

    /** Fisher-Yates 洗牌（使用 random_int，避免 mt_rand 可预测性） */
    private static function shuffle(array &$arr)
    {
        for ($i = count($arr) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $tmp = $arr[$i]; $arr[$i] = $arr[$j]; $arr[$j] = $tmp;
        }
    }

    private static function paintBg($img, $w, $h)
    {
        $c1 = imagecolorallocate($img, random_int(200, 245), random_int(215, 250), random_int(230, 255));
        $c2 = imagecolorallocate($img, random_int(180, 225), random_int(195, 240), random_int(215, 250));
        imagefilledrectangle($img, 0, 0, $w, $h, $c1);
        for ($i = 0; $i < 9; $i++) {
            $c = imagecolorallocate($img, random_int(90, 230), random_int(90, 230), random_int(90, 230));
            imagefilledellipse($img, random_int(0, $w), random_int(0, $h), random_int(30, 160), random_int(30, 160), $c);
        }
        imagefilledrectangle($img, 0, 0, $w, $h, $c2);
        imagealphablending($img, true);
        for ($i = 0; $i < 40; $i++) {
            $c = imagecolorallocatealpha($img, random_int(0, 255), random_int(0, 255), random_int(0, 255), random_int(90, 120));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }
    }

    private static function fontFile()
    {
        $candidates = [
            BASE_PATH . '/public/assets/fonts/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
        ];
        foreach ($candidates as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        return '';
    }

    private static function pngData($img)
    {
        ob_start();
        imagepng($img);
        return 'data:image/png;base64,' . base64_encode(ob_get_clean());
    }
}
