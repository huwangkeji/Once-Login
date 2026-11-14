<?php
/**
 * 设置项分类大纲（Settings Schema）
 *
 * 目标：把原先散落在 settings 表里、按 s_group 粗放的 90+ 个配置项，
 * 整理成清晰的三级层级：
 *
 *     模块 module（一级，如「安全与验证」）
 *       └ 分组 section（二级，如「人机验证」）
 *           └ 设置项 key（三级，如 captcha_style）
 *
 * 设计要点：
 *   1. 单一事实来源：分类、显示名、控件类型、默认值、校验规则全部集中在此文件，
 *      后台页面与保存接口共用，避免「页面写一套、接口写一套」。
 *   2. 向后兼容：模块 id 直接作为 settings.s_group 落库（site/account/security/sso/
 *      mail/sms/ucenter/oauth/system），老数据经 tools/migrate_settings.php 迁移。
 *   3. 不丢项：凡大纲未收录的键，页面会归入「未分类」模块展示，绝不会因改版而隐藏配置。
 *   4. 可检索：每个键可带 keywords（别名/同义词），支持按名称、键名、说明模糊搜索。
 */
class SettingsSchema
{
    // 控件类型
    const T_BOOL     = 'bool';      // 开关（1/0）
    const T_SELECT   = 'select';    // 下拉枚举
    const T_NUMBER   = 'number';    // 数字
    const T_TEXT     = 'text';      // 单行文本
    const T_TEXTAREA = 'textarea';  // 多行文本
    const T_JSON     = 'json';      // JSON（textarea + 校验）
    const T_PASSWORD = 'password';  // 敏感字段（掩码显示）
    const T_STATIC   = 'static';    // 只读（系统自动维护，不参与保存）

    /** @var array|null 大纲缓存 */
    private static $schema = null;
    /** @var array|null 键 → [module, section, def] 反向索引 */
    private static $index = null;

    /**
     * 大纲定义。结构：
     *   moduleId => [
     *     'title'    => 模块名,
     *     'desc'     => 模块说明,
     *     'sections' => [
     *        sectionId => [
     *          'title' => 分组名,
     *          'desc'  => 分组说明,
     *          'keys'  => [ key => def, ... ],
     *          // 可选：'dynamic' => ['prefix'=>'admin_totp_enabled_','label'=>'...','type'=>'static']
     *        ]
     *     ]
     *   ]
     * def 可用字段：label / type / options / default / unit / help / keywords / sensitive / readonly
     */
    public static function schema()
    {
        if (self::$schema !== null) {
            return self::$schema;
        }
        $self = self::class;
        $capStyles    = class_exists('CaptchaModel') ? CaptchaModel::styles() : ['slide' => '滑动拼图'];
        $capFallbacks = class_exists('CaptchaModel') ? CaptchaModel::fallbackStyles() : ['slide' => '滑动拼图'];

        self::$schema = [
            // ==================== 1. 站点信息 ====================
            'site' => [
                'title' => '站点信息',
                'desc'  => '品牌文案、主体备案、联系方式与页脚自定义',
                'sections' => [
                    'brand' => [
                        'title' => '品牌与文案',
                        'desc'  => '展示在前台与登录页的站点标识',
                        'keys'  => [
                            'site_name'    => ['label' => '站点名称', 'type' => self::T_TEXT, 'default' => '统一身份认证中心', 'keywords' => 'title 标题 名字'],
                            'site_slogan'  => ['label' => '站点标语', 'type' => self::T_TEXT, 'default' => '一次登录，处处通行', 'keywords' => 'slogan 副标题'],
                            'site_logo'    => ['label' => '站点 Logo 地址', 'type' => self::T_TEXT, 'default' => '', 'help' => '留空使用默认 U 标；建议 PNG/SVG', 'keywords' => 'logo 图标'],
                            'site_url'     => ['label' => '站点根地址', 'type' => self::T_TEXT, 'default' => '', 'help' => '留空自动推断；固定域名/回调地址时可手工填写', 'keywords' => 'url 域名 地址'],
                        ],
                    ],
                    'legal' => [
                        'title' => '主体与备案',
                        'desc'  => '公司主体、ICP / 公安备案与版权声明',
                        'keys'  => [
                            'site_company'     => ['label' => '主体 / 公司名称', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '公司 主体 企业名称'],
                            'site_icp'         => ['label' => 'ICP 备案号', 'type' => self::T_TEXT, 'default' => '', 'help' => '如：京ICP备12345678号', 'keywords' => '备案 icp'],
                            'site_icp_link'    => ['label' => '备案查询跳转地址', 'type' => self::T_TEXT, 'default' => 'https://beian.miit.gov.cn/', 'keywords' => '备案 链接'],
                            'site_police'      => ['label' => '公安联网备案号', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '公安 网安 备案'],
                            'site_police_link' => ['label' => '公安备案查询地址', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '公安 备案 链接'],
                            'site_copyright'   => ['label' => '版权声明', 'type' => self::T_TEXT, 'default' => '', 'help' => '留空自动生成', 'keywords' => 'copyright 版权'],
                        ],
                    ],
                    'contact' => [
                        'title' => '联系方式',
                        'desc'  => '展示在页脚与帮助页的对外联系渠道',
                        'keys'  => [
                            'site_contact_email' => ['label' => '对外联系邮箱', 'type' => self::T_TEXT, 'default' => '', 'keywords' => 'email 邮箱 联系'],
                            'site_contact_phone' => ['label' => '对外联系电话', 'type' => self::T_TEXT, 'default' => '', 'keywords' => 'phone 电话 联系'],
                            'site_contact_url'   => ['label' => '在线客服 / 工单地址', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '客服 工单 帮助'],
                        ],
                    ],
                    'footer' => [
                        'title' => '页脚与自定义',
                        'desc'  => '底部文案与附加 HTML（统计代码等）',
                        'keys'  => [
                            'site_footer_text' => ['label' => '底部自定义文案', 'type' => self::T_TEXTAREA, 'default' => '', 'help' => '纯文本，会追加在版权行之后', 'keywords' => 'footer 页脚 底部'],
                            'site_footer_html' => ['label' => '底部附加 HTML', 'type' => self::T_TEXTAREA, 'default' => '', 'help' => '如统计代码；仅填写可信内容', 'keywords' => 'footer html 统计 代码'],
                        ],
                    ],
                ],
            ],

            // ==================== 2. 账号与注册 ====================
            'account' => [
                'title' => '账号与注册',
                'desc'  => '注册策略、注册字段与账号生命周期',
                'sections' => [
                    'register' => [
                        'title' => '注册策略',
                        'desc'  => '是否开放注册、注册时收集哪些字段',
                        'keys'  => [
                            'register_enabled' => ['label' => '开放注册', 'type' => self::T_BOOL, 'default' => 1, 'help' => '关闭后前台不再提供注册入口', 'keywords' => 'register 注册 开放'],
                            'register_fields'  => ['label' => '注册可选字段', 'type' => self::T_JSON, 'default' => ['email' => 1, 'mobile' => 0], 'help' => 'JSON，如 {"email":1,"mobile":0}', 'keywords' => '字段 手机 邮箱 注册'],
                        ],
                    ],
                    'lifecycle' => [
                        'title' => '账号生命周期',
                        'desc'  => '注销申请等账号退出机制',
                        'keys'  => [
                            'account_deletion_enabled' => ['label' => '允许提交账号注销申请', 'type' => self::T_BOOL, 'default' => 1, 'help' => '关闭后前台不再显示注销入口', 'keywords' => '注销 删除 回收'],
                        ],
                    ],
                ],
            ],

            // ==================== 3. 安全与验证 ====================
            'security' => [
                'title' => '安全与验证',
                'desc'  => '登录保护、人机验证、密码策略与访问控制',
                'sections' => [
                    'login_guard' => [
                        'title' => '登录保护',
                        'desc'  => '失败重试与锁定时长',
                        'keys'  => [
                            'login_fail_limit' => ['label' => '登录失败次数上限', 'type' => self::T_NUMBER, 'default' => 5, 'unit' => '次', 'help' => '超过后按锁定时长冻结', 'keywords' => '失败 重试 锁定 次数'],
                            'login_lock_time'  => ['label' => '锁定时长', 'type' => self::T_NUMBER, 'default' => 900, 'unit' => '秒', 'keywords' => '锁定 冻结 时间'],
                        ],
                    ],
                    'captcha' => [
                        'title' => '人机验证',
                        'desc'  => '验证码开关与默认验证方式',
                        'keys'  => [
                            'login_captcha'       => ['label' => '前台登录 / 找回密码验证码', 'type' => self::T_BOOL, 'default' => 1, 'keywords' => '验证码 captcha 人机'],
                            'admin_login_captcha' => ['label' => '后台登录验证码', 'type' => self::T_BOOL, 'default' => 1, 'keywords' => '验证码 后台 captcha'],
                            'captcha_style'       => ['label' => '默认验证方式', 'type' => self::T_SELECT, 'options' => $capStyles, 'default' => 'slide', 'help' => '前台/后台登录、找回密码统一使用', 'keywords' => '验证码 样式 滑块 点选 无感'],
                            'captcha_fallback'    => ['label' => '无感验证回退方式', 'type' => self::T_SELECT, 'options' => $capFallbacks, 'default' => 'slide', 'help' => '无感验证评分未通过时自动切换为该方式', 'keywords' => '回退 fallback 无感 验证码'],
                        ],
                    ],
                    'passive' => [
                        'title' => '无感验证（行为式风险识别）',
                        'desc'  => '参考极验 / 腾讯天御 / Cloudflare 的风险引擎：采集行为轨迹 + 环境指纹，多维加权评分并分级拦截',
                        'keys'  => [
                            'passive_enabled'        => ['label' => '无感风险引擎', 'type' => self::T_BOOL, 'default' => 1, 'help' => '关闭后无感验证回退为旧版简单阈值判定', 'keywords' => '无感 行为 风险 引擎'],
                            'passive_trigger'        => [
                                'label'   => '触发条件',
                                'type'    => self::T_SELECT,
                                'options' => ['always' => '始终评分（推荐）', 'suspicious' => '仅对可疑流量评分（更低摩擦）'],
                                'default' => 'always',
                                'help'    => '始终做行为评分；或仅当服务端信号（IP 频率/令牌时效）异常时才评分，否则直接放行',
                                'keywords' => '触发 条件 无感 评分',
                            ],
                            'passive_verify_intensity' => [
                                'label'   => '验证强度',
                                'type'    => self::T_SELECT,
                                'options' => ['low' => '宽松', 'normal' => '默认', 'strict' => '严格', 'custom' => '自定义阈值'],
                                'default' => 'normal',
                                'help'    => '风险分级档位；选「自定义阈值」后下方 pass/challenge/block 阈值生效',
                                'keywords' => '强度 阈值 分级 验证',
                            ],
                            'passive_score_pass'      => ['label' => '放行阈值', 'type' => self::T_NUMBER, 'default' => 35, 'unit' => '分', 'help' => '风险分 ≤ 此值：无感放行（满分 100）', 'keywords' => '放行 阈值 通过 分数'],
                            'passive_score_challenge' => ['label' => '挑战阈值', 'type' => self::T_NUMBER, 'default' => 70, 'unit' => '分', 'help' => '风险分 ≤ 此值：降级交互式验证；超出则拦截', 'keywords' => '挑战 阈值 回退 分数'],
                            'passive_score_block'     => ['label' => '拦截阈值', 'type' => self::T_NUMBER, 'default' => 88, 'unit' => '分', 'help' => '风险分 > 此值：直接拦截', 'keywords' => '拦截 阈值 阻断 分数'],
                            'passive_w_behavior'      => ['label' => '行为信号权重', 'type' => self::T_NUMBER, 'default' => 60, 'unit' => '%', 'help' => '轨迹生物特征（速度/抖动/方向熵/变化）权重', 'keywords' => '权重 行为 轨迹 风险'],
                            'passive_w_env'           => ['label' => '环境信号权重', 'type' => self::T_NUMBER, 'default' => 20, 'unit' => '%', 'help' => '设备指纹 / 时区 / 语言 权重', 'keywords' => '权重 环境 指纹'],
                            'passive_w_server'        => ['label' => '服务端信号权重', 'type' => self::T_NUMBER, 'default' => 20, 'unit' => '%', 'help' => 'IP 频率 / 令牌时效 权重', 'keywords' => '权重 服务端 IP 频率'],
                            'passive_allowlist'       => ['label' => '放行白名单', 'type' => self::T_TEXTAREA, 'default' => '', 'help' => '每行一个，支持精确 IP / 通配 192.168.1.* / CIDR 10.0.0.0/8；命中即恒放行', 'keywords' => '白名单 放行 IP CIDR'],
                            'passive_collect_ms'      => ['label' => '采集窗口', 'type' => self::T_NUMBER, 'default' => 1600, 'unit' => 'ms', 'help' => '前端轨迹采集时长', 'keywords' => '采集 窗口 轨迹 时长'],
                            'passive_max_points'      => ['label' => '最大采样点', 'type' => self::T_NUMBER, 'default' => 120, 'unit' => '点', 'help' => '轨迹最大采样点数（控制上报体积）', 'keywords' => '采样 点数 轨迹'],
                            'passive_trust_ttl'       => ['label' => '信任凭证有效期', 'type' => self::T_NUMBER, 'default' => 3600, 'unit' => '秒', 'help' => '通过无感验证后签发的跨页放行凭证有效期', 'keywords' => '信任 凭证 放行 有效期'],
                            'passive_secret'          => ['label' => '签名密钥', 'type' => self::T_PASSWORD, 'default' => '', 'sensitive' => true, 'help' => '信任凭证 HMAC 签名密钥，建议填写随机值；留空使用安装级默认值', 'keywords' => '密钥 签名 HMAC 信任'],
                        ],
                    ],
                    'password' => [
                        'title' => '密码策略',
                        'desc'  => '用户与管理员口令约束',
                        'keys'  => [
                            'pwd_min_length'        => ['label' => '密码最小长度', 'type' => self::T_NUMBER, 'default' => 8, 'unit' => '位', 'help' => '安全基线建议 8 位及以上', 'keywords' => '密码 长度 复杂度'],
                            'pwd_max_length'        => ['label' => '密码最大长度', 'type' => self::T_NUMBER, 'default' => 32, 'unit' => '位', 'keywords' => '密码 长度'],
                            'admin_pwd_expire_days' => ['label' => '管理员密码有效期', 'type' => self::T_NUMBER, 'default' => 0, 'unit' => '天', 'help' => '0 = 永不过期；超期后登录需改密', 'keywords' => '密码 过期 有效期 管理员'],
                        ],
                    ],
                    'admin_findpwd' => [
                        'title' => '管理员找回密码',
                        'desc'  => '后台登录页「忘记密码？」的自助重置流程（邮件验证码）',
                        'keys'  => [
                            'admin_findpwd_enabled' => [
                                'label'   => '启用管理员自助找回',
                                'type'    => self::T_BOOL,
                                'default' => 1,
                                'help'    => '关闭后后台登录页不再显示「忘记密码？」，只能由超级管理员在「管理员」页代重置',
                                'keywords' => '管理员 找回 密码 忘记 后台',
                            ],
                            'admin_findpwd_debug' => [
                                'label'   => '回显验证码（仅本地联调）',
                                'type'    => self::T_BOOL,
                                'default' => 0,
                                'help'    => '需同时开启 debug 才生效。管理员账号权限极高，生产环境严禁开启，否则存在后台被接管风险',
                                'keywords' => '管理员 验证码 回显 调试 debug',
                            ],
                        ],
                    ],
                    'access' => [
                        'title' => '访问控制',
                        'desc'  => '登录 IP 策略与 API 跨域白名单',
                        'keys'  => [
                            'login_ip_policy' => [
                                'label'   => '登录 IP 访问策略',
                                'type'    => self::T_SELECT,
                                'options' => ['0' => '关闭：不限 IP', '1' => '白名单：仅名单内可登录', '2' => '黑名单：名单内禁止登录'],
                                'default' => 0,
                                'keywords' => 'IP 白名单 黑名单 访问',
                            ],
                            'login_ip_list' => ['label' => 'IP 名单', 'type' => self::T_TEXTAREA, 'default' => '', 'help' => '每行一个，支持精确 IP / 通配 192.168.1.* / CIDR 10.0.0.0/8', 'keywords' => 'IP 名单 白名单 黑名单'],
                            'cors_origins'  => ['label' => 'API 跨域来源白名单', 'type' => self::T_TEXT, 'default' => '', 'help' => '逗号分隔，留空=仅同源', 'keywords' => 'cors 跨域 来源 origin'],
                        ],
                    ],
                    'login_code' => [
                        'title' => '验证码登录',
                        'desc'  => '邮箱 / 手机号验证码登录：用户输入邮箱或手机号，接收验证码完成登录，无需密码',
                        'keys'  => [
                            'login_code_enabled'       => ['label' => '启用验证码登录', 'type' => self::T_BOOL, 'default' => 1, 'help' => '总开关：开启后登录页显示验证码登录入口', 'keywords' => '验证码 登录 邮箱 手机号 开关'],
                            'login_code_email_enabled' => ['label' => '邮箱验证码登录', 'type' => self::T_BOOL, 'default' => 1, 'help' => '允许用户通过邮箱接收验证码登录', 'keywords' => '验证码 登录 邮箱 email'],
                            'login_code_sms_enabled'   => ['label' => '手机号验证码登录', 'type' => self::T_BOOL, 'default' => 0, 'help' => '允许用户通过手机号接收验证码登录（需先接入短信服务）', 'keywords' => '验证码 登录 手机号 sms 短信'],
                            'login_code_ttl'           => ['label' => '验证码有效期', 'type' => self::T_NUMBER, 'default' => 300, 'unit' => '秒', 'help' => '默认 300（5 分钟）', 'keywords' => '验证码 登录 ttl 有效期'],
                            'login_code_interval'      => ['label' => '发送间隔', 'type' => self::T_NUMBER, 'default' => 60, 'unit' => '秒', 'help' => '同一邮箱/手机号两次发送的最小间隔', 'keywords' => '验证码 登录 间隔 频率'],
                        ],
                    ],
                    'passkey' => [
                        'title' => '通行密钥（WebAuthn）',
                        'desc'  => '无密码登录：用户使用设备生物识别（指纹 / 面容 / PIN）或安全密钥完成认证',
                        'keys'  => [
                            'passkey_enabled'      => ['label' => '启用通行密钥', 'type' => self::T_BOOL, 'default' => 0, 'help' => '开启后登录页显示通行密钥入口，安全中心可管理密钥。浏览器要求「合法域名 + 安全上下文」：请用 localhost 或 HTTPS 域名访问；用 IP（含 127.0.0.1）访问时入口会自动隐藏', 'keywords' => 'passkey webauthn 通行密钥 无密码 生物识别'],
                            'passkey_rp_id'        => ['label' => 'RP ID（域名）', 'type' => self::T_TEXT, 'default' => '', 'help' => '留空自动从站点地址推导；必须是合法域名（不能填 IP），通行密钥永久绑定此域名，更换域名需重新注册', 'keywords' => 'passkey rp id 域名 relying party'],
                            'passkey_rp_name'      => ['label' => 'RP 显示名称', 'type' => self::T_TEXT, 'default' => '', 'help' => '留空使用站点名称；显示在浏览器的密钥选择弹窗中', 'keywords' => 'passkey rp name 显示名称'],
                            'passkey_uv'           => [
                                'label'   => '用户验证要求',
                                'type'    => self::T_SELECT,
                                'options' => ['preferred' => 'preferred（推荐，优先生物识别）', 'required' => 'required（强制生物识别/PIN）', 'discouraged' => 'discouraged（仅检查用户在场）'],
                                'default' => 'preferred',
                                'help'    => 'required 会拒绝不支持用户验证的设备（如纯 USB 密钥）',
                                'keywords' => 'passkey uv 用户验证 生物识别',
                            ],
                            'passkey_timeout'      => ['label' => '操作超时', 'type' => self::T_NUMBER, 'default' => 120, 'unit' => '秒', 'help' => '浏览器等待用户完成认证的最大时间', 'keywords' => 'passkey timeout 超时'],
                            'passkey_max_per_user' => ['label' => '每用户最大密钥数', 'type' => self::T_NUMBER, 'default' => 10, 'unit' => '个', 'help' => '限制每位用户可注册的通行密钥数量', 'keywords' => 'passkey max 上限 数量'],
                        ],
                    ],
                ],
            ],

            // ==================== 4. 会话与单点登录 ====================
            'sso' => [
                'title' => '会话与单点登录',
                'desc'  => '各类凭证的有效期',
                'sections' => [
                    'ttl' => [
                        'title' => '凭证有效期',
                        'desc'  => '单位：秒',
                        'keys'  => [
                            'session_ttl'  => ['label' => '普通会话', 'type' => self::T_NUMBER, 'default' => 7200, 'unit' => '秒', 'help' => '默认 7200（2 小时）', 'keywords' => 'session 会话 有效期'],
                            'remember_ttl' => ['label' => '记住登录', 'type' => self::T_NUMBER, 'default' => 2592000, 'unit' => '秒', 'help' => '默认 2592000（30 天）', 'keywords' => 'remember 记住我 有效期'],
                            'ticket_ttl'   => ['label' => 'Ticket', 'type' => self::T_NUMBER, 'default' => 60, 'unit' => '秒', 'help' => 'SSO 票据，默认 60 秒', 'keywords' => 'ticket 票据 sso'],
                            'token_ttl'    => ['label' => 'Token', 'type' => self::T_NUMBER, 'default' => 604800, 'unit' => '秒', 'help' => '接口令牌，默认 604800（7 天）', 'keywords' => 'token 令牌 有效期'],
                        ],
                    ],
                ],
            ],

            // ==================== 5. 邮件服务 ====================
            'mail' => [
                'title' => '邮件服务',
                'desc'  => 'SMTP 发信配置（找回密码、通知邮件）',
                'sections' => [
                    'smtp' => [
                        'title' => 'SMTP 连接',
                        'desc'  => '主机、端口与账号凭据',
                        'keys'  => [
                            'mail_enabled' => ['label' => '邮件发送开关', 'type' => self::T_BOOL, 'default' => 0, 'help' => '关闭时相关邮件只记录日志不实际发送', 'keywords' => 'mail 邮件 开关 smtp'],
                            'mail_host'    => ['label' => 'SMTP 主机', 'type' => self::T_TEXT, 'default' => '', 'keywords' => 'smtp host 邮件'],
                            'mail_port'    => ['label' => 'SMTP 端口', 'type' => self::T_NUMBER, 'default' => 465, 'help' => '465=SSL，587=STARTTLS', 'keywords' => 'smtp port 端口'],
                            'mail_user'    => ['label' => 'SMTP 账号', 'type' => self::T_TEXT, 'default' => '', 'keywords' => 'smtp 账号 邮件'],
                            'mail_pass'    => ['label' => 'SMTP 密码', 'type' => self::T_PASSWORD, 'default' => '', 'sensitive' => true, 'keywords' => 'smtp 密码 授权码'],
                        ],
                    ],
                    'sender' => [
                        'title' => '发件人',
                        'desc'  => '对外显示的发件地址',
                        'keys'  => [
                            'mail_from' => ['label' => '发件人地址', 'type' => self::T_TEXT, 'default' => '', 'keywords' => 'from 发件人 邮件'],
                        ],
                    ],
                ],
            ],

            // ==================== 6. 短信服务 ====================
            'sms' => [
                'title' => '短信服务',
                'desc'  => '验证码网关总开关、公共策略与各服务商参数',
                'sections' => [
                    'base' => [
                        'title' => '网关总开关与调度',
                        'desc'  => '服务商选择、调度策略与验证码形态',
                        'keys'  => [
                            'sms_enabled'    => ['label' => '短信发送开关', 'type' => self::T_BOOL, 'default' => 0, 'keywords' => 'sms 短信 开关'],
                            'sms_provider'   => [
                                'label'   => '主服务商',
                                'type'    => self::T_SELECT,
                                'options' => ['' => '未接入（调试模式）', 'tencent' => '腾讯云', 'aliyun' => '阿里云', 'smsbao' => '短信宝', 'huawei' => '华为云'],
                                'default' => '',
                                'keywords' => 'sms 服务商 短信',
                            ],
                            'sms_strategy'   => [
                                'label'   => '调度策略',
                                'type'    => self::T_SELECT,
                                'options' => ['failover' => '故障自动切换', 'priority' => '优先级', 'roundrobin' => '轮询'],
                                'default' => 'failover',
                                'keywords' => 'sms 调度 策略 短信',
                            ],
                            'sms_code_style' => [
                                'label'   => '验证码形态',
                                'type'    => self::T_SELECT,
                                'options' => ['digits' => '纯数字 6 位', 'letters' => '纯字母 4 位', 'mixed' => '数字+字母 4 位'],
                                'default' => 'digits',
                                'keywords' => 'sms 验证码 样式 短信',
                            ],
                        ],
                    ],
                    'common' => [
                        'title' => '公共策略',
                        'desc'  => '有效期、频率限制与服务商私有配置（JSON）',
                        'keys'  => [
                            'sms_common'    => ['label' => '公共配置', 'type' => self::T_JSON, 'default' => ['code_ttl' => 600, 'send_interval' => 60, 'daily_limit' => 10, 'ip_daily_limit' => 30, 'template_var' => 'code', 'strategy' => 'failover'], 'help' => 'JSON：有效期 / 发送间隔 / 每日上限 / 模板变量', 'keywords' => 'sms 公共 频率 限制 短信'],
                            'sms_providers' => ['label' => '服务商私有配置', 'type' => self::T_JSON, 'default' => [], 'help' => 'JSON：{ 服务商: { enabled, priority, ... } }，密钥可留空由环境变量注入', 'keywords' => 'sms 私有 配置 短信'],
                        ],
                    ],
                    'tencent' => [
                        'title' => '腾讯云短信',
                        'desc'  => 'SecretId / SecretKey / 签名与模板',
                        'keys'  => [
                            'sms_tencent_secret_id'   => ['label' => 'SecretId', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '腾讯云 短信 sms'],
                            'sms_tencent_secret_key'  => ['label' => 'SecretKey', 'type' => self::T_PASSWORD, 'default' => '', 'sensitive' => true, 'keywords' => '腾讯云 短信 sms'],
                            'sms_tencent_sdk_app_id'  => ['label' => 'SdkAppId', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '腾讯云 短信 sms'],
                            'sms_tencent_sign'        => ['label' => '短信签名', 'type' => self::T_TEXT, 'default' => '', 'help' => '需在腾讯云控制台审核通过', 'keywords' => '腾讯云 签名 sms'],
                            'sms_tencent_template_id' => ['label' => '模板 ID', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '腾讯云 模板 sms'],
                            'sms_tencent_region'      => ['label' => '地域', 'type' => self::T_TEXT, 'default' => 'ap-guangzhou', 'keywords' => '腾讯云 地域 sms'],
                        ],
                    ],
                    'aliyun' => [
                        'title' => '阿里云短信',
                        'desc'  => 'AccessKey / 签名与模板',
                        'keys'  => [
                            'sms_aliyun_access_key_id'     => ['label' => 'AccessKeyId', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '阿里云 短信 sms'],
                            'sms_aliyun_access_key_secret' => ['label' => 'AccessKeySecret', 'type' => self::T_PASSWORD, 'default' => '', 'sensitive' => true, 'keywords' => '阿里云 短信 sms'],
                            'sms_aliyun_sign_name'         => ['label' => '短信签名', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '阿里云 签名 sms'],
                            'sms_aliyun_template_code'     => ['label' => '模板 CODE', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '阿里云 模板 sms'],
                            'sms_aliyun_region'            => ['label' => '地域', 'type' => self::T_TEXT, 'default' => 'cn-hangzhou', 'keywords' => '阿里云 地域 sms'],
                        ],
                    ],
                    'smsbao' => [
                        'title' => '短信宝',
                        'desc'  => '账号、密码与签名前缀',
                        'keys'  => [
                            'sms_smsbao_user' => ['label' => '账号', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '短信宝 sms'],
                            'sms_smsbao_pass' => ['label' => '密码', 'type' => self::T_PASSWORD, 'default' => '', 'sensitive' => true, 'keywords' => '短信宝 sms'],
                            'sms_smsbao_sign' => ['label' => '短信签名', 'type' => self::T_TEXT, 'default' => '', 'help' => '短信内容前缀，需与平台一致', 'keywords' => '短信宝 签名 sms'],
                        ],
                    ],
                    'huawei' => [
                        'title' => '华为云短信',
                        'desc'  => '旧扁平键兼容（新接入建议用「服务商私有配置」）',
                        'keys'  => [
                            'sms_huawei_app_key'         => ['label' => 'APP Key', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '华为云 sms'],
                            'sms_huawei_app_secret'      => ['label' => 'APP Secret', 'type' => self::T_PASSWORD, 'default' => '', 'sensitive' => true, 'keywords' => '华为云 sms'],
                            'sms_huawei_signature'       => ['label' => '短信签名', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '华为云 sms'],
                            'sms_huawei_template_id'     => ['label' => '模板 ID', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '华为云 sms'],
                            'sms_huawei_api_url'         => ['label' => '接口地址', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '华为云 sms'],
                            'sms_huawei_from'            => ['label' => '通道号', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '华为云 sms'],
                            'sms_huawei_status_callback' => ['label' => '状态回调地址', 'type' => self::T_TEXT, 'default' => '', 'keywords' => '华为云 sms'],
                        ],
                    ],
                ],
            ],

            // ==================== 7. UCenter 对接 ====================
            'ucenter' => [
                'title' => 'UCenter 对接',
                'desc'  => 'UCenter 通信参数与通知接收策略',
                'sections' => [
                    'conn' => [
                        'title' => '连接配置',
                        'desc'  => '地址、密钥、AppID 与通信方式',
                        'keys'  => [
                            'uc_enabled' => ['label' => '启用 UCenter', 'type' => self::T_BOOL, 'default' => 1, 'keywords' => 'ucenter uc 启用'],
                            'uc_driver'  => ['label' => '驱动', 'type' => self::T_SELECT, 'options' => ['http' => 'http：真实 UCenter', 'mock' => 'mock：本地模拟'], 'default' => 'http', 'help' => '生产必须为 http；mock 仅供本地无 UCenter 时调试，会使注册/登录不写入真实 UCenter', 'keywords' => 'ucenter 驱动 driver'],
                            'uc_url'     => ['label' => 'UCenter Server 地址', 'type' => self::T_TEXT, 'default' => 'http://ucenter.heicat.com/uc_server', 'help' => '上线前须与 UCenter 服务端核对', 'keywords' => 'ucenter 地址 url'],
                            'uc_ip'      => ['label' => 'UCenter Server IP', 'type' => self::T_TEXT, 'default' => '', 'help' => '可留空', 'keywords' => 'ucenter ip'],
                            'uc_key'     => ['label' => '通信密钥', 'type' => self::T_PASSWORD, 'default' => '', 'sensitive' => true, 'help' => '需与 UCenter 端完全一致', 'keywords' => 'ucenter 密钥 key'],
                            'uc_appid'   => ['label' => '应用 AppID', 'type' => self::T_NUMBER, 'default' => 4, 'help' => '须与 UCenter 端本应用 AppID 一致', 'keywords' => 'ucenter appid 应用'],
                            'uc_connect' => ['label' => '通信方式', 'type' => self::T_SELECT, 'options' => ['post' => 'post', 'mysql' => 'mysql'], 'default' => 'post', 'keywords' => 'ucenter 通信'],
                            'uc_charset' => ['label' => '字符集', 'type' => self::T_TEXT, 'default' => 'utf-8', 'keywords' => 'ucenter 字符集'],
                            'uc_timeout' => ['label' => '请求超时', 'type' => self::T_NUMBER, 'default' => 10, 'unit' => '秒', 'keywords' => 'ucenter 超时 timeout'],
                        ],
                    ],
                    'notify' => [
                        'title' => '通知接收（UCenter → 应用）',
                        'desc'  => '对应 UCenter 后台「通知设置」各开关',
                        'keys'  => [
                            'uc_notify_enabled' => ['label' => '接收通知总开关', 'type' => self::T_BOOL, 'default' => 1, 'keywords' => 'ucenter 通知 notify'],
                            'uc_notify_ttl'     => ['label' => '时间戳允许偏差', 'type' => self::T_NUMBER, 'default' => 3600, 'unit' => '秒', 'help' => '0 = 不校验', 'keywords' => 'ucenter 通知 时间戳'],
                            'uc_notify_strict'  => ['label' => '严格模式', 'type' => self::T_BOOL, 'default' => 0, 'help' => '未知通知动作直接拒绝', 'keywords' => 'ucenter 通知 严格'],
                            'uc_notify_ips'     => ['label' => '来源 IP 白名单', 'type' => self::T_TEXT, 'default' => '', 'help' => '逗号分隔，留空不限制', 'keywords' => 'ucenter 通知 ip'],
                            'uc_notify_actions' => ['label' => '各通知动作开关', 'type' => self::T_JSON, 'default' => [], 'keywords' => 'ucenter 通知 动作'],
                            'uc_notify_keep'    => ['label' => '通知记录保留条数', 'type' => self::T_NUMBER, 'default' => 500, 'unit' => '条', 'keywords' => 'ucenter 通知 保留'],
                            'uc_notify_plain'   => ['label' => '允许明文直传（调试）', 'type' => self::T_BOOL, 'default' => 0, 'keywords' => 'ucenter 通知 明文 调试'],
                        ],
                    ],
                ],
            ],

            // ==================== 8. 第三方登录 ====================
            'oauth' => [
                'title' => '第三方登录',
                'desc'  => 'OAuth 通用策略与各平台参数',
                'sections' => [
                    'base' => [
                        'title' => '通用策略',
                        'desc'  => '未绑定账号时的处理方式',
                        'keys'  => [
                            'oauth_auto_register' => ['label' => '未绑定时自动创建并绑定账号', 'type' => self::T_BOOL, 'default' => 1, 'keywords' => 'oauth 第三方 自动注册'],
                        ],
                    ],
                    'providers' => [
                        'title' => '平台配置',
                        'desc'  => 'JSON：{ "enabled":1, "appid":"", "secret":"" }',
                        'keys'  => [
                            'oauth_qq'        => ['label' => 'QQ 登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth qq'],
                            'oauth_wechat'    => ['label' => '微信登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth 微信 wechat'],
                            'oauth_baidu'     => ['label' => '百度登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth 百度'],
                            'oauth_alipay'    => ['label' => '支付宝登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth 支付宝'],
                            'oauth_github'    => ['label' => 'GitHub 登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth github'],
                            'oauth_google'    => ['label' => 'Google 登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth google'],
                            'oauth_microsoft' => ['label' => '微软登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth 微软 microsoft'],
                            'oauth_huawei'    => ['label' => '华为登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth 华为'],
                            'oauth_xiaomi'    => ['label' => '小米登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth 小米'],
                            'oauth_hbuilder'  => ['label' => 'HBuilder 登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth hbuilder'],
                            'oauth_weibo'     => ['label' => '新浪微博登录', 'type' => self::T_JSON, 'default' => ['enabled' => 0, 'appid' => '', 'secret' => ''], 'keywords' => 'oauth 微博'],
                        ],
                    ],
                ],
            ],

            // ==================== 9. 系统与运维 ====================
            'system' => [
                'title' => '系统与运维',
                'desc'  => '运行模式、维护公告与系统内部键值',
                'sections' => [
                    'runtime' => [
                        'title' => '运行模式',
                        'desc'  => '调试模式与维护模式',
                        'keys'  => [
                            'debug'            => ['label' => '调试模式', 'type' => self::T_BOOL, 'default' => 0, 'help' => '开启后暴露详细错误，生产环境请关闭', 'keywords' => 'debug 调试 错误'],
                            'maintenance_mode' => ['label' => '维护模式', 'type' => self::T_BOOL, 'default' => 0, 'help' => '开启后前台暂停服务并显示维护公告', 'keywords' => '维护 升级 公告'],
                        ],
                    ],
                    'internal' => [
                        'title' => '系统内部键值',
                        'desc'  => '由系统自动维护，只读展示（如需变更请使用对应功能页）',
                        'keys'  => [],
                        // 动态匹配：管理员两步验证开关（按管理员 ID 生成）
                        'dynamic' => [
                            'prefix' => 'admin_totp_enabled_',
                            'label'  => '管理员两步验证开关',
                            'type'   => self::T_STATIC,
                            'help'   => '管理员在「安全中心」开启/关闭 TOTP 时自动写入',
                        ],
                    ],
                ],
            ],
        ];
        return self::$schema;
    }

    /** 模块清单（保持定义顺序）：[id => ['title','desc','sections']] */
    public static function modules()
    {
        return self::schema();
    }

    /** 键 → [module, section, def] 反向索引（含动态段生成的条目） */
    public static function index()
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $idx = [];
        foreach (self::schema() as $mid => $mod) {
            foreach ($mod['sections'] as $sid => $sec) {
                foreach (($sec['keys'] ?? []) as $k => $def) {
                    $idx[$k] = ['module' => $mid, 'section' => $sid, 'def' => $def];
                }
            }
        }
        self::$index = $idx;
        return self::$index;
    }

    /** 某个键的定义（含动态段匹配），未收录返回 null */
    public static function def($key)
    {
        $idx = self::index();
        if (isset($idx[$key])) {
            return $idx[$key]['def'];
        }
        foreach (self::schema() as $mod) {
            foreach ($mod['sections'] as $sec) {
                if (empty($sec['dynamic'])) {
                    continue;
                }
                $d = $sec['dynamic'];
                if (strpos($key, $d['prefix']) === 0) {
                    return [
                        'label' => $d['label'] . '（' . substr($key, strlen($d['prefix'])) . '）',
                        'type'  => $d['type'],
                        'help'  => $d['help'] ?? '',
                    ];
                }
            }
        }
        return null;
    }

    /** 键所属模块 id（即 settings.s_group），未收录返回 'system' */
    public static function moduleOf($key)
    {
        $idx = self::index();
        if (isset($idx[$key])) {
            return $idx[$key]['module'];
        }
        foreach (self::schema() as $mid => $mod) {
            foreach ($mod['sections'] as $sec) {
                if (!empty($sec['dynamic']) && strpos($key, $sec['dynamic']['prefix']) === 0) {
                    return $mid;
                }
            }
        }
        return 'system';
    }

    /** 键所属分组 id，未收录返回 '' */
    public static function sectionOf($key)
    {
        $idx = self::index();
        if (isset($idx[$key])) {
            return $idx[$key]['section'];
        }
        foreach (self::schema() as $mod) {
            foreach ($mod['sections'] as $sid => $sec) {
                if (!empty($sec['dynamic']) && strpos($key, $sec['dynamic']['prefix']) === 0) {
                    return $sid;
                }
            }
        }
        return '';
    }

    /** 大纲默认值（无 DB 行且无文件默认值时使用） */
    public static function defaultValue($key)
    {
        $def = self::def($key);
        return ($def && array_key_exists('default', $def)) ? $def['default'] : '';
    }

    /** 大纲收录的全部键名 */
    public static function knownKeys()
    {
        return array_keys(self::index());
    }

    /**
     * 从 settings 行中挑出大纲未收录的键（保证改版不丢配置）
     * @param array $rows  settings 表行（含 s_key / s_value / remark）
     * @return array
     */
    public static function uncategorized($rows)
    {
        $known = self::knownKeys();
        $out = [];
        foreach ($rows as $r) {
            $k = $r['s_key'] ?? '';
            if ($k === '' || in_array($k, $known, true)) {
                continue;
            }
            if (self::def($k) !== null) {
                continue; // 动态段（如 admin_totp_enabled_*）
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * 按类型归一化并校验取值。
     * @return array [ok(bool), value(mixed), msg(string)]
     */
    public static function normalize($key, $value)
    {
        $def = self::def($key);
        if (!$def) {
            // 未收录：原样保存，仅做字符串收尾
            return [true, is_string($value) ? trim($value) : $value, ''];
        }
        $type = $def['type'] ?? self::T_TEXT;
        switch ($type) {
            case self::T_BOOL:
                $v = (string)$value === '1' || $value === 1 || $value === true
                    || strtolower((string)$value) === 'true' ? 1 : 0;
                return [true, $v, ''];

            case self::T_NUMBER:
                if ($value === '' ) {
                    $value = 0;
                }
                if (!is_numeric($value)) {
                    return [false, null, ($def['label'] ?? $key) . ' 必须为数字'];
                }
                return [true, $value + 0, ''];

            case self::T_SELECT:
                $opts = $def['options'] ?? [];
                // 键统一按字符串比较（option 的 key 可能是 int 0/1/2）
                $hit = false;
                foreach (array_keys($opts) as $ok) {
                    if ((string)$ok === (string)$value) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    return [false, null, ($def['label'] ?? $key) . ' 取值不在允许范围内'];
                }
                return [true, (string)$value, ''];

            case self::T_JSON:
                if ($value === '' || $value === null) {
                    return [true, [], ''];
                }
                if (is_array($value)) {
                    return [true, $value, ''];
                }
                $decoded = json_decode((string)$value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [false, null, ($def['label'] ?? $key) . ' 不是合法 JSON：' . json_last_error_msg()];
                }
                return [true, $decoded, ''];

            case self::T_STATIC:
                return [false, null, ($def['label'] ?? $key) . ' 为系统内部键值，不可编辑'];

            default: // text / textarea / password
                return [true, (string)$value, ''];
        }
    }

    /** 搜索：按标签 / 键名 / 说明 / 关键词匹配，返回命中的键名数组 */
    public static function search($q)
    {
        $q = mb_strtolower(trim((string)$q), 'UTF-8');
        if ($q === '') {
            return [];
        }
        $hit = [];
        foreach (self::schema() as $mid => $mod) {
            foreach ($mod['sections'] as $sid => $sec) {
                foreach (($sec['keys'] ?? []) as $k => $def) {
                    $hay = mb_strtolower(
                        $k . ' ' . ($def['label'] ?? '') . ' ' . ($def['help'] ?? '')
                        . ' ' . ($def['keywords'] ?? '') . ' ' . ($mod['title'] ?? '') . ' ' . ($sec['title'] ?? ''),
                        'UTF-8'
                    );
                    if (mb_strpos($hay, $q, 0, 'UTF-8') !== false) {
                        $hit[] = $k;
                    }
                }
            }
        }
        return $hit;
    }

    /** 供后台展示：把 DB 行按大纲重排为 [moduleId => [sectionId => [key => row]]] */
    public static function organize($rows)
    {
        $values  = [];
        $remarks = [];
        foreach ($rows as $r) {
            $raw = $r['s_value'] ?? '';
            $d   = json_decode((string)$raw, true);
            $values[$r['s_key']]  = ($d === null && $raw !== 'null') ? $raw : $d;
            $remarks[$r['s_key']] = $r['remark'] ?? '';
        }
        $out = [];
        foreach (self::schema() as $mid => $mod) {
            foreach ($mod['sections'] as $sid => $sec) {
                foreach (($sec['keys'] ?? []) as $k => $def) {
                    $out[$mid][$sid][$k] = [
                        's_key'  => $k,
                        'value'  => array_key_exists($k, $values) ? $values[$k] : ($def['default'] ?? ''),
                        'in_db'  => array_key_exists($k, $values),
                        'remark' => $remarks[$k] ?? '',
                        'def'    => $def,
                    ];
                }
                // 动态段（如 admin_totp_enabled_*）：把库中匹配前缀的键挂到该分组下
                if (!empty($sec['dynamic'])) {
                    $prefix = $sec['dynamic']['prefix'];
                    foreach ($values as $k => $v) {
                        if (strpos($k, $prefix) === 0) {
                            $out[$mid][$sid][$k] = [
                                's_key'  => $k,
                                'value'  => $v,
                                'in_db'  => true,
                                'remark' => $remarks[$k] ?? '',
                                'def'    => self::def($k) ?: [],
                            ];
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** 清空缓存（CaptchaModel 等动态选项变更后调用） */
    public static function flush()
    {
        self::$schema = null;
        self::$index  = null;
    }
}
