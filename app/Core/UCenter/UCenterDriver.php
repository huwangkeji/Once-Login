<?php
/**
 * UCenter 驱动接口
 */
interface UCenterDriver
{
    /**
     * @param string $module user/mail/friend...
     * @param string $action checkname/register/login/edit...
     * @param array  $args
     * @return mixed  原始返回值（int 错误码 / array / string）
     */
    public function call($module, $action, array $args = []);

    /** 连接测试，返回 ['ok'=>bool,'message'=>string,'detail'=>mixed] */
    public function ping();
}
