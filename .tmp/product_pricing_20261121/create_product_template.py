from __future__ import annotations

import re
from pathlib import Path

from openpyxl import load_workbook
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.worksheet.datavalidation import DataValidation


SOURCE = Path(r"D:\Book1.xlsx")
OUTPUT = Path(r"C:\xampp\htdocs\erp2\outputs\01a1fd84-4506-78d3-95d3-ec8eff738546\Book1_Product_Import_Template_Ready.xlsx")

PURPLE = "6F5B98"
DARK = "27364A"
WHITE = "FFFFFF"
LINE = "D8DEE8"
INPUT_FILL = "FFF3C4"
FORMULA_FILL = "E7EFFA"
CP_FILL = "E6F4EA"
NON_CP_FILL = "E7EFFA"
UNKNOWN_FILL = "FFF1D6"
BODY_FONT = "Arial"

HEADERS = [
    "Record ID", "Product", "Internal Reference", "Barcode", "Category", "Product Class", "Product Type",
    "Sales Unit", "Purchase Unit", "Carton Size", "Units per Carton", "Tracking", "Invoice Policy",
    "Piece Price", "Carton Price", "Cost", "Can be Sold", "Can be Purchased", "Active", "Weight", "Volume", "Description",
]
UOM_LABEL = "Unit (Unit) · Units"


def unit_count(value: object) -> int | None:
    if value is None or str(value).strip() == "":
        return None
    text = str(value).strip()
    match = re.fullmatch(r"(\d+)\s*\*\s*(\d+)", text)
    if match:
        return int(match.group(1)) * int(match.group(2))
    if re.fullmatch(r"\d+", text):
        return int(text)
    return None


def main() -> None:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    workbook = load_workbook(SOURCE)
    source_sheet = workbook["Ms Fariya Ent (2)"]
    before_values = [[cell.value for cell in row] for row in source_sheet.iter_rows()]
    before_merges = list(source_sheet.merged_cells.ranges)

    for sheet_name in ("Product Import", "Import Guide"):
        if sheet_name in workbook.sheetnames:
            del workbook[sheet_name]

    import_sheet = workbook.create_sheet("Product Import")
    import_sheet.sheet_view.showGridLines = False
    import_sheet.sheet_properties.tabColor = PURPLE
    import_sheet.freeze_panes = "C2"
    import_sheet.sheet_properties.pageSetUpPr.fitToPage = True
    import_sheet.page_setup.orientation = "landscape"
    import_sheet.page_setup.fitToWidth = 1
    import_sheet.page_setup.fitToHeight = 0
    import_sheet.print_title_rows = "1:1"
    import_sheet.sheet_format.defaultRowHeight = 20

    import_sheet.append(HEADERS)
    product_class = "unclassified"
    source_rows = []
    for row_index in range(8, 34):
        name, size, carton_price = [source_sheet.cell(row_index, col).value for col in range(1, 4)]
        if not name:
            continue
        label = str(name).strip()
        if label.lower() == "with cp":
            product_class = "cp"
            continue
        if label.lower() == "with non cp":
            product_class = "non_cp"
            continue
        source_rows.append((label, size, carton_price, product_class))

    if len(source_rows) != 24:
        raise ValueError(f"Expected the 24 product rows from Book1.xlsx; found {len(source_rows)}.")

    for product_name, carton_size_value, carton_price, classification in source_rows:
        carton_size = "" if carton_size_value is None else str(carton_size_value).strip()
        pieces = unit_count(carton_size_value)
        row_number = import_sheet.max_row + 1
        import_sheet.append([
            None,
            product_name,
            None,
            None,
            "All",
            {"cp": "CP", "non_cp": "Non-CP"}.get(classification, "Unclassified"),
            "Goods",
            UOM_LABEL,
            UOM_LABEL,
            carton_size,
            pieces,
            "By Quantity",
            "Ordered quantities",
            f'=IF(OR(K{row_number}="",K{row_number}=0,O{row_number}=""),"",ROUND(O{row_number}/K{row_number},6))',
            carton_price,
            None,
            "Yes",
            "Yes",
            "Yes",
            None,
            None,
            None,
        ])

    last_row = import_sheet.max_row
    import_sheet.auto_filter.ref = f"A1:V{last_row}"

    header_fill = PatternFill("solid", fgColor=PURPLE)
    input_fill = PatternFill("solid", fgColor=INPUT_FILL)
    formula_fill = PatternFill("solid", fgColor=FORMULA_FILL)
    thin_bottom = Side(style="thin", color=LINE)
    for cell in import_sheet[1]:
        cell.fill = header_fill
        cell.font = Font(name=BODY_FONT, size=10, bold=True, color=WHITE)
        cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
        cell.border = Border(bottom=Side(style="medium", color=WHITE))

    input_columns = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 15, 16, 17, 18, 19, 20, 21, 22]
    for row in import_sheet.iter_rows(min_row=2, max_row=import_sheet.max_row, min_col=1, max_col=22):
        for cell in row:
            cell.font = Font(name=BODY_FONT, size=10, color=DARK)
            cell.alignment = Alignment(vertical="center", horizontal="left")
            cell.border = Border(bottom=thin_bottom)
            if cell.column in input_columns:
                cell.fill = input_fill
        row[0].number_format = "@"
        row[10].number_format = "#,##0"
        for col in (14, 15, 16):
            row[col - 1].number_format = "#,##0.######"
        for col in (20, 21):
            row[col - 1].number_format = "#,##0.######"
        row[13].fill = formula_fill
        row[13].font = Font(name=BODY_FONT, size=10, color="24476B")
        row[13].number_format = "#,##0.######"
        class_cell = row[5]
        if class_cell.value == "CP":
            class_cell.fill = PatternFill("solid", fgColor=CP_FILL)
        elif class_cell.value == "Non-CP":
            class_cell.fill = PatternFill("solid", fgColor=NON_CP_FILL)
        else:
            class_cell.fill = PatternFill("solid", fgColor=UNKNOWN_FILL)
        for number_column in (11, 14, 15, 16, 20, 21):
            row[number_column - 1].alignment = Alignment(horizontal="right", vertical="center")

    widths = {
        "A": 12, "B": 27, "C": 20, "D": 17, "E": 18, "F": 17, "G": 15,
        "H": 27, "I": 27, "J": 16, "K": 18, "L": 23, "M": 22,
        "N": 17, "O": 17, "P": 15, "Q": 17, "R": 20, "S": 12,
        "T": 12, "U": 12, "V": 32,
    }
    for col, width in widths.items():
        import_sheet.column_dimensions[col].width = width
    import_sheet.row_dimensions[1].height = 40

    validations = [
        (f"F2:F{last_row}", '"CP,Non-CP,Unclassified"'),
        (f"G2:G{last_row}", '"Goods,Service,Combo"'),
        (f"L2:L{last_row}", '"By Quantity,By Lots,By Unique Serial Number"'),
        (f"M2:M{last_row}", '"Ordered quantities,Delivered quantities"'),
        (f"Q2:S{last_row}", '"Yes,No"'),
    ]
    for target, values in validations:
        validation = DataValidation(type="list", formula1=values, allow_blank=True)
        validation.errorTitle = "Choose a listed value"
        validation.error = "Use one of the displayed choices."
        validation.promptTitle = "Product import value"
        validation.prompt = "Keep values consistent with the ERP import template."
        import_sheet.add_data_validation(validation)
        validation.add(target)
    count_validation = DataValidation(type="whole", operator="between", formula1=1, formula2=4294967295, allow_blank=True)
    count_validation.error = "Enter a positive whole number of units per carton."
    import_sheet.add_data_validation(count_validation)
    count_validation.add(f"K2:K{last_row}")
    price_validation = DataValidation(type="decimal", operator="greaterThanOrEqual", formula1=0, allow_blank=True)
    price_validation.error = "Enter a non-negative price using a decimal point."
    import_sheet.add_data_validation(price_validation)
    price_validation.add(f"O2:P{last_row}")

    guide = workbook.create_sheet("Import Guide")
    guide.sheet_view.showGridLines = False
    guide.sheet_properties.tabColor = "A99BC4"
    guide.column_dimensions["A"].width = 27
    guide.column_dimensions["B"].width = 105
    guide["A1"] = "Product Import Guide"
    guide["A1"].font = Font(name=BODY_FONT, size=16, bold=True, color=PURPLE)
    guide.merge_cells("A1:B1")
    guide["A3"] = "কীভাবে Import করবেন"
    guide["B3"] = "Product Import tab নির্বাচন করে Save As → CSV UTF-8 করুন। Inventory → Products → Import Products থেকে কোম্পানি বেছে CSV আপলোড করুন। এই app সরাসরি XLSX নেয় না; CSV UTF-8 নেয়।"
    guide["A4"] = "Record ID"
    guide["B4"] = "প্রথমবারের নতুন product-এর Record ID ফাঁকা। ERP-তে import করার পর Export CSV নিলে Record ID পাওয়া যাবে; পরে update করতে সেটি রাখুন। Internal Reference দিলেও matching হবে।"
    guide["A5"] = "Category ও Unit"
    guide["B5"] = "Category হিসেবে All এবং Sales/Purchase Unit হিসেবে Unit (Unit) · Units দেওয়া আছে। এগুলো project migration 006-এর default shared master values। আপনার কোম্পানির ERP-তে এগুলো active না থাকলে Export CSV থেকে সক্রিয় Category/Unit নাম বসান।"
    guide["A6"] = "Product Class"
    guide["B6"] = "Source-এর With CP অংশের ৪টি পণ্য CP, With Non CP অংশের ১৩টি Non-CP। প্রথম ৭টি পণ্যের class source-এ দেওয়া নেই, তাই Unclassified রেখেছি। যাচাই করে dropdown থেকে class দিন।"
    guide["A7"] = "Piece ও Carton Price"
    guide["B7"] = "Carton Price মূল Price in CTN। Piece Price = Carton Price ÷ Units per Carton, ৬ decimal পর্যন্ত। Carton Size যেমন 30*1 থেকে Units per Carton 30 রাখা হয়েছে। Carton Size ফাঁকা হলে Piece Price ফাঁকা থাকে।"
    guide["A8"] = "Cost"
    guide["B8"] = "Book1-এ purchase cost নেই, তাই Cost ফাঁকা। নতুন product import করলে ERP-তে Cost 0 হবে। Purchase posting-এর আগে প্রকৃত cost পূরণ করুন।"
    guide["A9"] = "Duplicate product name"
    guide["B9"] = "Great Boss 2000 source-এ ৩ বার আছে, carton price 4,800 / 4,500 / 4,500। আলাদা row হিসেবে রেখেছি। Import-এর পর আলাদা করতে Internal Reference দিন; Record ID দিয়ে update নিরাপদ থাকবে।"
    guide["A10"] = "Review"
    guide["B10"] = "Beauty Sope নামটি source-এর বানান অনুযায়ী রাখা হয়েছে। Import-এর আগে product name ও price যাচাই করুন।"
    guide["A11"] = "Import সীমা"
    guide["B11"] = "CSV সর্বোচ্চ ৫ MB এবং ২,০০০ product row হতে পারে। কোনো row invalid হলে পুরো file import বাতিল হয়।"
    guide["A12"] = "দামের ব্যবহার"
    guide["B12"] = "Piece Price বিদ্যমান default Sales Price হিসেবে ব্যবহৃত হবে। Carton Price, Carton Size ও Units per Carton আলাদা product field হিসেবে থাকবে। Units per Carton আলাদা Carton Unit তৈরি করে না।"
    guide["A13"] = "Stock ও Promotion"
    guide["B13"] = "এই import on-hand stock quantity বা promotion/offer import করে না। Stock Move বা Cycle Count দিয়ে quantity পরিচালনা করুন। বর্তমান Product record-এ promotion/offer field নেই।"

    guide["A3"].font = Font(name=BODY_FONT, size=11, bold=True, color=PURPLE)
    for row_num in range(3, 14):
        left = guide.cell(row_num, 1)
        right = guide.cell(row_num, 2)
        left.fill = PatternFill("solid", fgColor="EEEAF5")
        left.font = Font(name=BODY_FONT, size=10, bold=True, color=DARK)
        left.alignment = Alignment(vertical="top", wrap_text=True)
        right.font = Font(name=BODY_FONT, size=10, color=DARK)
        right.alignment = Alignment(vertical="top", wrap_text=True)
        left.border = Border(bottom=thin_bottom)
        right.border = Border(bottom=thin_bottom)
        guide.row_dimensions[row_num].height = 54 if row_num in (3, 5, 6, 7, 12, 13) else 42

    # Keep the import data as the first sheet for spreadsheet importers, and
    # leave the source price sheet and instructions available as supporting tabs.
    workbook.move_sheet(import_sheet, offset=-1)
    for sheet in workbook.worksheets:
        sheet.sheet_view.tabSelected = False
    import_sheet.sheet_view.tabSelected = True
    workbook.active = workbook.sheetnames.index("Product Import")
    workbook.calculation.fullCalcOnLoad = True
    workbook.calculation.forceFullCalc = True
    workbook.calculation.calcMode = "auto"
    workbook.properties.title = "Product Import Template"
    workbook.properties.subject = "Product prices per piece and carton, with CP / Non-CP classification"
    workbook.save(OUTPUT)

    check = load_workbook(OUTPUT, data_only=False)
    expected_sheet_order = ["Product Import", "Ms Fariya Ent (2)", "Import Guide"]
    if check.sheetnames != expected_sheet_order or check.active.title != "Product Import":
        raise RuntimeError(f"Unexpected worksheet order or active tab: {check.sheetnames}; active={check.active.title}")
    selected_sheets = [sheet.title for sheet in check.worksheets if sheet.sheet_view.tabSelected]
    if selected_sheets != ["Product Import"]:
        raise RuntimeError(f"Expected only Product Import to be selected; found {selected_sheets}")
    copied_source = check["Ms Fariya Ent (2)"]
    after_values = [[cell.value for cell in row] for row in copied_source.iter_rows()]
    if after_values != before_values or list(copied_source.merged_cells.ranges) != before_merges:
        raise RuntimeError("The original source sheet changed while the template was being prepared.")
    products = check["Product Import"]
    counts = {label: sum(products.cell(row, 6).value == label for row in range(2, products.max_row + 1)) for label in ("CP", "Non-CP", "Unclassified")}
    print({"output": str(OUTPUT), "sheetnames": check.sheetnames, "active_sheet": check.active.title, "selected_sheets": selected_sheets, "product_rows": products.max_row - 1, "classes": counts, "first_piece_formula": products["N2"].value, "source_sheet_unchanged": True})


if __name__ == "__main__":
    main()
