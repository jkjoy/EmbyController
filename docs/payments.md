# 支付宝与微信支付

本项目使用 [yansongda/pay](https://github.com/yansongda/pay) 3.7 稳定版，支持支付宝当面付扫码和微信支付 API v3 Native 扫码。需要开通对应官方商户产品；旧易支付商户号和 MD5 密钥无法复用。

## 配置

在后台“系统设置”填写网站地址，如 `https://emby.example.com`，然后填写相应商户 JSON 并启用渠道。网站地址使用公开 HTTPS 根地址，不带 `/media`。商户 JSON 属于密钥，后台读取时不回显；留空保存原值，勾选“清除”会删除凭据并停止展示渠道。

支付宝配置示例（所有 ID 均填写字符串）：

```json
{
  "app_id": "支付宝应用 ID",
  "seller_id": "支付宝商户 UID",
  "app_secret_cert": "/app/data/pay-certificates/alipay-private.pem",
  "app_public_cert_path": "/app/data/pay-certificates/appCertPublicKey.crt",
  "alipay_public_cert_path": "/app/data/pay-certificates/alipayCertPublicKey_RSA2.crt",
  "alipay_root_cert_path": "/app/data/pay-certificates/alipayRootCert.crt",
  "mode": 0
}
```

支付宝使用证书模式与 RSA2；`seller_id` 是收款商户 UID。正式环境 `mode` 为 0，支付宝沙箱为 1，需使用整套沙箱凭据。

微信支付配置示例：

```json
{
  "mch_id": "微信商户号",
  "mp_app_id": "已与商户号关联的公众号或应用 AppID",
  "mch_secret_key": "替换为商户平台设置的32字符APIv3密钥",
  "mch_secret_cert": "/app/data/pay-certificates/apiclient_key.pem",
  "mch_public_cert_path": "/app/data/pay-certificates/apiclient_cert.pem",
  "wechat_public_cert_path": {
    "平台证书序列号或PUB_KEY_ID": "/app/data/pay-certificates/wechatpay-public.pem"
  },
  "mode": 0
}
```

`wechat_public_cert_path` 的键是支付平台证书序列号，或微信支付提供的 `PUB_KEY_ID_...` 公钥 ID；值是对应证书或公钥。更新平台证书或公钥时应保留仍有效的旧条目，以便处理正在重试的通知。项目仅支持普通商户，不支持服务商模式。

证书字段支持 PEM 原文或容器内绝对文件路径，私钥原文使用 PKCS#8 `BEGIN PRIVATE KEY` 格式。推荐把文件放在已持久化的 `/app/data/pay-certificates/`，确保运行用户 www-data 可读；不要放入 public、提交到 Git 或复制进镜像。数据库备份包含商户配置，也需要妥善保存。

SDK 的详细配置说明：[官方配置文档](https://pay.yansongda.cn/docs/v3/overview/config)。

## 回调与到账

应用自动向平台传递下列回调地址，反向代理需允许 POST 到这两个地址，并保留原始请求体和微信支付签名请求头：

- 支付宝：`https://emby.example.com/server/paymentNotify?method=alipay`
- 微信：`https://emby.example.com/server/paymentNotify?method=wxpay`

二维码有效期为 30 分钟；充值页自动检测，也可从账单详情“重新检测”。检测调用官方查询 API，不会自行补偿或重新创建平台订单。到账必须通过 SDK 验签，并匹配订单号、支付金额、币种、收款商户和交易号。到账比例在下单时保存，之后修改比例不会改变已创建订单的到账金额。回调与检测共用事务，同一交易只增加一次余额及流水；通知发送失败不会撤销到账。

启用前请用官方测试环境或小额订单完成实际下单、扫码、回调和余额核对。项目自动测试使用临时数据库、临时证书和离线平台响应，不会向真实商户扣款。

## 从旧易支付升级

容器启动时自动添加支付渠道和平台交易号字段，保留历史账单、余额、流水和旧配置记录。旧配置停止使用，旧 `/server/resolvePayment` 回调返回 HTTP 410。

先停用旧渠道并核对尚未完成的旧订单，再切换镜像。旧已付订单继续可查看；旧未付订单不能通过新 SDK 检测或续付，需要重新充值。对已付款未到账的旧订单，管理员应按旧平台账单人工核对处理，避免重复充值。
