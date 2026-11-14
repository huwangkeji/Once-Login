# -*- coding: utf-8 -*-
"""SDK 客户端统一入口（同步 + 异步）."""

from .async_clients import AsyncAuthClient, AsyncCaptchaClient, AsyncUserClient
from .auth import AuthClient
from .captcha import CaptchaClient
from .http import AsyncTransport, SyncTransport
from .user import UserClient

DEFAULTS = {
    "base_url": "http://127.0.0.5",
    "app_id": "",
    "app_secret": "",
    "timeout": 30,
}


class Client:
    """同步客户端.

    使用示例::

        from uic_sdk import Client
        client = Client(base_url="http://127.0.0.5", app_id="xxx", app_secret="yyy")
        url = client.auth.get_authorize_url("http://your-app.local/callback")
    """

    def __init__(self, **options):
        self._config = dict(DEFAULTS)
        self._config.update({k: v for k, v in options.items() if v is not None})
        self._http = SyncTransport(self._config)
        self.auth = AuthClient(self._config, self._http)
        self.user = UserClient(self._config, self._http)
        self.captcha = CaptchaClient(self._config, self._http)

    @property
    def config(self):
        return dict(self._config)


class AsyncClient:
    """异步客户端（基于 aiohttp，使用前 pip install uic-sdk[async]）.

    使用示例::

        import asyncio
        from uic_sdk import AsyncClient

        async def main():
            client = AsyncClient(base_url="http://127.0.0.5", app_id="xxx")
            user = await client.auth.get_user_info(token)
            await client.close()

        asyncio.run(main())
    """

    def __init__(self, **options):
        self._config = dict(DEFAULTS)
        self._config.update({k: v for k, v in options.items() if v is not None})
        self._http = AsyncTransport(self._config)
        self.auth = AsyncAuthClient(self._config, self._http)
        self.user = AsyncUserClient(self._config, self._http)
        self.captcha = AsyncCaptchaClient(self._config, self._http)

    @property
    def config(self):
        return dict(self._config)

    async def close(self):
        """关闭底层连接池."""
        await self._http.close()

    async def __aenter__(self):
        return self

    async def __aexit__(self, *exc):
        await self.close()