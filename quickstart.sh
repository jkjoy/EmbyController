#!/bin/bash
set -e

# Function to check if a command exists
command_exists() {
  command -v "$1" >/dev/null 2>&1
}

# Function to install Docker
install_docker() {
  echo "正在安装 Docker..."
  curl -fsSL https://get.docker.com -o get-docker.sh
  sh get-docker.sh
  rm get-docker.sh
  echo "Docker 安装完成。"
}

# Function to install Docker Compose
install_docker_compose() {
  echo "正在安装 Docker Compose..."
  compose_release=$(curl -fsSL https://api.github.com/repos/docker/compose/releases/latest | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p')
  if [ -z "$compose_release" ]; then
    echo "无法获取 Docker Compose 版本。退出。"
    exit 1
  fi
  compose_platform=$(uname -s | tr '[:upper:]' '[:lower:]')
  sudo curl -fL "https://github.com/docker/compose/releases/download/$compose_release/docker-compose-$compose_platform-$(uname -m)" -o /usr/local/bin/docker-compose
  sudo chmod +x /usr/local/bin/docker-compose
  echo "Docker Compose 安装完成。"
}

# Check if Docker is installed
if ! command_exists docker; then
  echo "Docker 未安装。"
  read -r -p "是否安装 Docker? (y/n): " install_docker_choice
  if [[ "$install_docker_choice" =~ ^[Yy]$ ]]; then
    install_docker
  else
    echo "Docker 是必须的。退出。"
    exit 1
  fi
fi

# 优先使用 Docker Compose v2，也兼容已有的独立 docker-compose。
if docker compose version >/dev/null 2>&1; then
  compose_command=(docker compose)
elif command_exists docker-compose; then
  compose_command=(docker-compose)
else
  echo "Docker Compose 未安装。"
  read -r -p "是否安装 Docker Compose? (y/n): " install_docker_compose_choice
  if [[ "$install_docker_compose_choice" =~ ^[Yy]$ ]]; then
    install_docker_compose
    compose_command=(docker-compose)
  else
    echo "Docker Compose 是必须的。退出。"
    exit 1
  fi
fi

# 创建 EmbyController 目录
mkdir -p EmbyController
cd EmbyController

# 已有部署配置保持原样，首次下载使用临时文件避免网络失败留下残缺配置。
if [ -e docker-compose.yml ]; then
  echo "保留现有 docker-compose.yml。"
else
  compose_download_tmp=$(mktemp ./docker-compose.yml.XXXXXX)
  if ! curl -fsSL https://raw.githubusercontent.com/jkjoy/EmbyController/refs/heads/main/docker-compose.yml -o "$compose_download_tmp"; then
    rm -f "$compose_download_tmp"
    echo "下载 docker-compose.yml 失败。退出。"
    exit 1
  fi
  mv -n "$compose_download_tmp" docker-compose.yml
  rm -f "$compose_download_tmp"
fi

echo "新下载的默认配置使用 SQLite，数据持久化到 $(pwd)/data，无需单独安装数据库。"
echo "已有 docker-compose.yml 保持原配置；如需 MySQL，请按 README 编辑 environment 中的数据库连接。"
echo "本脚本使用 Docker Compose 部署，数据库配置直接写在 Compose 文件中，无需 .env。"
echo "如升级旧部署，请先按 README 导入旧业务配置，再切换新的 Compose 配置。"
read -r -p "是否使用当前 Compose 配置启动? (y/n): " start_choice
if [[ "$start_choice" =~ ^[Yy]$ ]]; then
  "${compose_command[@]}" -f docker-compose.yml up -d
  echo "容器已启动。请配置外部 Nginx 连接 9000 端口、代理 2347 端口的 /ws，再登录管理后台填写网站地址、Emby 及其它服务设置。"
else
  echo "配置保存后，在 $(pwd) 执行: ${compose_command[*]} -f docker-compose.yml up -d"
fi
