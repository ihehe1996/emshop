<?php
/** @var array<string, mixed> $currentMerchant */
/** @var array<string, mixed>|null $merchantLevel */
/** @var string $activeThemePc 本店 PC 当前启用模板 name */
/** @var string $activeThemeMobile 本店手机当前启用模板 name */
/** @var array<string, array<string, mixed>> $availableThemes 过滤后可用的模板列表（已叠加 is_installed / is_active_* 字段） */
/** @var string $licenseError */
/** @var string $csrfToken */
?>
<style>
.mc-theme-actions {
    display: flex; gap: 6px; flex-wrap: wrap;
    margin-top: 10px;
}
.mc-theme-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 12px;
    font-size: 12px; font-weight: 500;
    border: 1px solid #e5e7eb; border-radius: 5px;
    background: #fff; color: #374151;
    cursor: pointer;
    transition: all .15s ease;
}
.mc-theme-btn:hover { background: #f9fafb; border-color: #d1d5db; }
.mc-theme-btn i { font-size: 11px; }
.mc-theme-btn--primary { background: #4e6ef2; color: #fff; border-color: #4e6ef2; }
.mc-theme-btn--primary:hover { background: #3c58d9; color: #fff; }
.mc-theme-btn--active  { background: #10b981; color: #fff; border-color: #10b981; }
.mc-theme-btn--active:hover { background: #059669; color: #fff; }
.mc-theme-btn--danger  { background: #fff; color: #dc2626; border-color: #fecaca; }
.mc-theme-btn--danger:hover { background: #fef2f2; border-color: #fca5a5; }

.mc-theme-tag {
    display: inline-flex; align-items: center;
    padding: 1px 6px;
    font-size: 10px; font-weight: 500;
    border-radius: 4px;
    margin-left: 4px;
}
.mc-theme-tag--pc      { background: #dbeafe; color: #1e40af; }
.mc-theme-tag--mobile  { background: #ede9fe; color: #5b21b6; }
.mc-theme-tag--uninstalled { background: #f3f4f6; color: #6b7280; }
</style>

<div class="mc-page">
    <div class="mc-page-header">
        <h2 class="mc-page-title">模板管理</h2>
        <p class="mc-page-desc">切换本店前台模板外观；仅展示本店已从应用商店购买的模板</p>
    </div>

    <?php if ($licenseError !== ''): ?>
    <div style="padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:6px;margin-bottom:16px;font-size:13px;">
        <i class="fa fa-exclamation-triangle"></i>
        无法连接中心服务：<?= htmlspecialchars($licenseError) ?>。模板列表暂不可见，请稍后重试。
    </div>
    <?php endif; ?>

    <!-- 当前模板 -->
    <div class="mc-section">
        <div class="mc-section-title">当前模板</div>
        <div style="display:flex;align-items:center;gap:14px;padding:16px;background:linear-gradient(135deg,#eef2ff 0%, #dbeafe 100%);border-radius:8px;">
            <div style="width:48px;height:48px;border-radius:8px;background:#4e6ef2;color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;">
                <i class="fa fa-paint-brush"></i>
            </div>
            <div style="flex:1;">
                <div style="font-weight:600;font-size:15px;">
                    PC：<?= htmlspecialchars($activeThemePc ?: '（未设置，默认跟随主站）') ?>
                </div>
                <div style="font-weight:500;font-size:13px;color:#4b5563;margin-top:2px;">
                    手机：<?= htmlspecialchars($activeThemeMobile ?: '（未设置，默认跟随主站）') ?>
                </div>
                <div style="color:#6b7280;font-size:12px;margin-top:6px;">
                    本店可独立切换模板
                </div>
            </div>
        </div>
    </div>

    <!-- 本店已购模板 -->
    <div class="mc-section">
        <div class="mc-section-title">
            本店已购模板
            <span style="font-size:12px;font-weight:normal;color:#9ca3af;">共 <?= count($availableThemes) ?> 个</span>
        </div>
        <?php if ($licenseError === '' && empty($availableThemes)): ?>
        <div class="mc-placeholder" style="padding:40px 20px;text-align:center;color:#9ca3af;">
            <i class="fa fa-shopping-bag" style="font-size:32px;display:block;margin-bottom:8px;color:#d1d5db;"></i>
            <div>本店尚未购买任何模板</div>
            <div style="margin-top:8px;font-size:12px;">
                可在<a href="/user/merchant/appstore.php" data-pjax="#merchantContent" style="color:#4e6ef2;">应用商店</a>购买后回到此页切换
            </div>
        </div>
        <?php elseif (!empty($availableThemes)): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;">
            <?php foreach ($availableThemes as $name => $info): ?>
            <?php
                $isInstalled = !empty($info['is_installed']);
                $isPcActive  = !empty($info['is_active_pc']);
                $isMobActive = !empty($info['is_active_mobile']);
                $hasSetting  = !empty($info['has_setting']);
            ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;transition:border-color .15s;">
                <?php if (!empty($info['preview'])): ?>
                <div style="height:140px;background:#f3f4f6 url(<?= htmlspecialchars($info['preview']) ?>) center/cover no-repeat;"></div>
                <?php else: ?>
                <div style="height:140px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9ca3af;"><i class="fa fa-picture-o fa-2x"></i></div>
                <?php endif; ?>
                <div style="padding:12px 14px;">
                    <div style="display:flex;align-items:center;gap:4px;font-weight:600;font-size:14px;flex-wrap:wrap;">
                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;"><?= htmlspecialchars($info['title'] ?: $name) ?></span>
                        <?php if (!$isInstalled): ?>
                        <span class="mc-theme-tag mc-theme-tag--uninstalled">未安装</span>
                        <?php else: ?>
                            <?php if ($isPcActive): ?>
                            <span class="mc-theme-tag mc-theme-tag--pc">PC 使用中</span>
                            <?php endif; ?>
                            <?php if ($isMobActive): ?>
                            <span class="mc-theme-tag mc-theme-tag--mobile">手机使用中</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div style="color:#9ca3af;font-size:12px;margin-top:4px;line-height:1.5;height:36px;overflow:hidden;">
                        <?= htmlspecialchars($info['description'] ?? '') ?>
                    </div>
                    <div style="color:#9ca3af;font-size:11px;margin-top:6px;">
                        v<?= htmlspecialchars($info['version'] ?? '1.0.0') ?> · <?= htmlspecialchars($info['author'] ?? '') ?>
                    </div>

                    <div class="mc-theme-actions">
                        <?php if (!$isInstalled): ?>
                            <button class="mc-theme-btn mc-theme-btn--primary" data-action="install" data-name="<?= htmlspecialchars($name) ?>" type="button">
                                <i class="fa fa-download"></i> 安装
                            </button>
                        <?php else: ?>
                            <button class="mc-theme-btn <?= $isPcActive ? 'mc-theme-btn--active' : '' ?>" data-action="activate_pc" data-name="<?= htmlspecialchars($name) ?>" type="button">
                                <i class="fa fa-desktop"></i> <?= $isPcActive ? '取消 PC 使用' : 'PC 启用' ?>
                            </button>
                            <button class="mc-theme-btn <?= $isMobActive ? 'mc-theme-btn--active' : '' ?>" data-action="activate_mobile" data-name="<?= htmlspecialchars($name) ?>" type="button">
                                <i class="fa fa-mobile"></i> <?= $isMobActive ? '取消手机' : '手机启用' ?>
                            </button>
                            <?php if ($hasSetting): ?>
                            <button class="mc-theme-btn" data-action="settings" data-name="<?= htmlspecialchars($name) ?>" type="button">
                                <i class="fa fa-gear"></i> 配置
                            </button>
                            <?php endif; ?>
                            <?php if (!$isPcActive && !$isMobActive): ?>
                            <button class="mc-theme-btn mc-theme-btn--danger" data-action="uninstall" data-name="<?= htmlspecialchars($name) ?>" type="button">
                                <i class="fa fa-trash"></i> 卸载
                            </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
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
    var csrfToken = <?= json_encode($csrfToken) ?>;
    var lock = false;

    function doRequest(action, name) {
        if (lock) return;
        lock = true;
        var loadingIdx = layui.layer.load(2, { shade: [0.3, '#000'] });
        $.ajax({
            url: '/user/merchant/theme.php',
            type: 'POST',
            dataType: 'json',
            data: { csrf_token: csrfToken, _action: action, name: name }
        }).done(function (res) {
            layui.layer.close(loadingIdx);
            if (res && (res.code === 200 || res.code === 0)) {
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                layui.layer.msg(res.msg || '操作成功');
                if ($.pjax) {
                    $.pjax({ url: '/user/merchant/theme.php', container: '#merchantContent' });
                } else {
                    location.reload();
                }
            } else {
                layui.layer.msg((res && res.msg) || '操作失败');
            }
        }).fail(function () {
            layui.layer.close(loadingIdx);
            layui.layer.msg('网络异常');
        }).always(function () {
            lock = false;
        });
    }

    function openSettings(name) {
        layui.layer.open({
            type: 2,
            title: '模板设置',
            skin: 'admin-modal',
            area: [window.innerWidth >= 800 ? '720px' : '94%', window.innerHeight >= 640 ? '80%' : '88%'],
            maxmin: true,
            shadeClose: false,
            content: '/user/merchant/theme.php?_popup=1&name=' + encodeURIComponent(name)
        });
    }

    $(document).off('click.mcThemeBtn').on('click.mcThemeBtn', '.mc-theme-btn[data-action]', function () {
        var action = $(this).data('action');
        var name   = $(this).data('name');
        if (!action || !name) return;
        if (action === 'settings')   { openSettings(name); return; }
        if (action === 'uninstall') {
            layui.layer.confirm('确定卸载该模板吗？仅清除本店的记录，磁盘文件不受影响。', { icon: 3, title: '卸载确认' }, function (idx) {
                layui.layer.close(idx);
                doRequest('uninstall', name);
            });
            return;
        }
        doRequest(action, name);
    });
})();
</script>
