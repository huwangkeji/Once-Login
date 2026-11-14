# UIC SDK for Python

统一身份认证中心（UCenter）Python SDK，支持**同步**与**异步（asyncio）**两种调用方式。

## 安装

```bash
# 同步调用（默认，依赖 requests）
pip install uic-sdk

# 异步调用（可选）
pip install uic-sdk[async]
```

## 快速开始

```python
from uic_sdk import Client

client = Client(
    base_url="http://127.0.0.5",
    app_id="your_app_id",
    app_secret="your_app_secret",
)

# 1. 构建 SSO 授权跳转 URL
auth_url = client.auth.get_authorize_url(
    callback="http://your-app.local/callback",
    state="random_state_string",
)

# 2. 回调中换取 Token
tokens = client.auth.exchange_ticket(ticket="ticket_from_callback")

# 3. 获取用户信息
user = client.auth.get_user_info(token=tokens["token"])
print(f"欢迎，{user['nickname']}")
```

## 异步调用

```python
import asyncio
from uic_sdk import AsyncClient

async def main():
    async with AsyncClient(
        base_url="http://127.0.0.5",
        app_id="your_app_id",
        app_secret="your_app_secret",
    ) as client:
        user = await client.auth.get_user_info(token="...")
        print(user)

asyncio.run(main())
```

## 核心能力

| 客户端 | 方法 | 说明 |
| --- | --- | --- |
| `client.auth` | `get_authorize_url()` | 构建 SSO 授权 URL |
| `client.auth` | `exchange_ticket()` | ticket 换 access_token |
| `client.auth` | `get_user_info()` | token 换用户信息 |
| `client.auth` | `logout()` | 单点退出 |
| `client.auth` | `test_connect()` | 连通性测试 |
| `client.user` | `register() / login()` | 注册 / 登录 |
| `client.user` | `get_info() / update_profile()` | 个人信息管理 |
| `client.user` | `change_password() / reset_password()` | 密码管理 |
| `client.user` | `check_unique()` | 唯一性检查 |
| `client.captcha` | `issue() / verify()` | 图形验证码 |

## 异常处理

```python
from uic_sdk import ApiError, NetworkError

try:
    client.auth.exchange_ticket("bad_ticket")
except ApiError as e:
    print(f"业务错误 code={e.code}: {e.message}")
except NetworkError as e:
    print(f"网络错误: {e.message}")
```

## 开发

```bash
pip install -e ".[dev]"
python -m pytest tests/ -v
```