<?php
// ENDPOINT: Export complete equipment borrowing history as an Excel XLSX file (POST).
// Admin-only. This report intentionally excludes password hashes and login credentials.
require_once __DIR__ . '/../config/bootstrap.php';
require_admin();
post();

function xlsx_text(string $value): string {
    // XML 1.0-safe UTF-8 text, escaped for an inline-string worksheet cell.
    $value = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/u', '', $value) ?? '';
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_col(int $number): string {
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

try {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel export is unavailable because the PHP ZIP extension is not installed.');
    }

    $sql = "
        SELECT
            r.id,
            COALESCE(r.receipt_no, '') AS receipt_no,
            s.student_id,
            s.first_name,
            s.last_name,
            s.email,
            s.course,
            s.year_level,
            e.name AS equipment_name,
            e.category AS equipment_category,
            r.purpose,
            r.course_code,
            r.borrow_date,
            r.return_date,
            r.status,
            r.created_at AS requested_at,
            r.processed_at,
            r.returned_at,
            r.admin_note,
            CASE WHEN r.waiver_signed THEN 'Yes' ELSE 'No' END AS waiver_signed,
            r.waiver_signature,
            r.waiver_signed_at,
            COALESCE(d.damage_details, '') AS damage_details
        FROM equipment_requests r
        JOIN students s ON s.id = r.student_id
        JOIN equipment e ON e.id = r.equipment_id
        LEFT JOIN (
            SELECT request_id,
                   string_agg(
                       severity || ': ' || description || ' (Estimated cost: ' || estimated_cost::text || ')',
                       ' | ' ORDER BY created_at
                   ) AS damage_details
            FROM damage_reports
            GROUP BY request_id
        ) d ON d.request_id = r.id
        ORDER BY r.created_at DESC, r.id DESC
    ";
    $records = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $headers = [
        'Request ID', 'Receipt Number', 'Student Number', 'First Name', 'Last Name',
        'Email', 'Course', 'Year Level', 'Equipment', 'Equipment Category',
        'Purpose', 'Course Code', 'Borrow Date', 'Expected Return Date', 'Request Status',
        'Request Submitted At', 'Processed At', 'Actually Returned At', 'Admin Note',
        'Waiver Signed', 'Waiver Signature', 'Waiver Signed At', 'Damage Report Details'
    ];

    $sheetRows = [];
    $sheetRows[] = $headers;
    foreach ($records as $record) {
        $sheetRows[] = [
            (string)$record['id'], (string)$record['receipt_no'], (string)$record['student_id'],
            (string)$record['first_name'], (string)$record['last_name'], (string)$record['email'],
            (string)$record['course'], (string)$record['year_level'], (string)$record['equipment_name'],
            (string)$record['equipment_category'], (string)$record['purpose'], (string)$record['course_code'],
            (string)$record['borrow_date'], (string)$record['return_date'], (string)$record['status'],
            (string)$record['requested_at'], (string)($record['processed_at'] ?? ''),
            (string)($record['returned_at'] ?? ''), (string)$record['admin_note'],
            (string)$record['waiver_signed'], (string)$record['waiver_signature'],
            (string)$record['waiver_signed_at'], (string)$record['damage_details']
        ];
    }

    $rowsXml = '';
    foreach ($sheetRows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $cells = '';
        foreach ($row as $colIndex => $value) {
            $cellRef = xlsx_col($colIndex + 1) . $excelRow;
            $style = $rowIndex === 0 ? ' s="1"' : '';
            $cells .= '<c r="' . $cellRef . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                . xlsx_text((string)$value) . '</t></is></c>';
        }
        $rowsXml .= '<row r="' . $excelRow . '">' . $cells . '</row>';
    }

    $lastColumn = xlsx_col(count($headers));
    $lastRow = count($sheetRows);
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:' . $lastColumn . $lastRow . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/><cols>'
        . '<col min="1" max="23" width="22" customWidth="1"/>'
        . '</cols><sheetData>' . $rowsXml . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . $lastRow . '"/>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF234A72"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        . '</styleSheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'rtu_xlsx_');
    if ($tmp === false) throw new RuntimeException('Could not create the Excel workbook.');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not package the Excel workbook.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Borrowing History" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $filename = 'rtu_equipment_borrowing_history_' . date('Ymd_His') . '.xlsx';
    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('X-Export-Records: ' . count($records));
    readfile($tmp);
    @unlink($tmp);
    exit;
} catch (Throwable $e) {
    error_log('Borrowing history export failed: ' . $e->getMessage());
    fail('Excel export failed. Please check the server error log.', 500);
}
