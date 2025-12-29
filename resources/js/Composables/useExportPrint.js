import * as XLSX from 'xlsx';

/**
 * Composable for exporting data to Excel/CSV and printing lists
 */
export function useExportPrint() {

    /**
     * Export data to Excel file (.xlsx)
     * @param {Array} data - Array of objects to export
     * @param {Object} columns - Column configuration { key: 'Label' }
     * @param {string} filename - Base filename without extension
     */
    const exportToExcel = (data, columns, filename = 'export') => {
        if (!data || data.length === 0) {
            alert('Aucune donnée à exporter');
            return;
        }

        const headers = Object.values(columns);
        const keys = Object.keys(columns);

        // Build data array for sheet
        const sheetData = [
            headers, // Header row
            ...data.map(row =>
                keys.map(key => {
                    let value = getNestedValue(row, key);
                    if (value === null || value === undefined) value = '';
                    if (typeof value === 'boolean') value = value ? 'Oui' : 'Non';
                    return value;
                })
            )
        ];

        // Create workbook and worksheet
        const workbook = XLSX.utils.book_new();
        const worksheet = XLSX.utils.aoa_to_sheet(sheetData);

        // Auto-size columns
        const colWidths = headers.map((header, i) => {
            const maxLength = Math.max(
                header.length,
                ...data.map(row => {
                    const val = getNestedValue(row, keys[i]);
                    return String(val || '').length;
                })
            );
            return { wch: Math.min(maxLength + 2, 50) };
        });
        worksheet['!cols'] = colWidths;

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(workbook, worksheet, 'Données');

        // Generate file and download
        XLSX.writeFile(workbook, `${filename}_${formatDate(new Date())}.xlsx`);
    };

    /**
     * Export data to CSV file (legacy support)
     * @param {Array} data - Array of objects to export
     * @param {Object} columns - Column configuration { key: 'Label' }
     * @param {string} filename - Base filename without extension
     */
    const exportToCsv = (data, columns, filename = 'export') => {
        if (!data || data.length === 0) {
            alert('Aucune donnée à exporter');
            return;
        }

        // Create CSV content with BOM for Excel UTF-8 support
        const BOM = '\uFEFF';
        const headers = Object.values(columns);
        const keys = Object.keys(columns);

        const csvRows = [
            headers.join(';'), // Header row
            ...data.map(row =>
                keys.map(key => {
                    let value = getNestedValue(row, key);
                    // Handle special values
                    if (value === null || value === undefined) value = '';
                    if (typeof value === 'boolean') value = value ? 'Oui' : 'Non';
                    // Escape quotes and wrap in quotes if contains semicolon
                    value = String(value).replace(/"/g, '""');
                    if (value.includes(';') || value.includes('"') || value.includes('\n')) {
                        value = `"${value}"`;
                    }
                    return value;
                }).join(';')
            )
        ];

        const csvContent = BOM + csvRows.join('\n');

        // Create and download file
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);

        link.setAttribute('href', url);
        link.setAttribute('download', `${filename}_${formatDate(new Date())}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    };

    /**
     * Print a list with custom styling
     * @param {Array} data - Array of objects to print
     * @param {Object} columns - Column configuration { key: 'Label' }
     * @param {string} title - Title for the print document
     */
    const printList = (data, columns, title = 'Liste') => {
        if (!data || data.length === 0) {
            alert('Aucune donnée à imprimer');
            return;
        }

        const headers = Object.values(columns);
        const keys = Object.keys(columns);

        // Build HTML table
        const tableRows = data.map(row =>
            `<tr>${keys.map(key => {
                let value = getNestedValue(row, key);
                if (value === null || value === undefined) value = '-';
                if (typeof value === 'boolean') value = value ? 'Oui' : 'Non';
                return `<td>${value}</td>`;
            }).join('')}</tr>`
        ).join('');

        const printContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>${title}</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { 
                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                        padding: 20px;
                        font-size: 12px;
                    }
                    .header { 
                        display: flex; 
                        justify-content: space-between; 
                        align-items: center;
                        margin-bottom: 20px;
                        padding-bottom: 10px;
                        border-bottom: 2px solid #16a34a;
                    }
                    .title { 
                        font-size: 18px; 
                        font-weight: bold; 
                        color: #16a34a;
                    }
                    .date { 
                        font-size: 11px; 
                        color: #666;
                    }
                    table { 
                        width: 100%; 
                        border-collapse: collapse; 
                    }
                    th { 
                        background: #f0fdf4; 
                        padding: 8px 10px; 
                        text-align: left; 
                        font-weight: 600;
                        border-bottom: 2px solid #16a34a;
                        font-size: 11px;
                        text-transform: uppercase;
                        color: #166534;
                    }
                    td { 
                        padding: 8px 10px; 
                        border-bottom: 1px solid #e5e7eb;
                        vertical-align: top;
                    }
                    tr:nth-child(even) { background: #f9fafb; }
                    .footer {
                        margin-top: 20px;
                        padding-top: 10px;
                        border-top: 1px solid #e5e7eb;
                        font-size: 10px;
                        color: #9ca3af;
                        text-align: center;
                    }
                    @media print {
                        body { padding: 0; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <div class="title">${title}</div>
                    <div class="date">Imprimé le ${formatDateFull(new Date())}</div>
                </div>
                <table>
                    <thead>
                        <tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr>
                    </thead>
                    <tbody>
                        ${tableRows}
                    </tbody>
                </table>
                <div class="footer">
                    Total: ${data.length} élément(s) • Système de Transport
                </div>
            </body>
            </html>
        `;

        // Open print window
        const printWindow = window.open('', '_blank');
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.focus();

        // Wait for content to load then print
        setTimeout(() => {
            printWindow.print();
            // Close window after printing (or user cancels)
            printWindow.onafterprint = () => printWindow.close();
        }, 250);
    };

    /**
     * Get nested value from object using dot notation
     */
    const getNestedValue = (obj, path) => {
        return path.split('.').reduce((current, key) => {
            return current && current[key] !== undefined ? current[key] : null;
        }, obj);
    };

    /**
     * Format date for filename
     */
    const formatDate = (date) => {
        return date.toISOString().split('T')[0];
    };

    /**
     * Format date for display
     */
    const formatDateFull = (date) => {
        return date.toLocaleDateString('fr-FR', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    return {
        exportToExcel,
        exportToCsv,
        printList
    };
}
