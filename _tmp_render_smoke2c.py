import sys
import fitz
pdf_path = sys.argv[1]
prefix = sys.argv[2]
doc = fitz.open(pdf_path)
print(f'Page count: {doc.page_count}')
for index, page in enumerate(doc, start=1):
    output_path = f'{prefix}{index}.png'
    page.get_pixmap(matrix=fitz.Matrix(2.5, 2.5)).save(output_path)
    print(f'Created: {output_path}')
doc.close()
