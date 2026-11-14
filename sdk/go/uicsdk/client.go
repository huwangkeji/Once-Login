// Package uicsdk 统一身份认证中心 · Go SDK v1.1.0
// 应用管理 + SSO 接入 + 用户接口 + 重试机制 + context 超时控制
//
// 覆盖：初始化 / 管理侧鉴权(Cookie) / 应用侧鉴权(AppSecret) /
//
//	应用列表 / 创建 / 更新 / 启停 / 删除 / 通信测试 /
//	SSO 授权跳转 / Ticket 换 Token / Token 换用户信息 / 单点退出 /
//	用户注册 / 登录 / 信息查询 / 修改密码 / 唯一性检查 / 图形验证码
package uicsdk

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"math/rand"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// Response 统一响应结构
type Response struct {
	Code    int         `json:"code"`
	Message string      `json:"message"`
	Data    interface{} `json:"data"`
	Time    int64       `json:"time"`
}

// Error SDK 错误
type Error struct {
	Message    string
	HTTPCode   int
	Code       int
	Response   *Response
	Underlying error
}

func (e *Error) Error() string {
	if e.Underlying != nil {
		return fmt.Sprintf("UicSdk: %s (code=%d http=%d): %v", e.Message, e.Code, e.HTTPCode, e.Underlying)
	}
	return fmt.Sprintf("UicSdk: %s (code=%d http=%d)", e.Message, e.Code, e.HTTPCode)
}

// Logger 日志接口
type Logger interface {
	Debug(msg string, ctx map[string]interface{})
	Info(msg string, ctx map[string]interface{})
	Warn(msg string, ctx map[string]interface{})
	Error(msg string, ctx map[string]interface{})
}

// noopLogger 默认空实现
type noopLogger struct{}

func (n noopLogger) Debug(string, map[string]interface{}) {}
func (n noopLogger) Info(string, map[string]interface{})  {}
func (n noopLogger) Warn(string, map[string]interface{})  {}
func (n noopLogger) Error(string, map[string]interface{}) {}

// RetryConfig 重试配置
type RetryConfig struct {
	MaxRetries   int
	BaseDelay    time.Duration
	MaxDelay     time.Duration
	RetryOnCodes []int
}

// Client SDK 客户端
type Client struct {
	baseURL   string
	timeout   time.Duration
	client    *http.Client
	sessionID string
	appID     string
	appSecret string
	logger    Logger
	retry     RetryConfig
	ctx       context.Context
}

// Option 配置函数
type Option func(*Client)

// WithTimeout 设置超时
func WithTimeout(d time.Duration) Option {
	return func(c *Client) { c.timeout = d }
}

// WithLogger 设置日志器
func WithLogger(l Logger) Option {
	return func(c *Client) { c.logger = l }
}

// WithContext 设置全局 context（可配合 http.Client 超时控制）
func WithContext(ctx context.Context) Option {
	return func(c *Client) { c.ctx = ctx }
}

// WithRetry 设置重试策略（maxRetries 次失败后放弃）
//
//	默认关闭；传 maxRetries<=0 可显式关闭。
//	可重试状态码默认：0(网络错误), 429, 500, 502, 503, 504
func WithRetry(maxRetries int, baseDelay, maxDelay time.Duration) Option {
	return func(c *Client) {
		c.retry = RetryConfig{
			MaxRetries:   maxRetries,
			BaseDelay:    baseDelay,
			MaxDelay:     maxDelay,
			RetryOnCodes: []int{0, 429, 500, 502, 503, 504},
		}
	}
}

// NewClient 初始化 SDK
func NewClient(baseURL string, opts ...Option) *Client {
	c := &Client{
		baseURL: strings.TrimRight(baseURL, "/"),
		timeout: 10 * time.Second,
		logger:  noopLogger{},
		retry:   RetryConfig{MaxRetries: 0},
		ctx:     context.Background(),
	}
	for _, o := range opts {
		o(c)
	}
	c.client = &http.Client{
		Timeout:   c.timeout,
		Transport: &http.Transport{MaxIdleConnsPerHost: 10, MaxIdleConns: 100},
	}
	return c
}

// SetAdminSession 设置管理侧 Cookie 会话
func (c *Client) SetAdminSession(sid string) *Client {
	c.sessionID = sid
	return c
}

// SetAppCredentials 设置应用侧凭据
func (c *Client) SetAppCredentials(appID, appSecret string) *Client {
	c.appID = appID
	c.appSecret = appSecret
	return c
}

// ================= 管理接口：应用管理 =================

// AppList 获取应用列表（RBAC: view:apps）
func (c *Client) AppList() ([]map[string]interface{}, error) {
	var data []map[string]interface{}
	if err := c.request("GET", "admin", "app_list", nil, &data); err != nil {
		return nil, err
	}
	return data, nil
}

// AppCreate 创建应用（RBAC: manage:apps）
func (c *Client) AppCreate(name, description, homeURL string, callbacks []string, ssoEnabled bool) (map[string]interface{}, error) {
	params := url.Values{}
	params.Set("name", name)
	params.Set("description", description)
	params.Set("home_url", homeURL)
	params.Set("sso_enabled", strconv.Itoa(btoi(ssoEnabled)))
	for _, cb := range callbacks {
		params.Add("callbacks[]", cb)
	}
	var data map[string]interface{}
	if err := c.request("POST", "admin", "app_save", params, &data); err != nil {
		return nil, err
	}
	return data, nil
}

// AppUpdate 更新应用（RBAC: manage:apps）
func (c *Client) AppUpdate(id int, fields map[string]interface{}) (map[string]interface{}, error) {
	params := url.Values{}
	params.Set("id", strconv.Itoa(id))
	for k, v := range fields {
		params.Set(k, fmt.Sprintf("%v", v))
	}
	var data map[string]interface{}
	if err := c.request("POST", "admin", "app_save", params, &data); err != nil {
		return nil, err
	}
	return data, nil
}

// AppResetSecret 重置 AppSecret（RBAC: manage:apps）
func (c *Client) AppResetSecret(id int) (map[string]interface{}, error) {
	params := url.Values{}
	params.Set("id", strconv.Itoa(id))
	var data map[string]interface{}
	if err := c.request("POST", "admin", "app_reset_secret", params, &data); err != nil {
		return nil, err
	}
	return data, nil
}

// AppToggle 启用 / 禁用应用（RBAC: manage:apps）
func (c *Client) AppToggle(id int, enabled bool) error {
	params := url.Values{}
	params.Set("id", strconv.Itoa(id))
	params.Set("status", strconv.Itoa(btoi(enabled)))
	return c.request("POST", "admin", "app_toggle", params, nil)
}

// AppDelete 删除应用（RBAC: manage:apps）
func (c *Client) AppDelete(id int) error {
	params := url.Values{}
	params.Set("id", strconv.Itoa(id))
	return c.request("POST", "admin", "app_delete", params, nil)
}

// AppTest 通信测试（RBAC: view:apps）
func (c *Client) AppTest(id int) (map[string]interface{}, error) {
	params := url.Values{}
	params.Set("id", strconv.Itoa(id))
	var data map[string]interface{}
	if err := c.request("POST", "admin", "app_test", params, &data); err != nil {
		return nil, err
	}
	return data, nil
}

// ================= SSO 接口 =================

// AuthorizeURL 构建 SSO 授权跳转 URL
func (c *Client) AuthorizeURL(appID, redirectURI string) string {
	return fmt.Sprintf("%s/api/index.php?m=sso&a=authorize&app_id=%s&redirect=%s",
		c.baseURL, url.QueryEscape(appID), url.QueryEscape(redirectURI))
}

// Exchange ticket 换 token（服务端对接）
func (c *Client) Exchange(ticket, appID, appSecret string) (map[string]interface{}, error) {
	params := url.Values{}
	params.Set("ticket", ticket)
	params.Set("app_id", first(appID, c.appID))
	params.Set("app_secret", first(appSecret, c.appSecret))
	var data map[string]interface{}
	if err := c.request("POST", "sso", "exchange", params, &data); err != nil {
		return nil, err
	}
	return data, nil
}

// Userinfo token 换用户信息
func (c *Client) Userinfo(token, appID string) (map[string]interface{}, error) {
	params := url.Values{}
	params.Set("token", token)
	params.Set("app_id", first(appID, c.appID))
	var data map[string]interface{}
	if err := c.request("POST", "sso", "userinfo", params, &data); err != nil {
		return nil, err
	}
	return data, nil
}

// Logout 单点退出
func (c *Client) Logout(token, appID string) error {
	params := url.Values{}
	params.Set("token", token)
	params.Set("app_id", first(appID, c.appID))
	return c.request("POST", "sso", "logout", params, nil)
}

// TestConnect 连通性测试
func (c *Client) TestConnect(appID, appSecret string) (map[string]interface{}, error) {
	params := url.Values{}
	params.Set("app_id", first(appID, c.appID))
	params.Set("app_secret", first(appSecret, c.appSecret))
	var data map[string]interface{}
	if err := c.request("POST", "sso", "test", params, &data); err != nil {
		return nil, err
	}
	return data, nil
}

// ================= 用户接口 =================

// UserRegister 用户注册
func (c *Client) UserRegister(data map[string]interface{}, autoLogin bool) (map[string]interface{}, error) {
	params := url.Values{}
	for k, v := range data {
		if k != "confirm" || v != "" {
			params.Set(k, fmt.Sprintf("%v", v))
		}
	}
	params.Set("auto_login", strconv.Itoa(btoi(autoLogin)))
	var out map[string]interface{}
	if err := c.request("POST", "user", "register", params, &out); err != nil {
		return nil, err
	}
	return out, nil
}

// UserLogin 用户登录
func (c *Client) UserLogin(account, password string, remember bool) (map[string]interface{}, error) {
	params := url.Values{}
	params.Set("account", account)
	params.Set("password", password)
	params.Set("remember", strconv.Itoa(btoi(remember)))
	var out map[string]interface{}
	if err := c.request("POST", "user", "login", params, &out); err != nil {
		return nil, err
	}
	return out, nil
}

// UserInfo 获取当前登录用户信息（需会话 Cookie）
func (c *Client) UserInfo() (map[string]interface{}, error) {
	var out map[string]interface{}
	if err := c.request("POST", "user", "info", nil, &out); err != nil {
		return nil, err
	}
	return out, nil
}

// UserChangePassword 修改密码
func (c *Client) UserChangePassword(oldPassword, newPassword, confirm string) (map[string]interface{}, error) {
	params := url.Values{}
	params.Set("old_password", oldPassword)
	params.Set("new_password", newPassword)
	params.Set("confirm", confirm)
	var out map[string]interface{}
	if err := c.request("POST", "user", "password", params, &out); err != nil {
		return nil, err
	}
	return out, nil
}

// UserCheckUnique 唯一性检查（true=可用）
func (c *Client) UserCheckUnique(field, value string) (bool, error) {
	params := url.Values{}
	params.Set("field", field)
	params.Set("value", value)
	var out bool
	if err := c.request("POST", "user", "check", params, &out); err != nil {
		return false, err
	}
	return out, nil
}

// ================= 验证码接口 =================

// CaptchaIssue 获取图形验证码
func (c *Client) CaptchaIssue() (map[string]interface{}, error) {
	var out map[string]interface{}
	if err := c.request("POST", "captcha", "issue", nil, &out); err != nil {
		return nil, err
	}
	return out, nil
}

// CaptchaVerify 校验验证码
func (c *Client) CaptchaVerify(id, code string) (bool, error) {
	params := url.Values{}
	params.Set("id", id)
	params.Set("code", code)
	var out bool
	if err := c.request("POST", "captcha", "verify", params, &out); err != nil {
		return false, err
	}
	return out, nil
}

// ================= HTTP 底层（含重试） =================

func (c *Client) request(method, m, a string, params url.Values, dst interface{}) error {
	u := fmt.Sprintf("%s/api/index.php?m=%s&a=%s", c.baseURL, url.QueryEscape(m), url.QueryEscape(a))

	var lastErr error
	maxAttempts := c.retry.MaxRetries + 1
	if maxAttempts < 1 {
		maxAttempts = 1
	}

	for attempt := 0; attempt < maxAttempts; attempt++ {
		lastErr = c.doOnce(method, u, params, dst)
		if lastErr == nil {
			return nil
		}

		code := errorCode(lastErr)
		if attempt+1 >= maxAttempts || !c.isRetryable(code) {
			return lastErr
		}

		delay := c.retryDelay(attempt)
		c.logger.Warn("请求失败，准备重试", map[string]interface{}{
			"url":     u,
			"attempt": attempt + 1,
			"code":    code,
			"delay":   delay.String(),
			"err":     lastErr.Error(),
		})
		select {
		case <-time.After(delay):
		case <-c.ctx.Done():
			return lastErr
		}
	}
	return lastErr
}

func (c *Client) doOnce(method, u string, params url.Values, dst interface{}) error {
	var body io.Reader
	if method == "POST" && params != nil {
		body = strings.NewReader(params.Encode())
	}

	req, err := http.NewRequestWithContext(c.ctx, method, u, body)
	if err != nil {
		return &Error{Message: "构造请求失败", Underlying: err}
	}

	if method == "POST" {
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	}
	req.Header.Set("User-Agent", "UicSdk-Go/1.1.0")
	req.Header.Set("Accept", "application/json")

	if c.sessionID != "" {
		req.Header.Set("Cookie", "PHPSESSID="+c.sessionID)
	}
	if c.appID != "" && c.appSecret != "" {
		req.Header.Set("Authorization", "Basic "+base64.StdEncoding.EncodeToString([]byte(c.appID+":"+c.appSecret)))
	}

	c.logger.Debug("API 请求", map[string]interface{}{"url": u, "method": method})

	resp, err := c.client.Do(req)
	if err != nil {
		return &Error{Message: "网络请求失败", Code: 0, Underlying: err}
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return &Error{Message: "读取响应失败", HTTPCode: resp.StatusCode, Underlying: err}
	}

	var r Response
	if err := json.Unmarshal(respBody, &r); err != nil {
		return &Error{Message: "响应 JSON 解析失败", HTTPCode: resp.StatusCode, Underlying: err, Response: &Response{Data: string(respBody)}}
	}

	if resp.StatusCode >= 500 {
		return &Error{Message: "服务器错误", HTTPCode: resp.StatusCode, Code: r.Code, Response: &r}
	}

	if r.Code != 0 {
		return &Error{Message: r.Message, HTTPCode: resp.StatusCode, Code: r.Code, Response: &r}
	}

	if dst != nil && r.Data != nil {
		b, _ := json.Marshal(r.Data)
		_ = json.Unmarshal(b, dst)
	}
	return nil
}

func (c *Client) isRetryable(code int) bool {
	if len(c.retry.RetryOnCodes) == 0 {
		return false
	}
	for _, v := range c.retry.RetryOnCodes {
		if v == code {
			return true
		}
	}
	return false
}

func (c *Client) retryDelay(attempt int) time.Duration {
	if c.retry.BaseDelay <= 0 {
		c.retry.BaseDelay = 100 * time.Millisecond
	}
	if c.retry.MaxDelay <= 0 {
		c.retry.MaxDelay = 5 * time.Second
	}
	delay := c.retry.BaseDelay * time.Duration(1<<uint(attempt))
	if delay > c.retry.MaxDelay {
		delay = c.retry.MaxDelay
	}
	// 添加 +/-25% 随机抖动
	jitter := time.Duration(float64(delay) * 0.25 * rand.Float64())
	if rand.Intn(2) == 0 {
		delay += jitter
	} else {
		delay -= jitter
	}
	if delay < time.Millisecond {
		delay = time.Millisecond
	}
	return delay
}

func errorCode(e error) int {
	if err, ok := e.(*Error); ok {
		if err.Code != 0 {
			return err.Code
		}
		return err.HTTPCode
	}
	return 0
}

func btoi(b bool) int {
	if b {
		return 1
	}
	return 0
}

func first(a, b string) string {
	if a != "" {
		return a
	}
	return b
}
