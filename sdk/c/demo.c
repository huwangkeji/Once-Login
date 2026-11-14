/* Demo：统一身份认证中心 C SDK 快速开始
 *
 * 编译：
 *   gcc -o demo demo.c uic_sdk.c -lcurl -lcjson -I.
 * 运行：
 *   ./demo
 */
#include "uic_sdk.h"
#include <stdio.h>
#include <stdlib.h>

static void my_logger(const char *level, const char *msg, const char *ctx) {
    printf("[%s] %s %s\n", level, msg, ctx);
}

int main(void) {
    printf("=== UicSdk C Demo ===\n");

    uic_client_t *client = uic_client_new("http://127.0.0.5", 10, my_logger);
    if (!client) {
        printf("客户端初始化失败\n");
        return 1;
    }

    // 1) 应用侧：连通性测试
    printf("\n--- 应用侧连通性测试 ---\n");
    uic_set_app_credentials(client, "your_app_id", "your_app_secret");
    char *result = NULL;
    int rc = uic_test_connect(client, NULL, NULL, &result);
    if (rc == UIC_OK) {
        printf("结果: %s\n", result);
    } else {
        printf("失败: %s\n", uic_strerror(rc));
    }
    uic_free(result);

    // 2) 管理侧：应用列表（需有效 PHPSESSID）
    printf("\n--- 管理侧应用列表（示例，需有效 PHPSESSID）---\n");
    // uic_set_admin_session(client, "有效的_PHPSESSID");
    // char *list = NULL;
    // rc = uic_app_list(client, &list);
    // printf("%s\n", list ? list : "(null)");
    // uic_free(list);

    // 3) SSO 授权跳转 URL
    printf("\n--- SSO 授权跳转 URL ---\n");
    char *url = uic_authorize_url(client, "your_app_id", "http://your-app.com/callback");
    printf("%s\n", url);
    uic_free(url);

    // 4) Ticket 换 Token（服务端回调处理）
    printf("\n--- Ticket 换 Token（示例）---\n");
    // char *token = NULL;
    // rc = uic_exchange(client, "ticket_from_callback", NULL, NULL, &token);
    // printf("%s\n", token ? token : "(null)");
    // uic_free(token);

    uic_client_free(client);
    printf("\nDemo 完成。\n");
    return 0;
}
