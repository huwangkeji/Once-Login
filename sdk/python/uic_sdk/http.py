# -*- coding: utf-8 -*-
"""HTTP 传输封装，同步基于 requests，异步基于 aiohttp（可选）. """

import time

try:  # pragma: no cover
    import requests as _requests
except ImportError:  # pragma: no cover
    _requests = None


def _build_payload(params):
    """过滤空值，None/'' 不参与传输."""
    if not params:
        return {}
    return {k: v for k, v in params.items() if v is not None and v != ""}


class SyncTransport:
    """同步 HTTP 传输（requests）. """

    def __init__(self, config):
        self.config = config
        if _requests is None:  # pragma: no cover
            raise RuntimeError("缺少依赖 requests，请先执行: pip install uic-sdk")
        self.session = _requests.Session()
        self.session.headers.update({
            "Accept": "application/json",
            "User-Agent": "UicSdk-Python/1.0.0",
            "X-App-Id": self.config.get("app_id", ""),
        })

    def request(self, method, endpoint, params=None, headers=None):
        base_url = self.config["base_url"].rstrip("/")
        url = base_url + "/api/index.php" + endpoint
        timeout = self.config.get("timeout", 30)
        payload = _build_payload(params)

        kw = {"timeout": timeout, "headers": headers or {}}
        if method.upper() == "POST":
            kw["data"] = payload
            kw["headers"]["Content-Type"] = "application/x-www-form-urlencoded"
        else:
            kw["params"] = payload

        resp = self.session.request(method, url, **kw)
        if resp.status_code >= 500:
            from .exceptions import NetworkError
            raise NetworkError("服务器错误 HTTP %d" % resp.status_code, resp.status_code)
        try:
            data = resp.json()
        except ValueError:
            from .exceptions import ApiError
            raise ApiError("响应解析失败: %s" % resp.text[:200], resp.status_code)
        code = data.get("code") if isinstance(data, dict) else None
        if code is not None and code not in (0, 200):
            from .exceptions import ApiError
            raise ApiError(data.get("message", "请求失败"), code, data.get("data"))
        return data


class AsyncTransport:
    """异步 HTTP 传输（aiohttp，按需延迟创建 session）. """

    def __init__(self, config):
        self.config = config
        self._session = None

    async def _get_session(self):
        if self._session is None:  # pragma: no cover
            import aiohttp
            self._session = aiohttp.ClientSession(headers={
                "Accept": "application/json",
                "User-Agent": "UicSdk-Python/1.0.0 (async)",
                "X-App-Id": self.config.get("app_id", ""),
            })
        return self._session

    async def close(self):  # pragma: no cover
        if self._session is not None:
            await self._session.close()
            self._session = None

    async def request(self, method, endpoint, params=None, headers=None):
        base_url = self.config["base_url"].rstrip("/")
        url = base_url + "/api/index.php" + endpoint
        timeout = self.config.get("timeout", 30)
        payload = _build_payload(params)
        session = await self._get_session()

        kw = {"headers": headers or {}, "timeout": timeout}
        if method.upper() == "POST":
            kw["data"] = payload
            kw["headers"]["Content-Type"] = "application/x-www-form-urlencoded"
        else:
            kw["params"] = payload

        async with session.request(method, url, **kw) as resp:
            if resp.status >= 500:
                from .exceptions import NetworkError
                raise NetworkError("服务器错误 HTTP %d" % resp.status, resp.status)
            try:
                data = await resp.json(content_type=None)
            except ValueError:
                from .exceptions import ApiError
                text = await resp.text()
                raise ApiError("响应解析失败: %s" % text[:200], resp.status)
            code = data.get("code") if isinstance(data, dict) else None
            if code is not None and code not in (0, 200):
                from .exceptions import ApiError
                raise ApiError(data.get("message", "请求失败"), code, data.get("data"))
            return data


def make_timestamp() -> str:
    return str(int(time.time()))