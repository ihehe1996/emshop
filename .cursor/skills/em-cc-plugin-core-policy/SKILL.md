---
name: em-cc-plugin-core-policy
description: >-
  For the em_cc / EMSHOP codebase when building content/plugin extensions or
  integrations. If any change to core code (include/, admin/ outside plugin
  entrypoints, shared models, Dispatcher, init, etc.) appears necessary, the
  agent must tell the user first why it is needed, what would change, and what
  benefit it brings; avoid silent core edits. Prefer plugin-only solutions
  (hooks, Storage, plugin callback_init, *_setting.php).
disable-model-invocation: true
---

# EMSHOP / em_cc 插件与核心代码

## 规则

- 实现 **`content/plugin/`** 下插件或对接功能时，**默认只改插件目录**（及用户点名的文档）。
- **禁止在未告知用户的情况下修改核心代码**（如 `include/`、`admin/` 中非插件专用入口、公共 Model、路由分发、`init.php` 等）。
- 若评估后**必须**动核心：
  1. **先说明**：技术原因（例如缺少 hook、无扩展点、现有 API 无法满足）。
  2. **说清改什么**：涉及文件与行为范围，避免顺带大重构。
  3. **说明好处**：可维护性、安全、性能、对接体验；或**不改的代价**（脏 hack、升级困难、与插件冲突）。

## 偏好

- 表结构：`callback_init` 幂等 `CREATE TABLE IF NOT EXISTS`。
- 后台 UI：`{slug}_setting.php` 的 `plugin_setting_view` / `plugin_setting`，经 `admin/plugin.php` 的 `save_config` 分发。
