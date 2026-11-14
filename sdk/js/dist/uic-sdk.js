/*! UIC SDK for JavaScript v1.0.0 - browser bundle */
(function (global, factory) {
  if (typeof module === 'object' && typeof module.exports === 'object') {
    module.exports = factory();
  } else {
    global.UIC = factory();
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  class ApiError extends Error {
    constructor(message, code, data) {
      super(message);
      this.name = 'ApiError';
      this.code = code || 0;
      this.data = data;
    }
  }

  class NetworkError extends Error {
    constructor(message, httpCode) {
      super(message);
      this.name = 'NetworkError';
      this.httpCode = httpCode || 0;
    }
  }

  function buildParams(params) {
    const sp = new URLSearchParams();
    Object.keys(params || {}).forEach((k) => {
      const v = params[k];
      if (v === undefined || v === null || v === '') return;
      if (Array.isArray(v)) {
        v.forEach((item) => sp.append(k + '[]', String(item)));
      } else if (typeof v === 'object') {
        sp.append(k, JSON.stringify(v));
      } else {
        sp.append(k, String(v));
      }
    });
    return sp;
  }

  class Request {
    constructor(config) {
      this.config = config;
    }
    async send(method, endpoint, params = {}, headers = {}) {
      const baseUrl = String(this.config.baseUrl || 'http://127.0.0.5').replace(/\/+$/, '');
      let url = baseUrl + '/api/index.php' + endpoint;
      const opts = {
        method,
        headers: Object.assign({ Accept: 'application/json', 'X-App-Id': this.config.appId || '' }, headers),
        signal: AbortSignal.timeout((this.config.timeout || 30) * 1000),
      };
      if (method.toUpperCase() === 'POST') {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        opts.body = buildParams(params).toString();
      } else {
        const qs = buildParams(params).toString();
        if (qs) url += (url.indexOf('?') !== -1 ? '&' : '?') + qs;
      }
      let resp;
      try {
        resp = await fetch(url, opts);
      } catch (e) {
        throw new NetworkError('网络请求失败: ' + e.message);
      }
      if (resp.status >= 500) throw new NetworkError('服务器错误 HTTP ' + resp.status, resp.status);
      let data;
      try {
        data = await resp.json();
      } catch (e) {
        throw new ApiError('响应解析失败', resp.status);
      }
      if (data && typeof data.code !== 'undefined' && data.code !== 0 && data.code !== 200) {
        throw new ApiError(data.message || '请求失败', data.code, data.data);
      }
      return data || {};
    }
  }

  class AuthClient {
    constructor(config, http) {
      this.config = config;
      this.http = http;
    }
    getAuthorizeUrl(callback, state = '') {
      const baseUrl = String(this.config.baseUrl || 'http://127.0.0.5').replace(/\/+$/, '');
      const p = new URLSearchParams({ app_id: this.config.appId || '', redirect: callback });
      if (state) p.set('state', state);
      return baseUrl + '/index.php?c=sso&a=authorize&' + p.toString();
    }
    async exchangeToken(ticket) {
      return this._post('?m=sso&a=exchange', { ticket, app_id: this.config.appId || '', app_secret: this.config.appSecret || '' });
    }
    async getUserInfo(token) {
      return this._post('?m=sso&a=userinfo', { token, app_id: this.config.appId || '' });
    }
    async logout(token) {
      return this._post('?m=sso&a=logout', { token, app_id: this.config.appId || '' });
    }
    async testConnect() {
      const res = await this._post('?m=sso&a=test', { app_id: this.config.appId || '', app_secret: this.config.appSecret || '' });
      return res.code === 0;
    }
    async _post(endpoint, params) {
      const res = await this.http.send('POST', endpoint, params);
      return res.data || {};
    }
  }

  class UserClient {
    constructor(config, http) {
      this.config = config;
      this.http = http;
    }
    async register(data, autoLogin = true) {
      return this._post('?m=user&a=register', Object.assign({}, data, { auto_login: autoLogin ? '1' : '0' }));
    }
    async login(account, password, remember = false) {
      return this._post('?m=user&a=login', { account, password, remember: remember ? '1' : '0' });
    }
    async getInfo() {
      return this._post('?m=user&a=info', {});
    }
    async changePassword(oldPassword, newPassword, confirm) {
      return this._post('?m=user&a=password', { old_password: oldPassword, new_password: newPassword, confirm });
    }
    async updateProfile(data) {
      return this._post('?m=user&a=profile', data);
    }
    async checkUnique(field, value) {
      const res = await this._post('?m=user&a=check', { field, value });
      return res === true;
    }
    async _post(endpoint, params) {
      const res = await this.http.send('POST', endpoint, params);
      return res.data || {};
    }
  }

  class CaptchaClient {
    constructor(config, http) {
      this.config = config;
      this.http = http;
    }
    async getCaptcha() {
      return this._post('?m=captcha&a=issue', {});
    }
    async verify(code, id) {
      const res = await this._post('?m=captcha&a=verify', { code, id });
      return res === true;
    }
    async _post(endpoint, params) {
      const res = await this.http.send('POST', endpoint, params);
      return res.data || {};
    }
  }

  class Client {
    constructor(options = {}) {
      this.config = Object.assign({ baseUrl: 'http://127.0.0.5', appId: '', appSecret: '', timeout: 30 }, options);
      this.http = new Request(this.config);
      this.auth = new AuthClient(this.config, this.http);
      this.user = new UserClient(this.config, this.http);
      this.captcha = new CaptchaClient(this.config, this.http);
    }
  }

  return { Client, AuthClient, UserClient, CaptchaClient, Request, ApiError, NetworkError };
});