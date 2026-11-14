#ifndef UIC_SDK_H
#define UIC_SDK_H

#ifdef __cplusplus
extern "C" {
#endif

/**
 * 统一身份认证中心 · C SDK v1.0.0
 * 应用管理 + SSO 接入（基于 libcurl + cJSON）
 *
 * 覆盖：初始化 / 管理侧鉴权(Cookie) / 应用侧鉴权(AppSecret) /
 *       应用列表 / 创建 / 更新 / 启停 / 删除 / 通信测试 /
 *       SSO 授权跳转 / Ticket 换 Token / Token 换用户信息 / 单点退出
 *
 * 编译依赖：libcurl, cJSON
 * 示例：见 demo.c
 */

#include <stddef.h>
#include <stdbool.h>

/* 错误码 */
#define UIC_OK            0
#define UIC_ERR_NETWORK  -1
#define UIC_ERR_HTTP     -2
#define UIC_ERR_JSON     -3
#define UIC_ERR_API      -4
#define UIC_ERR_MEMORY   -5
#define UIC_ERR_PARAM    -6

/* 统一响应结构 */
typedef struct {
    bool ok;          /* 业务成功标志（code==0） */
    int code;         /* 业务码：0=成功 */
    char *message;    /* 需 uic_free */
    char *data_raw;   /* 原始 JSON data 字段，需 uic_free */
} uic_response_t;

/* SDK 上下文（不透明指针） */
typedef struct uic_client uic_client_t;

/* 日志回调 */
typedef void (*uic_log_fn)(const char *level, const char *msg, const char *ctx_json);

/* ========== 生命周期 ========== */
uic_client_t* uic_client_new(const char *base_url, int timeout_sec, uic_log_fn logger);
void          uic_client_free(uic_client_t *client);

/* 鉴权设置 */
void uic_set_admin_session(uic_client_t *client, const char *session_id);
void uic_set_app_credentials(uic_client_t *client, const char *app_id, const char *app_secret);

/* ========== 管理接口：应用管理 ========== */

/**
 * 获取应用列表（RBAC: view:apps）
 * @param client SDK 上下文
 * @param out_json  输出：JSON 数组字符串（需 uic_free），失败为 NULL
 * @return UIC_OK 或错误码
 */
int uic_app_list(uic_client_t *client, char **out_json);

/**
 * 创建应用（RBAC: manage:apps）
 * @param out_json 输出：JSON 对象字符串（含 id/app_id/app_secret），需 uic_free
 */
int uic_app_create(uic_client_t *client,
                   const char *name, const char *description,
                   const char *home_url, const char *callbacks_csv,
                   bool sso_enabled,
                   char **out_json);

/** 更新应用（RBAC: manage:apps） */
int uic_app_update(uic_client_t *client, int id,
                   const char *fields_json, /* 如 {"name":"X","status":1} */
                   char **out_json);

/** 重置 AppSecret（RBAC: manage:apps） */
int uic_app_reset_secret(uic_client_t *client, int id, char **out_json);

/** 启用/禁用应用（RBAC: manage:apps） */
int uic_app_toggle(uic_client_t *client, int id, bool enabled);

/** 删除应用（RBAC: manage:apps） */
int uic_app_delete(uic_client_t *client, int id);

/** 通信测试（RBAC: view:apps） */
int uic_app_test(uic_client_t *client, int id, char **out_json);

/* ========== SSO 接口 ========== */

/**
 * 构建 SSO 授权跳转 URL
 * @return 完整 URL 字符串（需 uic_free），失败返回 NULL
 */
char* uic_authorize_url(uic_client_t *client, const char *app_id, const char *redirect_uri);

/** ticket 换 token（服务端对接） */
int uic_exchange(uic_client_t *client,
                 const char *ticket,
                 const char *app_id, const char *app_secret,
                 char **out_json);

/** token 换用户信息 */
int uic_userinfo(uic_client_t *client,
                 const char *token,
                 const char *app_id,
                 char **out_json);

/** 单点退出 */
int uic_logout(uic_client_t *client,
               const char *token,
               const char *app_id);

/** 连通性测试 */
int uic_test_connect(uic_client_t *client,
                     const char *app_id, const char *app_secret,
                     char **out_json);

/* ========== 工具 ========== */

/** 释放 SDK 分配的字符串 */
void uic_free(void *ptr);

/** 获取错误码描述 */
const char* uic_strerror(int err);

/** 解析响应 JSON，提取 data 字段 */
int uic_parse_response(const char *json_str, uic_response_t *out);

/** 释放 uic_response_t 内部指针 */
void uic_response_free(uic_response_t *resp);

#ifdef __cplusplus
}
#endif

#endif /* UIC_SDK_H */
