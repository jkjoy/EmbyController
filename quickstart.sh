#!/bin/bash

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
  sudo curl -L "https://github.com/docker/compose/releases/download/$(curl -s https://api.github.com/repos/docker/compose/releases/latest | grep -oP '"tag_name": "\K(.*)(?=")')/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
  sudo chmod +x /usr/local/bin/docker-compose
  echo "Docker Compose 安装完成。"
}

# Check if Docker is installed
if ! command_exists docker; then
  echo "Docker 未安装。"
  read -p "是否安装 Docker? (y/n): " install_docker_choice
  if [ "$install_docker_choice" = "y" ]; then
    install_docker
  else
    echo "Docker 是必须的。退出。"
    exit 1
  fi
fi

# Check if Docker Compose is installed
if ! command_exists docker-compose; then
  echo "Docker Compose 未安装。"
  read -p "是否安装 Docker Compose? (y/n): " install_docker_compose_choice
  if [ "$install_docker_compose_choice" = "y" ]; then
    install_docker_compose
  else
    echo "Docker Compose 是必须的。退出。"
    exit 1
  fi
fi

# 创建 EmbyController 目录
mkdir -p EmbyController
cd EmbyController

# 下载 .env 文件
curl -o .env https://raw.githubusercontent.com/jkjoy/EmbyController/refs/heads/main/example.env

# 下载 docker-compose.yml 文件
curl -o docker-compose.yml https://raw.githubusercontent.com/jkjoy/EmbyController/refs/heads/main/docker-compose.yml

# 让用户选择使用 Docker 还是 Docker Compose
while true; do
  echo "请选择创建容器的方法:"
  echo "1) Docker"
  echo "2) Docker Compose"
  read -p "请输入你的选择 (1 或 2): " choice

  if [ "$choice" -eq 1 ]; then
    # 使用 Docker 创建容器
    docker run -d -p 127.0.0.1:9000:9000 -p 127.0.0.1:2347:2347 --name emby-controller --log-driver json-file --log-opt max-size=10m --log-opt max-file=3 --env-file .env -v $(pwd)/.env:/app/.env ghcr.io/jkjoy/emby-controller:latest
    break
  elif [ "$choice" -eq 2 ]; then
    # 使用 Docker Compose 创建容器
    docker-compose up -d
    break
  else
    echo "无效的选择。请重新选择。"
  fi
done

echo "请修改.env中的数据库连接和APP_HOST后重启容器，并配置外部Nginx连接9000端口、代理2347端口的/ws。"
