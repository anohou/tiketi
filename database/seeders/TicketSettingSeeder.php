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
                'cc_label' => null,
                'footer_messages' => ['Valable pour ce voyage', 'Non remboursable'],
                'baggage_policy_message' => "La perte des bagages transportes doit faire l'objet d'une declaration aux agences de la societe.",
                'qr_code_base_url' => null,
                'print_qr_code' => false,
                'okohi_enabled' => false,
                'okohi_host' => null,
                'okohi_company_id' => null,
                'okohi_loyalty_type' => 'points',
                'okohi_integration_key' => null,
            ]
        );
    }
}
