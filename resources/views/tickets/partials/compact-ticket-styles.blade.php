<style>
    @page {
        margin: 0;
        size: 80mm 200mm;
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 7mm 5mm 10mm;
        font-size: 12px;
        line-height: 1.2;
        color: #000;
        background: #fff;
    }

    .ticket {
        width: 70mm;
        max-width: 70mm;
        margin: 0 auto;
        page-break-inside: avoid;
    }

    .ticket + .ticket {
        page-break-before: always;
    }

    .top-row {
        display: table;
        width: 100%;
        table-layout: fixed;
        margin-bottom: 4mm;
    }

    .company-block,
    .qr-block {
        display: table-cell;
        vertical-align: top;
    }

    .company-block {
        width: 45mm;
        padding-right: 3mm;
    }

    .company-name {
        font-size: 18px;
        line-height: 1;
        font-weight: 800;
        letter-spacing: 0;
        margin-bottom: 1.5mm;
    }

    .company-logo-line {
        display: inline-block;
        min-width: 18mm;
        padding-bottom: 1mm;
        border-bottom: 2px solid #000;
    }

    .company-details {
        font-size: 10.5px;
        line-height: 1.25;
    }

    .qr-block {
        width: 25mm;
        text-align: right;
    }

    .qr-code {
        display: inline-block;
        width: 22mm;
        height: 22mm;
        overflow: hidden;
        line-height: 0;
    }

    .qr-code svg {
        display: block;
        width: 22mm !important;
        height: 22mm !important;
        max-width: 22mm !important;
        max-height: 22mm !important;
    }

    .ticket-number-box {
        border: 2px solid #000;
        padding: 2.5mm 2mm;
        text-align: center;
        margin-bottom: 2mm;
    }

    .ticket-label {
        font-size: 10.5px;
        font-weight: 800;
        text-decoration: underline;
        margin-bottom: 1.5mm;
    }

    .ticket-value {
        font-size: 21px;
        font-weight: 900;
        line-height: 1;
    }

    .cc-box {
        border: 2px solid #000;
        padding: 1mm 2mm;
        margin-bottom: 2mm;
        font-weight: 800;
        font-size: 12px;
    }

    .journey-box {
        border: 2px solid #000;
        padding: 2mm;
        margin-bottom: 4mm;
    }

    .destination-panel {
        border: 2px solid #000;
        padding: 2mm;
        margin-bottom: 2mm;
        text-align: center;
    }

    .destination-label {
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        margin-bottom: 1mm;
    }

    .destination-name {
        font-size: 18px;
        line-height: 1.05;
        font-weight: 900;
        text-transform: uppercase;
    }

    .route-lines {
        font-size: 11px;
        line-height: 1.25;
        margin-bottom: 2mm;
    }

    .route-lines strong {
        font-weight: 800;
    }

    .info-grid {
        display: table;
        width: 100%;
        border: 2px solid #000;
        table-layout: fixed;
        margin-bottom: 3mm;
    }

    .info-cell {
        display: table-cell;
        padding: 2mm 1mm;
        text-align: center;
        vertical-align: middle;
        font-size: 13px;
        font-weight: 800;
    }

    .info-cell:first-child {
        border-right: 2px solid #000;
        width: 68%;
    }

    .summary-row {
        display: table;
        width: 100%;
        table-layout: fixed;
        margin-bottom: 3mm;
    }

    .summary-cell {
        display: table-cell;
        vertical-align: middle;
        font-size: 14px;
        font-weight: 800;
    }

    .summary-cell.center {
        text-align: center;
    }

    .summary-cell.right {
        text-align: right;
    }

    .seat-pill {
        display: inline-block;
        min-width: 12mm;
        border: 2px solid #000;
        border-radius: 8mm;
        padding: 1.5mm 3mm;
        font-size: 17px;
        line-height: 1;
        text-align: center;
        font-weight: 900;
    }

    .zone-line {
        text-align: center;
        font-size: 16px;
        font-weight: 900;
        text-transform: uppercase;
        margin-bottom: 1mm;
    }

    .footer {
        font-size: 9.5px;
        line-height: 1.25;
        margin-top: 3mm;
        padding-bottom: 8mm;
    }

    .footer-note {
        margin-bottom: 2mm;
        font-weight: 700;
    }

    .disclaimer {
        font-size: 8.5px;
        text-align: justify;
    }

    .timestamp {
        text-align: center;
        font-size: 10px;
        margin-top: 5mm;
    }
</style>
