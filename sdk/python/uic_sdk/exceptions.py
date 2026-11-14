# -*- coding: utf-8 -*-
"""SDK 异常定义."""


class UicError(Exception):
    """SDK 基础异常."""

    def __init__(self, message, code=0, data=None):
        super().__init__(message)
        self.message = message
        self.code = code
        self.data = data


class ApiError(UicError):
    """API 业务错误（响应 code != 0）. """


class AuthError(UicError):
    """认证相关错误."""


class NetworkError(UicError):
    """网络层错误."""

    def __init__(self, message, http_code=0):
        super().__init__(message, code=http_code)
        self.http_code = http_code