# CourseShop

> 由 SZY 创新工作室开发的课程售卖网站 / A course-selling website built by SZY Innovation Studio.

CourseShop 是一个可直接部署运行的在线课程售卖系统，使用**原生 PHP 8.1+**、**原生 HTML/CSS/JavaScript** 编写，**不依赖任何框架、包管理器或前端构建工具**。项目包含前台（课程浏览、下单支付、视频学习）与后台（课程、订单、用户、系统设置）两大部分。

CourseShop is a ready-to-deploy online course store built with **vanilla PHP 8.1+** and **vanilla HTML/CSS/JavaScript**. It uses **no framework, no package manager and no build step**. It ships both a storefront (browse, purchase, learn) and an admin console (courses, orders, users, settings).

---

## 目录 / Table of Contents

- [功能概览 / Features](#功能概览--features)
- [技术栈 / Tech Stack](#技术栈--tech-stack)
- [环境要求 / Requirements](#环境要求--requirements)
- [快速开始 / Quick Start](#快速开始--quick-start)
- [手动部署 / Manual Deployment](#手动部署--manual-deployment)
- [目录结构 / Project Structure](#目录结构--project-structure)
- [配置说明 / Configuration](#配置说明--configuration)
- [缓存 / Cache](#缓存--cache)
- [错误监控 / Error Monitoring](#错误监控--error-monitoring)
- [支付接入 / Payment (易支付)](#支付接入--payment-易支付)
- [邮件配置 / Mail](#邮件配置--mail)
- [队列与定时任务 / Queue & Scheduled Tasks](#队列与定时任务--queue--scheduled-tasks)
- [搜索与分类 / Search & Taxonomy](#搜索与分类--search--taxonomy)
- [优惠券、套餐与报表 / Coupons, Packages & Reports](#优惠券套餐与报表--coupons-packages--reports)
- [备份与恢复 / Backup & Restore](#备份与恢复--backup--restore)
- [设计系统 / Design System](#设计系统--design-system)
- [安全说明 / Security](#安全说明--security)
- [自测清单 / Self-test Checklist](#自测清单--self-test-checklist)
- [开发路线图 / Roadmap](#开发路线图--roadmap)
- [设计决策与假设 / Decisions & Assumptions](#设计决策与假设--decisions--assumptions)
- [版权与致谢 / Credits](#版权与致谢--credits)

---

## 功能概览 / Features

**前台 / Storefront**

- 首页：公告、推荐课程、推荐套餐、分类入口
- 课程列表：分类筛选、标签筛选、关键词搜索、排序、分页
- 课程详情：封面、简介、章节目录、免费试看、价格与购买按钮
- 套餐：套餐列表与详情，一次开通套餐内全部课程（可在后台整个关闭套餐入口）
- 账号：注册、登录、图形验证码、邮箱验证码绑定、找回/重置密码、两步验证（2FA）、登录设备管理
- 两步验证：`/account/2fa/setup` 以**扫码绑定**为主（服务端生成二维码，零依赖），密钥与 otpauth 链接折叠为备用方案
- 订单：创建订单、易支付发起支付、15 分钟未支付自动关闭
- 学习中心：我的课程、视频播放（签名鉴权 + 断点续播 + 键盘快捷键）、学习进度，**播完 5 秒自动进入下一节**（可随时取消，被浏览器拦截自动播放时提供「点击继续播放」）
- 工单：提交问题、附件上传、对话回复（管理员账号不参与工单提交）
- 多语言：简体中文 / 繁體中文 / English / Español / 日本語，后台可勾选启用语言与默认语言，用户可在前台导航或手机抽屉中切换；仅启用一种语言时自动隐藏切换入口
- 站点协议：用户协议 / 隐私政策 / 退款政策，同意记录留痕
- PWA：可添加到桌面/主屏，离线时展示提示页（生产环境需 HTTPS）

**后台 / Admin**

- 仪表盘：收入、订单、用户、课程数据总览
- 课程管理：分类、标签、课程、章节、视频文件、上下架、试看设置
- 订单管理：查询、详情、手动补单、退款
- 营销与报表：优惠券、套餐、经营报表、CSV 导出
- 用户管理：查询、封禁/解封、角色调整、新增用户、用户详情（购买课程、消费金额、登录/操作日志）、手动标记邮箱验证
- 工单管理：回复、关闭
- 内容管理：公告、协议版本
- 系统设置：站点、安全、验证码、安全响应头、支付、课程与套餐、邮件、视频、工单、多语言、错误监控、主题、日志，并提供一键清空缓存

---

## 技术栈 / Tech Stack

| 层次 / Layer | 选型 / Choice |
| --- | --- |
| 后端 / Backend | 原生 PHP 8.1+（PDO 预处理） |
| 数据库 / Database | MySQL 5.7+ / MySQL 8.0 / MariaDB 10.4+，`utf8mb4_unicode_ci` |
| 前端 / Frontend | 原生 HTML5 + CSS3（CSS 变量）+ ES6+，无框架、无构建 |
| 模板 / Templating | 原生 PHP 模板 + include 拆分 |
| 支付 / Payment | 易支付（页面跳转 + API 接口，MD5 签名） |
| 邮件 / Mail | 原生 SMTP Socket（不依赖 PHPMailer / Composer） |
| 视频 / Video | 本地磁盘存储 + 鉴权输出 + 签名 URL（预留对象存储适配） |

---

## 环境要求 / Requirements

- PHP **8.1 或更高**，启用扩展：`pdo_mysql`、`openssl`、`mbstring`、`fileinfo`、`gd`、`curl`
- MySQL 5.7+ 或 MariaDB 10.4+（推荐 `utf8mb4`）
- 可选：`ffmpeg`（用于自动截取视频首帧封面）

检查扩展：

```bash
php -m
```

---

## 快速开始 / Quick Start

### Windows（推荐，内置独立数据库实例）

项目自带两个 PowerShell 脚本，会在 `.dev/` 目录内启动一个**独立的 MariaDB 实例**（不注册系统服务、不污染系统数据库）以及 PHP 内置服务器。

```powershell
# 启动：初始化数据目录（首次）→ 建库建号 → 导入表结构与初始数据 → 启动站点
powershell -ExecutionPolicy Bypass -File scripts\dev-start.ps1

# 停止：按 PID 停止站点与数据库
powershell -ExecutionPolicy Bypass -File scripts\dev-stop.ps1
```

启动成功后默认地址：

- 站点：`http://127.0.0.1:8090/`
- 数据库：`127.0.0.1:3307`（库名 `courseshop`）
- 环境信息与数据库账号：`.dev/credentials.txt`

首次访问站点会进入**安装向导**，用于创建管理员账号。

> 脚本顶部的端口、数据库名与账号均可直接修改。
> 若 MariaDB 不在默认安装路径，可设置环境变量 `COURSESHOP_MARIADB_HOME` 指向其安装目录。

### Linux / macOS（使用系统数据库）

```bash
# 1. 准备数据库
mysql -u root -p -e "CREATE DATABASE courseshop DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. 生成配置
cp .env.example .env
# 编辑 .env 填写数据库账号、APP_URL、APP_KEY 等

# 3. 启动开发服务器
php -S 127.0.0.1:8090 -t public public/index.php
```

---

## 手动部署 / Manual Deployment

适用于 Apache / Nginx + PHP-FPM 的生产环境。

1. **上传源码**，将网站根目录指向 `public/`。
2. **开启伪静态**（将不存在的请求交给入口文件）：

   Nginx：

   ```nginx
   location / {
       try_files $uri $uri/ /index.php?$query_string;
   }
   ```

   Apache（已内置 `.htaccess` 规则，确保 `AllowOverride All`）：

   ```apache
   <IfModule mod_rewrite.c>
       RewriteEngine On
       RewriteCond %{REQUEST_FILENAME} !-f
       RewriteCond %{REQUEST_FILENAME} !-d
       RewriteRule ^ index.php [L]
   </IfModule>
   ```

3. **创建配置**：复制 `.env.example` 为 `.env`，填写数据库与 `APP_URL`（用于生成支付回调地址）。
4. **导入数据**：

   ```bash
   mysql -u root -p courseshop < database/schema.sql
   mysql -u root -p courseshop < database/seed.sql
   ```

5. **目录权限**：确保 `storage/` 可写。
6. **访问站点**，完成安装向导创建管理员。
7. **生产环境加固**：设置 `APP_ENV=production`、`APP_DEBUG=false`，配置 HTTPS，建议将 `storage/` 置于 Web 根目录之外或禁止外部访问。

---

## 目录结构 / Project Structure

```
CourseShop/
├── public/               # Web 根目录（唯一对外暴露的目录）
│   ├── index.php         # 前端控制器 / 唯一入口
│   ├── install.php       # Web 安装向导
│   ├── .htaccess         # Apache 伪静态规则
│   ├── sw.js / offline.html  # PWA Service Worker 与离线页
│   └── assets/           # 静态资源（css / js / img / icons）
├── app/                  # 应用代码
│   ├── bootstrap.php     # 引导：自动加载、环境、错误处理、会话
│   ├── Controllers/      # 控制器（Admin/ 为后台控制器）
│   ├── Models/           # 数据访问层
│   ├── Jobs/             # 队列任务（邮件、视频转码）
│   ├── Support/          # 基础设施（路由、数据库、视图、会话、CSRF、缓存、监控、多语言 I18n、二维码 QrCode…）
│   │   ├── Cache/        # 缓存驱动（file / memory）
│   │   ├── Payment/      # 支付网关适配（易支付）
│   │   └── Video/        # 视频存储与转码
│   └── Views/            # PHP 模板（layouts / partials / 各页面）
├── bin/                  # CLI 脚本（queue-worker / migrate / backup / restore）
├── config/               # 配置（路由表、应用配置）
├── database/             # schema.sql（表结构） + seed.sql（初始数据） + migrations/
├── lang/                 # 多语言词典（zh-TW / en / es / ja，以中文原文为键，缺译自动回退中文）
├── scripts/              # dev-start.ps1 / dev-stop.ps1
├── storage/              # 运行时目录（日志、缓存、上传、备份）需可写
│   ├── logs/             # 应用日志 app-*.log、监控日志 monitor-*.log
│   ├── cache/            # 文件缓存 *.cache
│   ├── uploads/          # 公开上传（封面、头像、工单附件）
│   ├── private/          # 私有文件（课程视频等）
│   └── backups/          # 备份产物（数据库 + 文件）
├── .dev/                 # 本地托管数据库实例（已加入 .gitignore）
├── .env.example          # 环境配置示例
└── README.md
```

---

## 配置说明 / Configuration

配置分两层：

- **`.env`**：部署相关的敏感/环境信息（数据库、应用密钥、URL、上传限制）。安装向导会写入该文件。
- **后台「系统设置」**：业务可运营项（站点信息、支付开关与密钥、邮件、验证码策略、主题色等），存储在 `settings` 表，**不硬编码**。

关键 `.env` 项：

| 变量 / Key | 说明 / Description |
| --- | --- |
| `APP_ENV` | `local` 或 `production` |
| `APP_DEBUG` | 生产环境必须为 `false` |
| `APP_URL` | 站点根地址，用于生成支付回调地址 |
| `APP_KEY` | 应用密钥（随机 32 位以上） |
| `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` `DB_PASSWORD` | 数据库连接 |
| `SESSION_NAME` `SESSION_LIFETIME` | 会话名称与有效期（秒） |
| `UPLOAD_MAX_VIDEO_MB` `UPLOAD_MAX_ATTACHMENT_MB` | 上传大小限制 |
| `VIDEO_DISK` `VIDEO_LOCAL_ROOT` `VIDEO_URL_TTL` | 视频存储驱动、本地根目录与签名有效期 |
| `CACHE_DRIVER` `CACHE_PATH` `CACHE_TTL` | 缓存驱动（`file` / `array`）、缓存目录与默认有效期 |
| `MONITOR_ENABLED` `MONITOR_CHANNEL` `MONITOR_WEBHOOK` `MONITOR_TIMEOUT` | 错误监控开关、通道、Webhook 地址与超时 |

后台「系统设置」中影响前台展示的常用开关：

| 分组 / Group | 设置项 / Key | 说明 / Description |
| --- | --- | --- |
| 课程与套餐 | `packages_enabled` | 关闭后前台隐藏套餐入口（导航栏、首页推荐、移动端菜单）且套餐页返回 404；已产生的套餐订单仍可查看与支付 |
| 多语言 | `i18n_locales` / `i18n_default_locale` | 勾选前台可用语言与默认语言（至少一种）；仅启用一种语言时全局隐藏语言切换菜单 |
| 视频设置 | `video_hls_enabled` / `ffmpeg_path` | HLS 转码开关与 ffmpeg 可执行文件路径，留空则自动在系统 PATH 中查找 |

> **ffmpeg 安装提示**：HLS 转码依赖 ffmpeg。Windows 可执行 `winget install Gyan.FFmpeg`；若网络受限，可从 gyan.dev 手动下载解压，再把 `ffmpeg.exe` 的**完整路径**填入「视频设置 → ffmpeg 路径」。修改后需重启队列进程（`bin/queue-worker.php`）才会生效。

---

## 缓存 / Cache

系统内置一套**零依赖缓存层**（`App\Support\Cache`），用于降低高频读取对数据库的压力。

- **驱动**：`file`（默认，文件缓存，键名 SHA-256 落盘到 `storage/cache`，跨请求持久化）/ `array`（进程内数组，仅当次请求有效，便于自测或临时关闭）
- **当前接入点**：
  - 系统设置全量读取（键 `settings:all`，后台保存时自动失效）
  - 首页推荐课程（键 `home:recommended`）与课程总数（键 `home:total`，课程增删改后自动失效）
- **有效期**：由 `CACHE_TTL` 指定默认值，`Cache::remember($key, $ttl, $callback)` 按需覆盖
- **手动清空**：后台 → 系统设置 右上角「清空缓存」按钮（`POST /admin/settings/cache/clear`）
- **扩展**：新增驱动只需实现 `App\Support\Cache\CacheStore` 接口，并在 `Cache::resolve()` 中注册，上层调用无需任何改动
- **容错**：缓存读写异常会被捕获并降级（记录 warning 日志），业务功能不受影响

缓存值约定只存放**标量与数组**（不使用对象），文件驱动反序列化时禁用对象还原，避免不可信内容触发的对象注入。

---

## 错误监控 / Error Monitoring

内置一个**可配置、默认关闭**的错误上报钩子（`App\Support\Monitor`），用于接入自建告警，**不依赖任何第三方 SDK**。

- **触发时机**：仅在站点发生 **5xx** 级严重错误时上报（挂接在 `ErrorHandler` 中，`4xx` 不触发）
- **上报通道**（`monitor_channel`）：
  - `log`：写入独立日志 `storage/logs/monitor-YYYY-MM-DD.log`（默认）
  - `webhook`：将异常摘要以 **JSON POST** 发送到自定义地址（支持钉钉 / 飞书 / Sentry 等自定义接收端）
- **配置位置**：后台 → 系统设置 → 错误监控（后台配置优先），或 `.env` 的 `MONITOR_*` 变量
- **上报内容**：环境、时间、请求方法 / 路径 / IP、异常类型与状态码、消息、文件行号、堆栈
- 上报过程中的任何异常都会被吞掉，**绝不影响主流程与错误页渲染**

---

## 支付接入 / Payment (易支付)

支付配置全部在**后台 → 系统设置 → 支付**中填写，代码中不硬编码商户信息。

- 接口地址：易支付站点根地址，例如 `https://zpayz.cn`
- 商户 ID（`pid`）与商户密钥（`key`）
- 签名方式：`MD5`
- 支付方式开关：微信支付、支付宝**分别独立开关**，可只开启其一
- 异步通知地址：`{APP_URL}/payment/notify/epay`（易支付以 GET / POST 回调）
- 同步跳转地址：`{APP_URL}/order/{orderNo}/return`（用户支付完成后跳回订单详情）

协议要点：

- 页面跳转支付：`POST/GET {接口地址}/submit.php`
- API 接口支付：`POST {接口地址}/mapi.php`，返回 JSON
- 异步通知为 **GET**，`trade_status=TRADE_SUCCESS` 表示支付成功，**必须输出 `success`**
- 签名：参数名按 ASCII 升序排序，排除 `sign`、`sign_type` 与空值，拼接为 `a=b&c=d`（值不 URL 编码），`sign = md5(拼接串 + 商户密钥)`（小写）

订单金额以**分（int）**存储，避免浮点误差；开通课程做**幂等处理**，防止重复通知重复开通。

---

## 邮件配置 / Mail

使用**原生 SMTP** 实现（不引入 PHPMailer / Composer），在后台 → 系统设置 → 邮件中配置：

- SMTP 服务器、端口（常用 `465` SSL / `587` TLS）、加密方式
- 发件账号与密码/授权码、发件人名称与地址
- 用途：邮箱验证码绑定、找回密码通知

未启用邮件时，系统仍可运行；但若后台开启「强制绑定邮箱」，除管理员外所有账号将被限制在绑定流程内。

---

## 队列与定时任务 / Queue & Scheduled Tasks

系统使用**数据库队列**（不依赖 Redis / 消息中间件），任务写入 `jobs` 表，由 `bin/queue-worker.php` 消费。目前包含两类队列：

- `mail`：出站邮件（订单/工单通知等）。后台 → 系统设置 → 邮件中开启「使用队列发送」后，普通邮件改为异步投递，避免支付回调、工单提交等入口被 SMTP 超时拖慢。**邮箱验证码始终同步发送**（用户在等待）。
- `transcode`：视频转码（P2，依赖 ffmpeg；未安装 ffmpeg 时自动降级为直接使用源 mp4）。

> **默认即装即用，无需手工配置**：站点会在「提交转码 / 邮件入队」以及管理员访问后台时，自动拉起一个「处理完即退出」的消费者（按 10 秒节流，进程用 `nohup` 脱离请求，跑完即退出），日志见 `storage/logs/queue-auto.log`。只有在自动运行不可用时（非 Unix 环境、`proc_open` 被禁用、找不到满足 8.1+ 的 PHP CLI），才需要按下面的方式手动配置常驻消费者。
>
> 自动运行是否生效可在后台「章节编辑 → 视频转码（HLS）」卡片中直接看到：显示「已启用自动运行」即为就绪。

启动消费进程（可选，适合任务量大或需要更稳定调度的场景）：

```bash
# 常驻消费全部队列（开发/小站可直接这样跑）
php bin/queue-worker.php

# 只消费某个队列
php bin/queue-worker.php --queue=mail
php bin/queue-worker.php --queue=mail,transcode

# 处理完当前所有待办任务后退出（配合计划任务，推荐生产使用）
php bin/queue-worker.php --stop-when-empty

# 其它参数
php bin/queue-worker.php --once            # 处理一个任务后退出
php bin/queue-worker.php --sleep=3 --max-jobs=100
```

生产环境可任选其一：

- **常驻进程**：用 `supervisor` / `systemd` 拉起上面的命令并保持存活；
- **计划任务**：每分钟执行一次 `php bin/queue-worker.php --stop-when-empty`（Linux crontab / Windows 计划任务均可）。

任务失败时会按 `30s / 60s / 90s …` 退避重试，最多 3 次，超过后标记为永久失败并记录 `last_error`。

> 注意：开启「使用队列发送」后必须保证 worker 正在运行，否则邮件只会停留在 `jobs` 表中而不会被投递。

---

## 搜索与分类 / Search & Taxonomy

**分类树 / Categories**

- 支持**两级分类**（`course_categories.parent_id`，上限由 `Category::MAX_LEVELS` 控制），后台创建/编辑时可选择上级分类，列表与下拉框以 `— `/`└` 缩进标识层级
- 前台按父分类筛选时，会**自动包含其所有子分类**下的课程
- 分类下存在课程或子分类时**不可删除**；上级分类只能选择顶级分类（自动排除自身及其后代）

**标签 / Tags**

- `course_tags` + `course_tag_relations` 多对多关联
- 前台课程列表与后台课程列表均支持按标签筛选（`?tag=ID`）

**全文搜索 / Full-text Search**

- **MySQL 5.7+ / 8.0**：`courses` 表带 `FULLTEXT KEY ft_courses_search (title, summary)`，建表脚本以 `WITH PARSER ngram` 创建，并配合 `MATCH ... AGAINST (? IN BOOLEAN MODE)` 检索
- **MariaDB / 无 ngram / 索引缺失**：`Search::fulltextReady()` 会同时检查 `ngram_token_size` 变量与 `ft_courses_search` 索引是否存在，任一不满足即**自动回退 `LIKE`**，确保中文关键词仍可搜索
- 后台关键词检索因需覆盖 `subtitle`（全文索引仅覆盖 `title, summary`），**始终使用 `LIKE`**

---

## 优惠券、套餐与报表 / Coupons, Packages & Reports

**优惠券 / Coupons**

后台 → 交易 → 优惠券 中创建优惠码，支持两种类型：

- **满减券（fixed）**：直接减免指定金额，可设置最低消费金额
- **折扣券（percent）**：`value` 为**减免百分比**（如 `20` 表示立减 20%）

其它可配置项：限定课程（留空为全场通用）、发行总量（`0` 为不限）、每人限用次数、生效与失效时间、启用开关。优惠码统一转为**大写**存储，字符限 `A-Z 0-9 _ -`。

**使用流程 / Flow**

1. 用户在**订单详情页**输入优惠码并提交，订单立即按券重新计算金额（`原价 - 优惠 = 应付`）
2. 一张订单**仅可使用一次**优惠券，已使用后不可更换或取消
3. **支付成功后自动核销**：写入 `coupon_usages` 并累加 `used_quantity`；订单状态流转本身具备幂等性，重复回调不会重复核销

**套餐 / Packages**

后台 → 交易 → 套餐 中创建套餐，将多门已上架课程打包以优惠价售卖：

- 数据模型：`packages`（套餐主体：标题、副标题、介绍、售价、划线价、封面、是否推荐、销量、状态）+ `package_courses`（套餐与课程的多对多关联，带排序）
- 套餐价格以**分**存储；划线价留空时，前台自动按套餐内课程**原价合计**作为划线价与「立省」金额的兜底
- 前台入口：导航「优惠套餐」→ `/packages`（列表）；`/package/{id}`（详情，展示套餐内课程清单、已拥有标记与一键购买）
- 首页「推荐套餐」区块展示后台勾选「推荐」的套餐（最多 3 个），无推荐套餐时该区块自动隐藏
- 下单复用 `POST /order/create`（提交 `package_id`）：`orders.course_id` 可为空、新增 `package_id` 列，套餐名称快照写入既有的 `course_title`，从而复用订单展示、导出与后台检索
- 支付成功后**批量开通**套餐内全部课程；若用户已拥有套餐内全部课程，则前端直接提示并跳转「我的课程」，不重复生成订单
- 管理员在后台可查看套餐销量与课程构成，并对套餐执行下架 / 恢复 / 软删除

**经营报表 / Reports**

后台 → 总览 → 经营报表，支持 `今日 / 近 7 天 / 近 30 天 / 近 90 天 / 自定义` 区间：

- 概览：营收、净营收（营收 - 退款）、支付订单、客单价、退款、新增用户
- 按日趋势：逐日支付订单、营收、退款订单与退款金额（区间内无数据的日期补零）
- 排行：课程销售排行、用户消费排行
- 分布：支付方式分布（订单数、占比、营收）、优惠券使用统计（使用次数与抵扣金额 Top 10）
- 支持将按日趋势**导出为 CSV**（UTF-8 BOM，Excel 直接打开不乱码）

> 统计口径：营收按订单**支付时间**归集（已支付 / 已完成），退款按**退款时间**归集，订单量按**下单时间**归集，金额一律以「分」为单位存储。

---

## 备份与恢复 / Backup & Restore

提供两个零依赖的 CLI 脚本，数据库通过 `mysqldump` / `mariadb-dump` 导出，文件（上传与私有目录）打包为 zip。

```bash
# 备份：数据库 + storage/uploads + storage/private
php bin/backup.php

# 常用选项
php bin/backup.php --db-only            # 只备份数据库
php bin/backup.php --no-videos          # 含文件，但排除 storage/private/videos
php bin/backup.php --dir=storage/backups --keep=20   # 指定目录并仅保留最近 20 份

# 恢复（默认只做校验并打印将执行的操作，不会真正覆盖）
php bin/restore.php --file=storage/backups/courseshop-20261002-120000.sql.gz
php bin/restore.php --file=xxx.sql.gz --files=xxx.files.zip --force  # 连同文件一起恢复
```

- 备份产物：`{库名}-{时间戳}.sql.gz`（数据库）、`.files.zip`（文件）、`.json`（元数据：时间、库名、PHP 版本、Git 版本等）
- 可用 `.env` 的 `DB_DUMP_BINARY` / `DB_CLIENT_BINARY` 指定 dump / cli 客户端可执行文件路径（Windows 会自动在常见安装位置探测）
- 未加 `--force` / `--yes` 时**不会真正导入或覆盖**，便于先预览
- 生产环境建议将 `--password` 方式改为受限的 `--defaults-extra-file=` 配置或数据库侧最小权限账号

多实例部署时可配合计划任务定期执行 `php bin/backup.php`。

---

## 设计系统 / Design System

前台与后台共用一套原生 CSS 设计系统（`public/assets/css/app.css`），基于 **CSS 变量**，无构建步骤。

- **色彩**：默认低饱和莫兰迪绿 `#4F6F52`（后台可改主强调色）；支持 `跟随系统 / 浅色 / 深色` 三种主题
- **设计令牌**：间距、圆角、字号、阴影、过渡等统一以 `--*` 变量定义，组件复用同一套令牌
- **组件**：按钮（`btn` 及 `--outline/--sm` 等修饰）、卡片、表单、表格、徽章、分页、空状态、后台统计卡（`admin-stat`）等
- **布局**：前台 `layouts/app.php`、后台 `layouts/admin.php`（侧边栏）、后台登录 `layouts/admin-auth.php`
- **交互脚本**：`public/assets/js/app.js` 负责主题切换、密码显隐、`data-confirm` 二次确认、`data-auto-submit` 自动提交、后台批量选择等
- **响应式**：桌面 / 移动端深度适配，前台含底部导航

---

## 安全说明 / Security

- 所有数据库访问使用 **PDO 预处理**，杜绝 SQL 注入
- 全站表单 **CSRF Token** 校验
- 密码使用 `password_hash()`（bcrypt）存储
- 图形验证码：登录失败 3 次后触发；注册必填
- 登录限流与账号锁定策略可在后台配置
- 会话安全：`HttpOnly`、`SameSite`、会话固定防护、超时
- 两步验证：基于 TOTP（RFC 6238），6 位口令 / 30 秒，可随时开启或关闭
- 登录设备管理：查看活跃会话并按设备退出，撤销即时生效
- 输出转义与安全响应头（`X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`、CSP 等），可在后台「安全响应头」分组开关；HSTS 仅在 HTTPS 下下发
- 文件上传：白名单校验、重命名、大小限制、防目录穿越
- 视频：鉴权后输出，支持签名 URL 与 `Range` 分片请求，防止盗链
- 金额以分存储，支付回调验签 + 幂等开通
- 注销账号时**匿名化**处理，保留订单与工单以满足财务与审计需要
- 缓存与错误监控：缓存可一键清空；错误监控默认关闭，开启后可将 5xx 异常写入日志或推送到 Webhook
- 语言切换为无 CSRF 的 GET 请求（`GET /lang/{locale}`），会写入 Cookie / 会话 / 账号偏好；影响范围仅限于「语言偏好被跨站切换」，属**有意接受的风险**，不改代码。后台界面与管理员操作均有 CSRF 保护

---

## 自测清单 / Self-test Checklist

> 部署完成后可按下列顺序快速自测。所有条目均对应本仓库的真实路由与功能，无需额外工具。

### 1. 安装与基础

- [ ] 访问站点根目录，未安装时自动跳转 `/install`，安装向导可正常填写数据库信息并创建管理员
- [ ] 安装完成后生成 `storage/installed.lock`，重复访问安装页被拦截
- [ ] 首页 `/` 正常展示推荐课程、课程总数、公告与页脚（协议入口可点击）
- [ ] `/manifest.webmanifest` 返回 200，`public/sw.js` 注册成功（浏览器 Application 面板可见 Service Worker）

### 2. 注册 / 登录 / 安全

- [ ] `/register` 注册（图形验证码必填；默认**注册需邮箱验证**：先填邮箱点「获取验证码」，再填入邮件中的验证码，未通过验证不会建号）
- [ ] 连续登录失败 3 次后，登录表单出现图形验证码 `/captcha`
- [ ] 登录成功后可在 `/account` 修改资料、修改密码、切换主题（暗色模式）
- [ ] 账户开启两步验证：`/account/2fa/setup` 展示二维码扫码绑定（密钥与 otpauth 链接折叠备用），退出后用 `/login/2fa` 校验 TOTP
- [ ] 登录设备管理：`/account` 设备列表可见，退出其他设备后对应会话立即失效
- [ ] 邮箱绑定：`/account/email` 发送验证码 → 校验通过后状态变为已绑定
- [ ] 找回密码：`/password/forgot` 发送邮箱验证码 → `/password/reset` 设置新密码成功
- [ ] 注销账号：`/account/delete` 二次确认后账号被**匿名化**，历史订单与工单仍保留

### 3. 课程浏览与学习

- [ ] `/courses` 列表可分页、按分类（两级分类树）筛选、关键词搜索、按价格/销量排序
- [ ] `/course/{id}` 详情展示章节大纲、价格、优惠信息与购买按钮
- [ ] `/packages` 与 `/package/{id}` 套餐页正常
- [ ] 后台关闭「显示优惠套餐」后：导航栏、首页推荐、移动端菜单均不再出现套餐入口，`/packages` 与 `/package/{id}` 返回 404；重新开启后恢复
- [ ] 未登录访问学习页被重定向到登录

### 4. 下单 / 优惠券 / 支付

- [ ] 登录后下单进入 `/order/{orderNo}`，订单 **15 分钟**未支付自动超时关闭
- [ ] 应用优惠券 `/order/{orderNo}/coupon` 后金额正确刷新
- [ ] 发起支付 `/order/{orderNo}/pay`，按后台开关展示微信 / 支付宝（易支付）
- [ ] 支付成功异步回调 `/payment/notify/epay` 验签通过并**幂等**开通课程（重复回调不重复开通）
- [ ] 同步跳转 `/order/{orderNo}/return` 后订单状态为已支付，课程出现在 `/my/courses`
- [ ] 后台对账 `/admin/orders/{id}/reconcile` 可补单

### 5. 视频播放

- [ ] `/my/courses` 可进入学习页 `/course/{courseId}/learn/{chapterId}`
- [ ] 视频通过签名 URL 流式播放，拖动进度条触发 `Range` 请求
- [ ] 学习进度保存（`.../progress`）后再次进入可续播
- [ ] 若课程启用了 HLS，`/hls/index.m3u8` 与分片正常加载
- [ ] 视频播完后出现 5 秒倒计时浮层（可「立即播放」或「取消」）；不操作时自动进入下一节并尝试自动播放，若被浏览器拦截则显示「点击继续播放」兜底
- [ ] 鼠标悬停在播放器上时：空格暂停 / 播放，`←` / `→` 后退 / 前进 5 秒（输入框内不触发）

### 6. 工单与申诉

- [ ] 登录用户可 `/ticket/create` 提交工单、上传附件、在 `/ticket/{id}` 回复与关闭
- [ ] 封禁用户可从公开入口 `/ticket/appeal` 提交申诉，无需登录
- [ ] 管理员账号访问 `/ticket/create` 被拦截并提示改由后台处理，前台不显示「提交工单」入口

### 7. 后台管理（`/admin`）

- [ ] 独立入口 `/admin/login` 登录，进入侧边栏布局仪表盘（概览卡片显示营收/订单/用户等）
- [ ] 用户管理：列表筛选、导出、新增用户、用户详情（购买课程/消费金额/日志）、手动标记邮箱已验证
- [ ] 课程管理：新增/编辑/上下架/批量操作；分类两级树维护
- [ ] 章节管理：直接上传 mp4（或触发 HLS 转码）、排序、删除视频
- [ ] 订单 / 退款 / 优惠券 / 套餐 / 报表导出均可用
- [ ] 公告、协议（用户协议 / 隐私政策 / 退款政策）、角色权限（RBAC）可维护
- [ ] 日志查看：登录 / 操作 / 支付 / 邮件 / 验证码分类可查
- [ ] 系统设置各分组（站点信息 / 注册安全 / 图形验证码 / 安全响应头 / 邮件 / 支付 / 视频 / 工单 / 错误监控 / 主题）可保存生效

### 8. 缓存与错误监控

- [ ] 首页首次访问后 `storage/cache/` 生成 `.cache` 文件；后台「系统设置 → 清空缓存」可清除并在下次访问自动重建
- [ ] 修改课程或系统设置后，相关缓存自动失效，前台展示更新
- [ ] 错误监控默认关闭；开启（`MONITOR_ENABLED=true` 或后台开关）后触发 5xx，按渠道写入 `storage/logs/monitor-*.log` 或推送 Webhook

### 9. 运维脚本

- [ ] `php bin/migrate.php --status` 可查看迁移状态
- [ ] `php bin/backup.php` 生成数据库与文件备份到 `storage/backups/`
- [ ] `php bin/restore.php --file=...` 可恢复（含 `--force` / `--yes` 参数）

### 10. 多语言

- [ ] 后台「多语言」勾选两种及以上语言后：前台导航栏出现语言切换，移动端抽屉内同样存在，切换后 URL 不变
- [ ] 切换语言后界面文案即时生效；登录用户的选择会写入账号（换设备登录后保持一致）
- [ ] 邮件与站内通知跟随收件人语言（切换语言后触发一次通知即可验证）
- [ ] 仅勾选一种语言时，前台全局不显示语言切换入口；直接访问 `/lang/{未启用语言}` 不会切换
- [ ] 课程名称、简介、课程正文、分类名等后台可配置内容保持原样（不参与翻译）

---

## 开发路线图 / Roadmap

- **P0（可用）**：认证、课程浏览、下单支付、我的课程与视频播放、后台基础管理
- **P1（完善）**：工单、封禁、邮件与强制邮箱绑定、账户设置、暗色模式、仪表盘、移动端深度适配
- **P2（进阶）**：RBAC 权限、操作/支付日志、订单状态机、HLS 视频、队列、优惠券、套餐、报表、备份恢复、两步验证（2FA）、登录设备管理、PWA
- **P3（体验增强）**：多语言（简体中文 / 繁體中文 / English / Español / 日本語）、两步验证扫码绑定、播完自动进入下一节与播放器键盘快捷键、套餐入口开关、管理员工单限制

---

## 设计决策与假设 / Decisions & Assumptions

| 项 / Item | 决策 / Decision |
| --- | --- |
| PHP 版本 | 要求 8.1+，同时保证在 8.1–8.5 及 MySQL 5.7 / 8.0 / MariaDB 上可用 |
| 前端 | 不使用任何框架与构建工具，纯原生实现 |
| 模板 | 原生 PHP 模板 + include，不引入模板引擎 |
| 依赖 | **不使用 Composer**；邮件等能力自行实现 |
| 支付 | 易支付适配层，后台可配置，微信/支付宝独立开关 |
| 视频 | 本地磁盘 + 鉴权播放；结构上预留对象存储驱动 |
| 数据库 | 全新库、无表前缀、`utf8mb4_unicode_ci`、金额用分、软删除、全表 `created_at/updated_at` |
| 订单超时 | 15 分钟未支付自动关闭（后台可调） |
| 主题色 | 默认低饱和莫兰迪绿 `#4F6F52`，后台可改 |
| 安装方式 | Web 安装向导创建管理员 |
| 注册策略 | 后台可开关注册；默认「注册需邮箱验证」（注册页先校验邮箱验证码才建号，可在后台关闭）；可强制绑定邮箱（开启后非管理员账号仅能进行绑定相关操作） |
| 注销策略 | 匿名化保留订单/工单 |
| 权限模型 | P0 仅「管理员 / 用户」，P2 扩展 RBAC |
| 搜索 | MySQL 全文索引（ngram）优先，MariaDB / 不支持时自动回退 `LIKE` |
| 分类 | 两级分类树，父分类筛选自动涵盖子分类 |
| 站点信息 | 默认占位内容，后台可改 |
| 语言 | 代码中文注释，README 中英双语 |
| 多语言 | 前台支持 5 种语言（简繁中文 / 英 / 西 / 日）；译文以**中文原文为键**（`lang/{locale}.php`），缺译自动回退中文；课程名、简介、正文、分类名等后台可配置内容不参与翻译；后台界面固定简体中文 |
| 二维码 | 两步验证绑定二维码由服务端**纯 PHP 生成内联 SVG**，不引入第三方库或外部 CDN，可完全离线使用 |
| 播放体验 | 播完 5 秒自动进入下一节（可取消）；自动播放被浏览器策略拦截时降级为「点击继续播放」；快捷键仅在播放器悬停/聚焦时生效，避免干扰页面滚动与输入 |

---

## 版权与致谢 / Credits

由SZY创新工作室开发（©2026-x SZY创新工作室，保留所有权利。）

开源地址：[https://github.com/SZYInnovationStudio/CourseShop](https://github.com/SZYInnovationStudio/CourseShop)

Developed by SZY Innovation Studio. All rights reserved.

## 许可证 / License

本项目使用 MIT 协议开源。
```
MIT License

Copyright (c) 2026 SZY Innovation Studio

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```