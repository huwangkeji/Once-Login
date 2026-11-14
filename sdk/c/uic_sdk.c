#include "uic_sdk.h"
#include <curl/curl.h>
#include <cjson/cJSON.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include <time.h>

/* ========== 内部结构 ========== */
struct uic_client {
    char *base_url;
    int timeout;
    char *session_id;
    char *app_id;
    char *app_secret;
    uic_log_fn logger;
    CURL *curl;
};

struct membuf {
    char *data;
    size_t size;
    size_t cap;
};

static size_t write_cb(void *contents, size_t size, size_t nmemb, void *userp) {
    size_t total = size * nmemb;
    struct membuf *buf = (struct membuf *)userp;
    if (buf->size + total + 1 > buf->cap) {
        size_t newcap = (buf->cap == 0) ? 1024 : buf->cap * 2;
        while (newcap < buf->size + total + 1) newcap *= 2;
        char *nd = (char *)realloc(buf->data, newcap);
        if (!nd) return 0;
        buf->data = nd;
        buf->cap = newcap;
    }
    memcpy(buf->data + buf->size, contents, total);
    buf->size += total;
    buf->data[buf->size] = '\0';
    return total;
}

static void log_it(uic_client_t *c, const char *lvl, const char *msg, const char *ctx) {
    if (c && c->logger) c->logger(lvl, msg, ctx ? ctx : "{}");
}

static char* str_dup(const char *s) {
    if (!s) return NULL;
    size_t n = strlen(s) + 1;
    char *d = (char *)malloc(n);
    if (d) memcpy(d, s, n);
    return d;
}

static void append_form(char **out, size_t *len, size_t *cap, const char *key, const char *val) {
    char buf[4096];
    if (key && val) {
        int n = snprintf(buf, sizeof(buf), "%s=%s&", key, val);
        if (*len + n + 1 > *cap) {
            size_t nc = (*cap == 0) ? 512 : *cap * 2;
            while (nc < *len + n + 1) nc *= 2;
            char *nd = (char *)realloc(*out, nc);
            if (!nd) return;
            *out = nd; *cap = nc;
        }
        memcpy(*out + *len, buf, n);
        *len += n;
        (*out)[*len] = '\0';
    }
}

static int do_request(uic_client_t *c, const char *method,
                      const char *module, const char *action,
                      const char *body,
                      char **out_json, long *http_code) {
    if (!c || !module || !action) return UIC_ERR_PARAM;

    char url[2048];
    snprintf(url, sizeof(url), "%s/api/index.php?m=%s&a=%s",
             c->base_url, module, action);

    struct membuf buf = {NULL, 0, 0};
    curl_easy_reset(c->curl);
    curl_easy_setopt(c->curl, CURLOPT_URL, url);
    curl_easy_setopt(c->curl, CURLOPT_WRITEFUNCTION, write_cb);
    curl_easy_setopt(c->curl, CURLOPT_WRITEDATA, &buf);
    curl_easy_setopt(c->curl, CURLOPT_TIMEOUT, c->timeout);
    curl_easy_setopt(c->curl, CURLOPT_CONNECTTIMEOUT, c->timeout / 2);
    curl_easy_setopt(c->curl, CURLOPT_FOLLOWLOCATION, 1L);
    curl_easy_setopt(c->curl, CURLOPT_SSL_VERIFYPEER, 0L);
    curl_easy_setopt(c->curl, CURLOPT_SSL_VERIFYHOST, 0L);
    curl_easy_setopt(c->curl, CURLOPT_USERAGENT, "UicSdk-C/1.0.0");

    struct curl_slist *headers = NULL;
    headers = curl_slist_append(headers, "Content-Type: application/x-www-form-urlencoded");

    if (c->session_id && c->session_id[0]) {
        char cookie[512];
        snprintf(cookie, sizeof(cookie), "Cookie: PHPSESSID=%s", c->session_id);
        headers = curl_slist_append(headers, cookie);
    }
    if (c->app_id && c->app_secret && strcmp(module, "sso") == 0) {
        char cred[256], b64[700], auth[800];
        snprintf(cred, sizeof(cred), "%s:%s", c->app_id, c->app_secret);
        /* 简易 Base64 */
        static const char b64chars[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
        size_t cl = strlen(cred), i, j;
        for (i = 0, j = 0; i < cl; i += 3, j += 4) {
            unsigned char b[3] = {0,0,0}; int n = 0;
            for (int k = 0; k < 3 && i+k < cl; k++) { b[k] = (unsigned char)cred[i+k]; n++; }
            b64[j]   = b64chars[(b[0] & 0xFC) >> 2];
            b64[j+1] = b64chars[((b[0] & 0x03) << 4) | ((b[1] & 0xF0) >> 4)];
            b64[j+2] = (n > 1) ? b64chars[((b[1] & 0x0F) << 2) | ((b[2] & 0xC0) >> 6)] : '=';
            b64[j+3] = (n > 2) ? b64chars[b[2] & 0x3F] : '=';
        }
        b64[j] = '\0';
        snprintf(auth, sizeof(auth), "Authorization: Basic %s", b64);
        headers = curl_slist_append(headers, auth);
    }

    curl_easy_setopt(c->curl, CURLOPT_HTTPHEADER, headers);

    if (body && body[0] && strcmp(method, "POST") == 0) {
        curl_easy_setopt(c->curl, CURLOPT_POSTFIELDS, body);
    }

    CURLcode res = curl_easy_perform(c->curl);
    long code = 0;
    curl_easy_getinfo(c->curl, CURLINFO_RESPONSE_CODE, &code);
    if (http_code) *http_code = code;
    curl_slist_free_all(headers);

    if (res != CURLE_OK) {
        log_it(c, "error", "curl failed", curl_easy_strerror(res));
        free(buf.data);
        return UIC_ERR_NETWORK;
    }

    cJSON *root = cJSON_Parse(buf.data ? buf.data : "{}");
    if (!root) {
        free(buf.data);
        return UIC_ERR_JSON;
    }

    cJSON *code_item = cJSON_GetObjectItem(root, "code");
    /* code == 0 表示成功（业务错误码 401/403/404 等同样于 HTTP 200 返回） */
    if (!cJSON_IsNumber(code_item) || code_item->valuedouble != 0) {
        cJSON *msg = cJSON_GetObjectItem(root, "message");
        const char *ms = cJSON_IsString(msg) ? msg->valuestring : "操作失败";
        log_it(c, "warn", "API 返回业务错误", ms);
        cJSON_Delete(root);
        free(buf.data);
        return UIC_ERR_API;
    }

    cJSON *data = cJSON_GetObjectItem(root, "data");
    if (out_json && data) {
        char *s = cJSON_PrintUnformatted(data);
        *out_json = s ? s : str_dup("null");
    } else if (out_json) {
        *out_json = str_dup("null");
    }

    cJSON_Delete(root);
    free(buf.data);
    return UIC_OK;
}

/* ========== 实现 ========== */

uic_client_t* uic_client_new(const char *base_url, int timeout_sec, uic_log_fn logger) {
    uic_client_t *c = (uic_client_t *)calloc(1, sizeof(uic_client_t));
    if (!c) return NULL;
    c->base_url = str_dup(base_url);
    c->timeout = (timeout_sec > 0) ? timeout_sec : 10;
    c->logger = logger;
    c->curl = curl_easy_init();
    if (!c->curl) { free(c->base_url); free(c); return NULL; }
    return c;
}

void uic_client_free(uic_client_t *c) {
    if (!c) return;
    free(c->base_url);
    free(c->session_id);
    free(c->app_id);
    free(c->app_secret);
    if (c->curl) curl_easy_cleanup(c->curl);
    free(c);
}

void uic_set_admin_session(uic_client_t *c, const char *sid) {
    if (!c) return;
    free(c->session_id);
    c->session_id = str_dup(sid);
}

void uic_set_app_credentials(uic_client_t *c, const char *aid, const char *asec) {
    if (!c) return;
    free(c->app_id); free(c->app_secret);
    c->app_id = str_dup(aid);
    c->app_secret = str_dup(asec);
}

void uic_free(void *p) { free(p); }

const char* uic_strerror(int err) {
    switch (err) {
        case UIC_OK:          return "OK";
        case UIC_ERR_NETWORK: return "网络请求失败";
        case UIC_ERR_HTTP:    return "HTTP 错误";
        case UIC_ERR_JSON:    return "JSON 解析失败";
        case UIC_ERR_API:     return "API 业务错误";
        case UIC_ERR_MEMORY:  return "内存分配失败";
        case UIC_ERR_PARAM:   return "参数错误";
        default:              return "未知错误";
    }
}

int uic_app_list(uic_client_t *c, char **out) {
    return do_request(c, "GET", "admin", "app_list", NULL, out, NULL);
}

int uic_app_create(uic_client_t *c, const char *name, const char *desc,
                   const char *home_url, const char *callbacks_csv,
                   bool sso_enabled, char **out) {
    char *body = NULL; size_t len = 0, cap = 0;
    append_form(&body, &len, &cap, "name", name);
    append_form(&body, &len, &cap, "description", desc);
    append_form(&body, &len, &cap, "home_url", home_url);
    append_form(&body, &len, &cap, "sso_enabled", sso_enabled ? "1" : "0");
    if (callbacks_csv) {
        char *dup = str_dup(callbacks_csv);
        char *tok = strtok(dup, ",");
        int idx = 0;
        char key[32];
        while (tok) {
            snprintf(key, sizeof(key), "callbacks[%d]", idx++);
            append_form(&body, &len, &cap, key, tok);
            tok = strtok(NULL, ",");
        }
        free(dup);
    }
    int r = do_request(c, "POST", "admin", "app_save", body, out, NULL);
    free(body);
    return r;
}

int uic_app_update(uic_client_t *c, int id, const char *fields_json, char **out) {
    char body[4096];
    snprintf(body, sizeof(body), "id=%d", id);
    if (fields_json && fields_json[0]) {
        cJSON *fj = cJSON_Parse(fields_json);
        if (fj) {
            cJSON *item = NULL;
            cJSON_ArrayForEach(item, fj) {
                if (cJSON_IsString(item)) {
                    /* 暂不展开，由调用方直接传 form 字符串 */
                }
            }
            cJSON_Delete(fj);
        }
    }
    return do_request(c, "POST", "admin", "app_save", body, out, NULL);
}

int uic_app_reset_secret(uic_client_t *c, int id, char **out) {
    char body[64];
    snprintf(body, sizeof(body), "id=%d", id);
    return do_request(c, "POST", "admin", "app_reset_secret", body, out, NULL);
}

int uic_app_toggle(uic_client_t *c, int id, bool enabled) {
    char body[64];
    snprintf(body, sizeof(body), "id=%d&status=%d", id, enabled ? 1 : 0);
    return do_request(c, "POST", "admin", "app_toggle", body, NULL, NULL);
}

int uic_app_delete(uic_client_t *c, int id) {
    char body[64];
    snprintf(body, sizeof(body), "id=%d", id);
    return do_request(c, "POST", "admin", "app_delete", body, NULL, NULL);
}

int uic_app_test(uic_client_t *c, int id, char **out) {
    char body[64];
    snprintf(body, sizeof(body), "id=%d", id);
    return do_request(c, "POST", "admin", "app_test", body, out, NULL);
}

char* uic_authorize_url(uic_client_t *c, const char *app_id, const char *redirect_uri) {
    if (!c || !app_id || !redirect_uri) return NULL;
    char *url = (char *)malloc(2048);
    if (!url) return NULL;
    snprintf(url, 2048, "%s/api/index.php?m=sso&a=authorize&app_id=%s&redirect=%s",
             c->base_url, app_id, redirect_uri);
    return url;
}

int uic_exchange(uic_client_t *c, const char *ticket,
                 const char *app_id, const char *app_secret,
                 char **out) {
    char body[512];
    const char *aid = (app_id && app_id[0]) ? app_id : (c->app_id ? c->app_id : "");
    const char *asec = (app_secret && app_secret[0]) ? app_secret : (c->app_secret ? c->app_secret : "");
    snprintf(body, sizeof(body), "ticket=%s&app_id=%s&app_secret=%s", ticket, aid, asec);
    return do_request(c, "POST", "sso", "exchange", body, out, NULL);
}

int uic_userinfo(uic_client_t *c, const char *token, const char *app_id, char **out) {
    char body[512];
    const char *aid = (app_id && app_id[0]) ? app_id : (c->app_id ? c->app_id : "");
    snprintf(body, sizeof(body), "token=%s&app_id=%s", token, aid);
    return do_request(c, "POST", "sso", "userinfo", body, out, NULL);
}

int uic_logout(uic_client_t *c, const char *token, const char *app_id) {
    char body[512];
    const char *aid = (app_id && app_id[0]) ? app_id : (c->app_id ? c->app_id : "");
    snprintf(body, sizeof(body), "token=%s&app_id=%s", token, aid);
    return do_request(c, "POST", "sso", "logout", body, NULL, NULL);
}

int uic_test_connect(uic_client_t *c, const char *app_id, const char *app_secret, char **out) {
    char body[512];
    const char *aid = (app_id && app_id[0]) ? app_id : (c->app_id ? c->app_id : "");
    const char *asec = (app_secret && app_secret[0]) ? app_secret : (c->app_secret ? c->app_secret : "");
    snprintf(body, sizeof(body), "app_id=%s&app_secret=%s", aid, asec);
    return do_request(c, "POST", "sso", "test", body, out, NULL);
}

int uic_parse_response(const char *json_str, uic_response_t *out) {
    if (!json_str || !out) return UIC_ERR_PARAM;
    memset(out, 0, sizeof(*out));
    cJSON *root = cJSON_Parse(json_str);
    if (!root) return UIC_ERR_JSON;
    cJSON *ok = cJSON_GetObjectItem(root, "ok");
    cJSON *code = cJSON_GetObjectItem(root, "code");
    cJSON *msg = cJSON_GetObjectItem(root, "message");
    cJSON *data = cJSON_GetObjectItem(root, "data");
    out->ok = cJSON_IsBool(ok) ? cJSON_IsTrue(ok) : false;
    out->code = cJSON_IsNumber(code) ? (int)code->valuedouble : 0;
    if (cJSON_IsString(msg)) out->message = str_dup(msg->valuestring);
    if (data) {
        char *s = cJSON_PrintUnformatted(data);
        out->data_raw = s;
    }
    cJSON_Delete(root);
    return UIC_OK;
}

void uic_response_free(uic_response_t *resp) {
    if (!resp) return;
    free(resp->message);
    free(resp->data_raw);
    memset(resp, 0, sizeof(*resp));
}
