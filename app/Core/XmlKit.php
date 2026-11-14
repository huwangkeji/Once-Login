<?php
/**
 * UCenter XML 序列化/反序列化
 * 对应 uc_client/lib/xml.class.php
 */
class XmlKit
{
    public static function serialize($arr, $htmlon = 0, $isnormal = false, $level = 1)
    {
        $s       = $level == 1 ? "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\r\n<root>\r\n" : '';
        $space   = str_repeat("\t", $level);
        $isarray = is_array($arr);
        foreach ($arr as $k => $v) {
            if (!$isarray) {
                $k = 'item';
            }
            if (is_array($v) && count($v)) {
                $s .= $space . '<item id="' . htmlspecialchars($k) . "\">\r\n"
                    . self::serialize($v, $htmlon, false, $level + 1)
                    . $space . "</item>\r\n";
            } elseif (is_array($v)) {
                $s .= $space . '<item id="' . htmlspecialchars($k) . "\"></item>\r\n";
            } else {
                $v = str_replace(array('<![CDATA[', ']]>', "\r"), array('', '', ''), $v);
                $s .= $space . '<item id="' . htmlspecialchars($k) . '">' . ($v) . "</item>\r\n";
            }
        }
        if ($level == 1) {
            $s .= "</root>";
        }
        return $s;
    }

    public static function unserialize($xml)
    {
        return self::xml2sub($xml);
    }

    public static function xml2sub($xml, $isnormal = false, $level = 1)
    {
        $arr = array();
        if (!preg_match_all("/<(item|Data)([^>]*?)>([\s\S]*?)<\/(item|Data)>/i", $xml, $matches)) {
            return $arr;
        }
        $isarray = 0;
        foreach ($matches[1] as $i => $tag) {
            $attrs  = $matches[2][$i];
            $val    = $matches[3][$i];
            if (preg_match('/id="(.+?)"/i', $attrs, $m)) {
                $key = $m[1];
            } else {
                $key = count($arr);
                $isarray = 1;
            }
            if (preg_match_all("/<(item|Data)([^>]*?)>([\s\S]*?)<\/(item|Data)>/i", $val, $m2)) {
                $arr[$key] = self::xml2sub($val, false, $level + 1);
            } elseif ($val === '' || $val === 'NULL') {
                $arr[$key] = '';
            } else {
                $arr[$key] = htmlspecialchars_decode($val, ENT_QUOTES);
            }
        }
        return $arr;
    }
}
