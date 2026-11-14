package com.uic.sdk;

import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.util.*;
import java.util.stream.*;
import com.google.gson.*;

/**
 * 统一身份认证中心 · Java SDK v1.0.0
 * 应用管理 + SSO 接入（基于 REST API 封装）
 *
 * 覆盖：初始化 / 管理侧鉴权(Cookie) / 应用侧鉴权(AppSecret) /
 *       应用列表 / 创建 / 更新 / 启停 / 删除 / 通信测试 /
 *       SSO 授权跳转 / Ticket 换 Token / Token 换用户信息 / 单点退出
 *
 * 依赖：Gson（JSON 解析）
 * Maven: com.google.code.gson:gson:2.10.1
 */
public class UicSdk {

    private final String baseURL;
    private final int timeoutMs;
    private String sessionId;
    private String appId;
    private String appSecret;
    private final Logger logger;
    private final Gson gson = new Gson();

    public interface Logger {
        void debug(String msg, Map<String, Object> ctx);
        void info(String msg, Map<String, Object> ctx);
        void warn(String msg, Map<String, Object> ctx);
        void error(String msg, Map<String, Object> ctx);
    }

    private static final Logger NOOP_LOGGER = new Logger() {
        public void debug(String m, Map<String, Object> c) {}
        public void info(String m, Map<String, Object> c)  {}
        public void warn(String m, Map<String, Object> c)   {}
        public void error(String m, Map<String, Object> c)  {}
    };

    public static class Builder {
        private String baseURL = "http://127.0.0.5";
        private int timeoutMs = 10000;
        private Logger logger = NOOP_LOGGER;
        public Builder baseURL(String url) { this.baseURL = url; return this; }
        public Builder timeout(int ms)     { this.timeoutMs = ms; return this; }
        public Builder logger(Logger l)    { this.logger = l; return this; }
        public UicSdk build() { return new UicSdk(baseURL, timeoutMs, logger); }
    }

    private UicSdk(String baseURL, int timeoutMs, Logger logger) {
        this.baseURL = baseURL.replaceAll("/+$", "");
        this.timeoutMs = timeoutMs;
        this.logger = logger;
    }

    /** 设置管理侧 Cookie 会话 */
    public UicSdk setAdminSession(String sessionId) {
        this.sessionId = sessionId; return this;
    }
    /** 设置应用侧凭据 */
    public UicSdk setAppCredentials(String appId, String appSecret) {
        this.appId = appId; this.appSecret = appSecret; return this;
    }

    // ================= 管理接口：应用管理 =================

    /** 获取应用列表（RBAC: view:apps） @return List<App> */
    @SuppressWarnings("unchecked")
    public List<Map<String, Object>> appList() throws UicSdkException {
        return (List<Map<String, Object>>) request("GET", "admin", "app_list", null);
    }

    /** 创建应用（RBAC: manage:apps） */
    @SuppressWarnings("unchecked")
    public Map<String, Object> appCreate(String name, String description, String homeUrl,
                                          List<String> callbacks, boolean ssoEnabled) throws UicSdkException {
        Map<String, String> p = new LinkedHashMap<>();
        p.put("name", name);
        p.put("description", description);
        p.put("home_url", homeUrl);
        p.put("sso_enabled", ssoEnabled ? "1" : "0");
        for (int i = 0; i < callbacks.size(); i++) {
            p.put("callbacks[" + i + "]", callbacks.get(i));
        }
        return (Map<String, Object>) request("POST", "admin", "app_save", p);
    }

    /** 更新应用（RBAC: manage:apps） */
    @SuppressWarnings("unchecked")
    public Map<String, Object> appUpdate(int id, Map<String, Object> fields) throws UicSdkException {
        Map<String, String> p = new LinkedHashMap<>();
        p.put("id", String.valueOf(id));
        for (Map.Entry<String, Object> e : fields.entrySet()) {
            p.put(e.getKey(), String.valueOf(e.getValue()));
        }
        return (Map<String, Object>) request("POST", "admin", "app_save", p);
    }

    /** 重置 AppSecret（RBAC: manage:apps） */
    @SuppressWarnings("unchecked")
    public Map<String, Object> appResetSecret(int id) throws UicSdkException {
        Map<String, String> p = new LinkedHashMap<>();
        p.put("id", String.valueOf(id));
        return (Map<String, Object>) request("POST", "admin", "app_reset_secret", p);
    }

    /** 启用 / 禁用应用（RBAC: manage:apps） */
    public void appToggle(int id, boolean enabled) throws UicSdkException {
        Map<String, String> p = new LinkedHashMap<>();
        p.put("id", String.valueOf(id));
        p.put("status", enabled ? "1" : "0");
        request("POST", "admin", "app_toggle", p);
    }

    /** 删除应用（RBAC: manage:apps） */
    public void appDelete(int id) throws UicSdkException {
        Map<String, String> p = new LinkedHashMap<>();
        p.put("id", String.valueOf(id));
        request("POST", "admin", "app_delete", p);
    }

    /** 通信测试（RBAC: view:apps） */
    @SuppressWarnings("unchecked")
    public Map<String, Object> appTest(int id) throws UicSdkException {
        Map<String, String> p = new LinkedHashMap<>();
        p.put("id", String.valueOf(id));
        return (Map<String, Object>) request("POST", "admin", "app_test", p);
    }

    // ================= SSO 接口 =================

    /** 构建 SSO 授权跳转 URL */
    public String authorizeURL(String appId, String redirectUri) {
        return baseURL + "/api/index.php?m=sso&a=authorize&app_id=" + urlEnc(appId) + "&redirect=" + urlEnc(redirectUri);
    }

    /** ticket 换 token（服务端对接） */
    @SuppressWarnings("unchecked")
    public Map<String, Object> exchange(String ticket, String appId, String appSecret) throws UicSdkException {
        Map<String, String> p = new LinkedHashMap<>();
        p.put("ticket", ticket);
        p.put("app_id", coalesce(appId, this.appId));
        p.put("app_secret", coalesce(appSecret, this.appSecret));
        return (Map<String, Object>) request("POST", "sso", "exchange", p);
    }

    /** token 换用户信息 */
    @SuppressWarnings("unchecked")
    public Map<String, Object> userinfo(String token, String appId) throws UicSdkException {
        Map<String, String> p = new LinkedHashMap<>();
        p.put("token", token);
        p.put("app_id", coalesce(appId, this.appId));
        return (Map<String, Object>) request("POST", "sso", "userinfo", p);
    }

    /** 单点退出 */
    public void logout(String token, String appId) throws UicSdkException {
        Map<String, String> p = new LinkedHashMap<>();
        p.put("token", token);
        p.put("app_id", coalesce(appId, this.appId));
        request("POST", "sso", "logout", p);
    }

    /** 连通性测试 */
    @SuppressWarnings("unchecked")
    public Map<String, Object> testConnect(String appId, String appSecret) throws UicSdkException {
        Map<String, String> p = new LinkedHashMap<>();
        p.put("app_id", coalesce(appId, this.appId));
        p.put("app_secret", coalesce(appSecret, this.appSecret));
        return (Map<String, Object>) request("POST", "sso", "test", p);
    }

    // ================= HTTP 底层 =================

    @SuppressWarnings("unchecked")
    private Object request(String method, String m, String a, Map<String, String> params) throws UicSdkException {
        String url = baseURL + "/api/index.php?m=" + urlEnc(m) + "&a=" + urlEnc(a);
        String payload = "";
        if ("POST".equals(method) && params != null) {
            payload = params.entrySet().stream()
                .map(e -> urlEnc(e.getKey()) + "=" + urlEnc(e.getValue()))
                .collect(Collectors.joining("&"));
        }

        HttpURLConnection conn = null;
        try {
            conn = (HttpURLConnection) new URL(url).openConnection();
            conn.setRequestMethod(method);
            conn.setConnectTimeout(timeoutMs / 2);
            conn.setReadTimeout(timeoutMs);
            conn.setDoInput(true);
            conn.setInstanceFollowRedirects(true);
            conn.setRequestProperty("User-Agent", "UicSdk-Java/1.0.0");

            if (sessionId != null && !sessionId.isEmpty()) {
                conn.setRequestProperty("Cookie", "PHPSESSID=" + sessionId);
            }
            if (appId != null && appSecret != null && "sso".equals(m)) {
                String auth = Base64.getEncoder().encodeToString((appId + ":" + appSecret).getBytes(StandardCharsets.UTF_8));
                conn.setRequestProperty("Authorization", "Basic " + auth);
            }

            if ("POST".equals(method) && !payload.isEmpty()) {
                conn.setDoOutput(true);
                conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");
                try (OutputStream os = conn.getOutputStream()) {
                    os.write(payload.getBytes(StandardCharsets.UTF_8));
                }
            }

            int code = conn.getResponseCode();
            InputStream in = (code >= 200 && code < 300) ? conn.getInputStream() : conn.getErrorStream();
            if (in == null) in = conn.getInputStream();

            String body;
            try (BufferedReader br = new BufferedReader(new InputStreamReader(in, StandardCharsets.UTF_8))) {
                body = br.lines().collect(Collectors.joining("\n"));
            }

            logger.debug("API 请求", Map.of("url", url, "method", method, "http", code));

            JsonObject json = JsonParser.parseString(body).getAsJsonObject();
            boolean ok = json.has("code") && json.get("code").getAsInt() == 0;
            if (!ok) {
                String msg = json.has("message") ? json.get("message").getAsString() : "操作失败";
                throw new UicSdkException(msg, code, json);
            }
            if (json.has("data") && !json.get("data").isJsonNull()) {
                return gson.fromJson(json.get("data"), Object.class);
            }
            return null;

        } catch (UicSdkException e) {
            throw e;
        } catch (Exception e) {
            int code = 0;
            if (conn != null) {
                try { code = conn.getResponseCode(); } catch (IOException ignored) {}
            }
            throw new UicSdkException("网络请求失败: " + e.getMessage(), code, null, e);
        } finally {
            if (conn != null) conn.disconnect();
        }
    }

    private String urlEnc(String s) {
        if (s == null) return "";
        try { return URLEncoder.encode(s, StandardCharsets.UTF_8.name()); }
        catch (UnsupportedEncodingException e) { return s; }
    }

    private String coalesce(String a, String b) {
        return (a != null && !a.isEmpty()) ? a : b;
    }

    // ================= 异常类 =================
    public static class UicSdkException extends Exception {
        public final int httpCode;
        public final JsonObject response;
        public UicSdkException(String message, int httpCode, JsonObject response) {
            super(message); this.httpCode = httpCode; this.response = response;
        }
        public UicSdkException(String message, int httpCode, JsonObject response, Throwable cause) {
            super(message, cause); this.httpCode = httpCode; this.response = response;
        }
        public UicSdkException(String message, int httpCode) {
            this(message, httpCode, null);
        }
    }

    // ================= Demo =================
    public static void main(String[] args) {
        System.out.println("=== UicSdk Java Demo ===");

        Logger console = new Logger() {
            public void debug(String m, Map<String, Object> c) { System.out.println("[DEBUG] " + m + " " + c); }
            public void info(String m, Map<String, Object> c)  { System.out.println("[INFO] " + m); }
            public void warn(String m, Map<String, Object> c)   { System.out.println("[WARN] " + m); }
            public void error(String m, Map<String, Object> c)  { System.out.println("[ERROR] " + m); }
        };

        UicSdk sdk = new UicSdk.Builder()
            .baseURL("http://127.0.0.5")
            .timeout(10000)
            .logger(console)
            .build();

        // 1) 应用侧连通性测试
        System.out.println("\n--- 应用侧连通性测试 ---");
        sdk.setAppCredentials("your_app_id", "your_app_secret");
        try {
            Map<String, Object> r = sdk.testConnect("", "");
            System.out.println("结果: " + r);
        } catch (UicSdkException e) {
            System.out.println("失败: " + e.getMessage() + " (HTTP " + e.httpCode + ")");
        }

        // 2) SSO 授权跳转
        System.out.println("\n--- SSO 授权跳转 URL ---");
        System.out.println(sdk.authorizeURL("your_app_id", "http://your-app.com/callback"));

        System.out.println("\nDemo 完成。");
    }
}
