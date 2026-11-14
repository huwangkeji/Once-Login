# -*- coding: utf-8 -*-
"""用户业务客户端."""


class UserClient:
    """用户业务客户端（需会话 Cookie）."""

    def __init__(self, config, transport):
        self._config = config
        self._http = transport

    def register(self, data, auto_login=True):
        """用户注册."""
        params = dict(data)
        params["auto_login"] = "1" if auto_login else "0"
        res = self._http.request("POST", "?m=user&a=register", params)
        return res.get("data") or {}

    def login(self, account, password, remember=False):
        """用户登录."""
        res = self._http.request("POST", "?m=user&a=login", {
            "account": account,
            "password": password,
            "remember": "1" if remember else "0",
        })
        return res.get("data") or {}

    def logout(self):
        """退出当前设备."""
        try:
            self._http.request("POST", "?m=user&a=logout")
            return True
        except Exception:
            return False

    def logout_all(self):
        """退出全部设备."""
        try:
            self._http.request("POST", "?m=user&a=logoutall")
            return True
        except Exception:
            return False

    def get_info(self):
        """获取当前登录用户信息."""
        res = self._http.request("POST", "?m=user&a=info")
        return res.get("data") or {}

    def get_security(self):
        """获取账号安全信息."""
        res = self._http.request("POST", "?m=user&a=security")
        return res.get("data") or {}

    def kill_device(self, sid):
        """踢掉指定设备."""
        try:
            self._http.request("POST", "?m=user&a=device_kill", {"sid": sid})
            return True
        except Exception:
            return False

    def change_password(self, old_password, new_password, confirm):
        """修改密码."""
        res = self._http.request("POST", "?m=user&a=password", {
            "old_password": old_password,
            "new_password": new_password,
            "confirm": confirm,
        })
        return res.get("data") or {}

    def update_profile(self, data):
        """修改资料."""
        res = self._http.request("POST", "?m=user&a=profile", data)
        return res.get("data") or {}

    def send_reset_code(self, target):
        """发送找回密码验证码."""
        try:
            self._http.request("POST", "?m=user&a=findpwd_send", {"target": target})
            return True
        except Exception:
            return False

    def reset_password(self, target, code, new_password, confirm):
        """找回密码 - 重置."""
        res = self._http.request("POST", "?m=user&a=findpwd_reset", {
            "target": target,
            "code": code,
            "new_password": new_password,
            "confirm": confirm,
        })
        return res.get("data") or {}

    def check_unique(self, field, value):
        """唯一性检查（true=可用）."""
        res = self._http.request("POST", "?m=user&a=check", {
            "field": field,
            "value": value,
        })
        return res.get("data") is True