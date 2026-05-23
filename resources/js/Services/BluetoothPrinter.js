/**
 * Bluetooth Thermal Printer Service
 * Handles connection and printing to ESC/POS compatible Bluetooth thermal printers
 */

class BluetoothPrinter {
    constructor() {
        this.device = null;
        this.characteristic = null;
        this.connected = false;

        // ESC/POS Commands
        this.ESC = '\x1B';
        this.GS = '\x1D';
        this.INIT = this.ESC + '@'; // Initialize printer
        this.ALIGN_LEFT = this.ESC + 'a' + '\x00';
        this.ALIGN_CENTER = this.ESC + 'a' + '\x01';
        this.ALIGN_RIGHT = this.ESC + 'a' + '\x02';
        this.BOLD_ON = this.ESC + 'E' + '\x01';
        this.BOLD_OFF = this.ESC + 'E' + '\x00';
        this.SIZE_NORMAL = this.GS + '!' + '\x00';
        this.SIZE_DOUBLE = this.GS + '!' + '\x11'; // Double width and height
        this.SIZE_LARGE = this.GS + '!' + '\x22';
        this.SIZE_TRIPLE = this.GS + '!' + '\x33'; // Triple size
        this.CUT_PAPER = this.GS + 'V' + '\x41' + '\x00'; // Cut paper
        this.LINE_FEED = '\n';
    }

    /**
     * Check if Web Bluetooth is supported
     */
    isSupported() {
        return 'bluetooth' in navigator;
    }

    /**
     * Connect to a Bluetooth printer
     */
    async connect() {
        if (!this.isSupported()) {
            throw new Error('Web Bluetooth is not supported in this browser');
        }

        try {
            // Request Bluetooth device - accept all services to find the printer
            this.device = await navigator.bluetooth.requestDevice({
                acceptAllDevices: true,
                optionalServices: [
                    '000018f0-0000-1000-8000-00805f9b34fb', // Common printer service
                    '49535343-fe7d-4ae5-8fa9-9fafd205e455', // Serial Port service
                    'e7810a71-73ae-499d-8c15-faa9aef0c3f2', // Another common service
                ]
            });

            // Connect to GATT server
            const server = await this.device.gatt.connect();

            // Try to find a writable characteristic
            const services = await server.getPrimaryServices();

            for (const service of services) {
                try {
                    const characteristics = await service.getCharacteristics();
                    for (const char of characteristics) {
                        // Look for a writable characteristic
                        if (char.properties.write || char.properties.writeWithoutResponse) {
                            this.characteristic = char;
                            this.connected = true;
                            console.log('Found writable characteristic:', char.uuid);

                            // Store device ID for auto-reconnect
                            localStorage.setItem('bluetooth_printer_id', this.device.id);

                            return true;
                        }
                    }
                } catch (e) {
                    // Skip services we can't access
                    continue;
                }
            }

            throw new Error('No writable characteristic found on this device');

        } catch (error) {
            console.error('Bluetooth connection error:', error);
            throw error;
        }
    }

    /**
     * Disconnect from printer
     */
    disconnect() {
        if (this.device && this.device.gatt.connected) {
            this.device.gatt.disconnect();
        }
        this.connected = false;
        this.device = null;
        this.characteristic = null;
    }

    /**
     * Send data to printer
     */
    async send(data) {
        if (!this.connected || !this.characteristic) {
            throw new Error('Printer not connected');
        }

        console.log('Sending data to printer, length:', data.length);
        const encoder = new TextEncoder();
        const encoded = encoder.encode(data);
        console.log('Encoded data length:', encoded.length);

        // Split data into chunks (Bluetooth has size limits)
        const chunkSize = 512;
        for (let i = 0; i < encoded.length; i += chunkSize) {
            const chunk = encoded.slice(i, i + chunkSize);
            console.log(`Sending chunk ${Math.floor(i / chunkSize) + 1}, size:`, chunk.length);

            try {
                if (this.characteristic.properties.writeWithoutResponse) {
                    await this.characteristic.writeValueWithoutResponse(chunk);
                } else {
                    await this.characteristic.writeValue(chunk);
                }
                console.log('Chunk sent successfully');
            } catch (error) {
                console.error('Error sending chunk:', error);
                throw error;
            }

            // Small delay between chunks
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        console.log('All data sent to printer');
    }

    stripAccents(value) {
        return String(value ?? '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^\x20-\x7E\n]/g, '');
    }

    fit(value, width = 32) {
        const text = this.stripAccents(value).replace(/\s+/g, ' ').trim();
        return text.length > width ? text.slice(0, Math.max(0, width - 1)) + '.' : text;
    }

    line(char = '-', width = 32) {
        return char.repeat(width).slice(0, width) + '\n';
    }

    pair(label, value, width = 32) {
        const left = this.fit(label, 12);
        const right = this.fit(value, width - left.length - 1);
        const spaces = Math.max(1, width - left.length - right.length);

        return left + ' '.repeat(spaces) + right + '\n';
    }

    /**
     * Print a ticket
     */
    async printTicket(ticketData, settings) {
        let commands = '';
        const width = 32;
        const rawQrData = settings.qr_code_base_url && ticketData.qr_code && !String(ticketData.qr_code).startsWith('TIKETI|')
            ? `${settings.qr_code_base_url}${ticketData.qr_code}`
            : ticketData.qr_code;
        const qrData = this.stripAccents(rawQrData).slice(0, 180);

        // Initialize printer
        commands += this.INIT;

        // Compact header / text logo
        commands += this.ALIGN_CENTER;
        commands += this.SIZE_DOUBLE;
        commands += this.BOLD_ON;
        commands += `${this.fit(settings.company_name || 'TSR CI', 16)}\n`;
        commands += this.BOLD_OFF;
        commands += this.SIZE_NORMAL;

        if (settings.phone_numbers && settings.phone_numbers.length > 0) {
            settings.phone_numbers.slice(0, 2).forEach(phone => {
                commands += `${this.fit(phone, width)}\n`;
            });
        }

        commands += this.line('=', width);

        // Ticket number
        commands += this.SIZE_NORMAL;
        commands += this.BOLD_ON;
        commands += `${this.fit(ticketData.ticket_number, width)}\n`;
        commands += this.BOLD_OFF;

        commands += this.line('-', width);
        commands += this.ALIGN_CENTER;
        commands += this.BOLD_ON;
        commands += `${this.fit('DESTINATION PASSAGER', width)}\n`;
        commands += this.SIZE_DOUBLE;
        commands += `${this.fit(ticketData.to_stop, 16)}\n`;
        commands += this.SIZE_NORMAL;
        commands += this.BOLD_OFF;

        commands += this.ALIGN_LEFT;
        commands += this.line('-', width);
        commands += this.pair('DEPART', ticketData.from_stop, width);
        commands += this.pair('ARRIVEE', ticketData.to_stop, width);
        commands += this.pair('DATE', `${ticketData.date} ${ticketData.time}`, width);
        commands += this.pair('VEHICULE', ticketData.vehicle_number, width);

        commands += this.line('-', width);
        commands += this.ALIGN_CENTER;
        commands += this.BOLD_ON;
        commands += this.SIZE_DOUBLE;
        commands += `PLACE ${ticketData.seat_number}  Z${ticketData.boarding_group || '1'}\n`;
        commands += this.BOLD_OFF;
        commands += this.SIZE_NORMAL;
        commands += this.BOLD_ON;
        commands += `${this.fit(ticketData.price, 12)} FCFA\n`;
        commands += this.BOLD_OFF;

        commands += this.line('-', width);

        const shouldPrintQrCode = settings.print_qr_code || (
            settings.okohi_enabled &&
            settings.okohi_host &&
            settings.okohi_company_id &&
            settings.okohi_loyalty_type &&
            settings.okohi_integration_key
        );

        if (shouldPrintQrCode && qrData) {
            commands += this.ALIGN_CENTER;
            commands += this.GS + '(k\x03\x00\x31\x43\x04'; // Set size (4)
            commands += this.GS + '(k\x03\x00\x31\x45\x30'; // Set error correction (L)

            const qrLength = qrData.length + 3;
            const pL = qrLength & 0xFF;
            const pH = (qrLength >> 8) & 0xFF;
            commands += this.GS + '(k' + String.fromCharCode(pL, pH) + '\x31\x50\x30' + qrData;

            // Print QR code
            commands += this.GS + '(k\x03\x00\x31\x51\x30';
            commands += this.LINE_FEED;
        }

        commands += this.ALIGN_CENTER;
        if (settings.footer_messages && settings.footer_messages.length > 0) {
            settings.footer_messages.slice(0, 2).forEach(message => {
                commands += `${this.fit(message, width)}\n`;
            });
        }

        commands += this.fit(ticketData.timestamp, width) + '\n';
        commands += this.LINE_FEED;
        commands += this.LINE_FEED;
        commands += this.LINE_FEED;

        // Cut paper
        commands += this.CUT_PAPER;

        // Send to printer
        await this.send(commands);
    }

    /**
     * Get printer status
     */
    getStatus() {
        return {
            supported: this.isSupported(),
            connected: this.connected,
            deviceName: this.device ? this.device.name : null
        };
    }
}

export default BluetoothPrinter;
