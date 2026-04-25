<?php

namespace Database\Seeders;

use App\Models\TicketSetting;
use Illuminate\Database\Seeder;

class TicketSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        TicketSetting::firstOrCreate(
            ['id' => 1],
            [
                'company_name' => 'TSR CI',
                'phone_numbers' => ['+225 XX XX XX XX XX', '+225 XX XX XX XX XX'],
                'footer_messages' => ['Valable pour ce voyage', 'Non remboursable'],
                'qr_code_base_url' => null,
                'print_qr_code' => false,
            ]
        );
    }
}
