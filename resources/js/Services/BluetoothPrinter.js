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

    /**
     * Print a ticket
     */
    async printTicket(ticketData, settings) {
        let commands = '';

        // Initialize printer
        commands += this.INIT;

        // Header - Company Name (from settings)
        commands += this.ALIGN_CENTER;
        commands += this.SIZE_DOUBLE;
        commands += this.BOLD_ON;
        commands += `${settings.company_name}\n`;
        commands += this.BOLD_OFF;
        commands += this.SIZE_NORMAL;

        // Phone numbers (from settings - dynamic array)
        commands += this.ALIGN_CENTER;
        if (settings.phone_numbers && settings.phone_numbers.length > 0) {
            settings.phone_numbers.forEach(phone => {
                commands += `Tel: ${phone}\n`;
            });
        }
        commands += this.LINE_FEED;

        // Separator (32 chars for 58mm)
        commands += '--------------------------------\n';

        // Ticket Number
        commands += this.ALIGN_CENTER;
        commands += this.SIZE_DOUBLE;
        commands += this.BOLD_ON;
        commands += `No: ${ticketData.ticket_number}\n`;
        commands += this.BOLD_OFF;
        commands += this.SIZE_NORMAL;
        commands += this.LINE_FEED;

        // Route name
        commands += this.ALIGN_LEFT;
        commands += this.BOLD_ON;
        commands += `${ticketData.route_name}\n`;
        commands += this.BOLD_OFF;
        commands += this.LINE_FEED;

        // Trajet - Compact format on same lines
        commands += this.BOLD_ON;
        commands += 'Trajet:\n';
        commands += this.BOLD_OFF;
        commands += `Depart: ${ticketData.from_stop}\n`;
        commands += `Arrive: ${ticketData.to_stop}\n`;
        commands += this.LINE_FEED;

        // Date and Time - One line
        commands += `${ticketData.date}   ${ticketData.time}\n`;
        commands += this.LINE_FEED;

        // Seat Number - Double size
        commands += this.ALIGN_CENTER;
        commands += this.SIZE_DOUBLE;
        commands += this.BOLD_ON;
        commands += `Siege: ${ticketData.seat_number}\n`;
        commands += `Zone: ${ticketData.boarding_group || '1'}\n`;
        commands += this.BOLD_OFF;
        commands += this.SIZE_NORMAL;
        commands += this.LINE_FEED;

        // Price - Large size
        commands += this.ALIGN_CENTER;
        commands += this.SIZE_LARGE;
        commands += this.BOLD_ON;
        commands += `${ticketData.price} FCFA\n`;
        commands += this.BOLD_OFF;
        commands += this.SIZE_NORMAL;
        commands += this.LINE_FEED;

        // Vehicle Number
        commands += this.ALIGN_CENTER;
        commands += this.BOLD_ON;
        commands += `Vehicule: ${ticketData.vehicle_number}\n`;
        commands += this.BOLD_OFF;
        commands += this.LINE_FEED;

        // Separator
        commands += this.ALIGN_LEFT;
        commands += '--------------------------------\n';

        // QR Code (if enabled in settings and ticket has qr_code)
        if (settings.print_qr_code && ticketData.qr_code) {
            commands += this.ALIGN_CENTER;
            // QR Code command: GS ( k - Model 2, Size 6, Error correction L
            commands += this.GS + '(k\x04\x00\x31\x41\x32\x00'; // Set model
            commands += this.GS + '(k\x03\x00\x31\x43\x06'; // Set size (6)
            commands += this.GS + '(k\x03\x00\x31\x45\x30'; // Set error correction (L)

            // Store QR data (use base URL if provided)
            const qrData = settings.qr_code_base_url
                ? `${settings.qr_code_base_url}${ticketData.qr_code}`
                : ticketData.qr_code;
            const qrLength = qrData.length + 3;
            const pL = qrLength & 0xFF;
            const pH = (qrLength >> 8) & 0xFF;
            commands += this.GS + '(k' + String.fromCharCode(pL, pH) + '\x31\x50\x30' + qrData;

            // Print QR code
            commands += this.GS + '(k\x03\x00\x31\x51\x30';
            commands += this.LINE_FEED;
        }

        // Footer - Dynamic messages from settings
        commands += this.ALIGN_CENTER;
        if (settings.footer_messages && settings.footer_messages.length > 0) {
            settings.footer_messages.forEach(message => {
                commands += `${message}\n`;
            });
        }
        commands += this.LINE_FEED;

        // Timestamp
        commands += this.ALIGN_CENTER;
        commands += `${ticketData.timestamp}\n`;
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
