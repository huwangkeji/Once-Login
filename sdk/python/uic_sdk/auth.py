# -*- coding: utf-8 -*-
"""认证客户端（OAuth2.0 / SSO）."""

from .exceptions import AuthError


class AuthClient:
    """用户认证客户端."""

    def __init__(self, config, transport):
        self._config = config
        self._http = transport

    def get_authorize_url(self, callback, state=""):
        """构建 SSO 授权跳转 URL.

        :param callback: 回调地址
        :param state: 防 CSRF 状态码
        """
        params = {
            "app_id": self._config.get("app_id", ""),
            "redirect": callback,
        }
        if state:
            params["state"] = state
        from urllib.parse import urlencode
        base = self._config["base_url"].rstrip("/")
        return base + "/index.php?c=sso&a=authorize&" + urlencode(params)

    def exchange_ticket(self, ticket):
        """ticket 换 token（服务端对接）."""
        res = self._http.request("POST", "?m=sso&a=exchange", {
            "ticket": ticket,
            "app_id": self._config.get("app_id", ""),
            "app_secret": self._config.get("app_secret", ""),
        })
        data = res.get("data") or {}
        if not data.get("token"):
            raise AuthError("Token 换取失败：响应中未包含 token")
        return data

    def get_user_info(self, token=""):
        """token 换用户信息."""
        if not token:
            raise AuthError("缺少 access_token，请先完成认证")
        res = self._http.request("POST", "?m=sso&a=userinfo", {
            "token": token,
            "app_id": self._config.get("app_id", ""),
        })
        return res.get("data") or {}

    def logout(self, token=""):
        """SSO 单点退出."""
        if not token:
            return False
        try:
            self._http.request("POST", "?m=sso&a=logout", {
                "token": token,
                "app_id": self._config.get("app_id", ""),
            })
        except Exception:
            return False
        return True

    def test_connect(self):
        """连通性测试."""
        try:
            res = self._http.request("POST", "?m=sso&a=test", {
                "app_id": self._config.get("app_id", ""),
                "app_secret": self._config.get("app_secret", ""),
            })
            return res.get("code") == 0
        except Exception:
            return False