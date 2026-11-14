# -*- coding: utf-8 -*-
"""统一身份认证中心(UCenter) Python SDK.

使用示例::

    from uic_sdk import Client

    client = Client(base_url="http://127.0.0.5", app_id="xxx", app_secret="yyy")
    auth_url = client.auth.get_authorize_url(callback="http://your-app.local/callback")
"""

from .client import AsyncClient, Client
from .exceptions import ApiError, AuthError, NetworkError, UicError

__version__ = "1.0.0"
__all__ = [
    "Client",
    "AsyncClient",
    "UicError",
    "ApiError",
    "AuthError",
    "NetworkError",
    "__version__",
]