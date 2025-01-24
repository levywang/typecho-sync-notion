# Typecho Sync Notion

Typecho 同步 Notion 笔记插件，支持将 Typecho 文章同步到 Notion 数据库中。

## 功能特性

- 支持在文章编辑页面选择是否同步到 Notion
- 支持配置 Notion API 密钥
- 支持配置 Notion 数据库 ID
- 支持文章标题、内容、分类、标签同步
- 支持文章更新时同步更新 Notion 页面

PS: 受限于 Notion 的限制，部分Markdown格式可能无法被正常识别，请注意
比如 Code 区块，超过 2000 个字符串会被识别成段落，否则 Notion API 报错，还要表格区块、有序列表等

## 安装方法

1. 下载插件压缩包
2. 将插件解压到 Typecho 的 `/usr/plugins/` 目录下
3. 进入 Typecho 后台启用插件
4. 在插件配置页面填写 Notion API 密钥和数据库 ID

## 配置说明

1. Notion API 密钥获取：
   - 访问 [Notion Integrations](https://www.notion.so/profile/integrations/)
   - 创建一个新的 integration
   - 复制生成的 API 密钥

2. Notion 数据库 ID 获取：
   - 在 Notion 中创建一个新的数据库
   - 在数据库页面的 URL 中获取数据库 ID
   - 确保将创建的 integration 添加到该数据库的访问权限中

## 使用方法

1. 在文章编辑页面右侧，您会看到"同步到 Notion"的选项
2. 勾选该选项并发布/更新文章即可自动同步到 Notion

## 注意事项

- 请确保您的 Notion API 密钥具有正确的权限
- 首次使用时请先测试配置是否正确