# ============================================================
# CourseShop 一键启动脚本（Windows PowerShell）
#
# 作用：
#   1. 用项目内 .dev/mariadb 作为独立 MariaDB 实例（不污染系统数据库）
#   2. 创建数据库 courseshop 与专用账号
#   3. 首次运行时导入 database/schema.sql 与 database/seed.sql
#   4. 启动 PHP 内置服务器，站点根目录为 public/
#
# 使用：powershell -ExecutionPolicy Bypass -File scripts\dev-start.ps1
# 停止：powershell -ExecutionPolicy Bypass -File scripts\dev-stop.ps1
# ============================================================

# 说明：脚本需要大量调用 MariaDB / PHP 原生命令行工具。
# PowerShell 5.1 在 Stop 模式下会把原生程序的 stderr 警告当成终止错误，
# 因此这里使用 Continue，并通过显式检查 $LASTEXITCODE + throw 保证失败能被发现。
$ErrorActionPreference = 'Continue'

# ---------------- 可调整参数 ----------------
$httpPort         = 8090                      # 站点监听端口
$databasePort     = 3307                      # 数据库监听端口
$databaseName     = 'courseshop'              # 数据库名
$databaseUser     = 'courseshop'              # 数据库账号
# 数据库密码：由脚本首次运行时随机生成并持久化到 .dev/db-password.txt，后续运行复用。
# 留空表示「自动生成 / 读取」，一般无需手动修改。
$databasePassword = ''

# ---------------- 路径 ----------------
$projectRoot       = Split-Path -Parent $PSScriptRoot
$devDirectory      = Join-Path $projectRoot '.dev'
$mariadbDirectory  = Join-Path $devDirectory 'mariadb'
$databaseDirectory = Join-Path $mariadbDirectory 'data'
$mariadbConfigFile = Join-Path $mariadbDirectory 'my.ini'
$mariadbErrorLog   = Join-Path $mariadbDirectory 'error.log'
$databasePidFile   = Join-Path $devDirectory 'mariadb.pid'
$phpPidFile        = Join-Path $devDirectory 'php.pid'
$queuePidFile      = Join-Path $devDirectory 'queue.pid'
$credentialsFile   = Join-Path $devDirectory 'credentials.txt'
$passwordFile      = Join-Path $devDirectory 'db-password.txt'
$envFile           = Join-Path $projectRoot '.env'

# ---------------- 通用函数 ----------------

# 写入不带 BOM 的 UTF-8 文本（MariaDB 的 my.ini 无法解析行首 BOM）
function Write-TextFileWithoutBom([string] $path, [string] $content) {
    [System.IO.File]::WriteAllText($path, $content, (New-Object System.Text.UTF8Encoding($false)))
}

function Test-ManagedProcess([string] $pidFile) {
    if (-not (Test-Path -LiteralPath $pidFile)) {
        return $false
    }

    $processId = [int] (Get-Content -Raw -LiteralPath $pidFile)
    return $null -ne (Get-Process -Id $processId -ErrorAction SilentlyContinue)
}

# 快速探测 TCP 端口是否可连接（比 mariadb-admin ping 更可靠，失败端口不会卡住）
function Test-TcpPort([string] $targetHost, [int] $targetPort, [int] $timeoutMilliseconds = 400) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $asyncResult = $client.BeginConnect($targetHost, $targetPort, $null, $null)
        if (-not $asyncResult.AsyncWaitHandle.WaitOne($timeoutMilliseconds)) {
            return $false
        }
        $client.EndConnect($asyncResult)
        return $true
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

# 等待数据库端口可用；若进程提前退出则立即失败
function Wait-MariaDbReady([int] $processId, [int] $timeoutSeconds = 60) {
    $deadline = (Get-Date).AddSeconds($timeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if ($null -eq (Get-Process -Id $processId -ErrorAction SilentlyContinue)) {
            return $false
        }
        if (Test-TcpPort '127.0.0.1' $databasePort) {
            return $true
        }
        Start-Sleep -Milliseconds 300
    }
    return $false
}

function Resolve-MariaDbBase {
    # 优先使用环境变量 COURSESHOP_MARIADB_HOME，其次查找已安装的 MariaDB / MySQL
    if ($env:COURSESHOP_MARIADB_HOME -and (Test-Path -LiteralPath (Join-Path $env:COURSESHOP_MARIADB_HOME 'bin\mariadbd.exe'))) {
        return $env:COURSESHOP_MARIADB_HOME
    }

    $candidates = @()
    $candidates += Get-ChildItem 'C:\Program Files\MariaDB*\bin\mariadbd.exe' -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty FullName
    $candidates += Get-ChildItem 'C:\Program Files\MySQL\*\bin\mysqld.exe' -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty FullName

    if ($candidates.Count -eq 0) {
        throw '未找到 MariaDB/MySQL 服务端。请安装 MariaDB，或设置环境变量 COURSESHOP_MARIADB_HOME 指向安装目录。'
    }

    # 取版本号最大的一项（目录名倒序）
    return Split-Path -Parent (Split-Path -Parent ($candidates | Sort-Object -Descending | Select-Object -First 1))
}

function Resolve-PhpExecutable {
    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $command) {
        return $command.Source
    }

    $patterns = @(
        'C:\php*\php.exe',
        (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PHP.PHP*_\php.exe'),
        'C:\Program Files\PHP\*\php.exe'
    )
    foreach ($pattern in $patterns) {
        $found = Get-ChildItem $pattern -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
        if ($found) {
            return $found
        }
    }

    throw '未找到 PHP 可执行文件。请安装 PHP 8.1+ 并加入 PATH。'
}

function Show-MariaDbErrorLog {
    if (Test-Path -LiteralPath $mariadbErrorLog) {
        Write-Host '--- 数据库错误日志（末尾 30 行） ---' -ForegroundColor DarkYellow
        Get-Content -Tail 30 -LiteralPath $mariadbErrorLog | ForEach-Object { Write-Host $_ }
    } else {
        Write-Host '（未生成数据库错误日志）' -ForegroundColor DarkYellow
    }
}

# ---------------- 目录准备 ----------------
New-Item -ItemType Directory -Path $devDirectory -Force | Out-Null
New-Item -ItemType Directory -Path $mariadbDirectory -Force | Out-Null

# ---------------- 数据库密码（随机生成并持久化，避免使用固定弱口令） ----------------
# 优先复用已保存的密码；若脚本顶部已显式配置则不覆盖。
if ([string]::IsNullOrWhiteSpace($databasePassword) -and (Test-Path -LiteralPath $passwordFile)) {
    $databasePassword = (Get-Content -Raw -LiteralPath $passwordFile).Trim()
}

if ([string]::IsNullOrWhiteSpace($databasePassword)) {
    $databasePassword = [guid]::NewGuid().ToString('N')
    Write-TextFileWithoutBom $passwordFile $databasePassword
    Write-Host "已生成随机数据库密码，保存于：$passwordFile" -ForegroundColor Cyan
}

$mariadbBase = Resolve-MariaDbBase
$serverExecutable  = Join-Path $mariadbBase 'bin\mariadbd.exe'
$clientExecutable  = Join-Path $mariadbBase 'bin\mariadb.exe'
$installExecutable = Join-Path $mariadbBase 'bin\mariadb-install-db.exe'

if (-not (Test-Path -LiteralPath $serverExecutable)) {
    throw "未找到数据库服务端程序：$serverExecutable"
}
if (-not (Test-Path -LiteralPath $installExecutable)) {
    throw "未找到数据库初始化程序：$installExecutable"
}

# ---------------- 初始化数据目录 ----------------
$dataInitialized = Test-Path -LiteralPath (Join-Path $databaseDirectory 'mysql')

if (-not $dataInitialized) {
    if (Test-Path -LiteralPath $databaseDirectory) {
        Remove-Item -LiteralPath $databaseDirectory -Recurse -Force
    }
    New-Item -ItemType Directory -Path $databaseDirectory -Force | Out-Null

    Write-Host '正在初始化数据库数据目录（首次运行，请稍候）...' -ForegroundColor Cyan
    # 注意：mariadb-install-db.exe 会自动推断 basedir，且仅在传入 --service 时才注册系统服务
    & $installExecutable "--datadir=$databaseDirectory" "--port=$databasePort"
    if ($LASTEXITCODE -ne 0) {
        throw '数据库数据目录初始化失败。'
    }
}

# ---------------- 生成 my.ini（每次启动覆盖，保证配置与脚本一致） ----------------
$iniContent = @"
[mysqld]
basedir="$($mariadbBase -replace '\\','/')"
datadir="$($databaseDirectory -replace '\\','/')"
port=$databasePort
bind-address=127.0.0.1
default-storage-engine=InnoDB
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
max_connections=200
innodb_buffer_pool_size=128M
log-error="$($mariadbErrorLog -replace '\\','/')"

[client]
default-character-set=utf8mb4
"@
Write-TextFileWithoutBom $mariadbConfigFile $iniContent

# ---------------- 启动数据库 ----------------
if (Test-ManagedProcess $databasePidFile) {
    Write-Host '数据库已在运行。' -ForegroundColor DarkGray
} else {
    if (Test-TcpPort '127.0.0.1' $databasePort) {
        throw "端口 $databasePort 已被其他进程占用，请先关闭它或在脚本顶部修改 `$databasePort。"
    }

    $databaseProcess = Start-Process -FilePath $serverExecutable `
        -ArgumentList @("--defaults-file=$mariadbConfigFile") `
        -WorkingDirectory $mariadbBase -WindowStyle Hidden -PassThru
    $databaseProcess.Id | Set-Content -LiteralPath $databasePidFile -NoNewline

    if (-not (Wait-MariaDbReady $databaseProcess.Id)) {
        Write-Host "数据库启动失败，请查看日志：$mariadbErrorLog" -ForegroundColor Red
        Show-MariaDbErrorLog
        Remove-Item -LiteralPath $databasePidFile -Force -ErrorAction SilentlyContinue
        throw '数据库启动失败。'
    }
    Write-Host '数据库已启动。' -ForegroundColor Green
}

# ---------------- 创建数据库与账号 ----------------
# CREATE USER IF NOT EXISTS 不会覆盖已存在账号的密码，因此再显式 ALTER USER 同步为当前密码，
# 保证数据库账号密码与 .dev/db-password.txt / .env 始终一致。
$bootstrapSql = @"
CREATE DATABASE IF NOT EXISTS ``$databaseName`` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$databaseUser'@'%' IDENTIFIED BY '$databasePassword';
ALTER USER '$databaseUser'@'%' IDENTIFIED BY '$databasePassword';
CREATE USER IF NOT EXISTS '$databaseUser'@'localhost' IDENTIFIED BY '$databasePassword';
ALTER USER '$databaseUser'@'localhost' IDENTIFIED BY '$databasePassword';
GRANT ALL PRIVILEGES ON ``$databaseName``.* TO '$databaseUser'@'%';
GRANT ALL PRIVILEGES ON ``$databaseName``.* TO '$databaseUser'@'localhost';
FLUSH PRIVILEGES;
"@
$bootstrapSql | & $clientExecutable --protocol=tcp -h 127.0.0.1 -P $databasePort -u root
if ($LASTEXITCODE -ne 0) {
    throw '创建数据库或数据库账号失败。'
}

# ---------------- 首次导入表结构与初始数据 ----------------
# 使用客户端的 source 命令读取文件，避免 PowerShell 管道破坏中文数据编码
function Import-SqlFile([string] $sqlFilePath) {
    $normalizedPath = $sqlFilePath -replace '\\', '/'
    & $clientExecutable --protocol=tcp -h 127.0.0.1 -P $databasePort -u root `
        --default-character-set=utf8mb4 -e "source $normalizedPath" $databaseName
    return $LASTEXITCODE
}

$seeded = & $clientExecutable --protocol=tcp -h 127.0.0.1 -P $databasePort -u root `
    --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$databaseName' AND table_name='users';" 2>$null

if (($seeded -join '') -ne '1') {
    Write-Host '正在导入表结构与初始数据...' -ForegroundColor Cyan

    if ((Import-SqlFile (Join-Path $projectRoot 'database\schema.sql')) -ne 0) {
        throw '导入 database/schema.sql 失败。'
    }
    if ((Import-SqlFile (Join-Path $projectRoot 'database\seed.sql')) -ne 0) {
        throw '导入 database/seed.sql 失败。'
    }
    Write-Host '表结构与初始数据导入完成。' -ForegroundColor Green
}

# ---------------- 生成本地 .env ----------------
$envCreated = -not (Test-Path -LiteralPath $envFile)

if ($envCreated) {
    Copy-Item -LiteralPath (Join-Path $projectRoot '.env.example') -Destination $envFile
}

# 数据库连接信息每次启动都同步，避免随机密码与旧 .env 不一致导致连接失败
$envContent = [System.IO.File]::ReadAllText($envFile)
$envContent = $envContent -replace '(?m)^DB_HOST=.*$',        "DB_HOST=127.0.0.1"
$envContent = $envContent -replace '(?m)^DB_PORT=.*$',        "DB_PORT=$databasePort"
$envContent = $envContent -replace '(?m)^DB_DATABASE=.*$',    "DB_DATABASE=$databaseName"
$envContent = $envContent -replace '(?m)^DB_USERNAME=.*$',    "DB_USERNAME=$databaseUser"
$envContent = $envContent -replace '(?m)^DB_PASSWORD=.*$',    "DB_PASSWORD=$databasePassword"

# APP_URL / APP_KEY 仅在首次生成时写入，避免每次启动变更 APP_KEY 导致会话 / 加密数据失效
if ($envCreated) {
    $envContent = $envContent -replace '(?m)^APP_URL=.*$',    "APP_URL=http://127.0.0.1:$httpPort"
    $envContent = $envContent -replace '(?m)^APP_KEY=.*$',    "APP_KEY=$([guid]::NewGuid().ToString('N'))"
}

Write-TextFileWithoutBom $envFile $envContent

if ($envCreated) {
    Write-Host '已生成 .env 本地配置。' -ForegroundColor Green
}

# ---------------- 启动 PHP ----------------
if (Test-ManagedProcess $phpPidFile) {
    Write-Host '站点服务已在运行。' -ForegroundColor DarkGray
} else {
    if (Test-TcpPort '127.0.0.1' $httpPort) {
        throw "端口 $httpPort 已被其他进程占用，请先关闭它或在脚本顶部修改 `$httpPort。"
    }

    $phpExecutable = Resolve-PhpExecutable
    $routerFile = Join-Path $projectRoot 'public\index.php'
    if (-not (Test-Path -LiteralPath $routerFile)) {
        throw "缺少站点入口文件：$routerFile（请确认项目文件完整）。"
    }

    # -d 参数用于放开 PHP 默认的上传限制，否则章节视频无法直接上传（默认仅 2M/8M）
    $phpProcess = Start-Process -FilePath $phpExecutable `
        -ArgumentList @(
            '-d', 'upload_max_filesize=2048M',
            '-d', 'post_max_size=2048M',
            '-d', 'memory_limit=512M',
            '-d', 'max_execution_time=600',
            '-S', "0.0.0.0:$httpPort", '-t', 'public', 'public/index.php'
        ) `
        -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru
    $phpProcess.Id | Set-Content -LiteralPath $phpPidFile -NoNewline

    Start-Sleep -Milliseconds 800
    if ($null -eq (Get-Process -Id $phpProcess.Id -ErrorAction SilentlyContinue)) {
        Remove-Item -LiteralPath $phpPidFile -Force -ErrorAction SilentlyContinue
        throw '站点服务启动失败（PHP 进程已退出）。'
    }
    Write-Host '站点服务已启动。' -ForegroundColor Green
}

# ---------------- 启动队列消费进程（P2） ----------------
# 邮件 / 视频转码等异步任务由 bin/queue-worker.php 消费，随环境一起拉起便于本地调试。
if (Test-ManagedProcess $queuePidFile) {
    Write-Host '队列消费进程已在运行。' -ForegroundColor DarkGray
} else {
    $queueExecutable = Resolve-PhpExecutable
    $queueScript = Join-Path $projectRoot 'bin\queue-worker.php'
    if (-not (Test-Path -LiteralPath $queueScript)) {
        throw "缺少队列消费脚本：$queueScript（请确认项目文件完整）。"
    }

    $queueLogFile = Join-Path $devDirectory 'queue.log'
    $queueProcess = Start-Process -FilePath $queueExecutable `
        -ArgumentList @('bin/queue-worker.php') `
        -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $queueLogFile
    $queueProcess.Id | Set-Content -LiteralPath $queuePidFile -NoNewline

    Start-Sleep -Milliseconds 500
    if ($null -eq (Get-Process -Id $queueProcess.Id -ErrorAction SilentlyContinue)) {
        Remove-Item -LiteralPath $queuePidFile -Force -ErrorAction SilentlyContinue
        throw "队列消费进程启动失败，请查看日志：$queueLogFile"
    }
    Write-Host '队列消费进程已启动。' -ForegroundColor Green
}

# ---------------- 输出访问信息 ----------------
$lanAddress = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
    Select-Object -First 1 -ExpandProperty IPAddress)

$credentialContent = @"
CourseShop 本地环境信息
生成时间：$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')

站点地址：http://127.0.0.1:$httpPort/
数据库地址：127.0.0.1:$databasePort
数据库名：$databaseName
数据库账号：$databaseUser
数据库密码：$databasePassword
数据库 root：root（无密码，仅本机可连）

首次访问站点会进入安装向导，用于创建管理员账号。
"@
Write-TextFileWithoutBom $credentialsFile $credentialContent

Write-Host ''
Write-Host 'CourseShop 环境已启动：' -ForegroundColor Green
Write-Host "  本机：http://127.0.0.1:$httpPort/"
if ($lanAddress) {
    Write-Host "  局域网（手机同一 WiFi 可访问）：http://${lanAddress}:$httpPort/"
}
Write-Host "  数据库：127.0.0.1:$databasePort / $databaseName"
Write-Host "  环境信息：$credentialsFile"
