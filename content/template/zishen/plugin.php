<?php

/**
 * 子神模板 · 系统钩子（与 default 逻辑一致，函数名独立避免冲突）
 */

defined('EM_ROOT') || exit('access denied!');

function zishen_add_download_style($logData, &$result)
{
    $pattern = '/<a\s+([^>]*href="[^"]*(?:\?resource_alias=[^&"]*(?:&resource_filename=[^"]*)?|\.(?:zip|rar|7z|gz|bz2|tar|exe|dmg|pkg|deb|rpm))(?:[^"]*)?"[^>]*)>/i';
    $replacement = '<a $1 class="em-download-btn"><span class="iconfont icon-clouddownload"></span> ';
    $result['log_content'] = preg_replace($pattern, $replacement, $logData['log_content']);
}

addAction('article_content_echo', 'zishen_add_download_style');

function zishen_render_download_btn()
{
    echo <<<EOT
<style>
.em-download-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #ff6eb4, #b794f6);
    color: #fff !important;
    border: none;
    padding: 10px 22px;
    border-radius: 999px;
    cursor: pointer;
    font-size: 15px;
    text-decoration: none !important;
    box-shadow: 0 4px 20px rgba(255, 110, 180, 0.35);
}
</style>

EOT;
}

addAction('index_head', 'zishen_render_download_btn');
