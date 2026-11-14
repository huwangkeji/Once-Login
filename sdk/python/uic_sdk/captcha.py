# -*- coding: utf-8 -*-
"""图形验证码客户端."""


class CaptchaClient:
    """图形验证码客户端."""

    def __init__(self, config, transport):
        self._config = config
        self._http = transport

    def issue(self):
        """获取图形验证码.

        :return: {captcha_id, image_base64, expires_in}
        """
        res = self._http.request("POST", "?m=captcha&a=issue")
        return res.get("data") or {}

    def verify(self, captcha_id, code):
        """校验验证码."""
        res = self._http.request("POST", "?m=captcha&a=verify", {
            "id": captcha_id,
            "code": code,
        })
        return res.get("data") is True