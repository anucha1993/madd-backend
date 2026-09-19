import fitz

pdf_path = r"d:\Programing\madd\madd-backend\storage\app\_tmp_layout_test.pdf"
doc = fitz.open(pdf_path)
print(f"Page count: {doc.page_count}")
for index in range(doc.page_count):
    page_number = index + 1
    output_path = f"d:/Programing/madd/_tmp_layout_test_page{page_number}.png"
    pixmap = doc[index].get_pixmap(matrix=fitz.Matrix(2.5, 2.5))
    pixmap.save(output_path)
    print(output_path)
doc.close()
