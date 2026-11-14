# -*- coding: utf-8 -*-
"""异步子客户端（AsyncClient 专用）.

同步子客户端（auth/user/captcha）的方法体是同步实现的，
无法直接 await AsyncTransport 的协程。此处提供对应异步版本，
方法签名与同步客户端保持一致，便于 async 项目使用。

用法::

    from uic_sdk import AsyncClient

    async def main():
        client = AsyncClient(base_url="http://127.0.0.1", app_id="xxx", app_secret="yyy")
        url = client.auth.get_authorize_url("http://your-app.local/callback")  # 同步构建 URL
        tokens = await client.auth.exchange_ticket("ticket")                  # 异步换 Token
        await client.close()
"""

from .exceptions import AuthError


class AsyncAuthClient:
    """异步认证客户端（OAuth2.0 / SSO）."""

    def __init__(self, config, transport):
        self._config = config
        self._http = transport

    def get_authorize_url(self, callback, state=""):
        """构建 SSO 授权跳转 URL（纯本地计算，无需 await）."""
        params = {
            "app_id": self._config.get("app_id", ""),
            "redirect": callback,
        }
        if state:
            params["state"] = state
        from urllib.parse import urlencode
        base = self._config["base_url"].rstrip("/")
        return base + "/index.php?c=sso&a=authorize&" + urlencode(params)

    async def exchange_ticket(self, ticket):
        """ticket 换 token（服务端对接）."""
        res = await self._http.request("POST", "?m=sso&a=exchange", {
            "ticket": ticket,
            "app_id": self._config.get("app_id", ""),
            "app_secret": self._config.get("app_secret", ""),
        })
        data = res.get("data") or {}
        if not data.get("token"):
            raise AuthError("Token 换取失败：响应中未包含 token")
        return data

    async def get_user_info(self, token=""):
        """token 换用户信息."""
        if not token:
            raise AuthError("缺少 access_token，请先完成认证")
        res = await self._http.request("POST", "?m=sso&a=userinfo", {
            "token": token,
            "app_id": self._config.get("app_id", ""),
        })
        return res.get("data") or {}

    async def logout(self, token=""):
        """SSO 单点退出."""
        if not token:
            return False
        try:
            await self._http.request("POST", "?m=sso&a=logout", {
                "token": token,
                "app_id": self._config.get("app_id", ""),
            })
        except Exception:
            return False
        return True

    async def test_connect(self):
        """连通性测试."""
        try:
            res = await self._http.request("POST", "?m=sso&a=test", {
                "app_id": self._config.get("app_id", ""),
                "app_secret": self._config.get("app_secret", ""),
            })
            return res.get("code") == 0
        except Exception:
            return False


class AsyncUserClient:
    """异步用户业务客户端（需会话 Cookie）."""

    def __init__(self, config, transport):
        self._config = config
        self._http = transport

    async def register(self, data, auto_login=True):
        """用户注册."""
        params = dict(data)
        params["auto_login"] = "1" if auto_login else "0"
        res = await self._http.request("POST", "?m=user&a=register", params)
        return res.get("data") or {}

    async def login(self, account, password, remember=False):
        """用户登录."""
        res = await self._http.request("POST", "?m=user&a=login", {
            "account": account,
            "password": password,
            "remember": "1" if remember else "0",
        })
        return res.get("data") or {}

    async def logout(self):
        """退出当前设备."""
        try:
            await self._http.request("POST", "?m=user&a=logout")
            return True
        except Exception:
            return False

    async def logout_all(self):
        """退出全部设备."""
        try:
            await self._http.request("POST", "?m=user&a=logoutall")
            return True
        except Exception:
            return False

    async def get_info(self):
        """获取当前登录用户信息."""
        res = await self._http.request("POST", "?m=user&a=info")
        return res.get("data") or {}

    async def get_security(self):
        """获取账号安全信息."""
        res = await self._http.request("POST", "?m=user&a=security")
        return res.get("data") or {}

    async def kill_device(self, sid):
        """踢掉指定设备."""
        try:
            await self._http.request("POST", "?m=user&a=device_kill", {"sid": sid})
            return True
        except Exception:
            return False

    async def change_password(self, old_password, new_password, confirm):
        """修改密码."""
        res = await self._http.request("POST", "?m=user&a=password", {
            "old_password": old_password,
            "new_password": new_password,
            "confirm": confirm,
        })
        return res.get("data") or {}

    async def update_profile(self, data):
        """修改资料."""
        res = await self._http.request("POST", "?m=user&a=profile", data)
        return res.get("data") or {}

    async def send_reset_code(self, target):
        """发送找回密码验证码."""
        try:
            await self._http.request("POST", "?m=user&a=findpwd_send", {"target": target})
            return True
        except Exception:
            return False

    async def reset_password(self, target, code, new_password, confirm):
        """找回密码 - 重置."""
        res = await self._http.request("POST", "?m=user&a=findpwd_reset", {
            "target": target,
            "code": code,
            "new_password": new_password,
            "confirm": confirm,
        })
        return res.get("data") or {}

    async def check_unique(self, field, value):
        """唯一性检查（true=可用）."""
        res = await self._http.request("POST", "?m=user&a=check", {
            "field": field,
            "value": value,
        })
        return res.get("data") is True


class AsyncCaptchaClient:
    """异步图形验证码客户端."""

    def __init__(self, config, transport):
        self._config = config
        self._http = transport

    async def issue(self):
        """获取图形验证码.

        :return: {captcha_id, image_base64, expires_in}
        """
        res = await self._http.request("POST", "?m=captcha&a=issue")
        return res.get("data") or {}

    async def verify(self, captcha_id, code):
        """校验验证码."""
        res = await self._http.request("POST", "?m=captcha&a=verify", {
            "id": captcha_id,
            "code": code,
        })
        return res.get("data") is True