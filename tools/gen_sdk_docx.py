#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
UIC SDK 开发文档（docx）生成器
================================
将仓库内的权威 Markdown 源（README / sdk/README / docs/API / 重构说明）
按统一结构转换为一份 Word 开发文档 UIC_SDK_开发文档.docx。

用法：
    python3 tools/gen_sdk_docx.py
产物：仓库根目录下 UIC_SDK_开发文档.docx

仅覆盖本项目文档实际用到的 Markdown 子集：
标题 / 围栏代码块 / 表格 / 引用 / 有序·无序列表 / 分隔线 / 粗体·斜体·行内代码 / 链接。
"""
import os
import re
from docx import Document
from docx.shared import Pt, RGBColor, Inches
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# 文档档案（profile）：每份文档的标题、副标题、输出文件名与章节来源
# 章节来源为权威 Markdown 文件，顺序即文档章节顺序
PROFILES = {
    "sdk": {
        "out": "UIC_SDK_开发文档.docx",
        "title": "统一身份认证中心（UIC）",
        "subtitle": "多语言 SDK 与开放平台接入开发文档",
        "version": "v1.1.0",
        "date": "2026-10-15",
        "sources": [
            ("README.md", "项目总览"),
            ("sdk/README.md", "SDK 与发布打包"),
            ("docs/API.md", "开放平台 API 参考"),
            ("docs/SDK包重构说明.md", "SDK 包重构说明"),
        ],
    },
    "appmgmt": {
        "out": "应用管理模块接入方案.docx",
        "title": "UIC 应用管理模块接入方案",
        "subtitle": "应用（SP）创建 · SSO 接入 · 多语言 SDK 集成",
        "version": "v1.1.0",
        "date": "2026-10-15",
        "sources": [
            ("README.md", "项目总览"),
            ("sdk/README.md", "SDK 与发布打包"),
            ("docs/API.md", "开放平台 API 参考"),
        ],
    },
}

CODE_BG = "F2F2F2"

# ------------------------- 行内格式 -------------------------
INLINE_RE = re.compile(
    r"\*\*(.+?)\*\*"
    r"|\*(.+?)\*"
    r"|_(.+?)_"
    r"|`(.+?)`"
    r"|\[(.*?)\]\(([^)]+)\)"
)


def _set_run(run, *, bold=False, italic=False, code=False, size=None, color=None):
    run.bold = bold
    run.italic = italic
    if code:
        run.font.name = "Consolas"
        r = run._element
        rPr = r.get_or_add_rPr()
        rFonts = rPr.find(qn("w:rFonts"))
        if rFonts is None:
            rFonts = OxmlElement("w:rFonts")
            rPr.append(rFonts)
        rFonts.set(qn("w:ascii"), "Consolas")
        rFonts.set(qn("w:hAnsi"), "Consolas")
    if size is not None:
        run.font.size = Pt(size)
    if color is not None:
        run.font.color.rgb = color


def add_inline(paragraph, text):
    """把一段可能含行内标记的纯文本拆成 run 加入段落。"""
    pos = 0
    for m in INLINE_RE.finditer(text):
        if m.start() > pos:
            paragraph.add_run(text[pos:m.start()])
        bold, ital, ital2, code, link_t, link_u = m.groups()
        if bold is not None:
            _set_run(paragraph.add_run(bold), bold=True)
        elif ital is not None:
            _set_run(paragraph.add_run(ital), italic=True)
        elif ital2 is not None:
            _set_run(paragraph.add_run(ital2), italic=True)
        elif code is not None:
            _set_run(paragraph.add_run(code), code=True, size=9)
        elif link_t is not None:
            _add_hyperlink(paragraph, link_t, link_u)
        pos = m.end()
    if pos < len(text):
        paragraph.add_run(text[pos:])


def _add_hyperlink(paragraph, text, url):
    # 仅允许 http/https/相对/锚点，防 javascript: 等
    if not re.match(r"^(https?://|/|#|\./|\.\./)", url):
        url = "#"
    part = paragraph.part
    r_id = part.relate_to(
        url,
        "http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink",
        is_external=True,
    )
    hyper = OxmlElement("w:hyperlink")
    hyper.set(qn("r:id"), r_id)
    new_run = OxmlElement("w:r")
    rPr = OxmlElement("w:rPr")
    color = OxmlElement("w:color")
    color.set(qn("w:val"), "0563C1")
    rPr.append(color)
    u = OxmlElement("w:u")
    u.set(qn("w:val"), "single")
    rPr.append(u)
    new_run.append(rPr)
    t = OxmlElement("w:t")
    t.text = text
    new_run.append(t)
    hyper.append(new_run)
    paragraph._p.append(hyper)


# ------------------------- 块级解析 -------------------------
def _shade_paragraph(paragraph, fill):
    pPr = paragraph._p.get_or_add_pPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), fill)
    pPr.append(shd)


def _add_code_block(doc, lines):
    text = "\n".join(lines)
    p = doc.add_paragraph()
    _shade_paragraph(p, CODE_BG)
    p.paragraph_format.left_indent = Inches(0.1)
    p.paragraph_format.space_before = Pt(4)
    p.paragraph_format.space_after = Pt(4)
    first = True
    for ln in text.split("\n"):
        if not first:
            p.add_run().add_break()
        _set_run(p.add_run(ln), code=True, size=9)
        first = False


def _split_row(row):
    cells = row.split("|")
    if cells and cells[0].strip() == "":
        cells = cells[1:]
    if cells and cells[-1].strip() == "":
        cells = cells[:-1]
    return [c.strip() for c in cells]


def _is_sep(cells):
    return all(re.fullmatch(r":?-{2,}:?", c) for c in cells) and any("-" in c for c in cells)


def _add_table(doc, rows):
    header = _split_row(rows[0])
    body = []
    for r in rows[2:]:
        cells = _split_row(r)
        if cells:
            body.append(cells)
    ncol = max(len(header), *(len(b) for b in body))
    table = doc.add_table(rows=1, cols=ncol)
    table.style = "Light Grid Accent 1"
    table.alignment = WD_TABLE_ALIGNMENT.LEFT
    hdr = table.rows[0].cells
    for i in range(ncol):
        val = header[i] if i < len(header) else ""
        add_inline(hdr[i].paragraphs[0], val)
        for run in hdr[i].paragraphs[0].runs:
            run.bold = True
    for b in body:
        cells = table.add_row().cells
        for i in range(ncol):
            val = b[i] if i < len(b) else ""
            add_inline(cells[i].paragraphs[0], val)
    doc.add_paragraph()


def convert(doc, md):
    lines = md.replace("\r\n", "\n").split("\n")
    i, n = 0, len(lines)
    while i < n:
        line = lines[i]
        stripped = line.strip()

        # 代码块
        m = re.match(r"^```(\w*)\s*$", line)
        if m:
            buf = []
            i += 1
            while i < n and not re.match(r"^```\s*$", lines[i]):
                buf.append(lines[i])
                i += 1
            i += 1  # 跳过结束围栏
            _add_code_block(doc, buf)
            continue

        if stripped == "":
            i += 1
            continue

        # 分隔线
        if re.match(r"^(---|\*\*\*|___)\s*$", stripped):
            p = doc.add_paragraph()
            pPr = p._p.get_or_add_pPr()
            pbdr = OxmlElement("w:pBdr")
            bottom = OxmlElement("w:bottom")
            bottom.set(qn("w:val"), "single")
            bottom.set(qn("w:sz"), "6")
            bottom.set(qn("w:space"), "1")
            bottom.set(qn("w:color"), "999999")
            pbdr.append(bottom)
            pPr.append(pbdr)
            p.paragraph_format.space_before = Pt(2)
            p.paragraph_format.space_after = Pt(2)
            i += 1
            continue

        # 标题
        m = re.match(r"^(#{1,6})\s+(.*)$", stripped)
        if m:
            level = len(m.group(1))
            h = doc.add_heading(level=min(level, 9))
            add_inline(h, m.group(2).strip())
            i += 1
            continue

        # 引用
        if re.match(r"^>\s?", stripped):
            buf = []
            while i < n and re.match(r"^>\s?", lines[i].strip()):
                buf.append(re.sub(r"^>\s?", "", lines[i].strip()))
                i += 1
            p = doc.add_paragraph()
            p.paragraph_format.left_indent = Inches(0.3)
            _set_run(p.add_run(" ".join(buf)), italic=True, color=RGBColor(0x55, 0x55, 0x55))
            continue

        # 表格（表头 + 分隔行构成）
        if "|" in stripped and i + 1 < n and "|" in lines[i + 1] and _is_sep(_split_row(lines[i + 1])):
            trows = [lines[i]]
            i += 1
            while i < n and lines[i].strip() and "|" in lines[i]:
                trows.append(lines[i])
                i += 1
            _add_table(doc, trows)
            continue

        # 无序列表
        m = re.match(r"^(\s*)[-*+]\s+(.*)$", line)
        if m:
            buf = []
            while i < n and re.match(r"^(\s*)[-*+]\s+(.*)$", lines[i]):
                buf.append(re.match(r"^(\s*)[-*+]\s+(.*)$", lines[i]).group(2))
                i += 1
            for item in buf:
                p = doc.add_paragraph(style="List Bullet")
                add_inline(p, item)
            continue

        # 有序列表
        m = re.match(r"^(\s*)\d+\.\s+(.*)$", line)
        if m:
            buf = []
            while i < n and re.match(r"^(\s*)\d+\.\s+(.*)$", lines[i]):
                buf.append(re.match(r"^(\s*)\d+\.\s+(.*)$", lines[i]).group(2))
                i += 1
            for item in buf:
                p = doc.add_paragraph(style="List Number")
                add_inline(p, item)
            continue

        # 段落（合并连续普通行）
        buf = [stripped]
        i += 1
        while i < n and lines[i].strip() and not re.match(
            r"^(#{1,6}\s|>|```|(\s*[-*+]\s)|(\s*\d+\.\s)|(---|\*\*\*|___)\s*$)",
            lines[i].strip(),
        ):
            buf.append(lines[i].strip())
            i += 1
        p = doc.add_paragraph()
        add_inline(p, " ".join(buf))
        p.paragraph_format.space_after = Pt(6)


# ------------------------- 文档骨架 -------------------------
def set_cjk_font(doc, font="宋体"):
    style = doc.styles["Normal"]
    style.font.name = "Calibri"
    style.font.size = Pt(10.5)
    rpr = style.element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = OxmlElement("w:rFonts")
        rpr.append(rfonts)
    rfonts.set(qn("w:eastAsia"), font)
    rfonts.set(qn("w:ascii"), "Calibri")
    rfonts.set(qn("w:hAnsi"), "Calibri")


def add_title_page(doc, title, subtitle, version, date):
    t = doc.add_paragraph()
    t.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = t.add_run(title)
    r.bold = True
    r.font.size = Pt(26)
    r.font.color.rgb = RGBColor(0x1F, 0x3A, 0x93)

    s = doc.add_paragraph()
    s.alignment = WD_ALIGN_PARAGRAPH.CENTER
    rs = s.add_run(subtitle)
    rs.font.size = Pt(16)
    rs.font.color.rgb = RGBColor(0x33, 0x33, 0x33)

    doc.add_paragraph()
    meta = doc.add_paragraph()
    meta.alignment = WD_ALIGN_PARAGRAPH.CENTER
    meta.add_run("版本 %s　|　生成日期 %s\n" % (version, date)).font.size = Pt(11)
    meta.add_run("适用：UIC 开放平台 %s（统一身份认证中心）" % version).font.size = Pt(10)

    doc.add_paragraph()
    note = doc.add_paragraph()
    note.alignment = WD_ALIGN_PARAGRAPH.CENTER
    _set_run(
        note.add_run(
            "本文档由仓库权威 Markdown 源（README / sdk/README / docs/API 等）"
            "自动汇编，结构与「统一发布包」保持一致。在线版详见 docs.php 开放文档门户。"
        ),
        italic=True,
        color=RGBColor(0x66, 0x66, 0x66),
    )
    note.runs[0].font.size = Pt(9.5)

    doc.add_page_break()


def build_profile(key):
    prof = PROFILES[key]
    doc = Document()
    set_cjk_font(doc)
    add_title_page(doc, prof["title"], prof["subtitle"], prof["version"], prof["date"])

    for rel, _chapter_label in prof["sources"]:
        path = os.path.join(ROOT, rel)
        if not os.path.isfile(path):
            print("  [跳过] 不存在: %s" % rel)
            continue
        with open(path, "r", encoding="utf-8") as fh:
            md = fh.read()
        convert(doc, md)
        doc.add_page_break()
        print("  ✓ 已并入章节源: %s" % rel)

    out = os.path.join(ROOT, prof["out"])
    doc.save(out)
    print("✔ [%s] 生成完成: %s (%.1f KB)" % (key, out, os.path.getsize(out) / 1024))


def main():
    import sys
    keys = sys.argv[1:] or list(PROFILES.keys())
    # 校验输入
    for k in keys:
        if k not in PROFILES:
            print("未知 profile: %s（可选: %s）" % (k, ", ".join(PROFILES.keys())))
            return
    for k in keys:
        print("=== 构建文档 profile: %s ===" % k)
        build_profile(k)


if __name__ == "__main__":
    main()
