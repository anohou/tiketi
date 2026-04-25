<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

class GenerateUserGuide extends Command
{
    protected $signature = 'app:generate-guide';

    protected $description = 'Génère le Guide Utilisateur en PDF pour les compagnies';

    public function handle()
    {
        $this->info('Génération du Guide Utilisateur PDF...');

        try {
            $pdf = Pdf::loadView('pdf.user_guide');

            $path = public_path('Guide_Utilisateur_TIKETI.pdf');
            $pdf->save($path);

            $this->info("✅ Guide généré avec succès dans : $path");
        } catch (\Exception $e) {
            $this->error('Erreur lors de la génération du PDF : '.$e->getMessage());

            return 1;
        }

        return 0;
    }
}
