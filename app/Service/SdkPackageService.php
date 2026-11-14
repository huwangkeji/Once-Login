<?php
/**
 * SDK 压缩包管理服务
 * 存储位置：data/sdk_packages/（web 根 public/ 之外，禁止直链下载）
 * 元数据：data/sdk_packages/index.json
 * 权限：后台 RBAC manage:system
 *
 * 安全要点：
 *  - 文件名白名单正则 + basename，杜绝路径穿越
 *  - 仅允许 .zip，校验 ZipArchive 可打开、成员数上限、解压总大小上限
 *  - 元数据与物理文件强一致：列表时校验磁盘存在，删除时两者同步
 */
class SdkPackageService
{
    const DIR = 'data/sdk_packages';
    const MAX_SIZE     = 52428800;  // 50MB
    const MAX_MEMBERS  = 4096;
    const MAX_EXTRACT  = 209715200; // 解压总量 200MB 上限（防 zip 炸弹）
    const NAME_PATTERN = '/^[A-Za-z0-9\x{4e00}-\x{9fa5}][A-Za-z0-9._\-\x{4e00}-\x{9fa5}]{0,120}\.zip$/u';

    /** 存储目录绝对路径（data/ 位于项目根，web 根 public/ 之外） */
    public static function dir()
    {
        return defined('DATA_PATH') ? DATA_PATH . '/sdk_packages' : (dirname(__DIR__, 2) . '/data/sdk_packages');
    }

    /** 元数据文件绝对路径 */
    public static function metaFile()
    {
        return self::dir() . '/index.json';
    }

    /** 读取元数据（不存在返回空结构） */
    public static function meta()
    {
        $f = self::metaFile();
        if (!is_file($f)) {
            return ['packages' => [], 'updated_at' => 0];
        }
        $data = json_decode((string)file_get_contents($f), true);
        if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
            return ['packages' => [], 'updated_at' => 0];
        }
        return $data;
    }

/** 字段编码归一化：强制 UTF-8，非法输入回退为空串（防 json_encode 失败破坏元数据） */
    public static function cleanText($s)
    {
        if (!is_string($s)) {
            return '';
        }
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $c = @mb_convert_encoding($s, 'UTF-8', 'GBK,GB18030,ASCII');
        if (is_string($c) && mb_check_encoding($c, 'UTF-8')) {
            return $c;
        }
        return '';
    }

    /** 写入元数据（序列化失败时拒绝写入，防止元数据被清空） */
    public static function saveMeta(array $meta)
    {
        $f = self::metaFile();
        $meta['updated_at'] = time();
        $tmp = $f . '.tmp';
        $js = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($js === false || $js === '') {
            return false; // 编码异常时不落盘，保持原元数据完好
        }
        if (file_put_contents($tmp, $js, LOCK_EX) === false) {
            return false;
        }
        chmod($tmp, 0644);
        $rr = rename($tmp, $f);
        clearstatcache(true, $f);
        return $rr;
    }

    /** 列表：与磁盘强一致，剔除已丢失文件 */
    public static function listPackages()
    {
        $meta    = self::meta();
        $dir     = self::dir();
        $pkg     = [];
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        foreach ($meta['packages'] as $name => $info) {
            $abs = $dir . '/' . $name;
            if (!self::isSafeName($name) || !is_file($abs)) {
                unset($meta['packages'][$name]);
                continue;
            }
            $stat = @stat($abs);
            $pkg[] = [
                'name'    => $name,
                'title'   => isset($info['title']) ? $info['title'] : $name,
                'version' => isset($info['version']) ? $info['version'] : 'v1.0.0',
                'desc'    => isset($info['desc']) ? $info['desc'] : '',
                'size'    => $stat ? $stat['size'] : 0,
                'mtime'   => $stat ? $stat['mtime'] : 0,
                'builtin' => !empty($info['builtin']),
            ];
        }
        if (count($pkg) !== count($meta['packages'])) {
            self::saveMeta($meta); // 清理失效引用
        }
        usort($pkg, function ($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });
        return $pkg;
    }

    /** 文件名合法性（严格白名单，杜绝穿越） */
    public static function isSafeName($name)
    {
        if (!is_string($name) || $name === '' || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            return false;
        }
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /** 下载：元数据白名单 + 物理存在双校验 */
    public static function download($name)
    {
        if (!self::isSafeName($name)) {
            return false;
        }
        $meta = self::meta();
        if (!isset($meta['packages'][$name])) {
            return false;
        }
        $abs = self::dir() . '/' . $name;
        if (!is_file($abs)) {
            return false;
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        readfile($abs);
        return true;
    }

    /** 上传：校验 → 落盘（临时名+原子 rename）→ 更新元数据 */
    public static function upload(array $file, $title, $version, $desc)
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['ok' => false, 'message' => '上传参数不完整'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => '文件上传失败（错误码 ' . intval($file['error']) . '）'];
        }
        $name = basedOnUploadName($file['name']);
        if (!self::isSafeName($name)) {
            return ['ok' => false, 'message' => '文件名不合法，仅允许 .zip（字母/数字/点/下划线/中划线/中文）'];
        }
        if ($file['size'] <= 0 || $file['size'] > self::MAX_SIZE) {
            return ['ok' => false, 'message' => '文件大小须在 1B ~ 50MB 之间'];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'message' => '上传源不可信'];
        }

        // Zip 完整性 + 炸弹防护
        $za = new ZipArchive();
        if (@$za->open($file['tmp_name']) !== true) {
            return ['ok' => false, 'message' => '不是有效的 zip 压缩包'];
        }
        $members = $za->numFiles;
        $total   = 0;
        for ($i = 0; $i < $members; $i++) {
            $st = $za->statIndex($i);
            if ($st === false) {
                $za->close();
                return ['ok' => false, 'message' => 'zip 内部结构异常'];
            }
            $total += isset($st['size']) ? intval($st['size']) : 0;
        }
        $za->close();
        if ($members <= 0) {
            return ['ok' => false, 'message' => 'zip 内没有文件'];
        }
        if ($members > self::MAX_MEMBERS) {
            return ['ok' => false, 'message' => 'zip 内文件数超过上限 ' . self::MAX_MEMBERS];
        }
        if ($total > self::MAX_EXTRACT) {
            return ['ok' => false, 'message' => 'zip 解压总大小超过上限，疑似压缩炸弹'];
        }

        // 落盘（临时名 + rename 原子写）
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $dir . '/.upload_' . bin2hex(random_bytes(8)) . '.tmp';
        if (!move_uploaded_file($file['tmp_name'], $tmp)) {
            return ['ok' => false, 'message' => '文件落盘失败，请检查目录权限'];
        }
        chmod($tmp, 0644);
        if (!rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);
            return ['ok' => false, 'message' => '文件写入失败，请检查目录权限'];
        }

// 更新元数据（文本字段强制 UTF-8，非法编码回退默认值）
        $title = self::cleanText($title);
        $desc  = self::cleanText($desc);
        $meta  = self::meta();
        $meta['packages'][$name] = [
            'title'   => $title !== '' ? $title : $name,
            'version' => self::cleanText($version) !== '' ? self::cleanText($version) : 'v1.0.0',
            'desc'    => $desc,
            'size'    => intval($file['size']),
            'mtime'   => time(),
            'builtin' => false,
        ];
        if (self::saveMeta($meta) === false) {
            @unlink($dir . '/' . $name); // 元数据写入失败则回滚物理文件
            return ['ok' => false, 'message' => '元数据写入失败，请重试'];
        }

        Logger::info('sdkpkg', '上传 SDK 包 ' . $name, ['title' => $title, 'size' => $file['size']]);
        return ['ok' => true, 'name' => $name];
    }

    /** 删除：内置包禁用；删除物理文件 + 元数据 */
    public static function remove($name, $adminId = 0)
    {
        if (!self::isSafeName($name)) {
            return ['ok' => false, 'message' => '文件名不合法'];
        }
        $meta = self::meta();
        if (!isset($meta['packages'][$name])) {
            return ['ok' => false, 'message' => '包不存在'];
        }
        if (!empty($meta['packages'][$name]['builtin'])) {
            return ['ok' => false, 'message' => '内置 SDK 包不可删除'];
        }
        $abs = self::dir() . '/' . $name;
        if (is_file($abs) && !@unlink($abs)) {
            return ['ok' => false, 'message' => '文件删除失败，请检查目录权限'];
        }
        unset($meta['packages'][$name]);
        self::saveMeta($meta);
        Logger::warn('sdkpkg', '删除 SDK 包 ' . $name, ['admin_id' => intval($adminId)]);
        return ['ok' => true];
    }
}

/** 上传文件名规范化：编码兜底 + 只留 basename */
if (!function_exists('basedOnUploadName')) {
    function basedOnUploadName($raw)
    {
        $name = is_string($raw) ? $raw : '';
        $name = str_replace('\\', '/', $name);
        $pos  = strrpos($name, '/');
        if ($pos !== false) {
            $name = substr($name, $pos + 1);
        }
        if ($name === '') {
            $name = 'sdk.zip';
        }
        return $name;
    }
}