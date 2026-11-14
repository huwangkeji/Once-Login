#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
UIC 开放平台 / SDK 压缩包统一构建脚本
=====================================
将仓库源码按统一内部目录结构打包为可分发 zip，保证：
  - 每个 zip 内部顶层为一个与包名一致的「包裹目录」；
  - 每个包内含统一的  docs/ （文档集）、examples/ （可运行示例）；
  - 附带 VERSION / CHANGELOG.md / README.md；
  - 移除冗余与过时文件：data/、node_modules/、vendor/、.git、*.lock、调试日志等；
  - 开放平台合集不再内嵌嵌套 zip，改为直接展开子包目录。

用法：
    python3 tools/build_packages.py
产物：仓库根目录下 5 个 zip（与现有交付物同名，原地覆盖）。
"""
import os
import shutil
import zipfile
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # 仓库根目录

# 需要剔除的目录名（任何层级出现即跳过）
EXCLUDE_DIRS = {"node_modules", "vendor", ".git", "__pycache__", ".idea", "data"}
# 需要剔除的文件（后缀/文件名）
EXCLUDE_FILES = {".DS_Store", "Thumbs.db", "composer.lock", "package-lock.json"}
EXCLUDE_EXT = {".pyc", ".log", ".tmp", ".bak"}


def _prune(root, dirs):
    dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS]


def _skip_file(fn):
    if fn in EXCLUDE_FILES:
        return True
    ext = os.path.splitext(fn)[1].lower()
    if ext in EXCLUDE_EXT:
        return True
    return False


def add_tree(files, src_dir, arc_prefix):
    """把 src_dir 下所有文件加入 files（arcname -> 真实路径），跳过排除项。"""
    for cur, dirs, fnames in os.walk(src_dir):
        _prune(cur, dirs)
        for fn in fnames:
            if _skip_file(fn):
                continue
            full = os.path.join(cur, fn)
            rel = os.path.relpath(full, src_dir)
            arc = os.path.join(arc_prefix, rel) if arc_prefix else rel
            files[arc.replace(os.sep, "/")] = full


def add_file(files, real_path, arc_name):
    files[arc_name.replace(os.sep, "/")] = real_path


def stage_openapi(wrapper):
    """开放平台完整源码包内容（app/public/config/sdk/docs.php）。"""
    f = {}
    for d in ("app", "public", "config", "sdk"):
        add_tree(f, os.path.join(ROOT, d), os.path.join(wrapper, d))
    add_file(f, os.path.join(ROOT, "docs.php"), os.path.join(wrapper, "docs.php"))
    return f


def stage_sdk(wrapper):
    """多语言 SDK 包内容（整棵 sdk/ 树，剔除 vendor 等）。"""
    f = {}
    add_tree(f, os.path.join(ROOT, "sdk"), wrapper)
    return f


def add_common_under(files, wrapper, version, readme_text):
    """向指定包裹目录注入统一附属物：docs/、examples/、VERSION、CHANGELOG.md、README.md。"""
    # docs 文档集
    docs_src = os.path.join(ROOT, "docs")
    if os.path.isdir(docs_src):
        for fn in sorted(os.listdir(docs_src)):
            if fn.lower().endswith(".md"):
                add_file(files, os.path.join(docs_src, fn),
                         os.path.join(wrapper, "docs", fn))
    # examples：PHP 可运行示例（不含 legacy 历史残留、不含 _helper.php 本身由示例 require）
    ex_src = os.path.join(ROOT, "sdk", "examples")
    if os.path.isdir(ex_src):
        for fn in sorted(os.listdir(ex_src)):
            if not fn.endswith(".php"):
                continue
            if fn == "_helper.php" or fn.startswith("legacy"):
                continue
            add_file(files, os.path.join(ex_src, fn),
                     os.path.join(wrapper, "examples", fn))
    # VERSION
    vf = tempfile.NamedTemporaryFile("w", suffix=".ver", delete=False, encoding="utf-8")
    vf.write(version + "\n")
    vf.close()
    add_file(files, vf.name, os.path.join(wrapper, "VERSION"))
    # CHANGELOG
    cl = os.path.join(ROOT, "CHANGELOG.md")
    if os.path.isfile(cl):
        add_file(files, cl, os.path.join(wrapper, "CHANGELOG.md"))
    # README（按包生成）
    rf = tempfile.NamedTemporaryFile("w", suffix=".md", delete=False, encoding="utf-8")
    rf.write(readme_text)
    rf.close()
    add_file(files, rf.name, os.path.join(wrapper, "README.md"))


def build_zip(zip_name, files):
    dest = os.path.join(ROOT, zip_name)
    if os.path.exists(dest):
        os.remove(dest)
    with zipfile.ZipFile(dest, "w", zipfile.ZIP_DEFLATED) as zf:
        for arc, real in sorted(files.items()):
            zf.write(real, arc)
    return dest


# ----------------------- 各包定义 -----------------------
OPENAPI_README = """# UIC 开放平台 · 完整源码包（UIC_OpenAPI）

本包包含统一身份认证中心（UIC）的完整可部署源码：

- `app/`        应用核心（Controller / Service / Model / View）
- `public/`     Web 根目录（唯一对外暴露目录，含 api/uc.php 等入口）
- `config/`     全局配置样例
- `sdk/`        多语言客户端 SDK 源码
- `docs.php`    在线开发者文档入口

文档见 `docs/`，接入示例见 `examples/`（与 `sdk/examples/` 一致）。
部署：首次访问 `public/install.php` 完成初始化（生成管理员、写入 data/install.lock）。
"""

SDK_README = """# UIC 多语言客户端 SDK 包（UIC_SDK）

提供 PHP / Python / JavaScript / Go / Java / C 六种语言的客户端 SDK，
统一签名鉴权协议（详见 `src/`、`contract.json`）。

- `src/`        PHP PSR-4 源码（Client / AuthClient / UserClient / CaptchaClient）
- `php/`        PHP 单文件零依赖版（UicSdk.php）
- `python/` `js/` `go/` `java/` `c/`   各语言实现
- `examples/`   可运行示例（quickstart / basic_usage / sso_callback / captcha_flow /
                login_code / passkey_flow / admin_products / sso_full_flow）
- `tests/`      单元测试
- `docs/`       接口文档集

安装：Composer `require uic/sdk`，或复制 `sdk/` 目录引入 `vendor/autoload.php`。
"""

APPMGMT_README = """# 应用管理模块接入 SDK（应用管理模块SDK源码）

面向「应用管理 / SSO 接入」场景的轻量 SDK 合集，等价于 UIC_SDK 的多语言客户端，
聚焦应用（SP）创建、AppID/Secret 管理、SSO 授权跳转与 Token 交换。

- `src/` `php/` `python/` `js/` `go/` `java/` `c/`   多语言 SDK
- `examples/`   接入示例（快速开始 / 完整用法 / SSO 回调）
- `docs/`       接口文档集
"""

BUNDLE_README = """# UIC 开放平台合集（开放平台）

本合集整合以下交付物，便于一次性分发：

- `UIC_OpenAPI_v1.1.0/`   开放平台完整源码包（app / public / config / sdk）
- `UIC_SDK_v1.1.0/`       多语言客户端 SDK 包
- `office_docs/`           Office 格式开发文档（docx）
- `docs/`                  Markdown 接口文档集

> 说明：本合集不再内嵌嵌套 zip，子包均以目录形式直接展开，便于检索与二次打包。
"""


def main():
    specs = []

    # 1) OpenAPI 完整源码包
    f = stage_openapi("UIC_OpenAPI_v1.1.0")
    add_common_under(f, "UIC_OpenAPI_v1.1.0", "1.1.0", OPENAPI_README)
    specs.append(("UIC_OpenAPI_v1.1.0.zip", f))

    # 2) SDK v1.1.0
    f = stage_sdk("UIC_SDK_v1.1.0")
    add_common_under(f, "UIC_SDK_v1.1.0", "1.1.0", SDK_README)
    specs.append(("UIC_SDK_v1.1.0.zip", f))

    # 3) SDK v1.0.0（修正历史命名 bug：原包内包裹目录误用 v1.1.0）
    f = stage_sdk("UIC_SDK_v1.0.0")
    add_common_under(f, "UIC_SDK_v1.0.0", "1.0.0",
               SDK_README + "\n> 注：本包为旧版本号包，内容与 v1.1.0 一致，建议优先使用 v1.1.0。\n")
    specs.append(("UIC_SDK_v1.0.0.zip", f))

    # 4) 应用管理模块 SDK 源码（保留中文包名以对应既有交付物）
    f = stage_sdk("应用管理模块SDK源码")
    add_common_under(f, "应用管理模块SDK源码", "1.1.0", APPMGMT_README)
    specs.append(("应用管理模块SDK源码.zip", f))

    # 5) 开放平台合集（不再嵌套 zip）
    f = {}
    # 复用 OpenAPI 与 SDK 的装配内容（改包裹目录名）
    oa = stage_openapi("UIC_OpenAPI_v1.1.0")
    for arc, real in oa.items():
        f[arc] = real
    sdk = stage_sdk("UIC_SDK_v1.1.0")
    for arc, real in sdk.items():
        f[arc] = real
    # 让内嵌子包自包含（与独立 zip 内容一致）
    add_common_under(f, "UIC_OpenAPI_v1.1.0", "1.1.0", OPENAPI_README)
    add_common_under(f, "UIC_SDK_v1.1.0", "1.1.0", SDK_README)
    # Office 文档
    for docx in ("UIC_SDK_开发文档.docx", "应用管理模块接入方案.docx"):
        p = os.path.join(ROOT, docx)
        if os.path.isfile(p):
            add_file(f, p, os.path.join("开放平台V1.0.1", "office_docs", docx))
    # 合集根目录：文档集 + VERSION + CHANGELOG + README
    docs_src = os.path.join(ROOT, "docs")
    if os.path.isdir(docs_src):
        for fn in sorted(os.listdir(docs_src)):
            if fn.lower().endswith(".md"):
                add_file(f, os.path.join(docs_src, fn),
                         os.path.join("开放平台V1.0.1", "docs", fn))
    vf = tempfile.NamedTemporaryFile("w", suffix=".ver", delete=False, encoding="utf-8")
    vf.write("1.0.1\n"); vf.close()
    add_file(f, vf.name, os.path.join("开放平台V1.0.1", "VERSION"))
    cl = os.path.join(ROOT, "CHANGELOG.md")
    if os.path.isfile(cl):
        add_file(f, cl, os.path.join("开放平台V1.0.1", "CHANGELOG.md"))
    rf = tempfile.NamedTemporaryFile("w", suffix=".md", delete=False, encoding="utf-8")
    rf.write(BUNDLE_README); rf.close()
    add_file(f, rf.name, os.path.join("开放平台V1.0.1", "README.md"))
    specs.append(("开放平台V1.0.1.zip", f))

    # 执行构建
    manifest = []
    for zip_name, files in specs:
        dest = build_zip(zip_name, files)
        size = os.path.getsize(dest) // 1024
        tops = sorted(set(k.split("/")[0] for k in files))
        print("✓ %-28s %6d KB | %4d files | top: %s" %
              (zip_name, size, len(files), ", ".join(tops)))
        manifest.append((zip_name, size, len(files), tops))

    print("\n全部打包完成。产物位于仓库根目录。")


if __name__ == "__main__":
    main()
