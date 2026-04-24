<?php
/**
Plugin Name: 弹窗装饰图
Version: 1.0.0
Plugin URL:
Description: 在后台弹窗底部展示一张装饰图片。
Author: 驳手
Author URL: https://em.ihehe.me/
Category: 系统扩展
*/

defined('EM_ROOT') || exit('access denied!');

function popup_gif_render() {
    echo '<div class="popup-gif-wrap"><img src="/content/plugin/popup_gif/popup.gif" alt=""></div>';
    echo '<style>
.popup-gif-wrap {
    position: fixed;
    left: 12px;
    bottom: 0;
    z-index: 9999;
    pointer-events: none;
}
.popup-gif-wrap img {
    display: block;
    max-height: 120px;
}
</style>';
}

addAction('admin_popup_footer', 'popup_gif_render');
