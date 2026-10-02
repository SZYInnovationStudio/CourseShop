# ============================================================
# CourseShop 环境停止脚本（Windows PowerShell）
#
# 作用：按 .dev 下的 PID 文件停止 PHP 站点服务与 MariaDB 实例。
# 使用：powershell -ExecutionPolicy Bypass -File scripts\dev-stop.ps1
# ============================================================

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$devDirectory = Join-Path $projectRoot '.dev'
$stopped = @()

# 先停队列消费进程与站点服务，再停数据库，避免连接被强制中断
foreach ($name in @('queue', 'php', 'mariadb')) {
    $pidFile = Join-Path $devDirectory "$name.pid"
    if (-not (Test-Path -LiteralPath $pidFile)) {
        continue
    }

    $processId = [int] (Get-Content -Raw -LiteralPath $pidFile)
    $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
    if ($null -ne $process) {
        Stop-Process -Id $processId -Force
        $stopped += "$name (PID $processId)"
    }
    Remove-Item -LiteralPath $pidFile -Force
}

if ($stopped.Count -eq 0) {
    Write-Host '没有正在运行的 CourseShop 服务。' -ForegroundColor DarkGray
} else {
    Write-Host "已停止：$($stopped -join '、')" -ForegroundColor Yellow
}
