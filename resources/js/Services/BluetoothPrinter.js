import { i18n } from '@/i18n.js';

class BluetoothPrinter {
    constructor() {
        this.device = null;
        this.characteristic = null;
        this.connected = false;

        this._writeLock = Promise.resolve();
        this._disconnectHandler = null;

        this.ESC = '\x1B';
        this.GS = '\x1D';
        this.INIT = this.ESC + '@';
        this.ALIGN_LEFT = this.ESC + 'a' + '\x00';
        this.ALIGN_CENTER = this.ESC + 'a' + '\x01';
        this.ALIGN_RIGHT = this.ESC + 'a' + '\x02';
        this.BOLD_ON = this.ESC + 'E' + '\x01';
        this.BOLD_OFF = this.ESC + 'E' + '\x00';
        this.SIZE_NORMAL = this.GS + '!' + '\x00';
        this.SIZE_DOUBLE = this.GS + '!' + '\x11';
        this.SIZE_LARGE = this.GS + '!' + '\x22';
        this.SIZE_TRIPLE = this.GS + '!' + '\x33';
        this.CUT_PAPER = this.GS + 'V' + '\x41' + '\x00';
        this.LINE_FEED = '\n';
    }

    isSupported() {
        return 'bluetooth' in navigator;
    }

    _onDisconnected() {
        this.connected = false;
        this.characteristic = null;
        if (this._onDisconnectCallback) {
            this._onDisconnectCallback();
        }
    }

    _registerDisconnectHandler() {
        this._unregisterDisconnectHandler();
        if (!this.device) return;
        this._disconnectHandler = () => this._onDisconnected();
        this.device.addEventListener('gattserverdisconnected', this._disconnectHandler);
    }

    _unregisterDisconnectHandler() {
        if (this.device && this._disconnectHandler) {
            this.device.removeEventListener('gattserverdisconnected', this._disconnectHandler);
        }
        this._disconnectHandler = null;
    }

    setDisconnectCallback(cb) {
        this._onDisconnectCallback = typeof cb === 'function' ? cb : null;
    }

    async connect() {
        if (!this.isSupported()) {
            throw new Error(i18n.global.t('service.bluetooth.unsupported'));
        }

        try {
            this.device = await navigator.bluetooth.requestDevice({
                acceptAllDevices: true,
                optionalServices: [
                    '000018f0-0000-1000-8000-00805f9b34fb',
                    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
                    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
                ]
            });

            this._registerDisconnectHandler();

            const server = await this.device.gatt.connect();

            const services = await server.getPrimaryServices();

            for (const service of services) {
                try {
                    const characteristics = await service.getCharacteristics();
                    for (const char of characteristics) {
                        if (char.properties.write || char.properties.writeWithoutResponse) {
                            this.characteristic = char;
                            this.connected = true;
                            localStorage.setItem('bluetooth_printer_id', this.device.id);
                            return true;
                        }
                    }
                } catch (e) {
                    continue;
                }
            }

            throw new Error(i18n.global.t('service.bluetooth.no_write_characteristic'));

        } catch (error) {
            this._unregisterDisconnectHandler();
            this.device = null;
            this.characteristic = null;
            throw error;
        }
    }

    async restoreAuthorizedDevice() {
        if (!this.isSupported() || typeof navigator.bluetooth.getDevices !== 'function') {
            return false;
        }

        const devices = await navigator.bluetooth.getDevices();
        const storedDeviceId = localStorage.getItem('bluetooth_printer_id');
        const authorizedDevice = devices.find(device => device.id === storedDeviceId)
            || devices[0]
            || null;

        if (!authorizedDevice) return false;

        this._unregisterDisconnectHandler();
        this.device = authorizedDevice;
        this._registerDisconnectHandler();

        return this.reconnect();
    }

    async reconnect() {
        if (!this.device) return false;

        try {
            this._registerDisconnectHandler();
            const server = await this.device.gatt.connect();

            const services = await server.getPrimaryServices();

            for (const service of services) {
                try {
                    const characteristics = await service.getCharacteristics();
                    for (const char of characteristics) {
                        if (char.properties.write || char.properties.writeWithoutResponse) {
                            this.characteristic = char;
                            this.connected = true;
                            return true;
                        }
                    }
                } catch (e) {
                    continue;
                }
            }

            return false;
        } catch (error) {
            this.connected = false;
            this.characteristic = null;
            return false;
        }
    }

    disconnect() {
        this._unregisterDisconnectHandler();
        if (this.device && this.device.gatt.connected) {
            this.device.gatt.disconnect();
        }
        this.connected = false;
        this.device = null;
        this.characteristic = null;
    }

    async send(data) {
        if (!this.device) throw new Error(i18n.global.t('service.bluetooth.no_device_connected'));
        if (!this.device.gatt.connected) throw new Error(i18n.global.t('service.bluetooth.device_disconnected'));
        if (!this.characteristic) throw new Error(i18n.global.t('service.bluetooth.no_write_characteristic_short'));

        const encoder = new TextEncoder();
        const encoded = encoder.encode(data);
        const chunkSize = 512;

        for (let i = 0; i < encoded.length; i += chunkSize) {
            const chunk = encoded.slice(i, i + chunkSize);
            const writeOperation = this._writeLock
                .catch(() => undefined)
                .then(async () => {
                    if (!this.device?.gatt?.connected || !this.characteristic) {
                        throw new Error(i18n.global.t('service.bluetooth.device_disconnected_while_printing'));
                    }
                    if (this.characteristic.properties.writeWithoutResponse) {
                        await this.characteristic.writeValueWithoutResponse(chunk);
                    } else {
                        await this.characteristic.writeValue(chunk);
                    }
                });
            this._writeLock = writeOperation;
            await writeOperation;

            await new Promise(resolve => setTimeout(resolve, 50));
        }
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

    wrap(value, width = 32) {
        const words = this.stripAccents(value).replace(/\s+/g, ' ').trim().split(' ').filter(Boolean);
        const lines = [];
        let currentLine = '';

        words.forEach(word => {
            if (word.length > width) {
                if (currentLine) lines.push(currentLine);
                for (let offset = 0; offset < word.length; offset += width) {
                    lines.push(word.slice(offset, offset + width));
                }
                currentLine = '';
                return;
            }

            const candidate = currentLine ? `${currentLine} ${word}` : word;
            if (candidate.length > width) {
                lines.push(currentLine);
                currentLine = word;
            } else {
                currentLine = candidate;
            }
        });

        if (currentLine) lines.push(currentLine);
        return lines;
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

    async printTicket(ticketData, settings) {
        let commands = '';
        const width = 32;
        const rawQrData = settings.qr_code_base_url && ticketData.qr_code && !String(ticketData.qr_code).startsWith('TIKETI|')
            ? `${settings.qr_code_base_url}${ticketData.qr_code}`
            : ticketData.qr_code;
        const qrData = this.stripAccents(rawQrData).slice(0, 180);

        commands += this.INIT;
        commands += this.ALIGN_CENTER;
        commands += this.SIZE_DOUBLE;
        commands += this.BOLD_ON;
        commands += `${this.fit(settings.company_name || 'TEST TRANSPORT', 16)}\n`;
        commands += this.BOLD_OFF;
        commands += this.SIZE_NORMAL;

        if (settings.phone_numbers && settings.phone_numbers.length > 0) {
            settings.phone_numbers.slice(0, 2).forEach(phone => {
                commands += `${this.fit(phone, width)}\n`;
            });
        }

        commands += this.line('=', width);

        commands += this.SIZE_NORMAL;
        commands += this.BOLD_ON;
        commands += `${this.fit(i18n.global.t('service.bluetooth.print.ticket_no'), width)}\n`;
        commands += `${this.fit(ticketData.ticket_number, width)}\n`;
        commands += this.BOLD_OFF;

        commands += this.line('-', width);
        commands += this.ALIGN_LEFT;
        commands += this.pair(i18n.global.t('service.bluetooth.print.departure'), ticketData.from_stop, width);
        commands += this.ALIGN_CENTER;
        commands += this.BOLD_ON;
        commands += `${this.fit(ticketData.transfer_stop ? i18n.global.t('service.bluetooth.print.final_destination') : i18n.global.t('service.bluetooth.print.destination'), width)}\n`;
        commands += this.SIZE_DOUBLE;
        this.wrap(ticketData.to_stop, 16).slice(0, 2).forEach(line => {
            commands += `${line}\n`;
        });
        commands += this.SIZE_NORMAL;
        commands += this.BOLD_OFF;

        commands += this.ALIGN_LEFT;
        if (ticketData.transfer_stop) {
            commands += this.pair(i18n.global.t('service.bluetooth.print.transfer'), ticketData.transfer_stop, width);
        }
        commands += this.line('-', width);
        commands += this.pair(i18n.global.t('service.bluetooth.print.departure_date'), ticketData.date, width);
        commands += this.pair(i18n.global.t('service.bluetooth.print.vehicle'), ticketData.vehicle_number, width);

        commands += this.line('-', width);
        commands += this.ALIGN_CENTER;
        commands += this.BOLD_ON;
        commands += this.SIZE_DOUBLE;
        commands += this.pair(
            i18n.global.t('service.bluetooth.print.seat', { seat: ticketData.seat_number }),
            i18n.global.t('service.bluetooth.print.time', { time: ticketData.time }),
            16
        );
        commands += this.BOLD_OFF;
        commands += this.SIZE_NORMAL;
        commands += this.BOLD_ON;
        commands += this.SIZE_DOUBLE;
        commands += `${this.fit(ticketData.price, 12)} FCFA\n`;
        commands += this.BOLD_OFF;
        commands += this.SIZE_NORMAL;

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
            commands += this.GS + '(k\x03\x00\x31\x43\x04';
            commands += this.GS + '(k\x03\x00\x31\x45\x30';

            const qrLength = qrData.length + 3;
            const pL = qrLength & 0xFF;
            const pH = (qrLength >> 8) & 0xFF;
            commands += this.GS + '(k' + String.fromCharCode(pL, pH) + '\x31\x50\x30' + qrData;

            commands += this.GS + '(k\x03\x00\x31\x51\x30';
            commands += this.LINE_FEED;
        }

        commands += this.ALIGN_CENTER;
        if (settings.footer_messages && settings.footer_messages.length > 0) {
            settings.footer_messages.slice(0, 2).forEach(message => {
                commands += `${this.fit(message, width)}\n`;
            });
        }

        if (settings.baggage_policy_message) {
            commands += this.ALIGN_LEFT;
            this.wrap(`1. ${settings.baggage_policy_message}`, width).forEach(line => {
                commands += `${line}\n`;
            });
            if (settings.baggage_policy_message_2) {
                this.wrap(`2. ${settings.baggage_policy_message_2}`, width).forEach(line => {
                    commands += `${line}\n`;
                });
            }
            commands += this.ALIGN_CENTER;
        }

        commands += this.fit(ticketData.timestamp, width) + '\n';
        commands += this.LINE_FEED;
        commands += this.LINE_FEED;
        commands += this.LINE_FEED;

        commands += this.CUT_PAPER;

        await this.send(commands);
    }

    getStatus() {
        return {
            supported: this.isSupported(),
            connected: this.connected && !!(this.device?.gatt?.connected),
            deviceName: this.device ? this.device.name : null
        };
    }
}

export default BluetoothPrinter;
