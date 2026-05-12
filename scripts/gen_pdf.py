#!/usr/bin/env python3
"""
gen_pdf.py — สร้างรายงาน KPI ในรูปแบบ PDF ด้วย reportlab
Usage: python3 gen_pdf.py <input.json> <output.pdf>
"""
import sys, json
from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.units import cm, mm
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_RIGHT
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
    HRFlowable, KeepTogether, PageBreak
)
from reportlab.graphics.shapes import Drawing, Rect, Line, String, Group
from reportlab.graphics.charts.barcharts import VerticalBarChart
from reportlab.graphics.charts.lineplots import LinePlot
from reportlab.graphics import renderPDF
from reportlab.pdfgen import canvas as rl_canvas
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
import math, os

# ── Colours ──────────────────────────────────────────────────
TEAL   = colors.HexColor("#0F766E")
TEAL2  = colors.HexColor("#0D9488")
DARK   = colors.HexColor("#065F46")
PASS_C = colors.HexColor("#15803D")
FAIL_C = colors.HexColor("#B91C1C")
PASS_B = colors.HexColor("#DCFCE7")
FAIL_B = colors.HexColor("#FEE2E2")
BLUE   = colors.HexColor("#2563EB")
GRAY   = colors.HexColor("#64748B")
LIGHT  = colors.HexColor("#F1F5F9")
WHITE  = colors.white
BLACK  = colors.HexColor("#1E293B")
ALT    = colors.HexColor("#F8FAFC")
STAT_B = colors.HexColor("#F0FDF4")
HEADER_ROW = colors.HexColor("#0F766E")
AMBER  = colors.HexColor("#D97706")

PAGE_W, PAGE_H = A4
MARGIN = 1.8 * cm

def try_register_thai_font():
    """ลอง register ฟอนต์ภาษาไทยถ้ามี"""
    font_paths = [
        "/usr/share/fonts/truetype/thai-tlwg/TlwgMono.ttf",
        "/usr/share/fonts/truetype/tlwg/TlwgMono.ttf",
        "/usr/share/fonts/truetype/noto/NotoSansThai-Regular.ttf",
        "/usr/share/fonts/truetype/noto/NotoSansThai.ttf",
        "/System/Library/Fonts/Supplemental/Tahoma.ttf",  # macOS
    ]
    for path in font_paths:
        if os.path.exists(path):
            try:
                pdfmetrics.registerFont(TTFont("ThaiFont", path))
                return "ThaiFont"
            except Exception:
                pass
    return "Helvetica"

def main():
    if len(sys.argv) < 3:
        print("Usage: gen_pdf.py <input.json> <output.pdf>")
        sys.exit(1)

    with open(sys.argv[1], encoding='utf-8') as f:
        d = json.load(f)

    outpath  = sys.argv[2]
    rows     = d['rows']
    total    = d['total']
    kpi_name = d['kpi_name']
    kpi_code = d['kpi_code']
    target   = d['target']
    operator = d['operator']

    font_name = try_register_thai_font()
    # Use Helvetica as fallback (safe for PDF)
    BODY_FONT  = font_name
    BOLD_FONT  = font_name  # reportlab: bold via Font spec

    # ── Page setup with header/footer ────────────────────────
    def on_page(canvas, doc):
        canvas.saveState()
        # Header bar
        canvas.setFillColor(TEAL)
        canvas.rect(0, PAGE_H - 1.2*cm, PAGE_W, 1.2*cm, fill=1, stroke=0)
        canvas.setFillColor(WHITE)
        canvas.setFont("Helvetica-Bold", 10)
        canvas.drawString(MARGIN, PAGE_H - 0.85*cm,
                          f"KPI {kpi_code} Report")
        canvas.drawRightString(PAGE_W - MARGIN, PAGE_H - 0.85*cm,
                               d["date"])
        # Footer
        canvas.setFillColor(GRAY)
        canvas.setFont("Helvetica", 8)
        canvas.drawCentredString(PAGE_W/2, 0.6*cm,
                                 f"Page {doc.page}  |  PHO KPI Dashboard")
        canvas.restoreState()

    doc = SimpleDocTemplate(
        outpath,
        pagesize     = A4,
        topMargin    = 1.6 * cm,
        bottomMargin = 1.4 * cm,
        leftMargin   = MARGIN,
        rightMargin  = MARGIN,
        title        = f"KPI Report - {kpi_code}",
        author       = "PHO KPI Dashboard",
    )

    styles = getSampleStyleSheet()
    story  = []

    usable_w = PAGE_W - 2 * MARGIN

    def h_style(sz, color=BLACK, bold=True, align=TA_LEFT):
        return ParagraphStyle("custom",
            fontName  = "Helvetica-Bold" if bold else "Helvetica",
            fontSize  = sz,
            textColor = color,
            alignment = align,
            leading   = sz * 1.4,
        )

    def p_style(sz=10, color=BLACK, align=TA_LEFT, bold=False):
        return ParagraphStyle("p",
            fontName  = "Helvetica-Bold" if bold else "Helvetica",
            fontSize  = sz,
            textColor = color,
            alignment = align,
            leading   = sz * 1.5,
        )

    # ── TITLE BLOCK ─────────────────────────────────────────
    story.append(Spacer(1, 0.5*cm))

    # Big title
    story.append(Paragraph(
        "รายงานข้อมูลตัวชี้วัด (KPI)",
        h_style(20, DARK, align=TA_CENTER)
    ))
    story.append(Spacer(1, 0.2*cm))

    # KPI code badge + name
    info_data = [
        [Paragraph(f"<b>รหัส:</b> {kpi_code}", p_style(11)),
         Paragraph(f"<b>เป้าหมาย:</b> {operator} {target}%", p_style(11)),
         Paragraph(f"<b>วันที่:</b> {d['date']}", p_style(10, align=TA_RIGHT))],
    ]
    info_tbl = Table(info_data, colWidths=[usable_w*0.3, usable_w*0.4, usable_w*0.3])
    info_tbl.setStyle(TableStyle([
        ("BACKGROUND", (0,0), (-1,-1), STAT_B),
        ("BOX",        (0,0), (-1,-1), 0.5, TEAL),
        ("TOPPADDING", (0,0), (-1,-1), 8),
        ("BOTTOMPADDING", (0,0), (-1,-1), 8),
        ("LEFTPADDING", (0,0), (0,-1), 12),
    ]))
    story.append(info_tbl)
    story.append(Spacer(1, 0.2*cm))

    # KPI name full
    story.append(Paragraph(kpi_name, h_style(14, BLACK, align=TA_CENTER)))
    story.append(Spacer(1, 0.3*cm))
    story.append(HRFlowable(width="100%", thickness=2, color=TEAL))
    story.append(Spacer(1, 0.3*cm))

    # ── SUMMARY STAT BOXES ───────────────────────────────────
    story.append(Paragraph("สรุปผลการดำเนินงาน", h_style(13, TEAL)))
    story.append(Spacer(1, 0.2*cm))

    pass_rate = d['pass_rate']
    stat_data = [
        [
            _stat_cell("จำนวนทั้งหมด", f"{total} รายการ", BLUE,     colors.HexColor("#EFF6FF")),
            _stat_cell("ผ่านเกณฑ์",    f"{d['pass_count']} รายการ", PASS_C, PASS_B),
            _stat_cell("ไม่ผ่านเกณฑ์", f"{d['fail_count']} รายการ", FAIL_C, FAIL_B),
            _stat_cell("อัตราผ่าน",    f"{pass_rate}%",    PASS_C if pass_rate>=50 else FAIL_C,
                                                            PASS_B if pass_rate>=50 else FAIL_B),
        ],
        [
            _stat_cell("ค่าเฉลี่ย",   f"{d['avg_val']}%",  BLUE,  colors.HexColor("#F0F9FF")),
            _stat_cell("ค่าสูงสุด",   f"{d['max_val']}%",  PASS_C, PASS_B),
            _stat_cell("ค่าต่ำสุด",   f"{d['min_val']}%",  FAIL_C, FAIL_B),
            _stat_cell("เป้าหมาย",    f"{operator} {target}%", colors.HexColor("#0369A1"), colors.HexColor("#E0F2FE")),
        ],
    ]

    cw = usable_w / 4
    stat_tbl = Table(stat_data, colWidths=[cw]*4, rowHeights=[1.8*cm]*2)
    stat_tbl.setStyle(TableStyle([
        ("ALIGN",  (0,0), (-1,-1), "CENTER"),
        ("VALIGN", (0,0), (-1,-1), "MIDDLE"),
        ("LEFTPADDING",  (0,0), (-1,-1), 4),
        ("RIGHTPADDING", (0,0), (-1,-1), 4),
        ("TOPPADDING",   (0,0), (-1,-1), 4),
        ("BOTTOMPADDING",(0,0), (-1,-1), 4),
        ("ROWBACKGROUNDS", (0,0), (-1,-1), [colors.white]),
    ]))
    story.append(stat_tbl)
    story.append(Spacer(1, 0.4*cm))

    # ── CHARTS ───────────────────────────────────────────────
    story.append(Paragraph("กราฟแสดงข้อมูล", h_style(13, TEAL)))
    story.append(Spacer(1, 0.2*cm))

    # Prepare chart data
    chart_vals   = [float(r['actual']) for r in rows]
    chart_labels = [r['period'] for r in rows]
    n_pts = len(chart_vals)

    if n_pts > 0:
        charts_row_data = [[
            _make_bar_chart(chart_vals, chart_labels, target, usable_w * 0.5 - 0.5*cm, 7*cm),
            _make_trend_chart(chart_vals, chart_labels, target, usable_w * 0.5 - 0.5*cm, 7*cm),
        ]]
        charts_tbl = Table(charts_row_data,
                           colWidths=[usable_w*0.5, usable_w*0.5])
        charts_tbl.setStyle(TableStyle([
            ("ALIGN",  (0,0), (-1,-1), "CENTER"),
            ("VALIGN", (0,0), (-1,-1), "MIDDLE"),
            ("BOX",    (0,0), (0,0), 0.5, colors.HexColor("#E2E8F0")),
            ("BOX",    (1,0), (1,0), 0.5, colors.HexColor("#E2E8F0")),
        ]))
        story.append(charts_tbl)
        story.append(Spacer(1, 0.4*cm))

    # ── DATA TABLE ───────────────────────────────────────────
    story.append(Paragraph("ตารางข้อมูลรายละเอียด", h_style(13, TEAL)))
    story.append(Spacer(1, 0.2*cm))

    # Headers
    tbl_headers = ["#", "ช่วงเวลา", "ผลงาน (%)", "เทียบเป้า", "สถานะ", "หมายเหตุ"]
    tbl_data    = [
        [Paragraph(f"<b>{h}</b>", p_style(9, WHITE, TA_CENTER, bold=True)) for h in tbl_headers]
    ]

    for row in rows:
        is_pass = row["pass"]
        diff    = float(row["diff"])
        diff_s  = ("+") + f"{diff:.2f}%" if diff >= 0 else f"{diff:.2f}%"
        tbl_data.append([
            Paragraph(str(row["no"]), p_style(9, GRAY, TA_CENTER)),
            Paragraph(str(row["period"]), p_style(9)),
            Paragraph(f"<b>{float(row['actual']):.2f}%</b>",
                      p_style(9, PASS_C if is_pass else FAIL_C, TA_CENTER, bold=True)),
            Paragraph(diff_s, p_style(9, PASS_C if diff>=0 else FAIL_C, TA_CENTER, bold=True)),
            Paragraph("<b>" + ("บรรลุ" if is_pass else "ต่ำกว่าเป้า") + "</b>",
                      p_style(9, PASS_C if is_pass else FAIL_C, TA_CENTER, bold=True)),
            Paragraph(str(row.get("note", "")), p_style(8, GRAY)),
        ])

    cws = [0.06, 0.24, 0.14, 0.14, 0.18, 0.24]
    cws = [usable_w * x for x in cws]

    tbl_style = [
        ("BACKGROUND",    (0,0), (-1,0), HEADER_ROW),
        ("TEXTCOLOR",     (0,0), (-1,0), WHITE),
        ("ALIGN",         (0,0), (-1,-1), "CENTER"),
        ("VALIGN",        (0,0), (-1,-1), "MIDDLE"),
        ("FONTSIZE",      (0,0), (-1,-1), 9),
        ("TOPPADDING",    (0,0), (-1,-1), 5),
        ("BOTTOMPADDING", (0,0), (-1,-1), 5),
        ("LEFTPADDING",   (0,0), (-1,-1), 3),
        ("RIGHTPADDING",  (0,0), (-1,-1), 3),
        ("GRID",          (0,0), (-1,-1), 0.35, colors.HexColor("#CBD5E1")),
        ("ROWBACKGROUNDS",(0,1), (-1,-1), [WHITE, ALT]),
    ]

    # Highlight pass/fail rows
    for ri, row in enumerate(rows, 1):
        if row["pass"]:
            tbl_style.append(("BACKGROUND", (2,ri), (2,ri), PASS_B))
            tbl_style.append(("BACKGROUND", (4,ri), (4,ri), PASS_B))
        else:
            tbl_style.append(("BACKGROUND", (2,ri), (2,ri), FAIL_B))
            tbl_style.append(("BACKGROUND", (4,ri), (4,ri), FAIL_B))

    data_table = Table(tbl_data, colWidths=cws, repeatRows=1)
    data_table.setStyle(TableStyle(tbl_style))
    story.append(data_table)

    # ── Build ──────────────────────────────────────────────────
    doc.build(story, onFirstPage=on_page, onLaterPages=on_page)
    print(f"OK: {outpath}")


def _stat_cell(label, value, fg_color, bg_color):
    from reportlab.platypus import Table, TableStyle
    from reportlab.lib.styles import ParagraphStyle
    inner = [
        [Paragraph(f"<b>{value}</b>",
                   ParagraphStyle("v", fontName="Helvetica-Bold",
                                  fontSize=14, textColor=fg_color,
                                  alignment=TA_CENTER, leading=18))],
        [Paragraph(label,
                   ParagraphStyle("l", fontName="Helvetica",
                                  fontSize=8, textColor=GRAY,
                                  alignment=TA_CENTER))],
    ]
    t = Table(inner, colWidths=[None], rowHeights=[1.1*cm, 0.5*cm])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0,0), (-1,-1), bg_color),
        ("BOX",        (0,0), (-1,-1), 1.0, fg_color),
        ("ALIGN",      (0,0), (-1,-1), "CENTER"),
        ("VALIGN",     (0,0), (-1,-1), "MIDDLE"),
        ("TOPPADDING", (0,0), (-1,-1), 4),
        ("BOTTOMPADDING", (0,0), (-1,-1), 2),
        ("RADIUS",     (0,0), (-1,-1), 6, 6),
    ]))
    return t


def _make_bar_chart(vals, labels, target, width, height):
    drawing = Drawing(width, height)
    n = len(vals)
    if n == 0:
        return drawing

    bc = VerticalBarChart()
    bc.x = 50
    bc.y = 30
    bc.width  = width - 65
    bc.height = height - 50

    bc.data = [vals, [target] * n]

    bc.bars[0].fillColor = colors.HexColor("#0D9488")
    bc.bars[1].fillColor = colors.HexColor("#DC2626")
    bc.bars[1].strokeColor = colors.HexColor("#DC2626")

    bc.categoryAxis.categoryNames = _short_labels(labels)
    bc.categoryAxis.labels.fontSize = 6
    bc.categoryAxis.labels.angle = 30
    bc.categoryAxis.labels.dx = -5

    bc.valueAxis.valueMin = max(0, min(vals) - 5) if vals else 0
    bc.valueAxis.valueMax = min(100, max(vals) + 10) if vals else 100
    bc.valueAxis.labels.fontSize = 7

    title_str = String(width/2, height - 15, "Bar Chart: ผลงานรายช่วงเวลา",
                       textAnchor="middle", fontSize=9,
                       fillColor=colors.HexColor("#1E293B"),
                       fontName="Helvetica-Bold")
    drawing.add(bc)
    drawing.add(title_str)
    return drawing


def _make_trend_chart(vals, labels, target, width, height):
    from reportlab.graphics.charts.lineplots import LinePlot
    drawing = Drawing(width, height)
    n = len(vals)
    if n < 2:
        return drawing

    # Compute trend line (linear regression)
    xs = list(range(n))
    avg_x = sum(xs) / n
    avg_y = sum(vals) / n
    numer = sum((xs[i] - avg_x) * (vals[i] - avg_y) for i in range(n))
    denom = sum((xs[i] - avg_x) ** 2 for i in range(n))
    slope = numer / denom if denom != 0 else 0
    intercept = avg_y - slope * avg_x
    trend_vals = [round(intercept + slope * i, 2) for i in xs]

    lp = LinePlot()
    lp.x = 50
    lp.y = 30
    lp.width  = width - 65
    lp.height = height - 50

    pts_actual = [(i, v) for i, v in enumerate(vals)]
    pts_target = [(i, target) for i in range(n)]
    pts_trend  = [(i, v) for i, v in enumerate(trend_vals)]

    lp.data = [pts_actual, pts_target, pts_trend]

    # Actual
    lp.lines[0].strokeColor = colors.HexColor("#2563EB")
    lp.lines[0].strokeWidth = 2
    lp.lines[0].symbol = None

    # Target
    lp.lines[1].strokeColor = colors.HexColor("#DC2626")
    lp.lines[1].strokeWidth = 1.5
    lp.lines[1].strokeDashArray = [4, 3]
    lp.lines[1].symbol = None

    # Trend
    lp.lines[2].strokeColor = colors.HexColor("#F59E0B")
    lp.lines[2].strokeWidth = 1.5
    lp.lines[2].strokeDashArray = [3, 2]
    lp.lines[2].symbol = None

    all_y = vals + [target] + trend_vals
    lp.xValueAxis.valueMin = 0
    lp.xValueAxis.valueMax = n - 1
    lp.xValueAxis.labels.fontSize = 7
    lp.yValueAxis.valueMin = max(0, min(all_y) - 5)
    lp.yValueAxis.valueMax = min(100, max(all_y) + 5)
    lp.yValueAxis.labels.fontSize = 7

    title_str = String(width/2, height - 15, "Trend Chart: แนวโน้มผลงาน",
                       textAnchor="middle", fontSize=9,
                       fillColor=colors.HexColor("#1E293B"),
                       fontName="Helvetica-Bold")
    drawing.add(lp)
    drawing.add(title_str)
    return drawing


def _short_labels(labels, max_len=8):
    result = []
    for l in labels:
        s = str(l)
        result.append(s[:max_len] if len(s) > max_len else s)
    return result


if __name__ == "__main__":
    main()
