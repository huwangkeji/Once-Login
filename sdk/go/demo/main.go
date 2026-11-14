// Demo：统一身份认证中心 Go SDK 快速开始
// 运行方式（在 sdk/go 目录下）：go run ./demo
package main

import (
	"context"
	"fmt"
	"time"

	"uicsdk-demo/uicsdk"
)

type simpleLogger struct{}

func (s simpleLogger) Debug(msg string, ctx map[string]interface{}) {
	fmt.Printf("[DEBUG] %s %#v\n", msg, ctx)
}
func (s simpleLogger) Info(msg string, ctx map[string]interface{})  { fmt.Printf("[INFO] %s\n", msg) }
func (s simpleLogger) Warn(msg string, ctx map[string]interface{})  { fmt.Printf("[WARN] %s\n", msg) }
func (s simpleLogger) Error(msg string, ctx map[string]interface{}) { fmt.Printf("[ERROR] %s\n", msg) }

func main() {
	fmt.Println("=== UicSdk Go Demo ===")

	client := uicsdk.NewClient(
		"http://127.0.0.5",
		uicsdk.WithTimeout(10*time.Second),
		uicsdk.WithLogger(simpleLogger{}),
		uicsdk.WithContext(context.Background()),
		uicsdk.WithRetry(3, 100*time.Millisecond, 2*time.Second),
	)

	// 1) 应用侧：连通性测试
	fmt.Println("\n--- 应用侧连通性测试 ---")
	client.SetAppCredentials("your_app_id", "your_app_secret")
	if r, err := client.TestConnect("", ""); err != nil {
		fmt.Printf("失败: %v\n", err)
	} else {
		fmt.Printf("结果: %+v\n", r)
	}

	// 2) 管理侧：应用列表（需有效 PHPSESSID）
	fmt.Println("\n--- 管理侧应用列表（示例，需有效 PHPSESSID）---")
	// client.SetAdminSession("有效的_PHPSESSID")
	// list, err := client.AppList()
	// fmt.Println(list, err)

	// 3) SSO 授权跳转 URL
	fmt.Println("\n--- SSO 授权跳转 URL ---")
	fmt.Println(client.AuthorizeURL("your_app_id", "http://your-app.com/callback"))

	// 4) Ticket 换 Token（服务端回调处理）
	fmt.Println("\n--- Ticket 换 Token（示例）---")
	// token, err := client.Exchange("ticket_from_callback", "your_app_id", "your_app_secret")
	// fmt.Println(token, err)

	fmt.Println("\nDemo 完成。")
}
