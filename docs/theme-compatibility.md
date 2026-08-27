# FriendLinks 主题兼容性报告

> 测试日期：2026-08-27  
> FriendLinks：v1.0.1（`454a64e`）  
> 结果：30 / 30 通过

## 测试环境

| 项目 | 版本 |
| --- | --- |
| Typecho | 1.2.1 |
| PHP | 8.2.33 |
| 数据库 | SQLite |
| 浏览器 | Google Chrome 143.0.7499.110 |
| 浏览器尺寸 | 1280 x 900、390 x 844 |
| 运行方式 | Podman Linux 容器运行 Typecho，Playwright 驱动真实浏览器 |

插件自身的领域测试和 Typecho 集成测试还分别在 PHP 7.4 与 PHP 8.2 下执行。主题运行矩阵统一使用 PHP 8.2，避免把 PHP 版本差异混入主题渲染结果。

## 验证方法

每个主题都在 Typecho 中实际启用，并请求绑定 FriendLinks 的普通独立页面。测试数据固定为一个分类和三条公开友链。

每个主题必须同时满足：

1. 独立页面响应 HTTP 200。
2. 页面最终只存在一个 `<friend-links-widget>`。
3. 宿主成功创建 Shadow Root。
4. Shadow Root 内存在三条 `.flm-item`。
5. 卡片模板在桌面端计算为三列 Grid。
6. 桌面端和移动端组件高度均大于 0。
7. 移动端不存在横向内容溢出。
8. 公共 `frontend.css` 与当前模板 `style.css` 均加载成功。
9. 普通正文注入或 footer fallback 至少有一条可用渲染路径。

测试会阻止主题访问外部 CDN，避免第三方网络状态影响结果。同源主题资源正常加载；主题自身因外部依赖缺失产生的 JavaScript 错误不计为 FriendLinks 失败，但 FriendLinks 的宿主、Shadow Root、内容、布局和资源仍必须全部通过上述断言。

此外，footer fallback 单独覆盖了声明式 Shadow DOM 场景，确保移动原始 `template.content`，不会通过 `cloneNode(true)` 丢失 Shadow Root。

## 主题矩阵

| # | 主题 | 固定版本 | 结果 |
| ---: | --- | --- | --- |
| 1 | Typecho 默认主题 | Typecho 1.2.1 内置版本 | 通过 |
| 2 | [AlanDecode/Typecho-Theme-VOID](https://github.com/AlanDecode/Typecho-Theme-VOID) | [`a70bbc9`](https://github.com/AlanDecode/Typecho-Theme-VOID/commit/a70bbc9a173b7f80ebf6dcd386e4286a8a7fbcb2) | 通过 |
| 3 | [bakaomg/castle-Typecho-Theme](https://github.com/bakaomg/castle-Typecho-Theme) | [`3a63106`](https://github.com/bakaomg/castle-Typecho-Theme/commit/3a63106572aafcefa8f08f9fbe388656ee9700b4) | 通过 |
| 4 | [bhaoo/Cuckoo](https://github.com/bhaoo/Cuckoo) | [`d84b8df`](https://github.com/bhaoo/Cuckoo/commit/d84b8dfe22b3ff76742c5b047ccff846aa9bf8ab) | 通过 |
| 5 | [BigCoke233/matcha](https://github.com/BigCoke233/matcha) | [`95857a0`](https://github.com/BigCoke233/matcha/commit/95857a0e4f36808397c87622af65f7719abfdf1e) | 通过 |
| 6 | [BigCoke233/miracles](https://github.com/BigCoke233/miracles) | [`c4450ad`](https://github.com/BigCoke233/miracles/commit/c4450ad83e57ee3165cbb73fcf05bb4a16ab1502) | 通过 |
| 7 | [chakhsu/lpisme](https://github.com/chakhsu/lpisme) | [`b095ae4`](https://github.com/chakhsu/lpisme/commit/b095ae4318715cf4dce5f08d918bedef77a6f7ad) | 通过 |
| 8 | [changbin1997/Facile](https://github.com/changbin1997/Facile) | [`27e431a`](https://github.com/changbin1997/Facile/commit/27e431aff94012645511a6b7c0484eae584e74c2) | 通过 |
| 9 | [changbin1997/MWordStar](https://github.com/changbin1997/MWordStar) | [`9723220`](https://github.com/changbin1997/MWordStar/commit/972322037e5dc071cda386f9d0a3e9682d4f0ff4) | 通过 |
| 10 | [dingzd1995/typecho-theme-waxy](https://github.com/dingzd1995/typecho-theme-waxy) | [`608ec5d`](https://github.com/dingzd1995/typecho-theme-waxy/commit/608ec5d40b5785ffe7b3633c1df17629d771cbe7) | 通过 |
| 11 | [Dreamer-Paul/Single](https://github.com/Dreamer-Paul/Single) | [`9665079`](https://github.com/Dreamer-Paul/Single/commit/9665079d39b21640a0121786769ce2c3de303155) | 通过 |
| 12 | [liaocp666/Jasmine](https://github.com/liaocp666/Jasmine) | [`d8744a4`](https://github.com/liaocp666/Jasmine/commit/d8744a44a53af88fc6be2daf84a30e595b605870) | 通过 |
| 13 | [MoXiaoXi233/PureSuck-theme](https://github.com/MoXiaoXi233/PureSuck-theme) | [`e24f457`](https://github.com/MoXiaoXi233/PureSuck-theme/commit/e24f4576817f0b384fe0275d74dcbb0931ef6993) | 通过 |
| 14 | [Seevil/cactus](https://github.com/Seevil/cactus) | [`7bab2cc`](https://github.com/Seevil/cactus/commit/7bab2cc6ca8be76b4dec3e7a80d9da76576cbd8c) | 通过 |
| 15 | [Seevil/fantasy](https://github.com/Seevil/fantasy) | [`730c1a6`](https://github.com/Seevil/fantasy/commit/730c1a6d95f6f4115c733f95523ac797aea5092e) | 通过 |
| 16 | [shiyiya/typecho-theme-sagiri](https://github.com/shiyiya/typecho-theme-sagiri) | [`e3cbd97`](https://github.com/shiyiya/typecho-theme-sagiri/commit/e3cbd978b7038190274ba23e343e0bc83372f9e2) | 通过 |
| 17 | [spiritree/typecho-theme-amaze](https://github.com/spiritree/typecho-theme-amaze) | [`365c6d0`](https://github.com/spiritree/typecho-theme-amaze/commit/365c6d0e54bc3d40847d5e299ad49198851e74c1) | 通过 |
| 18 | [txperl/Story-for-Typecho](https://github.com/txperl/Story-for-Typecho) | [`d51f5f7`](https://github.com/txperl/Story-for-Typecho/commit/d51f5f731a82ccd1a1a4c176bbf57004cfb91f2b) | 通过 |
| 19 | [wehaox/Typecho-Butterfly](https://github.com/wehaox/Typecho-Butterfly) | [`bb5fb59`](https://github.com/wehaox/Typecho-Butterfly/commit/bb5fb5922a4b2d74a7829005a2a507027fe08ffa) | 通过 |
| 20 | [youranreus/G](https://github.com/youranreus/G) | [`8f26a6e`](https://github.com/youranreus/G/commit/8f26a6e7962f11b085b2c1ad4f9b4a518a3e85d4) | 通过 |
| 21 | [YuYisir/Initial-M](https://github.com/YuYisir/Initial-M) | [`7a9be0e`](https://github.com/YuYisir/Initial-M/commit/7a9be0e21cca97d55dea6153e9e2f4e14423dee8) | 通过 |
| 22 | [awinds/xaink](https://github.com/awinds/xaink) | [`bf69372`](https://github.com/awinds/xaink/commit/bf69372eb280db7aeb31e401a17f985900a0b184) | 通过 |
| 23 | [cairbin/tiphia-for-typecho](https://github.com/cairbin/tiphia-for-typecho) | [`0fff023`](https://github.com/cairbin/tiphia-for-typecho/commit/0fff023c7e0e79be566542af0702065882362132) | 通过 |
| 24 | [FeiFan86/SeeLTheme](https://github.com/FeiFan86/SeeLTheme) | [`069147f`](https://github.com/FeiFan86/SeeLTheme/commit/069147f6640790f9ee519a4110558cf11267e032) | 通过 |
| 25 | [hoytzhang/typecho-theme-final](https://github.com/hoytzhang/typecho-theme-final) | [`3128049`](https://github.com/hoytzhang/typecho-theme-final/commit/3128049874c501fb5b245ec104d3f4dff9b8d61f) | 通过 |
| 26 | [jkjoy/typecho-theme-nebula](https://github.com/jkjoy/typecho-theme-nebula) | [`991d2f2`](https://github.com/jkjoy/typecho-theme-nebula/commit/991d2f2eaa6f2b481183c9adf92cd631aaffced8) | 通过 |
| 27 | [praming/Typecho-Theme-Pui](https://github.com/praming/Typecho-Theme-Pui) | [`4f6ed23`](https://github.com/praming/Typecho-Theme-Pui/commit/4f6ed2379f480ecdd153f71849eb6623d92e819d) | 通过 |
| 28 | [smcloudcat/shufeicat-typecho](https://github.com/smcloudcat/shufeicat-typecho) | [`36bd8af`](https://github.com/smcloudcat/shufeicat-typecho/commit/36bd8af1ed81659974418cbbd75c59823fc5a6b7) | 通过 |
| 29 | [umehina/kibou_lite](https://github.com/umehina/kibou_lite) | [`c993f65`](https://github.com/umehina/kibou_lite/commit/c993f65bbc0998849175804b711554e287b71477) | 通过 |
| 30 | [ZShijun/WaterDrop](https://github.com/ZShijun/WaterDrop) | [`d779f70`](https://github.com/ZShijun/WaterDrop/commit/d779f70cca23b92b841c9af6691af20c63339da2) | 通过 |

## 结论和边界

30 个主题覆盖了默认主题、传统 PHP 模板、组件化模板、Bootstrap、MDUI、PJAX/Swup、深色模式和多种正文 CSS 规则。该矩阵作为发布前的主题兼容性门槛，比单纯加载大量 CSS 更能验证真实渲染链路。

结果仅对应表中固定提交。主题升级、浏览器行为变化或主题绕过 Typecho 正文与 footer 钩子后，应重新执行兼容性验证。测试通过不代表 FriendLinks 对任意未来主题作无条件兼容承诺。
