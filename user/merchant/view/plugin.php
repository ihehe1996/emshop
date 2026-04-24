<?php
/** @var array<string, mixed> $currentMerchant */
/** @var array<string, array<string, mixed>> $availablePlugins */
/** @var string $licenseError */
/** @var string $csrfToken */
?>
<style>
/* 插件卡片按钮组 */
.mc-plugin-actions {
    display: flex; gap: 6px; flex-wrap: wrap;
    margin-top: 12px;
}
.mc-plugin-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 12px;
    font-size: 12px; font-weight: 500;
    border: 1px solid #e5e7eb; border-radius: 5px;
    background: #fff; color: #374151;
    cursor: pointer;
    transition: all .15s ease;
}
.mc-plugin-btn:hover { background: #f9fafb; border-color: #d1d5db; }
.mc-plugin-btn i { font-size: 11px; }
.mc-plugin-btn--primary  { background: #4e6ef2; color: #fff; border-color: #4e6ef2; }
.mc-plugin-btn--primary:hover  { background: #3c58d9; color: #fff; }
.mc-plugin-btn--success  { background: #10b981; color: #fff; border-color: #10b981; }
.mc-plugin-btn--success:hover  { background: #059669; color: #fff; }
.mc-plugin-btn--warning  { background: #fef3c7; color: #92400e; border-color: #fde68a; }
.mc-plugin-btn--warning:hover  { background: #fde68a; }
.mc-plugin-btn--danger   { background: #fff; color: #dc2626; border-color: #fecaca; }
.mc-plugin-btn--danger:hover   { background: #fef2f2; border-color: #fca5a5; }

.mc-plugin-status {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 1px 8px;
    font-size: 10px; font-weight: 500;
    border-radius: 3px;
    margin-left: 6px;
}
.mc-plugin-status--enabled  { background: #d1fae5; color: #047857; }
.mc-plugin-status--disabled { background: #f3f4f6; color: #6b7280; }
.mc-plugin-status--uninstalled { background: #fef3c7; color: #92400e; }
</style>

<div class="mc-page">
    <div class="mc-page-header">
        <h2 class="mc-page-title">插件管理</h2>
        <p class="mc-page-desc">仅展示本店已从应用商店购买的插件；可在此启用、禁用或卸载</p>
    </div>

    <?php if ($licenseError !== ''): ?>
    <div style="padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:6px;margin-bottom:16px;font-size:13px;">
        <i class="fa fa-exclamation-triangle"></i>
        无法连接中心服务：<?= htmlspecialchars($licenseError) ?>。插件列表暂不可见，请稍后重试。
    </div>
    <?php endif; ?>

    <div class="mc-section">
        <div class="mc-section-title">
            本店已购插件
            <span style="font-size:12px;font-weight:normal;color:#9ca3af;">共 <?= count($availablePlugins) ?> 个</span>
        </div>
        <?php if ($licenseError === '' && empty($availablePlugins)): ?>
        <div class="mc-placeholder" style="padding:40px 20px;text-align:center;color:#9ca3af;">
            <i class="fa fa-plug" style="font-size:32px;display:block;margin-bottom:8px;color:#d1d5db;"></i>
            <div>本店尚未购买任何插件</div>
            <div style="margin-top:8px;font-size:12px;">
                可在<a href="/user/merchant/appstore.php" data-pjax="#merchantContent" style="color:#4e6ef2;">应用商店</a>购买后回到此页启用
            </div>
        </div>
        <?php elseif (!empty($availablePlugins)): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;">
            <?php foreach ($availablePlugins as $name => $info): ?>
            <?php
                $isInstalled = !empty($info['is_installed']);
                $isEnabled   = !empty($info['is_enabled']);
                $hasSetting  = !empty($info['has_setting']);
                $statusClass = !$isInstalled ? 'uninstalled' : ($isEnabled ? 'enabled' : 'disabled');
                $statusText  = !$isInstalled ? '未安装' : ($isEnabled ? '已启用' : '已停用');
            ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                    <?php if (!empty($info['icon'])): ?>
                    <img src="<?= htmlspecialchars($info['icon']) ?>" style="width:36px;height:36px;border-radius:6px;object-fit:contain;background:#f3f4f6;flex-shrink:0;">
                    <?php else: ?>
                    <span style="width:36px;height:36px;border-radius:6px;background:#eef2ff;color:#4e6ef2;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fa fa-plug"></i></span>
                    <?php endif; ?>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:6px;font-weight:600;font-size:14px;">
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($info['title'] ?: $name) ?></span>
                            <?php if (!empty($info['custom'])): ?>
                            <span style="padding:1px 6px;background:#fef3c7;color:#92400e;border-radius:4px;font-size:10px;font-weight:normal;">自建</span>
                            <?php endif; ?>
                            <span class="mc-plugin-status mc-plugin-status--<?= $statusClass ?>"><?= $statusText ?></span>
                        </div>
                        <div style="color:#9ca3af;font-size:11px;margin-top:2px;">
                            v<?= htmlspecialchars($info['version'] ?? '1.0.0') ?>
                            <?php if (!empty($info['category'])): ?> · <?= htmlspecialchars($info['category']) ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="color:#6b7280;font-size:12px;line-height:1.6;min-height:36px;">
                    <?= htmlspecialchars($info['description'] ?? '') ?>
                </div>

                <div class="mc-plugin-actions">
                    <?php if (!$isInstalled): ?>
                        <button class="mc-plugin-btn mc-plugin-btn--primary" data-action="install" data-name="<?= htmlspecialchars($name) ?>" type="button">
                            <i class="fa fa-download"></i> 安装
                        </button>
                    <?php else: ?>
                        <?php if ($isEnabled): ?>
                            <button class="mc-plugin-btn mc-plugin-btn--warning" data-action="disable" data-name="<?= htmlspecialchars($name) ?>" type="button">
                                <i class="fa fa-pause"></i> 停用
                            </button>
                        <?php else: ?>
                            <button class="mc-plugin-btn mc-plugin-btn--success" data-action="enable" data-name="<?= htmlspecialchars($name) ?>" type="button">
                                <i class="fa fa-play"></i> 启用
                            </button>
                        <?php endif; ?>
                        <?php if ($hasSetting): ?>
                            <button class="mc-plugin-btn" data-action="settings" data-name="<?= htmlspecialchars($name) ?>" type="button">
                                <i class="fa fa-gear"></i> 配置
                            </button>
                        <?php endif; ?>
                        <button class="mc-plugin-btn mc-plugin-btn--danger" data-action="uninstall" data-name="<?= htmlspecialchars($name) ?>" type="button">
                            <i class="fa fa-trash"></i> 卸载
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    'use strict';
    // 脚本幂等：PJAX 来回切换时只绑一次事件
    var csrfToken = <?= json_encode($csrfToken) ?>;
    var lock = false;

    function doRequest(action, name, onDone) {
        if (lock) return;
        lock = true;
        var loadingIdx = layui.layer.load(2, { shade: [0.3, '#000'] });
        $.ajax({
            url: '/user/merchant/plugin.php',
            type: 'POST',
            dataType: 'json',
            data: { csrf_token: csrfToken, _action: action, name: name }
        }).done(function (res) {
            layui.layer.close(loadingIdx);
            if (res && (res.code === 200 || res.code === 0)) {
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                layui.layer.msg(res.msg || '操作成功');
                // PJAX 重载本页
                if ($.pjax) {
                    $.pjax({ url: '/user/merchant/plugin.php', container: '#merchantContent' });
                } else {
                    location.reload();
                }
            } else {
                layui.layer.msg((res && res.msg) || '操作失败');
            }
            if (onDone) onDone();
        }).fail(function () {
            layui.layer.close(loadingIdx);
            layui.layer.msg('网络异常');
            if (onDone) onDone();
        }).always(function () {
            lock = false;
        });
    }

    function openSettings(name) {
        layui.layer.open({
            type: 2,
            title: '插件设置',
            skin: 'admin-modal',
            area: [window.innerWidth >= 800 ? '720px' : '94%', window.innerHeight >= 640 ? '80%' : '88%'],
            maxmin: true,
            shadeClose: false,
            content: '/user/merchant/plugin.php?_popup=1&name=' + encodeURIComponent(name)
        });
    }

    $(document).off('click.mcPluginBtn').on('click.mcPluginBtn', '.mc-plugin-btn[data-action]', function () {
        var action = $(this).data('action');
        var name   = $(this).data('name');
        if (!action || !name) return;
        if (action === 'settings') { openSettings(name); return; }
        if (action === 'uninstall') {
            layui.layer.confirm('确定卸载该插件吗？仅清除本店的记录，磁盘文件不受影响。', { icon: 3, title: '卸载确认' }, function (idx) {
                layui.layer.close(idx);
                doRequest('uninstall', name);
            });
            return;
        }
        doRequest(action, name);
    });
})();
</script>
