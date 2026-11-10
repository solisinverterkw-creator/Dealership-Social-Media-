# -*- coding: utf-8 -*-
"""
Suzuki Fronx Question Bank Excel Synchronization Helper
Enables Super Admin to:
1. Download the complete 42 MCQs + 8 Open-Ended pitch questions as a formatted Excel (.xlsx) file.
2. Upload a modified Excel (.xlsx) file to instantly update questions.json and questions.js live on the server.
"""

import json
import os
import io
import openpyxl
from openpyxl.styles import PatternFill, Font, Alignment, Border, Side

def get_bank_paths(root_dir):
    json_path = os.path.join(root_dir, 'assets', 'fronx', 'questions.json')
    js_path = os.path.join(root_dir, 'assets', 'fronx', 'questions.js')
    return json_path, js_path

def export_questions_to_excel(root_dir):
    json_path, _ = get_bank_paths(root_dir)
    bank = {}
    if os.path.exists(json_path):
        try:
            with open(json_path, 'r', encoding='utf-8') as f:
                bank = json.load(f)
        except Exception:
            bank = {}

    wb = openpyxl.Workbook()
    wb.remove(wb.active)  # Remove default blank sheet

    header_font = Font(name="Segoe UI", size=11, bold=True, color="FFFFFF")
    mcq_header_fill = PatternFill(start_color="1E3A8A", end_color="1E3A8A", fill_type="solid")  # Navy Blue
    open_header_fill = PatternFill(start_color="92400E", end_color="92400E", fill_type="solid")  # Bronze/Amber
    thin_border = Border(
        left=Side(style='thin', color='CBD5E1'),
        right=Side(style='thin', color='CBD5E1'),
        top=Side(style='thin', color='CBD5E1'),
        bottom=Side(style='thin', color='CBD5E1')
    )

    # 1. MCQ Sheet
    ws_mcq = wb.create_sheet(title="MCQ Questions")
    ws_mcq.views.sheetView[0].showGridLines = True
    mcq_headers = [
        "Question ID", "Section Key", "Section Name",
        "Question (English)", "Question (Urdu)",
        "Option A", "Option B", "Option C", "Option D",
        "Correct Option (A/B/C/D)", "Explanation / Winning Pitch"
    ]
    ws_mcq.append(mcq_headers)
    for col_idx in range(1, len(mcq_headers) + 1):
        cell = ws_mcq.cell(row=1, column=col_idx)
        cell.font = header_font
        cell.fill = mcq_header_fill
        cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
    ws_mcq.row_dimensions[1].height = 28

    sections_map = bank.get("sections", {})
    idx_to_letter = {0: "A", 1: "B", 2: "C", 3: "D"}

    for r_idx, q in enumerate(bank.get("mcq", []), start=2):
        sec_name = sections_map.get(q.get("s"), {}).get("en", q.get("s", ""))
        opts = q.get("o", ["", "", "", ""])
        correct_letter = idx_to_letter.get(q.get("a", 0), "A")

        row_data = [
            q.get("id", f"MCQ_{r_idx-1}"),
            q.get("s", "A"),
            sec_name,
            q.get("q", ""),
            q.get("qu", ""),
            opts[0] if len(opts) > 0 else "",
            opts[1] if len(opts) > 1 else "",
            opts[2] if len(opts) > 2 else "",
            opts[3] if len(opts) > 3 else "",
            correct_letter,
            q.get("x", "")
        ]
        ws_mcq.append(row_data)
        for col_idx in range(1, len(row_data) + 1):
            c = ws_mcq.cell(row=r_idx, column=col_idx)
            c.border = thin_border
            c.font = Font(name="Segoe UI", size=10)
            if col_idx in [1, 2, 10]:
                c.alignment = Alignment(horizontal="center", vertical="top")
                if col_idx == 10:
                    c.font = Font(name="Segoe UI", size=11, bold=True, color="166534")
            elif col_idx == 5:
                c.alignment = Alignment(horizontal="right", vertical="top", wrap_text=True)
            else:
                c.alignment = Alignment(horizontal="left", vertical="top", wrap_text=True)
        ws_mcq.row_dimensions[r_idx].height = 55

    mcq_col_widths = {
        "A": 14, "B": 12, "C": 22, "D": 45, "E": 45,
        "F": 28, "G": 28, "H": 28, "I": 28, "J": 14, "K": 40
    }
    for col_letter, width in mcq_col_widths.items():
        ws_mcq.column_dimensions[col_letter].width = width

    # 2. Open-Ended Sheet
    ws_open = wb.create_sheet(title="Open Ended Pitch")
    ws_open.views.sheetView[0].showGridLines = True
    open_headers = [
        "Question ID", "Question (English)", "Question (Urdu)",
        "Prompt Hint (English)", "Prompt Hint (Urdu)", "Max Marks",
        "Scoring Keywords (Comma Separated)",
        "Model Winning Answer (English)", "Model Winning Answer (Urdu)"
    ]
    ws_open.append(open_headers)
    for col_idx in range(1, len(open_headers) + 1):
        cell = ws_open.cell(row=1, column=col_idx)
        cell.font = header_font
        cell.fill = open_header_fill
        cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
    ws_open.row_dimensions[1].height = 28

    for r_idx, oq in enumerate(bank.get("open", []), start=2):
        row_data = [
            oq.get("id", f"O{r_idx-1}"),
            oq.get("q", ""),
            oq.get("qu", ""),
            oq.get("hint", ""),
            oq.get("hintu", ""),
            oq.get("max", 5),
            ", ".join(oq.get("k", [])),
            oq.get("model_en", ""),
            oq.get("model_ur", "")
        ]
        ws_open.append(row_data)
        for col_idx in range(1, len(row_data) + 1):
            c = ws_open.cell(row=r_idx, column=col_idx)
            c.border = thin_border
            c.font = Font(name="Segoe UI", size=10)
            if col_idx in [1, 6]:
                c.alignment = Alignment(horizontal="center", vertical="top")
                if col_idx == 6:
                    c.font = Font(name="Segoe UI", size=11, bold=True, color="92400E")
            elif col_idx in [3, 5, 9]:
                c.alignment = Alignment(horizontal="right", vertical="top", wrap_text=True)
            else:
                c.alignment = Alignment(horizontal="left", vertical="top", wrap_text=True)
        ws_open.row_dimensions[r_idx].height = 65

    open_col_widths = {
        "A": 14, "B": 42, "C": 42, "D": 32, "E": 32,
        "F": 12, "G": 28, "H": 48, "I": 48
    }
    for col_letter, width in open_col_widths.items():
        ws_open.column_dimensions[col_letter].width = width

    # 3. Guide Sheet
    ws_info = wb.create_sheet(title="User Guide & Instructions")
    ws_info.views.sheetView[0].showGridLines = True
    ws_info.column_dimensions["A"].width = 25
    ws_info.column_dimensions["B"].width = 80

    info_data = [
        ("Feature", "Suzuki Fronx Official Question Bank Management (Excel Sync)"),
        ("Version", bank.get("version", "2.1")),
        ("Total MCQs", str(len(bank.get("mcq", [])))),
        ("Total Open Pitches", str(len(bank.get("open", [])))),
        ("Allowed Section Keys", "A (Variants), B (Tech/Hybrid), C (Competitors), F (Chinese Brands), D (Advantages), E (Objections)"),
        ("Correct Option Format", "Must be 'A', 'B', 'C', or 'D' in column J of MCQ sheet"),
        ("How to Add New Questions", "Simply add new rows in 'MCQ Questions' or 'Open Ended Pitch' sheet and upload via Admin panel."),
        ("Bilingual Requirement", "Both English and Urdu question text are recommended so tests render smoothly for all dealerships."),
        ("Instant Web Sync", "When you upload this Excel sheet, both web test portal and super admin answer key update instantly!")
    ]

    for r_idx, (k, v) in enumerate(info_data, start=1):
        ws_info.cell(row=r_idx, column=1, value=k).font = Font(name="Segoe UI", size=11, bold=True, color="1E3A8A")
        ws_info.cell(row=r_idx, column=2, value=v).font = Font(name="Segoe UI", size=10)
        ws_info.row_dimensions[r_idx].height = 24

    output = io.BytesIO()
    wb.save(output)
    output.seek(0)
    return output

def import_questions_from_excel(root_dir, file_stream):
    """
    Parses an uploaded Excel file and safely updates questions.json & questions.js.
    Returns (success: bool, message: str, mcq_count: int, open_count: int).
    """
    json_path, js_path = get_bank_paths(root_dir)
    letter_to_idx = {"A": 0, "B": 1, "C": 2, "D": 3}

    try:
        wb = openpyxl.load_workbook(file_stream, data_only=True)
    except Exception as e:
        return False, f"Invalid Excel file format: {str(e)}", 0, 0

    # Load existing sections and metadata
    existing_bank = {}
    if os.path.exists(json_path):
        try:
            with open(json_path, 'r', encoding='utf-8') as f:
                existing_bank = json.load(f)
        except Exception:
            existing_bank = {}

    sections = existing_bank.get("sections", {
        "A": {"en": "Fronx Variants & Specifications", "ur": "فرونکس ویریئنٹس اور بنیادی معلومات"},
        "B": {"en": "Technology & Safety Systems (SHVS, ADAS, 360, HUD)", "ur": "ٹیکنالوجی اور سیفٹی سسٹمز"},
        "C": {"en": "Direct Competitors (Stonic, 2008, HR-V, Sedans)", "ur": "براہِ راست مدمقابل گاڑیاں"},
        "F": {"en": "Chinese Brands & C-SUVs (Jaecoo, BYD, MG, Jetour)", "ur": "چائنیز کراس اوورز اور الیکٹرک گاڑیاں"},
        "D": {"en": "Fronx Value & Practical Advantages", "ur": "فرونکس کی برتری اور مالی فوائد"},
        "E": {"en": "Customer Objection Handling & Pitching", "ur": "کسٹمر کے سوالات اور سیلز پچ"}
    })

    # 1. Parse MCQs
    mcqs = []
    if "MCQ Questions" in wb.sheetnames:
        ws_mcq = wb["MCQ Questions"]
        for row in ws_mcq.iter_rows(min_row=2, values_only=True):
            if not row or not any(row):
                continue
            q_id = str(row[0]).strip() if row[0] is not None else ""
            sec_key = str(row[1]).strip().upper() if row[1] is not None else "A"
            q_en = str(row[3]).strip() if len(row) > 3 and row[3] is not None else ""
            q_ur = str(row[4]).strip() if len(row) > 4 and row[4] is not None else ""
            if not q_en and not q_ur:
                continue

            opt_a = str(row[5]).strip() if len(row) > 5 and row[5] is not None else ""
            opt_b = str(row[6]).strip() if len(row) > 6 and row[6] is not None else ""
            opt_c = str(row[7]).strip() if len(row) > 7 and row[7] is not None else ""
            opt_d = str(row[8]).strip() if len(row) > 8 and row[8] is not None else ""

            ans_letter = str(row[9]).strip().upper() if len(row) > 9 and row[9] is not None else "A"
            correct_idx = letter_to_idx.get(ans_letter, 0)
            explanation = str(row[10]).strip() if len(row) > 10 and row[10] is not None else ""

            mcqs.append({
                "id": q_id or f"MCQ_{len(mcqs)+1}",
                "s": sec_key if sec_key in sections else "A",
                "q": q_en,
                "qu": q_ur or q_en,
                "o": [opt_a, opt_b, opt_c, opt_d],
                "a": correct_idx,
                "x": explanation
            })

    # 2. Parse Open Questions
    opens = []
    if "Open Ended Pitch" in wb.sheetnames:
        ws_open = wb["Open Ended Pitch"]
        for row in ws_open.iter_rows(min_row=2, values_only=True):
            if not row or not any(row):
                continue
            oq_id = str(row[0]).strip() if row[0] is not None else ""
            oq_en = str(row[1]).strip() if len(row) > 1 and row[1] is not None else ""
            oq_ur = str(row[2]).strip() if len(row) > 2 and row[2] is not None else ""
            if not oq_en and not oq_ur:
                continue

            hint_en = str(row[3]).strip() if len(row) > 3 and row[3] is not None else ""
            hint_ur = str(row[4]).strip() if len(row) > 4 and row[4] is not None else ""

            try:
                max_pts = int(row[5]) if len(row) > 5 and row[5] is not None else 5
            except Exception:
                max_pts = 5

            keywords_raw = str(row[6]).strip() if len(row) > 6 and row[6] is not None else ""
            keywords = [k.strip() for k in keywords_raw.split(",") if k.strip()]

            model_en = str(row[7]).strip() if len(row) > 7 and row[7] is not None else ""
            model_ur = str(row[8]).strip() if len(row) > 8 and row[8] is not None else ""

            opens.append({
                "id": oq_id or f"O{len(opens)+1}",
                "q": oq_en,
                "qu": oq_ur or oq_en,
                "hint": hint_en,
                "hintu": hint_ur,
                "max": max_pts,
                "k": keywords,
                "model_en": model_en,
                "model_ur": model_ur
            })

    if not mcqs:
        return False, "No valid MCQ questions found in 'MCQ Questions' sheet.", 0, 0

    new_bank = {
        "version": str(float(existing_bank.get("version", "2.1")) + 0.1)[:4],
        "totalQuestions": len(mcqs) + len(opens),
        "mcqCount": len(mcqs),
        "openCount": len(opens),
        "sections": sections,
        "mcq": mcqs,
        "open": opens
    }

    # Save to questions.json
    try:
        with open(json_path, 'w', encoding='utf-8') as f:
            json.dump(new_bank, f, ensure_ascii=False, indent=2)
    except Exception as e:
        return False, f"Failed saving questions.json: {str(e)}", len(mcqs), len(opens)

    # Save to questions.js
    try:
        js_content = f"""/* ============================================================
   SUZUKI FRONX SALES MASTERY TEST - OFFICIAL QUESTION BANK
   {len(mcqs)} MCQs + {len(opens)} Open-Ended Showroom Winning Pitches ({len(mcqs) + len(opens)} Total)
   Pak Suzuki Dealership Network - National Sales Skill Assessment
   Bilingual: English + Urdu (اردو)
   Synchronized via Excel Upload
   ============================================================ */

window.QUESTION_BANK = {json.dumps(new_bank, ensure_ascii=False, indent=2)};
"""
        with open(js_path, 'w', encoding='utf-8') as f:
            f.write(js_content)
    except Exception as e:
        return False, f"Failed saving questions.js: {str(e)}", len(mcqs), len(opens)

    return True, f"Successfully imported {len(mcqs)} MCQs and {len(opens)} Open-Ended Pitch Questions!", len(mcqs), len(opens)
