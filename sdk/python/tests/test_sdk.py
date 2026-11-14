# -*- coding: utf-8 -*-
"""uic-sdk Python 单元测试（pytest）."""

import asyncio
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pytest  # noqa: E402

from uic_sdk import AsyncClient, Client  # noqa: E402
from uic_sdk.exceptions import ApiError  # noqa: E402


def async_run(coro):
    """同步上下文中运行一个协程并返回结果（测试辅助）."""
    return asyncio.new_event_loop().run_until_complete(coro)


class FakeTransport:
    """可编程的假传输层（模拟 http.SyncTransport 的响应校验行为）."""

    def __init__(self, responses=None):
        self.responses = list(responses or [])
        self.calls = []

    def request(self, method, endpoint, params=None, headers=None):
        self.calls.append((method, endpoint, params or {}))
        if not self.responses:
            return {"code": 0, "data": {}, "message": "ok"}
        resp = self.responses.pop(0)
        code = resp.get("code") if isinstance(resp, dict) else None
        if code is not None and code not in (0, 200):
            raise ApiError(resp.get("message", "请求失败"), code, resp.get("data"))
        return resp


def make_sync_client(transport=None):
    c = object.__new__(Client)
    from uic_sdk import auth as _auth_mod
    from uic_sdk import captcha as _captcha_mod
    from uic_sdk import user as _user_mod
    c._config = {"base_url": "http://127.0.0.5", "app_id": "a1", "app_secret": "s1", "timeout": 30}
    c._http = transport or FakeTransport()
    c.auth = _auth_mod.AuthClient(c._config, c._http)
    c.user = _user_mod.UserClient(c._config, c._http)
    c.captcha = _captcha_mod.CaptchaClient(c._config, c._http)
    return c


def test_sync_client_defaults():
    client = Client()
    assert client.config["base_url"] == "http://127.0.0.5"
    assert client.config["timeout"] == 30


def test_sync_client_custom_options():
    client = Client(base_url="http://127.0.0.1:8080/", app_id="x")
    assert client.config["base_url"] == "http://127.0.0.1:8080/"
    assert client.config["app_id"] == "x"


def test_authorize_url_built():
    transport = FakeTransport()
    client = make_sync_client(transport)
    url = client.auth.get_authorize_url("http://app.local/cb", state="abc")
    assert "c=sso&a=authorize" in url
    assert "app_id=a1" in url
    assert "redirect=" in url and "http%3A%2F%2Fapp.local%2Fcb" in url
    assert "state=abc" in url


def test_exchange_ticket_ok():
    transport = FakeTransport([{"code": 0, "data": {"token": "tk1", "expires_in": 7200}}])
    client = make_sync_client(transport)
    tokens = client.auth.exchange_ticket("t-1")
    assert tokens["token"] == "tk1"
    assert transport.calls[0][1] == "?m=sso&a=exchange"
    assert transport.calls[0][2]["app_secret"] == "s1"


def test_exchange_ticket_missing_token_raises():
    transport = FakeTransport([{"code": 0, "data": {}}])
    client = make_sync_client(transport)
    with pytest.raises(Exception) as exc:
        client.auth.exchange_ticket("t-1")
    assert "token" in str(exc.value).lower()


def test_get_user_info_requires_token():
    client = make_sync_client()
    with pytest.raises(Exception):
        client.auth.get_user_info()


def test_user_login_sends_params():
    transport = FakeTransport([{"code": 0, "data": {"uid": 1}}])
    client = make_sync_client(transport)
    data = client.user.login("alice", "secret123", remember=True)
    assert data["uid"] == 1
    method, endpoint, params = transport.calls[0]
    assert method == "POST" and endpoint == "?m=user&a=login"
    assert params["account"] == "alice" and params["remember"] == "1"


def test_api_error_raised():
    transport = FakeTransport([{"code": 1001, "message": "账号不存在", "data": None}])
    client = make_sync_client(transport)
    with pytest.raises(ApiError) as exc:
        client.user.login("nobody", "x")
    assert exc.value.code == 1001
    assert "账号不存在" in exc.value.message


def test_captcha_issue():
    transport = FakeTransport([{"code": 0, "data": {"captcha_id": "c1", "image_base64": "..."}}])
    client = make_sync_client(transport)
    data = client.captcha.issue()
    assert data["captcha_id"] == "c1"


def test_check_unique_true():
    transport = FakeTransport([{"code": 0, "data": True}])
    client = make_sync_client(transport)
    assert client.user.check_unique("username", "alice") is True


def test_check_unique_false():
    transport = FakeTransport([{"code": 0, "data": False}])
    client = make_sync_client(transport)
    assert client.user.check_unique("username", "taken") is False


def test_async_client_exports():
    client = AsyncClient(base_url="http://127.0.0.1")
    assert hasattr(client, "auth") and hasattr(client, "user") and hasattr(client, "captcha")
    assert client.config["base_url"] == "http://127.0.0.1"


class FakeAsyncTransport:
    """模拟 http.AsyncTransport 的协程响应校验行为."""

    def __init__(self, responses=None):
        self.responses = list(responses or [])
        self.calls = []

    async def request(self, method, endpoint, params=None, headers=None):
        self.calls.append((method, endpoint, params or {}))
        if not self.responses:
            return {"code": 0, "data": {}, "message": "ok"}
        resp = self.responses.pop(0)
        code = resp.get("code") if isinstance(resp, dict) else None
        if code is not None and code not in (0, 200):
            raise ApiError(resp.get("message", "请求失败"), code, resp.get("data"))
        return resp


def make_async_client(transport=None):
    c = object.__new__(AsyncClient)
    from uic_sdk import async_clients as _async_mod
    c._config = {"base_url": "http://127.0.0.5", "app_id": "a1", "app_secret": "s1", "timeout": 30}
    c._http = transport or FakeAsyncTransport()
    c.auth = _async_mod.AsyncAuthClient(c._config, c._http)
    c.user = _async_mod.AsyncUserClient(c._config, c._http)
    c.captcha = _async_mod.AsyncCaptchaClient(c._config, c._http)
    return c


def test_async_exchange_token_awaited():
    transport = FakeAsyncTransport([{"code": 0, "data": {"token": "tk1", "expires_in": 7200}}])
    client = make_async_client(transport)
    tokens = async_run(client.auth.exchange_ticket("t-1"))
    assert tokens["token"] == "tk1"
    assert transport.calls[0][1] == "?m=sso&a=exchange"


def test_async_user_login_awaited():
    transport = FakeAsyncTransport([{"code": 0, "data": {"uid": 1}}])
    client = make_async_client(transport)
    data = async_run(client.user.login("alice", "secret123", remember=True))
    assert data["uid"] == 1
    method, endpoint, params = transport.calls[0]
    assert method == "POST" and endpoint == "?m=user&a=login"
    assert params["account"] == "alice" and params["remember"] == "1"


def test_async_captcha_issue_awaited():
    transport = FakeAsyncTransport([{"code": 0, "data": {"captcha_id": "c1", "image_base64": "..."}}])
    client = make_async_client(transport)
    data = async_run(client.captcha.issue())
    assert data["captcha_id"] == "c1"


def test_async_api_error_awaited():
    transport = FakeAsyncTransport([{"code": 1001, "message": "账号不存在", "data": None}])
    client = make_async_client(transport)
    with pytest.raises(ApiError) as exc:
        async_run(client.user.login("nobody", "x"))
    assert exc.value.code == 1001


def test_async_get_user_info_requires_token():
    client = make_async_client()
    with pytest.raises(Exception):
        async_run(client.auth.get_user_info())


def test_setup_py_readme_exists():
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    assert os.path.isfile(os.path.join(root, "setup.py"))
    assert os.path.isfile(os.path.join(root, "README.md"))