# 贡献指南 / Contributing Guide

感谢你愿意为 CourseShop 贡献力量！本指南说明如何报告问题、提交代码以及需要遵守的约定。请在动手前先阅读 [README.md](README.md) 了解项目定位与运行方式。

Thanks for your interest in contributing to CourseShop. This guide explains how to report issues and submit code. Please read [README.md](README.md) first.

---

## 目录 / Table of Contents

- [行为准则 / Code of Conduct](#行为准则--code-of-conduct)
- [报告问题 / Reporting Issues](#报告问题--reporting-issues)
- [开发环境 / Development Setup](#开发环境--development-setup)
- [分支与提交 / Branch & Commit](#分支与提交--branch--commit)
- [代码规范 / Coding Standards](#代码规范--coding-standards)
- [数据库变更 / Database Changes](#数据库变更--database-changes)
- [安全要求 / Security Requirements](#安全要求--security-requirements)
- [自测 / Self-testing](#自测--self-testing)
- [提交 PR / Pull Requests](#提交-pr--pull-requests)
- [许可证与署名 / License & Attribution](#许可证与署名--license--attribution)

---

## 行为准则 / Code of Conduct

- 保持友善与尊重，就事论事，不进行人身攻击或歧视性言论。
- 欢迎不同经验水平的贡献者，耐心对待新手提问。
- 讨论聚焦技术与项目本身，避免无关争论。

Be respectful and constructive. Harassment or discriminatory behavior is not tolerated.

---

## 报告问题 / Reporting Issues

提交 Issue 前请先搜索是否已有相同问题。报告 Bug 时请尽量包含：

- **环境**：操作系统、PHP 版本（`php -v`）、数据库类型与版本、浏览器
- **复现步骤**：访问的路由、操作顺序、期望结果与实际结果
- **报错信息**：相关日志（`storage/logs/app-*.log`）或页面错误，注意**隐去敏感信息**
- **截图**（如为界面问题）

> 安全漏洞请**不要**公开提交 Issue。当前仓库暂无公开安全邮箱，可先通过仓库主页所列联系方式私下联系维护者，确认后再同步修复。

---

## 开发环境 / Development Setup

项目**不使用任何框架、包管理器与构建工具**，无需 `composer install` 或 `npm install`。

**环境要求**

- PHP **8.1+**，启用扩展 `pdo_mysql`、`openssl`、`mbstring`、`fileinfo`、`gd`、`curl`
- MySQL 5.7+ / MySQL 8.0 / MariaDB 10.4+
- 可选：`ffmpeg`（视频封面/转码）

**启动方式**

- Windows（推荐，自带独立 MariaDB 实例）：

  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts\dev-start.ps1
  ```

  默认站点 `http://127.0.0.1:8090/`，数据库 `127.0.0.1:3307`，账号见 `.dev/credentials.txt`。停止用 `scripts\dev-stop.ps1`。

- Linux / macOS：

  ```bash
  cp .env.example .env      # 填写数据库、APP_URL、APP_KEY 等
  php -S 127.0.0.1:8090 -t public public/index.php
  ```

首次访问会进入安装向导，用于创建管理员账号。

> `.env`、`.dev/`、`storage/` 运行时内容与 `public/uploads/` 均已加入 `.gitignore`，**请勿提交**。

---

## 分支与提交 / Branch & Commit

**分支命名**

- 新功能：`feat/简短描述`
- 修复：`fix/简短描述`
- 文档：`docs/简短描述`
- 重构 / 杂项：`refactor/...`、`chore/...`

前端 / 后台共用 `main` 为主分支，请从最新 `main` 拉出功能分支后开发。

**提交信息（Conventional Commits）**

```
<type>(<scope>): <简述>

[可选的正文：说明动机与影响]
```

常用 `type`：`feat`（新功能）、`fix`（修复）、`docs`（文档）、`style`（格式）、`refactor`（重构）、`perf`（性能）、`test`（测试）、`chore`（构建/杂项）。

示例：

```
feat(order): 订单详情页支持应用优惠券
fix(account): 发送邮箱验证码后保留已填邮箱
docs(readme): 补充备份与恢复说明
```

- 提交信息与代码注释统一使用**中文**，代码标识符（类名、函数、变量、文件名）使用**英文**。
- 一次提交尽量只做一件事，改动小而聚焦。

---

## 代码规范 / Coding Standards

**通用**

- 唯一 Web 入口为 `public/index.php`，Web 根指向 `public/`；业务代码放在 `app/`，切勿把源码暴露到 Web 根。
- 保持**零依赖**：不引入 Composer / npm 依赖、不使用需要构建的前端框架或预处理器。新增能力优先用原生 PHP / 原生 JS 实现。
- PHP 文件使用 `declare(strict_types=1);`，遵循 **PSR-12** 风格（4 空格缩进、类名 StudlyCaps、方法 camelCase、常量全大写）。
- 关键逻辑写**中文注释**；含义自明的短代码不必加注释。

**路由与控制器**

- 路由一律登记在 [config/routes.php](config/routes.php)，不要新增隐式的 URL 规则。
- 控制器继承 `App\Controllers\Controller`，使用 `success()` / `fail()` 完成 **PRG（Post/Redirect/Get）** 跳转与消息提示；表单失败时通过 `fail(..., $input)` 回填 `_old`，视图中用 `old()` 读取。

**视图与前端**

- 视图位于 `app/Views/`，布局为 `layouts/app.php`（前台）、`layouts/admin.php`（后台）、`layouts/admin-auth.php`（后台登录）。
- 所有输出到 HTML 的动态内容必须用 `e()` 转义；URL 用 `url()` / `asset()` 生成。
- CSS 复用 `public/assets/css/app.css` 中的设计令牌（`--*` 变量），类名遵循 **BEM**；不要写内联样式堆砌。
- 若确需内联 `<script>`，必须带 CSP nonce：`<script nonce="<?= e(csp_nonce()) ?>">`。交互脚本尽量放到 `public/assets/js/app.js`。
- 上传图片的表单需加 `enctype="multipart/form-data"`。

**后台设置项**

- 后台「系统设置」的分组与字段在 `App\Controllers\Admin\SettingsController::groups()` 中集中定义，字段类型支持 `text/email/number/color/password/bool/select/textarea/image`。
- 业务可运营项应存 `settings` 表，**不要硬编码**；环境/部署相关项放 `.env`。

**数值约定**

- 金额一律以**分（int）**存储与运算，禁止使用浮点数表示金额。

---

## 数据库变更 / Database Changes

- 表结构变更必须新增迁移文件到 `database/migrations/`，命名 `YYYYMMDDHHMMSS_描述.sql`，并同步更新 `database/schema.sql`；初始/演示数据变更同步 `database/seed.sql`。
- 保持既有约定：全新库、无表前缀、`utf8mb4_unicode_ci`、软删除、全表 `created_at/updated_at`。
- 迁移可通过 `php bin/migrate.php` 执行，`--status` 查看状态。
- 兼容性：需同时适配 MySQL 5.7 / 8.0 / MariaDB；全文检索在不支持 ngram 的环境应自动回退（参见 `App\Support\Search`）。

---

## 安全要求 / Security Requirements

提交涉及下列内容的改动时，请务必遵守：

- 所有数据库访问使用 **PDO 预处理**，禁止字符串拼接 SQL。
- 所有写操作表单必须携带并校验 **CSRF Token**。
- 输出到页面的一律经 `e()` 转义，避免 XSS。
- 文件上传：仅允许白名单扩展名（`jpg/jpeg/png/gif/webp`）与白名单目录（`logo`/`covers`），重命名存储、限制大小、防目录穿越；**不要放开 `svg`**（存在存储型 XSS 风险）。
- 新增或修改安全响应头（CSP 等）时，注意内联脚本需使用 `csp_nonce()`。
- 支付回调必须**验签 + 幂等**，金额以分比较。
- 不要在代码、注释、提交信息或 Issue 中泄露密钥、口令等敏感信息。

---

## 自测 / Self-testing

提交前请至少完成：

1. 语法检查：对所有改动的 PHP 文件执行

   ```bash
   php -l path/to/file.php
   ```

2. 按 [README 自测清单](README.md#自测清单--self-test-checklist) 中与改动相关的条目手动回归。
3. 涉及数据库迁移时，请确认 `php bin/migrate.php --status` 状态正确、可从 `schema.sql` 全新安装。
4. 涉及队列/邮件的改动，确认 `php bin/queue-worker.php` 正常消费。

> 项目暂无自动化测试套件，手动自测结果请在 PR 描述中说明（含复现/验证步骤）。

---

## 提交 PR / Pull Requests

1. Fork 仓库并从最新 `main` 拉出功能分支。
2. 按上文的提交规范分次提交，保持历史清晰。
3. 发起 PR 到 `main`，在描述中说明：
   - **改了什么、为什么改**（关联的 Issue 编号，如 `Closes #12`）
   - **如何验证**（操作步骤 / 路由 / 截图）
   - 是否有**破坏性变更**或需要**数据库迁移**
4. 确保 CI（如有）通过，并响应评审意见。请保持 PR 聚焦单一主题，避免夹带无关改动。
5. 维护者可能会请求调整后再合并，感谢你的耐心。

---

## 许可证与署名 / License & Attribution

- 本项目使用 **MIT 协议**开源，贡献即表示你同意以该协议发布你的代码，详见 [LICENSE](LICENSE)。
- 页脚署名（“由SZY创新工作室开发”）及其他品牌标识为项目固定内容，**请勿修改或移除**。

By contributing, you agree that your contributions will be licensed under the MIT License. The footer attribution and brand marks are fixed and must not be modified or removed.
