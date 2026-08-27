# FriendLinks 插件架构设计

> 状态：Release v1.0.0
> 类型：Typecho 独立插件技术设计
> 目标版本：Typecho 1.2 及以上、PHP 7.4 及以上
> 工作名称：`FriendLinks`，最终名称待定

## 1. 目标

开发一个与主题无关的 Typecho 友情链接插件，完整负责：

- 友情链接、分类和排序管理。
- 独立页面展示及前端资源。
- URL、DNS、TLS/SSL、域名到期状态检测。
- 定时任务、检测缓存、状态历史和异常标记。
- 钉钉机器人、通用 Webhook 和 SMTP 邮件通知。
- CSV、JSON 数据导入导出。
- 后续反向友链检测的扩展能力。

插件不得调用主题函数，不要求主题增加模板、CSS 或兼容代码。更换主题后，友链数据、检测能力和展示页面仍然可用。

## 2. 非目标

首个稳定版本不包含：

- 面向任意互联网资产的通用监控平台。
- 需要登录、Cookie 或自定义认证头的私有 URL 检测。
- 自动抓取并永久保存对方网站完整内容。
- 基于页面内容相似度判断网站是否易主。
- 自动删除异常友链。
- 把一次网络失败直接判定为永久失效。

反向友链和页面内容指纹仍作为后续模块，不进入当前核心链路。

## 3. 架构决策

### 3.1 插件拥有完整功能

插件同时拥有管理、展示和检测能力：

- 管理端、数据表、检测器、调度器均在插件内。
- 前端 HTML 和 CSS 均由插件生成。
- 主题只需遵循 Typecho 标准页面模板约定，调用 `$this->content()`、`$this->header()` 和 `$this->footer()`。
- 插件运行时不检测当前主题名称，也不包含主题专用渲染或数据适配分支。

### 3.2 使用普通独立页面承载展示

管理员先在 Typecho 原生“独立页面”管理中创建页面，再在插件中选择一个已发布的普通独立页面。插件不创建、删除或修改页面模板。

插件在 `Widget\Base\Contents::contentEx` 钩子中检查当前内容 CID：

1. 不是已配置页面时，原样返回内容。
2. 仅当前对象是前台 `Widget\Archive`、路由为单篇独立页面且 CID 匹配时继续。
3. 保留管理员填写的页面介绍内容。
4. 在介绍内容后追加插件生成的友链列表。

这样仍然使用站点现有页头、页脚、导航和 SEO 路由，但不依赖任何主题专用模板。

不使用主题 `page-links.php`，也不要求在页面正文中放置短代码。页面 CID 是唯一绑定依据，避免文章作者意外触发插件渲染。

为兼容 Typecho 1.2 和多个内容插件串联，回调固定使用三参数：

```php
public static function injectLinks($content, $widget, $lastResult = null)
{
    $baseContent = null === $lastResult ? $content : $lastResult;
    // 非目标前台独立页面直接返回 $baseContent。
    // 目标页面返回 $baseContent 与 Renderer 输出的拼接结果。
}
```

插件注册到 `Widget\Base\Contents::pluginHandle()->contentEx`。回调必须以 `$lastResult ?? $content` 为输入基底，不能覆盖其他插件已经处理过的内容。

承载页必须使用主题的普通页面模板。设置页应检测自定义模板：

- 用户在 Typecho 中新建页面时不指定自定义模板。
- 选择现有页面时，如果它仍使用 `page-links.php` 等友链模板，阻止保存并提示用户在 Typecho 页面管理中切换为普通页面模板。
- 页面被删除、转为草稿或密码保护后停止公开渲染，并在插件后台显示配置错误。

### 3.3 页面请求只读缓存

访客打开友链页面时只读取数据库中的友链和最近检测结果，不发起任何外部网络请求。

所有 HTTP、DNS、TLS 和 RDAP 请求只能由后台任务执行。这样可以避免：

- 页面响应时间受远端站点影响。
- 访客并发放大外部请求。
- 网络错误拖垮 PHP-FPM 工作进程。
- 前台请求成为 SSRF 触发入口。

### 3.4 系统 Cron 为主调度器

Typecho 没有可靠的内建定时任务，因此采用：

1. CLI Worker：主方案，由插件自动安装的系统 Cron 调用。
2. 签名 HTTP Worker：默认关闭的可选外部触发入口。
3. 访客触发的伪 Cron：不实现。

自动管理模式采用 Linux-only 约束：启用时通过参数化 `proc_open` 调用当前 PHP Web 用户的 `crontab`，安装每分钟唤醒一次的调度器，再由 CLI 按管理员选择的周期执行；停用和卸载时自动删除。缺少 `proc_open`、`crontab`、可用 PHP CLI 或用户 crontab 权限时，插件仍可启用，但切换为手工 CLI Cron 或签名 HTTP Worker 调度，不回退为访客请求触发。

每个站点在数据库中持久化随机 Cron 种子和安装时的 Linux 有效 UID，并结合规范化插件路径派生实例 ID，使用成对注释标记管理自己的块。数据库克隆到同一系统用户的其他路径时会派生不同 ID；后续检查、停用和卸载必须由同一系统用户执行：

```text
# BEGIN FriendLinks <instance-id>
* * * * * '<php-cli>' '<plugin>/bin/console.php' check --scheduled --due
# END FriendLinks <instance-id>
```

安装和删除必须保留标记之外的全部用户 Cron，并通过同一 Unix 用户共享的文件锁串行化；写入前再次检查原内容，写入后重新读取并逐字验证，验证失败时恢复原 crontab。重复启用只替换本实例块，不产生重复任务。CLI 入口仍在执行前检查 Typecho 插件启用状态，关闭停用过程与已进入调度队列进程之间的时序窗口。

`crontab -l` 与 `crontab -` 不提供跨工具原子比较交换语义，因此插件启用或停用期间不得同时通过 `crontab -e` 或主机面板修改同一 Linux 用户的其他任务。FriendLinks 自身的多站点并发由共享锁覆盖，外部并发编辑只能通过写前复核和写后校验检测，无法在 user-crontab 接口层彻底消除竞争窗口。

健康页只展示检测统计、最近心跳、运行结果和积压任务，不展示 Cron 管理区块。CLI Worker 设置页展示自动任务状态和最近一次 CLI 运行状态。

设置页允许管理员使用“数值 + 秒/分钟/小时/天/周/月”配置 CLI Worker 周期，并设置每批处理条数与单次运行预算；秒单位最小 60，月按 30 天折算。系统 Cron 每分钟调用带 `--scheduled` 的 CLI；CLI 从写连接读取最近一次 CLI 运行记录，仅在配置周期到期且没有新鲜运行中任务时启动 Worker。环境不支持自动管理时这些控件禁用；PHP CLI 路径、crontab 路径和原始命令不接受后台输入。手工 CLI Cron 不由插件创建或删除。

## 4. 总体结构

```text
Typecho
  |
  +-- Plugin.php
  |     激活、停用、钩子、面板、Action 注册
  |
  +-- Admin
  |     友链管理 / 分类 / 检测状态 / 历史 / 设置 / 导入导出
  |
  +-- Frontend
  |     独立页面内容注入 / HTML Renderer / CSS
  |
  +-- Application
  |     用例编排 / DTO / 状态聚合 / 调度
  |
  +-- Domain
  |     Link / CheckResult / StatusPolicy / Repository 接口
  |
  +-- Infrastructure
        Typecho 数据库适配
        HTTP/DNS/TLS/RDAP 探测
        缓存、锁、迁移、日志
        CLI Worker / 签名 HTTP Worker
```

检测数据流：

```text
Cron
  -> Scheduler 查询到期任务
  -> LeaseManager 原子领取友链
  -> URL Policy + SSRF Guard
  -> DNS Probe
  -> HTTP Probe
  -> TLS Probe（HTTPS）
  -> Domain Probe（按独立 TTL）
  -> Status Aggregator
  -> 更新 Current Status
  -> 追加 History
  -> 同事务写入 Notification Outbox
  -> 释放 Lease
  -> Notification Dispatcher 异步投递
```

前端数据流：

```text
独立页面请求
  -> Typecho contentEx
  -> LinkQueryService
  -> links + current_status
  -> Renderer
  -> 插件 HTML
```

## 5. 目录设计

```text
FriendLinks/
├── Plugin.php
├── Action.php
├── bootstrap.php
├── composer.json
├── composer.lock
├── bin/
│   └── console.php
├── panel/
│   ├── links.php
│   ├── link-edit.php
│   ├── categories.php
│   ├── health.php
│   ├── history.php
│   ├── import.php
│   ├── notifications.php
│   └── settings.php
├── src/
│   ├── Application/
│   ├── Domain/
│   ├── Infrastructure/
│   └── Presentation/
├── migrations/
│   ├── mysql/
│   ├── pgsql/
│   └── sqlite/
├── assets/
│   ├── frontend.css
│   ├── admin.css
│   └── admin.js
├── templates/
│   └── <name>/
│       ├── manifest.json
│       └── style.css
├── resources/
│   └── public_suffix_list.dat
├── vendor/
├── README.md
└── LICENSE
```

插件发布包包含已安装的 `vendor/`，生产环境不要求执行 Composer。
`Plugin.php` 和 CLI 入口必须显式加载 `vendor/autoload.php`，并将 `TypechoPlugin\FriendLinks\` 的业务类映射到 `src/`。不能假设 Typecho 自带插件自动加载器会自动识别 `src/` 目录。

所有第三方依赖必须：

- 与 PHP 7.4 基线兼容。
- 许可证允许随插件发布。
- 在 `composer.lock` 中固定版本。
- 通过依赖漏洞扫描。

## 6. 模块职责

### 6.1 Plugin Bootstrap

- 检查 Typecho、PHP 和必要扩展版本。
- 创建或升级数据库结构。
- 环境支持时自动安装、验证和删除带实例标记的 Linux 用户 Cron；否则记录手工调度状态。
- 注册后台菜单和面板。
- 注册管理 Action、内容钩子、页头和页脚资源钩子。
- 注册签名 HTTP Worker。
- 停用时先删除插件自动安装的 Cron，再移除路由和面板，但不删除业务数据。

删除数据必须通过 Typecho 插件配置页中的“卸载并删除数据”操作完成，并要求管理员输入 `DELETE` 后二次确认。

### 6.2 Link Management

- 新增、编辑、归档、启用和停用友链。
- 分类、排序和批量操作。
- URL 规范化、重复检查和展示字段校验。
- 单条或批量安排重新检测。
- CSV、JSON 导入导出。
- 导入向导必须预览解析结果、重复 URL 和分类映射，确认后才写入插件表。

### 6.3 Probe Engine

每种检测实现独立接口：

```text
DnsProbe
HttpProbe
TlsProbe
DomainRegistrationProbe
```

探测器只返回结构化结果，不直接决定最终公开状态。最终状态由 `StatusAggregator` 统一计算，避免各模块产生冲突标记。

同一次任务使用共享 `ProbeContext`：

- DNS 探测产生经过安全校验的解析结果。
- HTTP 传输只能使用该批解析结果中已固定的 IP。
- HTTPS 请求在严格验证 TLS 的同时采集证书信息，`TlsProbe` 复用该连接证据，不重复发起正常业务请求。
- 任何仅用于诊断失败证书的补充握手都不能发送 HTTP 请求，也不能把“关闭验证后连接成功”视为可达。

### 6.4 Scheduler

- 按组件 TTL 计算下一次检测时间。
- 为任务加入随机抖动，避免整点集中请求。
- 使用数据库租约防止多个 Cron 重复检测。
- 控制全局并发、单主机并发和单次运行时长。
- 失败后按退避策略重新安排。
- 清理过期历史和缓存。

### 6.5 Renderer

- 输出语义化 HTML。
- 支持通过白名单 JSON 清单和隔离 CSS 切换展示模板，不执行模板 PHP 或 JavaScript。
- 支持分类筛选、排序和分页配置。
- 显示名称、描述、Logo、状态和最后检测时间。
- 对公开状态使用稳定、可访问的文字，不只依赖颜色。
- 不向访客暴露解析 IP、原始异常、响应头或证书链等诊断细节。
- 所有类名使用 `flm-` 前缀，CSS 不修改主题全局元素。

### 6.6 Notification

- 状态变化后由 `NotificationPlanner` 生成不可用、恢复或预警事件。
- 检测结果、历史和通知 Outbox 在同一事务中提交。
- `NotificationDispatcher` 使用独立租约异步投递，失败最多重试五次并指数退避。
- 支持钉钉加签机器人、带可选 HMAC-SHA256 签名的通用 Webhook 和 SMTP 邮件。
- 标题与正文模板只允许白名单占位符替换，不执行 HTML、PHP 或表达式。
- 同一友链、事件和渠道按冷却时间去重；Webhook 使用稳定 `event_id` 供接收方幂等处理。

## 7. 数据模型

所有时间使用 Unix 时间戳，布尔值使用整数，复杂诊断使用 JSON 文本，避免依赖特定数据库的 JSON 类型。

所有表使用 Typecho 数据表前缀。

为兼容 Typecho 支持的不同数据库和既有表结构，表间关系默认由应用层维护，不把物理外键约束作为运行前提。删除分类、归档友链和清理历史必须在应用服务中显式维护引用完整性。

### 7.1 `flm_categories`

| 字段 | 用途 |
| --- | --- |
| `id` | 主键 |
| `name` | 分类显示名称 |
| `slug` | 唯一稳定标识 |
| `sort_order` | 排序 |
| `enabled` | 是否公开显示 |
| `created_at` | 创建时间 |
| `updated_at` | 更新时间 |

第一阶段每条友链属于零个或一个分类，不引入多对多标签模型。

### 7.2 `flm_links`

| 字段 | 用途 |
| --- | --- |
| `id` | 主键 |
| `category_id` | 分类逻辑外键，可空 |
| `name` | 友链名称 |
| `url` | 管理员输入的 URL |
| `normalized_url` | 规范化后的检测 URL |
| `url_hash` | 规范化 URL 的 SHA-256，用于唯一约束 |
| `description` | 描述 |
| `logo_url` | 显式 Logo URL，可空 |
| `sort_order` | 排序 |
| `visibility` | `published`、`draft`、`archived` |
| `check_enabled` | 是否参与自动检测 |
| `created_at` | 创建时间 |
| `updated_at` | 更新时间 |

默认不自动抓取远程 favicon。未设置 Logo 时使用名称首字符生成本地占位符，避免额外的隐私泄露和 SSRF 面。

### 7.3 `flm_current_status`

每条友链一行，既是公开状态来源，也是调度状态。

| 字段 | 用途 |
| --- | --- |
| `link_id` | 主键、友链逻辑外键 |
| `overall_state` | 聚合状态 |
| `reason_code` | 主要原因代码 |
| `http_state` | HTTP 组件状态 |
| `http_code` | 最终 HTTP 状态码 |
| `response_time_ms` | 响应时间 |
| `final_url` | 最终重定向 URL |
| `dns_state` | DNS 组件状态 |
| `tls_state` | TLS 组件状态 |
| `cert_not_after` | 叶证书到期时间 |
| `domain_state` | 域名组件状态 |
| `domain_expires_at` | 注册域名到期时间 |
| `availability_consecutive_failures` | 主链路连续失败次数 |
| `checked_at` | 最近综合检测时间 |
| `dns_checked_at` | 最近 DNS 检测时间 |
| `http_checked_at` | 最近 HTTP 检测时间 |
| `tls_checked_at` | 最近 TLS 检测时间 |
| `domain_checked_at` | 最近域名检测时间 |
| `dns_next_check_at` | 下次 DNS 检测时间 |
| `http_next_check_at` | 下次 HTTP 检测时间 |
| `tls_next_check_at` | 下次证书元数据刷新时间 |
| `domain_next_check_at` | 下次域名注册检测时间 |
| `last_success_at` | 最近一次主链路成功时间 |
| `last_failure_at` | 最近一次主链路失败时间 |
| `state_changed_at` | 聚合状态最近变化时间 |
| `next_check_at` | 上述组件到期时间的最小值，用于调度索引 |
| `lease_token` | Worker 租约令牌 |
| `lease_until` | 租约到期时间 |
| `details_json` | 管理端诊断详情 |

必要索引：

- `flm_categories.slug` 唯一索引。
- `flm_links.url_hash` 唯一索引。
- `flm_links(visibility, category_id, sort_order)` 展示索引。
- `flm_current_status(next_check_at, lease_until)` 调度索引。
- `flm_current_status(overall_state, checked_at)` 状态索引。
- `flm_check_history(link_id, started_at)` 历史索引。
- `flm_runs(started_at, status)` 运行记录索引。
- `flm_cache(namespace, expires_at)` 清理索引。
- `flm_notification_outbox(status, available_at, lease_until)` 通知调度索引。
- `flm_notification_outbox(event_key, channel)` 唯一索引。

### 7.4 `flm_check_history`

| 字段 | 用途 |
| --- | --- |
| `id` | 主键 |
| `link_id` | 友链 ID |
| `run_id` | 一次 Worker 运行标识 |
| `overall_state` | 本次聚合状态 |
| `reason_code` | 主要原因 |
| `http_code` | HTTP 状态码 |
| `response_time_ms` | 响应时间 |
| `started_at` | 开始时间 |
| `finished_at` | 完成时间 |
| `details_json` | 各组件结构化结果 |

默认保留 90 天，可配置为 30 至 365 天。清理任务按批次删除，避免长事务。

### 7.5 `flm_runs`

| 字段 | 用途 |
| --- | --- |
| `run_id` | Worker 运行唯一标识 |
| `mode` | `cli` 或 `http` |
| `status` | `running`、`completed`、`partial`、`failed` |
| `started_at` | 开始时间 |
| `heartbeat_at` | 最近心跳 |
| `finished_at` | 完成时间 |
| `claimed_count` | 领取数量 |
| `completed_count` | 完成数量 |
| `failed_count` | 失败数量 |
| `error_summary` | 截断并脱敏的错误摘要 |

运行记录用于后台健康检查和任务审计，不使用进程内内存代替持久化心跳。

### 7.6 `flm_cache`

| 字段 | 用途 |
| --- | --- |
| `cache_key` | SHA-256 主键 |
| `namespace` | `rdap_bootstrap`、`rdap_domain`、`probe`、`nonce` 等 |
| `payload` | JSON 或文本 |
| `expires_at` | 到期时间 |
| `updated_at` | 更新时间 |

用途包括：

- IANA RDAP Bootstrap 缓存。
- 相同注册域名的 RDAP 结果复用。
- 相同主机和端口的短期 TLS 结果复用。
- 签名 HTTP Worker 的防重放 nonce。

### 7.7 `flm_notification_outbox`

| 字段 | 用途 |
| --- | --- |
| `id` | 主键 |
| `event_key` | 稳定事件标识，与渠道组成唯一约束 |
| `link_id` | 友链逻辑外键 |
| `event_type` | `down`、`recovery` 或 `warning` |
| `channel` | `webhook`、`dingtalk` 或 `email` |
| `subject` | 入队时渲染的标题 |
| `message` | 入队时渲染的纯文本正文 |
| `payload_json` | 通用 Webhook 的结构化事件 |
| `status` | `pending`、`sending`、`sent` 或 `failed` |
| `attempts` | 已开始的投递次数 |
| `available_at` | 下次允许投递时间 |
| `lease_token` / `lease_until` | 通知投递租约 |
| `last_error` | 截断后的最近错误 |
| `created_at` / `sent_at` | 创建与成功时间 |

通知采用至少一次投递语义。Worker 在领取时原子增加尝试次数，进程异常退出后可由过期租约恢复；通用 Webhook 接收方应使用 `event_id` 去重。

## 8. URL 和 SSRF 安全策略

所有网络探测必须先通过统一 `TargetPolicy`：

1. 仅允许 `http` 和 `https`。
2. 禁止 URL 用户名和密码。
3. 默认只允许 80 和 443 端口。
4. 限制 URL、主机名和路径长度。
5. 规范化主机名、默认端口、路径和 IDN。
6. 解析全部 A、AAAA 和 CNAME 结果。
7. 拒绝任一回环、私网、链路本地、保留、组播和云元数据地址。
8. 每次重定向都重新执行完整校验。
9. 手动处理重定向，禁止把 `CURLOPT_FOLLOWLOCATION` 作为安全边界。
10. 只有全部解析结果都通过公网地址校验后，才选择其中一个地址连接。
11. 将选中的已验证 IP 固定到本次连接；连接失败时只能切换到同批次已验证的其他 IP，防止 DNS Rebinding。
12. cURL 必须使用 `CURLOPT_RESOLVE` 或等价能力绑定目标，并在完成后校验 `CURLINFO_PRIMARY_IP` 仍属于已验证集合。
13. 显式清空 cURL 代理配置并禁止继承代理环境；第一版不支持通过代理执行探测。

需要覆盖的地址至少包括：

- IPv4 私网、回环、链路本地和保留段。
- IPv6 `::1`、ULA、链路本地、IPv4 映射地址。
- 十进制、八进制、十六进制和混合格式 IP 表达。
- IPv6 zone-id、控制字符和歧义主机文本。
- 重定向至内网、协议切换和异常端口。

非 ASCII 域名通过 `ext-intl` 转换为 Punycode。缺少该扩展时允许保存和展示，但拒绝启用该 URL 的网络检测，并明确标记原因。

HTTP、TLS、RDAP、PSL 更新和其他 HTTPS 出站请求全部使用同一策略。WHOIS 使用独立的端口 43 策略，但仍必须验证目标 IP、限制 referral，并拒绝非全球单播地址。生产部署额外建议使用网络层出站 ACL 作为第二道边界。

## 9. HTTP 可达性检测

### 9.1 请求策略

- 使用无认证 `GET`，不依赖 `HEAD`。
- `connect_timeout` 默认 3 秒，总超时默认 10 秒。
- 最大重定向 5 次。
- 响应正文最多读取 64 KiB，正文不入库。
- 达到正文上限后的主动中止属于成功的限流行为，不能被记录为传输失败。
- 不保存 Cookie，不执行 JavaScript。
- 使用固定、可识别的 User-Agent。
- 全局并发默认 5，单主机并发默认 1。
- 只对网络错误、408、425、429 和 5xx 执行一次受总超时约束的 GET 重试，`Retry-After` 等待上限为 2 秒。
- Webhook POST 不做进程内即时重试，失败统一交给 Outbox，避免重复副作用。

### 9.2 状态解释

| 情况 | 组件结论 |
| --- | --- |
| 200-299 | 可达 |
| 300-399 且存在合法下一跳 | 继续验证重定向链 |
| 300-399 但无合法 `Location` | 重定向异常 |
| 401、403 | 可达但受限 |
| 404、410 | 目标不存在 |
| 408、425、429 | 临时异常，退避重试 |
| 500-599 | 服务端异常 |
| 其他 4xx | 客户端或策略异常 |
| 连接、解析、超时错误 | 网络异常 |

不得把 403 简单标记为“网站失效”，也不得把所有非 200 状态统一处理。

只有完整重定向链成功结束后，才能按最终状态码解释结果：

- DNS 和 SSRF 检查针对每一跳主机。
- TLS 检查针对每一个 HTTPS 跳转端点，公开 TLS 状态以最终 HTTPS 端点为准。
- 域名注册信息默认针对管理员配置 URL 的原始注册域，跨域最终地址另行记录并提示。
- 循环、超过跳转上限、缺失或非法 `Location` 使用独立原因码。
- 总超时、最大响应体和解压后正文上限覆盖整条重定向链，不在每一跳重新计数。

### 9.3 失败确认

- 首次瞬时失败：`degraded`，保留上次成功信息。
- 连续 2 次失败：`degraded`，公开显示不稳定。
- 连续 3 次失败：`down`。
- 恢复成功 1 次：恢复为正常并清零连续失败。
- 已确认的证书过期和主机名不匹配不受普通网络抖动阈值掩盖；RDAP 到期日期经过只产生高优先级预警。

`availability_consecutive_failures` 只统计 DNS、连接、TLS、超时和 HTTP 主链路失败；401、403、到期预警及辅助组件 `unknown` 不增加连续失败次数。

## 10. DNS 检测

- 解析 A、AAAA 和 CNAME 链，限制最大链深度。
- 区分无记录、解析失败和安全策略拒绝。
- DNS 结果同时用于 URL 可达性和 SSRF 判定。
- 解析结果只在一次探测周期内固定，不长期作为权威 DNS 缓存。
- 公开页面不显示目标 IP。

如果运行环境无法安全获得完整解析结果，则本次网络检测失败关闭，状态记为 `unknown`，不得降级为不受保护的直接请求。

## 11. TLS/SSL 检测

仅对 HTTPS 链接执行：

- TLS 握手是否成功。
- 系统 CA 是否信任证书链。
- 证书主机名是否匹配。
- 证书是否尚未生效、已过期或即将过期。
- 叶证书 `notBefore`、`notAfter` 和签发者摘要。
- 本次连接协商的 TLS 版本和协议错误作为管理端诊断信息。

cURL 的证书链和主机名验证结果是连接有效性的权威来源。OpenSSL 只用于解析已取得的证书元数据，不得用“关闭验证后连接成功”覆盖 cURL 的失败结论。

默认阈值：

- 证书剩余 30 天：`warning`。
- 证书剩余 7 天：高优先级 `warning`。
- 已过期、尚未生效、链不可信或主机名不匹配：立即标记 `down`，原因码必须精确。
- 无法完成握手、连接被重置等可能瞬时恢复的错误：遵循连续失败阈值，先标记 `degraded`。

插件不提供“全局忽略证书错误”开关。
不同 cURL TLS 后端无法提供完整证书详情时返回 `unknown_detail`。第一版不通过单次协商结果推断服务端支持的最低 TLS 版本。

## 12. 域名到期检测

### 12.1 注册域名识别

不能通过“最后两个标签”截取域名。域名注册查询使用 Public Suffix List 的 ICANN 区计算 registrable domain，并转换为 A-label，例如正确识别 `example.co.uk`。PRIVATE 区可用于展示分组，但不能用于决定 RDAP 查询对象。

发布包内置一份 PSL 数据，并允许后台任务通过 HTTPS 更新。更新失败时继续使用上次有效版本。

### 12.2 RDAP

- 从 IANA RDAP Bootstrap 确定注册局服务端点。
- 读取 `eventAction=expiration` 等标准事件。
- Bootstrap 默认缓存 7 天。
- 正常域名结果默认缓存 24 小时。
- 临时错误缓存 1 小时，防止频繁请求注册局。
- 对 429 执行更长退避并尊重 `Retry-After`。

### 12.3 WHOIS

WHOIS 数据格式、频率限制和网络可用性差异较大。第一阶段将其设计为可插拔降级适配器：

- 默认关闭，不作为准确性的必要条件。
- 仅查询已知公共 WHOIS 服务。
- 严格限制端口、响应大小、超时和 referral 深度。
- 解析不到确定日期时返回 `unknown`，不能猜测。

### 12.4 结果语义

| 情况 | 组件状态 |
| --- | --- |
| 到期时间大于 30 天 | `healthy` |
| 30 天内到期 | `warning` |
| 7 天内到期 | 高优先级 `warning` |
| RDAP 到期日期已经过去 | `past_expiration`，高优先级预警 |
| URL 使用 IP 地址 | `not_applicable` |
| 后缀没有 RDAP 服务 | `unsupported` |
| RDAP 404 | `not_found`，需要人工核验 |
| 缺少到期事件或临时解析失败 | `unknown` |

域名状态 `unknown` 不应覆盖已经确认正常的 HTTP 和 TLS 状态，但必须在管理端明确显示。
RDAP 中已经过去的 `expiration` 事件可能处于自动续费或注册局宽限期，不能单独证明域名已经失效。域名注册探测只产生预警；只有 DNS、TLS 或 HTTP 主链路证据才能把整体可用性判定为 `down`。

## 13. 聚合状态模型

公开状态保持有限集合，具体原因另存：

```text
pending
healthy
warning
degraded
down
unknown
disabled
```

典型原因码：

```text
http_unreachable
http_not_found
http_server_error
http_restricted
http_rate_limited
dns_failed
dns_blocked_target
tls_expired
tls_expiring
tls_hostname_mismatch
tls_untrusted
tls_handshake_failed
domain_expiration_passed
domain_expiring
domain_unknown
domain_unsupported
domain_not_applicable
domain_not_found
worker_error
```

聚合规则：

1. `disabled` 和 `pending` 优先按生命周期处理。
2. 证书过期、尚未生效、主机名不匹配或证书链不可信立即产生 `down`。
3. 尚未达到连续失败阈值的网络错误产生 `degraded`。
4. 到期预警产生 `warning`。
5. 域名注册到期日期已过但 HTTP、DNS 仍正常时产生高优先级 `warning`；只有主链路同时失败时才聚合为 `down`。
6. 401、403 表示目标存在，默认产生 `degraded`；可配置为 `healthy`，但不能产生 `down`。
7. 辅助组件 `unknown` 不覆盖已确认的主链路成功。
8. 所有组件都没有结论时才使用整体 `unknown`。
9. `flm_current_status.overall_state` 是前后台唯一健康状态来源。最近一次完整检测结果持续有效，只有后续检测完成并成功写入后才更新；`checked_at` 仅表示检测时间，不参与状态计算。

多个异常同时存在时按 `down`、`degraded`、`warning`、`healthy` 的顺序决定整体状态；主要原因按 DNS/SSRF、TLS、HTTP、域名注册的顺序选择，其余原因保留在组件详情中。

公开标记显示聚合状态和简化原因；管理端显示各组件原始结果。

主链路状态迁移：

| 证据 | 首次或第二次 | 连续第三次 | 成功后 |
| --- | --- | --- | --- |
| NXDOMAIN、无 A/AAAA、连接失败、超时 | `degraded` | `down` | 清零并恢复 |
| TLS 握手中断、连接重置 | `degraded` | `down` | 清零并恢复 |
| 404、410、其他不可用 4xx | `degraded` | `down` | 清零并恢复 |
| 429、5xx | `degraded` | `down` | 清零并恢复 |
| 401、403 | `degraded`，不计失败次数 | 同左 | 正常重新聚合 |
| 证书过期、尚未生效、主机名或信任失败 | 立即 `down` | `down` | 严格验证成功后恢复 |
| RDAP 到期日期经过或临近到期 | `warning`，不计失败次数 | 同左 | 新 RDAP 结果重新聚合 |

## 14. 调度、租约和缓存

默认周期：

| 检测项 | 周期 |
| --- | --- |
| HTTP + DNS | 6 小时 |
| TLS 严格验证 | 每次 HTTPS 请求 |
| 完整证书元数据刷新 | 24 小时 |
| 域名注册 | 24 小时 |
| PSL 更新 | 7 天 |
| 历史清理 | 24 小时 |

每项周期加入正负 10% 随机抖动。

Worker 领取任务时执行条件更新：

```text
UPDATE current_status
SET lease_token = ?, lease_until = ?
WHERE link_id = ?
  AND next_check_at <= now
  AND (lease_until IS NULL OR lease_until < now)
```

只有受影响行数为 1 的 Worker 获得任务。默认租约 5 分钟，Worker 崩溃后任务可自动恢复。

Worker 领取后重新确认友链仍为 `published` 且启用检测，并计算：

```text
due_components = 所有 component_next_check_at <= now 的组件
next_check_at = min(所有 component_next_check_at)
```

只更新本次到期组件，其他组件沿用最近结果。存在以下强制依赖：

- HTTP 到期时必须同时重新执行 DNS 和 SSRF 校验。
- HTTPS 的 HTTP 探测每次都执行严格 TLS 验证。
- `tls_next_check_at` 到期时额外刷新完整证书元数据。
- 普通“立即检测”强制 DNS、HTTP 和 TLS 元数据到期；“完整复检”额外强制域名注册检测。

Worker 写回结果、续租和释放租约时必须同时匹配 `link_id` 与 `lease_token`，写回前还要确认租约未过期。租约过期后，旧 Worker 不得覆盖新 Worker 已写入的结果。接近租约期限时按令牌续租。

结果事务使用条件写回：

```text
UPDATE current_status
SET ..., lease_token = NULL, lease_until = NULL
WHERE link_id = ?
  AND lease_token = ?
  AND lease_until >= now
```

受影响行数必须为 1，随后在同一事务内追加历史并提交；否则回滚并丢弃该 Worker 的过期结果。

一次运行默认限制：

- 最多 50 条友链。
- 最长 240 秒。
- 全局并发 5。
- 单主机并发 1。

检测结果更新、历史追加和租约释放必须在同一事务边界内完成。插件自有表不具备事务能力时终止该任务并在后台报告环境错误，不执行非原子降级写入。

签名 HTTP Worker 使用更严格的上限：单次最多 5 条友链、最长 20 秒。到达上限后保留剩余任务，等待下一次调用，避免 PHP-FPM、反向代理或 CDN 超时。

## 15. HTTP Worker 鉴权

HTTP Worker 作为可选的外部主动触发入口，并要求 HTTPS。
HTTP Worker 默认关闭，管理员必须在设置页显式启用；未启用时入口返回 `403 worker_disabled`，不执行验签、检测或通知投递。CLI Worker 始终是默认和推荐方案。
CLI Worker 与 HTTP Worker 不互斥；两者可同时触发，数据库租约保证同一友链在同一时刻只会被一个 Worker 领取。
入口只接受固定 Typecho 路由上的 `POST`。`bin/console.php` 必须检查 `PHP_SAPI`，拒绝通过 Web SAPI 执行，并在访问插件业务表前确认 FriendLinks 仍处于启用状态；自动 Cron 使用当前站点和 PHP CLI 的绝对路径，不依赖 Cron 工作目录。

请求头：

```text
X-FLM-Timestamp
X-FLM-Nonce
X-FLM-Signature
```

签名内容包括：

```text
HTTP method
request path
timestamp
nonce
SHA-256(body)
```

使用独立随机密钥执行 HMAC-SHA256：

- 时间窗口默认 5 分钟。
- nonce 通过 `flm_cache.cache_key` 唯一键原子插入，在有效期内只能使用一次。
- 使用常量时间比较。
- 密钥首次启用时随机生成，单独保存在 `friendlinks_worker_secret` 数据库选项中；普通停用和再次启用不会改变。
- 密钥不出现在 URL 或后台 HTML；显式轮换时由管理员输入新密钥，并同步更新外部调用脚本。
- 失败请求不返回签名计算细节。

## 16. 管理端

### 16.1 页面

- 友链列表：搜索、分类、公开状态、排序、批量操作。
- 编辑友链：基础信息和检测开关；检测状态统一在友链列表的状态列展示。
- 分类管理。
- 健康总览：状态数量、到期预警、任务积压、Worker 心跳。
- 检测历史：按友链、状态和时间筛选。
- 导入导出：CSV、JSON。
- 通知：以内部标签组织触发策略、Webhook、钉钉、SMTP 和共享消息模板；渠道测试跟随对应渠道，投递记录固定显示在下方。
- 设置：页面、检测周期、阈值、并发、保留期、HTTP Worker。

### 16.2 权限

- 修改配置、迁移、密钥和删除数据：仅 `administrator`。
- 友链增删改和手动检测：默认仅 `administrator`。
- 所有写操作使用 Typecho Security Token 防止 CSRF。
- Action 内再次验证角色，不能只依赖面板菜单隐藏。
- 所有 ID 查询必须带对象存在性检查，批量操作限制最大数量。

保存已启用自动检测的公开友链时，保存请求只将该友链标记为立即到期并返回列表；列表页随后自动发起独立的管理员检测请求，状态列在执行期间显示“检测中…”，完成后刷新结果。该请求中断时任务仍保持到期状态，由 CLI Cron 或签名 HTTP Worker 接续处理。列表中的“立即检测”和“完整复检”仍在当前后台请求中执行所选友链，并受 30 秒总预算限制。

## 17. 前端展示

### 17.1 默认内容

每条友链显示：

- Logo 或本地首字符占位。
- 名称。
- 描述。
- 分类。
- 状态文字和状态图标。
- 最后检测时间。

默认不向访客显示域名具体到期日期和证书签发者。管理员可以选择公开“即将到期”提示，但详细诊断始终只在后台展示。

### 17.2 可访问性

- 使用列表语义。
- 状态同时使用图标、文字和颜色。
- 键盘可访问分类筛选。
- Logo 提供替代文本。
- 不用仅依赖 `title` 的悬停提示承载关键信息。
- 遵循 `prefers-reduced-motion`。

### 17.3 主题隔离

- 根节点使用 `.flm-root`。
- 所有类名使用 `.flm-` 前缀。
- CSS 变量只定义在 `.flm-root`。
- 不设置 `body`、全局 `a`、`ul`、`img` 等选择器。
- 卡片圆角不超过 8px。
- 明暗模式首先跟随 `prefers-color-scheme`，允许主题通过公开 CSS 变量覆盖，但不识别主题类名。

如果主题未调用 Typecho 标准 `header()` 钩子，插件页面仍输出语义化 HTML，但不承诺完整样式。这属于主题不满足 Typecho 插件兼容约定，不增加主题专用兜底。

第三方整页缓存可能延迟状态更新。插件始终输出最近检测时间，不尝试调用未知缓存插件的私有清理接口；管理员应将友链页缓存 TTL 设置为不高于 HTTP 检测周期。

## 18. 输入、输出和隐私

- 名称、描述、分类和 URL 输出前按上下文转义。
- 只允许 `http`、`https` 的链接和 Logo URL。
- 新窗口外链始终使用 `rel="noopener"`；`noreferrer` 和 `nofollow` 由全局展示配置决定。
- Logo 默认 `loading="lazy"`、`referrerpolicy="no-referrer"`。
- 不保存远端正文、Cookie、认证信息和访问者信息。
- 日志中不记录 HTTP Worker 密钥、签名或完整异常堆栈。
- 通知地址、签名密钥和 SMTP 密码不回显到表单，也不写入投递记录。
- SMTP 无加密模式只允许无认证本地中继；存在用户名或密码时必须使用 STARTTLS 或 SMTPS。
- 前台不显示内部 IP、解析链、原始 cURL 错误或数据库错误。

## 19. 数据库兼容与迁移

支持：

- MySQL / MariaDB。
- PostgreSQL。
- SQLite。

迁移要求：

- 将 `Mysqli`、`Pdo_Mysql` 和旧 `Mysql` 归一化为 MySQL；将 `Pgsql`、`Pdo_Pgsql` 归一化为 PostgreSQL；将 `SQLite`、`Pdo_SQLite` 归一化为 SQLite。
- 表名使用 Typecho 前缀并通过适配器安全引用。
- 迁移有明确 `schema_version`。
- 每一步可重复执行或能检测已完成状态。
- 激活失败不得删除已有业务表。
- 升级前检查必要索引和字段。
- 停用插件不回滚 Schema。

不使用仅某一数据库支持的 `ENUM`、原生 JSON、部分索引或 `SKIP LOCKED` 作为核心依赖。

逻辑 Schema 约束：

- ID 和时间统一使用 64 位整数；SQLite 使用 `INTEGER`，MySQL 和 PostgreSQL 使用对应 64 位类型。
- Hash 使用固定长度 ASCII 字段；状态和原因码使用有长度上限的 ASCII 字段。
- URL、描述、错误摘要和 JSON 使用文本字段，并在应用层限制最大长度。
- 插件自有 MySQL 表固定使用 InnoDB；不具备事务能力时阻止启用检测，不使用非原子降级写入。
- 创建友链时在同一事务中创建当前状态行；删除或归档由应用服务显式处理状态、历史和缓存关系。
- 三套迁移文件必须给出完整字段类型、NULL、默认值、唯一约束和索引，不能由运行时猜测。

## 20. 降级策略

| 条件 | 行为 |
| --- | --- |
| 非 Linux、缺少 `proc_open`/`crontab`/PHP CLI 或无 crontab 权限 | 插件可启用，禁用自动调度配置，并提示手工 CLI Cron 或签名 HTTP Worker |
| 缺少 cURL | 阻止启用自动检测，管理和展示仍可用 |
| 缺少 PHP OpenSSL 扩展 | 关闭额外证书元数据解析；cURL 验证和 HMAC Worker 不受影响 |
| 缺少 intl | 非 ASCII 域名检测禁用，ASCII 域名正常 |
| RDAP 不支持该后缀 | 域名状态 `unsupported`，不影响主链路状态 |
| 系统 CA 异常 | TLS 明确标记为运行环境错误，不允许关闭验证 |
| Cron 长期未运行 | 前后台保留最近一次完整检测状态，CLI Worker 设置页根据最近运行时间提示调度异常 |
| 单个探测器异常 | 记录组件错误，其他探测器继续，Worker 不整体崩溃 |
| 数据库租约残留 | 到期后自动恢复 |
| 通知渠道失败 | 检测结果照常提交，Outbox 指数退避并最多尝试五次 |
| 通知渠道被停用 | 已领取任务终止重试，未领取任务在全局通知恢复前保持队列状态 |

## 21. 可观测性

后台显示：

- Worker 最近启动、完成和心跳时间。
- 待检测、租约中和过期任务数量。
- 最近运行成功数、失败数、耗时。
- RDAP 限流次数。
- 各状态最近检测时间。
- 最近迁移版本。
- 通知待发送、失败、已发送数量和最近错误摘要。

日志使用结构化事件：

```text
run_id
link_id
probe
event
reason_code
duration_ms
```

默认只记录摘要。调试日志可临时开启，并自动在指定时间后关闭。

## 22. 测试策略

### 22.1 单元测试

- URL 规范化和 URL Hash。
- IPv4、IPv6、IDN 和特殊 IP 表达解析。
- Public Suffix List 匹配。
- RDAP 事件解析。
- 状态聚合和连续失败状态机。
- HMAC 签名、时钟窗口和 nonce 防重放。
- 通知模板白名单、状态变化事件和三种渠道签名/配置。
- 缓存 TTL 和调度抖动。

### 22.2 集成测试

- MySQL、PostgreSQL、SQLite 的安装、升级和卸载。
- Typecho 插件激活、停用、Action 和权限。
- 自动 Cron 首次安装、重复启用去重、无关任务保留、停用删除和失败回滚。
- 普通页面内容注入及非目标页面无变化。
- 200、3xx、401、403、404、429、5xx、超时和大响应。
- 有效、过期、未生效、自签名和主机名不匹配证书。
- RDAP 正常、无到期事件、404、429 和超时。
- 多 Worker 同时领取任务。
- 检测结果与通知事件原子提交、通知租约恢复和五次重试上限。

### 22.3 安全测试

- 回环、私网、链路本地、保留地址。
- DNS Rebinding。
- 公网 URL 重定向至内网。
- IPv4 映射 IPv6 和非标准 IP 文本。
- URL 用户信息、异常端口和非 HTTP 协议。
- 存储型 XSS、属性注入和恶意 Logo URL。
- CSRF、低权限 Action 调用和批量 ID 越权。
- HTTP Worker 重放、过期签名和时序比较。
- Cron 命令参数化、路径控制字符、实例标记隔离和超大 crontab 拒绝。
- Webhook SSRF、钉钉地址白名单、SMTP 证书校验和敏感字段不回显。

### 22.4 兼容矩阵

- PHP 7.4、8.1、8.3、8.4、8.5。
- Typecho 1.2 稳定版和当前主分支。
- MySQL / MariaDB、PostgreSQL、SQLite。
- 自动 Cron 管理覆盖 Linux 且 PHP Web 用户可管理自身 crontab；其他环境使用手工 CLI 调度或 HTTP Worker。
- 默认主题、Classic 主题和多种普通第三方主题。

## 23. 分阶段实施

### Phase 1：数据与展示

- 插件骨架、迁移和后台菜单。
- 友链及分类管理。
- 选择由用户在 Typecho 中创建的独立页面。
- 默认前端渲染和 CSS。
- CSV、JSON 导入导出。

### Phase 2：核心检测

- URL Policy 和 SSRF Guard。
- DNS、HTTP、TLS 探测。
- 当前状态、历史、聚合和公开标记。
- CLI Worker、租约、退避和后台健康总览。

### Phase 3：域名与兼容调度

- PSL 和 RDAP。
- 域名到期预警。
- 签名 HTTP Worker。
- 缓存和历史清理。

### Phase 4：扩展能力

- 钉钉机器人、通用 Webhook、SMTP 邮件和可编辑消息模板。
- 反向友链检测。
- 可用率统计和趋势图。
- 可选 WHOIS 适配器。

## 24. 第一版验收标准

- 不修改任何主题文件即可完成安装、管理和前台展示。
- 切换到符合 Typecho 标准钩子的其他主题后，友链页面继续可用。
- 前台请求不会产生外部检测流量。
- 能区分可达、受限、临时异常、失效和未知。
- 能验证 TLS 链、主机名和有效期。
- 当注册局 RDAP 返回有效 `expiration` 事件时，能解析并缓存域名到期时间。
- 对无法获得域名到期时间的情况明确返回 `not_applicable`、`unsupported` 或 `unknown`，不误报过期。
- 连续失败阈值、缓存和租约按设计工作。
- SSRF 测试覆盖 IPv4、IPv6、重定向和 DNS Rebinding。
- 三种数据库完成安装、升级和基本 CRUD。
- 状态变化可以通过钉钉、通用 Webhook 或 SMTP 异步通知，失败不会回滚检测结果。
- 停用插件不删除数据，显式卸载操作才能删除数据。

## 25. 待确认决策

以下事项不阻塞架构，但应在进入实现前确认：

1. 插件最终名称和目录名是否采用 `FriendLinks`。
2. 第一版最低 Typecho 版本是 1.2.0、1.2.1，还是只支持最新稳定版。
3. WHOIS 降级是否进入第一版；建议只保留接口，第一版默认不实现。
4. 公开页面是否显示具体的证书或域名剩余天数；建议默认只显示简化状态。
5. 插件只选择并绑定现有独立页面，不负责创建、删除或修改页面模板。
