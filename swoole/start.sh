#!/bin/bash
# EMSHOP Swoole 启动脚本
# 在 WSL 终端中执行: bash /mnt/d/wwwroot/em_cc/swoole/start.sh

cd /mnt/d/wwwroot/em_cc

# 停止旧进程
pkill -f "swoole/server.php" 2>/dev/null
sleep 1

echo "Starting EMSHOP Swoole Server..."
php swoole/server.php start
