/**
 * 统一身份认证中心 · 通行密钥（WebAuthn / Passkey）前端模块
 *
 * 用法：
 *   <script src="/assets/passkey.js"></script>
 *   Passkey.isSupported()                          // → boolean
 *   Passkey.register({ label: 'MacBook' })         // 注册新通行密钥（需已登录）
 *   Passkey.auth({ remember: true })               // 通行密钥登录
 *   Passkey.conditionalMediation()                 // 条件 UI 自动填充探测
 *
 * 依赖：页面已引入 App.req（portal 布局自带）
 */
(function (global) {
  'use strict';

  // ---- base64url ↔ ArrayBuffer ----

  function b64uToBuf(b64u) {
    var s = b64u.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }

  function bufToB64u(buf) {
    var arr = new Uint8Array(buf);
    var s = '';
    for (var i = 0; i < arr.length; i++) s += String.fromCharCode(arr[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  // ---- 选项预处理：将 base64url 字段转为 ArrayBuffer ----

  function prepareCreateOptions(opts) {
    var pk = opts.publicKey;
    pk.challenge = b64uToBuf(pk.challenge);
    if (pk.user && pk.user.id) pk.user.id = b64uToBuf(pk.user.id);
    if (pk.excludeCredentials) {
      pk.excludeCredentials.forEach(function (c) { c.id = b64uToBuf(c.id); });
    }
    return pk;
  }

  function prepareGetOptions(opts) {
    var pk = opts.publicKey;
    pk.challenge = b64uToBuf(pk.challenge);
    if (pk.allowCredentials) {
      pk.allowCredentials.forEach(function (c) { c.id = b64uToBuf(c.id); });
    }
    return pk;
  }

  // ---- 响应序列化：将 ArrayBuffer 转为 base64url ----

  function serializeCreate(cred) {
    return {
      client_data:  bufToB64u(cred.response.clientDataJSON),
      attestation:  bufToB64u(cred.response.attestationObject),
    };
  }

  function serializeGet(cred) {
    return {
      credential_id:      bufToB64u(cred.rawId),
      client_data:        bufToB64u(cred.response.clientDataJSON),
      authenticator_data: bufToB64u(cred.response.authenticatorData),
      signature:          bufToB64u(cred.response.signature),
      user_handle:        cred.response.userHandle ? bufToB64u(cred.response.userHandle) : '',
    };
  }

  // ---- 访问域可用性（浏览器对 WebAuthn 的硬约束） ----
  //
  //  ① RP ID 必须是「合法域名」：IP 字面量（127.0.0.1 / 192.168.x.x / [::1]）一律非法，
  //     浏览器会直接抛 SecurityError 并在界面提示 “This is an invalid domain.”；
  //  ② 页面必须处于安全上下文：https，或 http://localhost。
  //  这里做前置判断，让界面提前降级并给出中文提示，而不是把英文原报错丢给用户。

  var DOMAIN_MSG = {
    ip:       '当前通过 IP 地址访问，浏览器不允许在 IP 上使用通行密钥；请改用 http://localhost 或已配置 HTTPS 的域名访问。',
    insecure: '当前页面不是 HTTPS 安全环境，浏览器会拒绝通行密钥操作；请使用 https，或本机用 http://localhost 调试。',
    host:     '当前主机名不是浏览器认可的合法域名，无法创建通行密钥；请使用 localhost 或正式域名访问。',
  };

  /** 当前访问域的问题码（null = 可用） */
  function domainIssue() {
    var h = location.hostname || '';
    if (h.charAt(0) === '[' || h.indexOf(':') >= 0) return 'ip';                       // IPv6
    if (/^\d{1,3}(\.\d{1,3}){3}$/.test(h)) return 'ip';                               // IPv4
    if (h !== 'localhost') {
      if (typeof window.isSecureContext === 'boolean' && !window.isSecureContext) return 'insecure';
      if (h.indexOf('.') === -1) return 'host';
    }
    return null;
  }

  /** 域不可用时返回中文 Error，否则 null */
  function domainError() {
    var c = domainIssue();
    return c ? new Error(DOMAIN_MSG[c] || '当前访问地址无法使用通行密钥。') : null;
  }

  /** 把浏览器抛出的底层错误翻成中文，避免把 "This is an invalid domain." 直接甩给用户 */
  function translateError(err) {
    var name = (err && err.name) || '';
    var msg  = (err && err.message) || '';
    var c    = domainIssue();
    if (name === 'SecurityError' || /invalid domain|is not a valid domain/i.test(msg)) {
      return new Error(c ? DOMAIN_MSG[c] : ('浏览器拒绝了该操作：' + (msg || '当前地址不允许使用通行密钥')));
    }
    if (name === 'NotAllowedError' || name === 'AbortError') {
      return new Error('操作已取消或超时（未完成设备验证）');
    }
    if (name === 'InvalidStateError') {
      return new Error('该设备上已存在此网站的通行密钥，请勿重复添加');
    }
    return err;
  }

  // ---- API 封装 ----

  function api(m, a, data) {
    return App.req(m, a, data || {}, { okMsg: false });
  }

  // ---- 公开 API ----

  var Passkey = {

    /** 浏览器与当前访问域是否都支持 WebAuthn */
    isSupported: function () {
      if (domainIssue()) return false;   // 域不可用 → 视为不支持，直接降级
      return typeof window.PublicKeyCredential !== 'undefined' &&
             typeof window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable === 'function';
    },

    /** 当前访问域的问题码（null = 可用）：ip / insecure / host */
    domainIssue: domainIssue,

    /** 当前访问域问题的中文说明（可用时返回空串） */
    domainMessage: function () {
      var c = domainIssue();
      return c ? (DOMAIN_MSG[c] || '当前访问地址无法使用通行密钥。') : '';
    },

    /** 平台认证器（如 Touch ID / Windows Hello）是否可用 */
    isPlatformAvailable: function () {
      if (!this.isSupported()) return Promise.resolve(false);
      return PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    },

    /**
     * 注册新通行密钥（需已登录）
     * @param {object} opt { label: string }
     * @returns {Promise} resolve({code:0}) | reject(Error)
     */
    register: function (opt) {
      opt = opt || {};
      var de = domainError();
      if (de) return Promise.reject(de);
      return api('passkey', 'register_options').then(function (res) {
        if (res.code !== 0) throw new Error(res.message || '获取注册选项失败');
        var pk = prepareCreateOptions(res.data.options);
        return navigator.credentials.create({ publicKey: pk }).then(function (cred) {
          if (!cred) throw new Error('操作已取消');
          var s = serializeCreate(cred);
          return api('passkey', 'register_finish', {
            tx:          res.data.tx,
            client_data: s.client_data,
            attestation: s.attestation,
            label:       opt.label || '',
          });
        }).catch(function (err) { throw translateError(err); });
      });
    },

    /**
     * 通行密钥登录
     * @param {object} opt { remember: bool, app_id: string }
     * @returns {Promise}
     */
    auth: function (opt) {
      opt = opt || {};
      var de = domainError();
      if (de) return Promise.reject(de);
      return api('passkey', 'auth_options').then(function (res) {
        if (res.code !== 0) throw new Error(res.message || '获取认证选项失败');
        var pk = prepareGetOptions(res.data.options);
        return navigator.credentials.get({ publicKey: pk }).then(function (cred) {
          if (!cred) throw new Error('操作已取消');
          var s = serializeGet(cred);
          return api('passkey', 'auth_finish', {
            tx:                res.data.tx,
            credential_id:     s.credential_id,
            client_data:       s.client_data,
            authenticator_data: s.authenticator_data,
            signature:         s.signature,
            remember:          opt.remember ? '1' : '0',
            app_id:            opt.app_id || '',
          });
        }).catch(function (err) { throw translateError(err); });
      });
    },

    /**
     * 条件 UI 自动填充（autocomplete="webauthn"）
     * 在页面加载时调用，浏览器会在用户聚焦用户名输入框时自动弹出通行密钥选择
     * @param {object} opt { remember: bool, app_id: string, onSuccess: function }
     * @returns {Promise|null} 不支持条件 UI 时返回 null
     */
    conditionalMediation: function (opt) {
      opt = opt || {};
      if (!this.isSupported()) return null;
      if (typeof PublicKeyCredential.isConditionalMediationAvailable !== 'function') return null;

      return PublicKeyCredential.isConditionalMediationAvailable().then(function (available) {
        if (!available) return null;

        return api('passkey', 'auth_options').then(function (res) {
          if (res.code !== 0) return null;
          var pk = prepareGetOptions(res.data.options);
          pk.mediation = 'conditional';

          return navigator.credentials.get({ publicKey: pk, mediation: 'conditional' }).then(function (cred) {
            if (!cred) return null;
            var s = serializeGet(cred);
            return api('passkey', 'auth_finish', {
              tx:                res.data.tx,
              credential_id:     s.credential_id,
              client_data:       s.client_data,
              authenticator_data: s.authenticator_data,
              signature:         s.signature,
              remember:          opt.remember ? '1' : '0',
              app_id:            opt.app_id || '',
            }).then(function (loginRes) {
              if (loginRes.code === 0 && opt.onSuccess) opt.onSuccess(loginRes);
              return loginRes;
            });
          });
        });
      }).catch(function () {
        // 条件 UI 的 get() 在用户改用其他登录方式、或发起新的 WebAuthn 请求时会被浏览器中止，
        // 抛出 NotAllowedError（正常现象）。此处吞掉，避免未处理的 Promise 拒绝刷入控制台。
        return null;
      });
    },

    /** 列出当前用户的通行密钥 */
    list: function () {
      return api('passkey', 'list');
    },

    /** 重命名 */
    rename: function (id, label) {
      return api('passkey', 'rename', { id: id, label: label });
    },

    /** 删除 */
    delete: function (id) {
      return api('passkey', 'delete', { id: id });
    },

    /** 查询功能状态 */
    status: function () {
      return api('passkey', 'status');
    },

    // 暴露工具函数供外部使用
    _b64uToBuf: b64uToBuf,
    _bufToB64u: bufToB64u,
  };

  global.Passkey = Passkey;
})(window);
