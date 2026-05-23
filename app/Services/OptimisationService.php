<?php

namespace App\Services;

use App\Models\RouteStopOrder;
use App\Models\Ticket;
use App\Models\Trip;
use Illuminate\Support\Collection;

class OptimisationService
{
    /**
     * Cache en mémoire des index d'arrêts par route
     * Évite les requêtes N+1 dans getStopIndex()
     *
     * @var array<string, array{map: array<string, int>, total: int}>
     */
    private array $stopIndexCache = [];

    /**
     * Obtient les suggestions de sièges optimaux pour un voyage et une destination
     *
     * @param  string  $tripId  ID du voyage
     * @param  string  $destinationStationId  ID de la gare de destination
     * @param  int  $maxSuggestions  Nombre maximum de suggestions (défaut: 5)
     * @param  string|null  $boardingStationId  ID de la gare d'embarquement (pour le mode semi-intelligent)
     * @return array Tableau de suggestions avec seat_number, score et reason
     */
    public function getSuggestedSeats(string $tripId, string $destinationStationId, int $maxSuggestions = 5, ?string $boardingStationId = null): array
    {
        $trip = Trip::with(['vehicle.vehicleType', 'route.routeStopOrders'])->findOrFail($tripId);

        // Si le voyage est en mode "bulk", retourner un tableau vide (pas de suggestions)
        if ($trip->isBulk()) {
            return [];
        }

        // Précharger tous les index d'arrêts de la route en une seule requête
        $this->preloadStopIndices($trip->route_id);

        // Détecter si le voyage est inversé par rapport à la direction par défaut de la route
        $isReversedTrip = app(TripSegmentService::class)->isReversed($trip);

        $tickets = Ticket::where('trip_id', $tripId)
            ->where('status', '!=', 'cancelled')
            ->with(['toStation', 'fromStation'])
            ->get();

        // Récupérer la configuration du véhicule
        $vehicleType = $trip->vehicle->vehicleType;
        $totalSeats = $vehicleType->seat_count;
        $doorPositions = $vehicleType->door_positions ?? [];

        // Parser le plan de sièges pour obtenir les types et voisins
        $seatMapService = app(SeatMapService::class);
        $fullSeatMap = $seatMapService->ensureGrid($vehicleType->seat_map ?? [], [
            'seat_count' => $totalSeats,
            'seat_configuration' => $vehicleType->seat_configuration ?? '2+2',
            'door_positions' => $doorPositions,
            'last_row_seats' => $vehicleType->last_row_seats ?? 5,
        ]);

        $seatMapInfo = $this->parseSeatMap($fullSeatMap);

        // Calculer l'index de l'arrêt de destination (en tenant compte de la direction)
        $destinationIndex = $this->getStopIndex($trip->route_id, $destinationStationId, $isReversedTrip);

        // Index de l'arrêt d'embarquement (pour le mode semi-intelligent)
        $boardingIndex = $boardingStationId
            ? $this->getStopIndex($trip->route_id, $boardingStationId, $isReversedTrip)
            : 0;

        $occupiedSeatsData = $tickets
            ->filter(function (Ticket $ticket) use ($trip, $isReversedTrip, $boardingIndex, $destinationIndex) {
                if (! $trip->isSemiIntelligent()) {
                    return true;
                }

                $ticketStart = $this->getStopIndex($trip->route_id, $ticket->from_station_id, $isReversedTrip);
                $ticketEnd = $this->getStopIndex($trip->route_id, $ticket->to_station_id, $isReversedTrip);

                return $ticketStart < $destinationIndex && $boardingIndex < $ticketEnd;
            })
            ->keyBy('seat_number');

        // Nombre total d'arrêts sur la route
        $totalStops = $this->stopIndexCache[$trip->route_id]['total'];

        $remainingStops = max(1, ($totalStops - 1) - $boardingIndex);
        $segmentStopDistance = max(1, $destinationIndex - $boardingIndex);

        // Ratio de distance du segment vendu depuis la gare d'embarquement.
        // 0 = arrêt le plus proche, 1 = terminus depuis cette gare.
        $tripDistanceRatio = $remainingStops > 0
            ? ($segmentStopDistance - 1) / max(1, $remainingStops - 1)
            : 0;

        // Classifier le tronçon : le premier arrêt après le départ est toujours proche,
        // même sur une ligne à seulement trois gares où son ratio serait 50%.
        $troncon = 'long';
        if ($segmentStopDistance <= 1) {
            $troncon = 'short';
        } elseif ($destinationIndex >= ($totalStops - 1)) {
            $troncon = 'long';
        } elseif ($tripDistanceRatio < 0.40) {
            $troncon = 'short';
        } elseif ($tripDistanceRatio < 0.70) {
            $troncon = 'medium';
        }

        // Les zones de sièges doivent rester physiques (avant / milieu / arrière),
        // même si la route ne contient qu'une seule destination restante.
        $numZones = max(3, $remainingStops);
        if ($segmentStopDistance <= 1) {
            $targetZone = 1;
        } elseif ($destinationIndex >= ($totalStops - 1)) {
            $targetZone = $numZones;
        } else {
            $targetZone = min($numZones - 1, $segmentStopDistance);
        }

        // Déterminer les sièges disponibles selon le mode de réservation
        $availableSeats = [];

        if ($trip->isSemiIntelligent()) {
            // MODE SEMI-INTELLIGENT : les sièges peuvent être réutilisés
            // si l'occupant actuel descend avant l'arrêt d'embarquement du nouveau passager
            for ($seatNumber = 1; $seatNumber <= $totalSeats; $seatNumber++) {
                if (! $occupiedSeatsData->has($seatNumber)) {
                    $availableSeats[] = $seatNumber;
                } else {
                    $currentOccupant = $occupiedSeatsData[$seatNumber];
                    $occupantDestIndex = $this->getStopIndex($trip->route_id, $currentOccupant->to_station_id, $isReversedTrip);
                    if ($occupantDestIndex <= $boardingIndex) {
                        $availableSeats[] = $seatNumber;
                    }
                }
            }
        } else {
            // MODE INTELLIGENT : seuls les sièges vraiment vides
            for ($seatNumber = 1; $seatNumber <= $totalSeats; $seatNumber++) {
                if (! $occupiedSeatsData->has($seatNumber)) {
                    $availableSeats[] = $seatNumber;
                }
            }
        }

        if ($troncon === 'short') {
            $frontZoneSeats = $this->filterSeatsByPhysicalZone($availableSeats, $seatMapInfo, 'front');
            if (! empty($frontZoneSeats)) {
                $availableSeats = $frontZoneSeats;
            }
        }

        // Obtenir l'ordre de préférence des zones selon le type de trajet
        $zonePreferences = $this->getZonePreferences($numZones, $troncon);

        // Pré-calculer les index de destination de tous les passagers occupés (évite N+1)
        $occupantDestIndices = [];
        foreach ($occupiedSeatsData as $seatNum => $ticket) {
            $occupantDestIndices[$seatNum] = $this->getStopIndex(
                $trip->route_id, $ticket->to_station_id, $isReversedTrip
            );
        }

        // Calculer les scores pour chaque siège disponible
        $seatScores = [];
        foreach ($availableSeats as $seatNumber) {
            $score = $this->calculateDynamicZoneScore(
                $seatNumber,
                $troncon,
                $targetZone,
                $destinationStationId,
                $numZones,
                $zonePreferences,
                $totalSeats,
                $seatMapInfo,
                $occupiedSeatsData,
                $occupantDestIndices,
                $destinationIndex,
                $segmentStopDistance
            );

            $seatScores[] = [
                'seat_number' => $seatNumber,
                'score' => $score['score'],
                'reason' => $score['reason'],
            ];
        }

        // Trier par score décroissant
        usort($seatScores, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // GROUPE / FAMILLE : si on cherche > 1 siège, trouver des groupes adjacents
        if ($maxSuggestions > 1) {
            return $this->findAdjacentGroups(
                $seatScores,
                $seatMapInfo,
                $maxSuggestions,
                $maxSuggestions
            );
        }

        return array_slice($seatScores, 0, $maxSuggestions);
    }

    /**
     * Trouve des groupes de sièges adjacents (même rangée) et retourne
     * le meilleur groupe trié par score moyen.
     *
     * @param  array  $seatScores  Scores individuels de chaque siège disponible
     * @param  array  $seatMapInfo  Informations parsed du plan de sièges
     * @param  int  $groupSize  Nombre de sièges par groupe
     * @param  int  $maxResults  Nombre maximum de sièges à retourner
     * @return array Sièges du meilleur groupe avec scores et raisons
     */
    private function findAdjacentGroups(array $seatScores, array $seatMapInfo, int $groupSize, int $maxResults = 5): array
    {
        $bestIndividualSeats = array_slice($seatScores, 0, $maxResults);
        $bestIndividualAvg = count($bestIndividualSeats) > 0
            ? array_sum(array_column($bestIndividualSeats, 'score')) / count($bestIndividualSeats)
            : 0;

        // Créer un lookup rapide des scores par numéro de siège
        $scoreMap = [];
        foreach ($seatScores as $s) {
            $scoreMap[$s['seat_number']] = $s;
        }

        // Regrouper les sièges disponibles par rangée
        $seatsByRow = [];
        foreach ($seatScores as $s) {
            $seatNum = $s['seat_number'];
            $info = $seatMapInfo['seats'][$seatNum] ?? null;
            if (! $info) {
                continue;
            }
            $row = $info['row'];
            $col = $info['col'];
            $seatsByRow[$row][] = ['seat_number' => $seatNum, 'col' => $col, 'score' => $s['score'], 'reason' => $s['reason']];
        }

        // Trouver tous les groupes adjacents possibles dans chaque rangée
        $groups = [];
        foreach ($seatsByRow as $row => $seats) {
            // Trier par colonne pour garantir l'adjacence
            usort($seats, fn ($a, $b) => $a['col'] <=> $b['col']);

            // Sliding window pour trouver des séquences adjacentes
            for ($i = 0; $i <= count($seats) - $groupSize; $i++) {
                $group = array_slice($seats, $i, $groupSize);

                // Vérifier l'adjacence : les colonnes doivent être consécutives
                // (en ignorant les couloirs/portes entre les colonnes)
                $isAdjacent = true;
                for ($j = 1; $j < count($group); $j++) {
                    $colDiff = $group[$j]['col'] - $group[$j - 1]['col'];
                    // Adjacent = colonnes consécutives (diff == 1), pas de couloir entre eux
                    if ($colDiff !== 1) {
                        $isAdjacent = false;
                        break;
                    }
                }

                if ($isAdjacent) {
                    $avgScore = array_sum(array_column($group, 'score')) / $groupSize;
                    $groups[] = [
                        'seats' => $group,
                        'avg_score' => $avgScore,
                        'row' => $row,
                    ];
                }
            }
        }

        if (empty($groups)) {
            // Pas de groupe adjacent trouvé : fallback sur les meilleurs sièges individuels
            return $bestIndividualSeats;
        }

        // Trier les groupes par score moyen décroissant
        usort($groups, fn ($a, $b) => $b['avg_score'] <=> $a['avg_score']);

        // Retourner les sièges du meilleur groupe
        $bestGroup = $groups[0];

        // Ne pas sacrifier la logique métier pour forcer l'adjacence.
        // Exemple: sur un autocar 2+2, 3 sièges réellement adjacents existent surtout
        // sur la dernière rangée; pour une destination proche, ils sont beaucoup moins
        // pertinents que les meilleurs sièges proches des portes.
        if ($bestGroup['avg_score'] < ($bestIndividualAvg - 250)) {
            return $bestIndividualSeats;
        }

        $result = [];
        foreach ($bestGroup['seats'] as $seat) {
            $result[] = [
                'seat_number' => $seat['seat_number'],
                'score' => $seat['score'],
                'reason' => $seat['reason'].' | Groupe adjacent',
            ];
        }

        return array_slice($result, 0, $maxResults);
    }

    private function filterSeatsByPhysicalZone(array $seatNumbers, array $seatMapInfo, string $zone): array
    {
        $rowsWithSeats = max(1, $seatMapInfo['rows_with_seats'] ?? 1);
        $frontLimit = max(1, (int) ceil($rowsWithSeats / 3));
        $rearStart = max(1, (int) floor(($rowsWithSeats * 2) / 3) + 1);

        return array_values(array_filter($seatNumbers, function (int $seatNumber) use ($seatMapInfo, $zone, $frontLimit, $rearStart) {
            $rowRank = $seatMapInfo['seats'][$seatNumber]['row_rank'] ?? null;
            if ($rowRank === null) {
                return false;
            }

            return match ($zone) {
                'front' => $rowRank <= $frontLimit,
                'rear' => $rowRank >= $rearStart,
                default => $rowRank > $frontLimit && $rowRank < $rearStart,
            };
        }));
    }

    /**
     * Précharge tous les index d'arrêts d'une route en une seule requête
     * Stocke le résultat en cache mémoire pour éviter les requêtes N+1
     */
    private function preloadStopIndices(string $routeId): void
    {
        if (isset($this->stopIndexCache[$routeId])) {
            return;
        }

        $route = \App\Models\Route::with('routeStopOrders')->find($routeId);
        $orderedStationIds = [];
        $addStation = function (?string $stationId) use (&$orderedStationIds): void {
            if ($stationId && ! in_array($stationId, $orderedStationIds, true)) {
                $orderedStationIds[] = $stationId;
            }
        };

        if ($route) {
            $addStation($route->origin_station_id);
            foreach (($route->routeStopOrders ?? collect())->sortBy('stop_index') as $order) {
                $addStation($order->station_id);
            }
            $addStation($route->destination_station_id);
        } else {
            RouteStopOrder::where('route_id', $routeId)
                ->orderBy('stop_index')
                ->get()
                ->each(fn ($order) => $addStation($order->station_id));
        }

        $this->stopIndexCache[$routeId] = [
            'map' => array_flip($orderedStationIds),
            'total' => count($orderedStationIds),
        ];
    }

    /**
     * Parse the seat map to determine seat types, neighbors and coordinates.
     * Calcule aussi les sièges de fenêtre adjacents pour chaque siège couloir
     * (nécessaire pour l'anti-blocage bidirectionnel).
     */
    private function parseSeatMap(array $seatMap): array
    {
        $info = [
            'seats' => [],
            'doors' => [],
        ];

        // Ensure we handle decks
        $decks = isset($seatMap['lower_deck']) || isset($seatMap['upper_deck'])
            ? $seatMap
            : ['lower_deck' => $seatMap];

        $globalRowOffset = 0;

        foreach ($decks as $deckKey => $deckMap) {
            foreach ($deckMap as $rowIndex => $row) {
                $actualRowIndex = $globalRowOffset + $rowIndex;
                $rowLength = count($row);

                foreach ($row as $colIndex => $cell) {
                    if (! isset($cell['type'])) {
                        continue;
                    }

                    if ($cell['type'] === 'door') {
                        $info['doors'][] = [
                            'row' => $actualRowIndex,
                            'col' => $colIndex,
                        ];

                        continue;
                    }

                    if ($cell['type'] !== 'seat') {
                        continue;
                    }

                    $seatNumber = (int) $cell['number'];
                    $type = 'middle';
                    $adjacentAisleSeats = [];
                    $adjacentWindowSeats = [];

                    // Vérifier le voisin gauche
                    $leftIsWall = ($colIndex === 0);
                    $leftIsAisle = false;
                    if (! $leftIsWall) {
                        $leftCell = $row[$colIndex - 1];
                        if (in_array($leftCell['type'], ['aisle', 'door', 'empty'])) {
                            $leftIsAisle = true;
                        }
                    }

                    // Vérifier le voisin droit
                    $rightIsWall = ($colIndex === $rowLength - 1);
                    $rightIsAisle = false;
                    if (! $rightIsWall) {
                        $rightCell = $row[$colIndex + 1];
                        if (in_array($rightCell['type'], ['aisle', 'door', 'empty'])) {
                            $rightIsAisle = true;
                        }
                    }

                    // Déterminer le type de siège
                    if ($leftIsWall || $rightIsWall) {
                        $type = 'window';
                    } elseif ($leftIsAisle || $rightIsAisle) {
                        $type = 'aisle';
                    }

                    // Trouver les sièges adjacents entre ce siège et le couloir
                    if ($type === 'window' || $type === 'middle') {
                        // Chercher vers le couloir dans la même rangée
                        for ($i = $colIndex - 1; $i >= 0; $i--) {
                            if ($row[$i]['type'] === 'aisle') {
                                break;
                            }
                            if ($row[$i]['type'] === 'seat') {
                                $adjacentAisleSeats[] = (int) $row[$i]['number'];
                            }
                        }
                        for ($i = $colIndex + 1; $i < $rowLength; $i++) {
                            if ($row[$i]['type'] === 'aisle') {
                                break;
                            }
                            if ($row[$i]['type'] === 'seat') {
                                $adjacentAisleSeats[] = (int) $row[$i]['number'];
                            }
                        }
                    }

                    // Pour les sièges couloir, trouver les sièges fenêtre adjacents
                    // (nécessaire pour vérifier si on bloquerait un passager fenêtre)
                    if ($type === 'aisle') {
                        // Chercher les sièges fenêtre du même côté (entre ce siège et le mur)
                        for ($i = $colIndex - 1; $i >= 0; $i--) {
                            if (in_array($row[$i]['type'], ['aisle', 'door', 'empty'])) {
                                break;
                            }
                            if ($row[$i]['type'] === 'seat') {
                                $adjacentWindowSeats[] = (int) $row[$i]['number'];
                            }
                        }
                        for ($i = $colIndex + 1; $i < $rowLength; $i++) {
                            if (in_array($row[$i]['type'], ['aisle', 'door', 'empty'])) {
                                break;
                            }
                            if ($row[$i]['type'] === 'seat') {
                                $adjacentWindowSeats[] = (int) $row[$i]['number'];
                            }
                        }
                    }

                    $info['seats'][$seatNumber] = [
                        'type' => $type,
                        'adjacent_aisle_seats' => array_unique($adjacentAisleSeats),
                        'adjacent_window_seats' => array_unique($adjacentWindowSeats),
                        'row' => $actualRowIndex,
                        'col' => $colIndex,
                        'deck' => $deckKey,
                    ];
                }
            }
            $globalRowOffset += count($deckMap) + 2; // Offset to visually separate decks
        }

        // Calculer le classement global de proximité aux portes
        $seatDistances = [];
        foreach ($info['seats'] as $seatNumber => $s) {
            $minDist = PHP_INT_MAX;
            foreach ($info['doors'] as $door) {
                $dist = abs($s['row'] - $door['row']) + abs($s['col'] - $door['col']);
                if ($dist < $minDist) {
                    $minDist = $dist;
                }
            }
            $seatDistances[$seatNumber] = $minDist;
        }

        // Trier par proximité (ascendant), puis par numéro de siège
        uksort($seatDistances, function ($a, $b) use ($seatDistances) {
            if ($seatDistances[$a] !== $seatDistances[$b]) {
                return $seatDistances[$a] <=> $seatDistances[$b];
            }

            return $a <=> $b;
        });

        $rank = 0;
        $info['proximity_ranking'] = [];
        foreach ($seatDistances as $seatNumber => $dist) {
            $rank++;
            $info['seats'][$seatNumber]['proximity_rank'] = $rank;
            $info['seats'][$seatNumber]['door_distance'] = $dist;
            $info['proximity_ranking'][] = $seatNumber;
        }

        $seatRows = array_values(array_unique(array_map(fn ($seat) => $seat['row'], $info['seats'])));
        sort($seatRows);
        $rowRanks = array_flip($seatRows);
        foreach ($info['seats'] as $seatNumber => $seat) {
            $info['seats'][$seatNumber]['row_rank'] = ($rowRanks[$seat['row']] ?? 0) + 1;
        }
        $info['rows_with_seats'] = count($seatRows);

        return $info;
    }

    /**
     * Obtient l'ordre de préférence des zones selon le type de trajet
     * Zone 1 = proche de la porte (avant), Zone N = fond du véhicule
     */
    private function getZonePreferences(int $numZones, string $troncon): array
    {
        $zones = range(1, $numZones);

        if ($troncon === 'long') {
            // Trajets longs : préférer le fond (zone N) vers l'avant (zone 1)
            return array_reverse($zones);
        } elseif ($troncon === 'short') {
            // Trajets courts : préférer l'avant (zone 1) vers le fond (zone N)
            return $zones;
        } else {
            // Trajets moyens : préférer les zones du milieu, puis s'étendre
            usort($zones, function ($a, $b) use ($numZones) {
                $mid = ($numZones + 1) / 2;
                $distA = abs($a - $mid);
                $distB = abs($b - $mid);

                if (abs($distA - $distB) < 0.001) {
                    return $a <=> $b;
                }

                return $distA <=> $distB;
            });

            return $zones;
        }
    }

    /**
     * Calcule le score d'un siège selon les zones dynamiques, la proximité aux portes,
     * l'anti-blocage bidirectionnel et le regroupement par destination
     */
    private function calculateDynamicZoneScore(
        int $seatNumber,
        string $troncon,
        int $targetZone,
        string $destinationStationId,
        int $numZones,
        array $zonePreferences,
        int $totalSeats,
        array $seatMapInfo,
        Collection $occupiedSeatsData,
        array $occupantDestIndices,
        int $destinationIndex,
        int $segmentStopDistance
    ): array {
        $score = 100;
        $reasons = [];

        // --- 1. ZONAGE PAR DESTINATION ---
        $seatsPerZone = $totalSeats / max(1, $numZones);
        $seatInfo = $seatMapInfo['seats'][$seatNumber] ?? ['type' => 'middle', 'adjacent_aisle_seats' => [], 'adjacent_window_seats' => [], 'row' => 999, 'col' => 999];

        // Zone du siège (basée sur le classement de proximité)
        $seatRank = $seatMapInfo['seats'][$seatNumber]['proximity_rank'] ?? 999;
        $seatZone = (int) ceil($seatRank / $seatsPerZone);

        if ($troncon === 'short') {
            $seatRow = $seatMapInfo['seats'][$seatNumber]['row_rank'] ?? 999;
            $rowsWithSeats = $seatMapInfo['rows_with_seats'] ?? 1;
            $rowZone = (int) ceil(($seatRow / max(1, $rowsWithSeats)) * max(1, $numZones));
            $seatZone = max(1, $rowZone);
        }

        $seatZone = max(1, min($numZones, $seatZone));

        if ($seatZone === $targetZone) {
            $score += 2000;
            $reasons[] = "Zone Idéale ($seatZone)";
        } else {
            $zoneDiff = abs($seatZone - $targetZone);

            // Vérifier le taux de remplissage de la zone cible pour réduire la pénalité
            $seatsInTargetZone = 0;
            $occupiedInTargetZone = 0;
            foreach ($seatMapInfo['seats'] as $sNum => $sInfo) {
                $sZone = (int) ceil(($sInfo['proximity_rank'] ?? 999) / $seatsPerZone);
                $sZone = max(1, min($numZones, $sZone));
                if ($sZone === $targetZone) {
                    $seatsInTargetZone++;
                    if ($occupiedSeatsData->has($sNum)) {
                        $occupiedInTargetZone++;
                    }
                }
            }

            $zoneFillRate = $seatsInTargetZone > 0 ? $occupiedInTargetZone / $seatsInTargetZone : 0;

            if ($zoneFillRate > 0.80) {
                // Zone cible quasi pleine : pénalité réduite, favoriser la zone adjacente
                $score -= 200 + ($zoneDiff * 100);
                $reasons[] = "Zone $seatZone (cible $targetZone pleine à ".round($zoneFillRate * 100).'%)';
            } else {
                $score -= 1000 + ($zoneDiff * 500);
                $reasons[] = "Zone $seatZone (cible: $targetZone)";
            }
        }

        // --- 2. PRÉFÉRENCE DE ZONE (court/moyen/long) ---
        // Appliquer un bonus basé sur la position dans l'ordre de préférence des zones
        $preferencePosition = array_search($seatZone, $zonePreferences);
        if ($preferencePosition !== false) {
            // Le premier dans l'ordre de préférence obtient le meilleur bonus
            $preferenceBonus = max(0, (count($zonePreferences) - $preferencePosition) * 15);
            $score += $preferenceBonus;
        }

        // --- 3. CONFORT INTRA-ZONE ---
        if ($troncon === 'long') {
            // Pour les longs trajets, favoriser les sièges plus au fond (plus calmes)
            $score += ($seatNumber / $totalSeats) * 50;
        }

        // --- 4. PROXIMITÉ AUX PORTES ---
        $doorDistance = $seatMapInfo['seats'][$seatNumber]['door_distance'] ?? PHP_INT_MAX;
        $isNearDoor = ($doorDistance <= 1);
        $proximityRatio = $totalSeats > 1 ? 1 - (($seatRank - 1) / ($totalSeats - 1)) : 1;

        if ($troncon === 'short') {
            $score += $proximityRatio * 350;
            $reasons[] = 'Proche porte';

            $seatRow = $seatInfo['row_rank'] ?? 999;
            $rowsWithSeats = max(1, $seatMapInfo['rows_with_seats'] ?? 1);
            $frontnessRatio = 1 - min(1, ($seatRow - 1) / max(1, $rowsWithSeats - 1));
            $score += $frontnessRatio * 900;

            if ($seatRow >= max(1, $rowsWithSeats - 2)) {
                $score -= 1200;
                $reasons[] = 'Arrière évité';
            }
        } elseif ($troncon === 'long') {
            $score += (1 - $proximityRatio) * 120;
        } else {
            $score += $proximityRatio * 120;
        }

        // --- 5. TYPE DE SIÈGE ---
        $seatType = $seatInfo['type'];

        if ($seatType === 'aisle') {
            $score += 30;
            $reasons[] = 'Couloir';
        } elseif ($seatType === 'window' && ! $isNearDoor) {
            $score -= 20;
            $reasons[] = 'Fenêtre';
        }

        // --- 6. ANTI-BLOCAGE BIDIRECTIONNEL ---

        // 6a. Siège fenêtre : vérifier si un passager couloir descend AVANT nous
        // (il devrait se lever pour nous laisser passer quand nous descendons)
        if ($seatType === 'window') {
            foreach ($seatInfo['adjacent_aisle_seats'] as $aisleSeat) {
                if ($occupiedSeatsData->has($aisleSeat)) {
                    $occupantDest = $occupantDestIndices[$aisleSeat] ?? 0;
                    if ($occupantDest < $destinationIndex) {
                        // L'occupant du couloir descend avant nous : il nous bloquera
                        $score -= 200;
                        $reasons[] = 'Bloqué par couloir';
                        break;
                    }
                }
            }
        }

        // 6b. Siège couloir : vérifier si un passager fenêtre descend APRÈS nous
        // (nous devrions nous lever pour le laisser sortir plus tard)
        if ($seatType === 'aisle') {
            foreach ($seatInfo['adjacent_window_seats'] as $windowSeat) {
                if ($occupiedSeatsData->has($windowSeat)) {
                    $occupantDest = $occupantDestIndices[$windowSeat] ?? 0;
                    if ($occupantDest > $destinationIndex) {
                        // L'occupant de la fenêtre descend après nous : on le bloquerait
                        $score -= 150;
                        $reasons[] = 'Bloquerait fenêtre';
                        break;
                    }
                }
            }
        }

        // --- 7. REGROUPEMENT PAR DESTINATION ---
        // Bonus si un siège voisin (même rangée) a la même destination
        $seatRow = $seatInfo['row'];
        $seatCol = $seatInfo['col'];
        $allNeighborSeats = array_merge(
            $seatInfo['adjacent_aisle_seats'] ?? [],
            $seatInfo['adjacent_window_seats'] ?? []
        );

        foreach ($allNeighborSeats as $neighborSeat) {
            if ($occupiedSeatsData->has($neighborSeat)
                && $occupiedSeatsData[$neighborSeat]->to_station_id === $destinationStationId) {
                $score += 100;
                $reasons[] = 'Même destination voisin';
                break;
            }
        }

        return [
            'score' => round($score, 2),
            'reason' => implode(' | ', $reasons),
        ];
    }

    /**
     * Obtient l'index d'un arrêt dans un trajet (utilise le cache mémoire)
     *
     * @param  string  $routeId  ID de la route
     * @param  string  $stationId  ID de la station
     * @param  bool  $isReversed  Si le voyage est en sens inverse
     * @return int Index de l'arrêt (0 = départ, valeurs plus élevées = plus loin)
     */
    private function getStopIndex(string $routeId, string $stationId, bool $isReversed = false): int
    {
        // Utiliser le cache pré-chargé
        $cached = $this->stopIndexCache[$routeId] ?? null;

        if (! $cached) {
            // Fallback : charger et cacher si pas encore fait
            $this->preloadStopIndices($routeId);
            $cached = $this->stopIndexCache[$routeId];
        }

        $originalIndex = $cached['map'][$stationId] ?? null;

        if ($originalIndex === null) {
            return 0;
        }

        if ($isReversed) {
            return ($cached['total'] - 1) - $originalIndex;
        }

        return $originalIndex;
    }

    /**
     * Compute the boarding group (zone) for a given seat to speed up boarding.
     * Zone 1 : furthest from door (board first)
     * Zone 2 : middle distance
     * Zone 3 : closest to door (board last)
     */
    public function computeBoardingGroup(\App\Models\VehicleType $vehicleType, int $seatNumber): int
    {
        $totalSeats = $vehicleType->seat_count;
        $doorPositions = $vehicleType->door_positions ?? [];

        $seatMapService = app(SeatMapService::class);
        $fullSeatMap = $seatMapService->ensureGrid($vehicleType->seat_map ?? [], [
            'seat_count' => $totalSeats,
            'seat_configuration' => $vehicleType->seat_configuration ?? '2+2',
            'door_positions' => $doorPositions,
            'last_row_seats' => $vehicleType->last_row_seats ?? 5,
        ]);

        $seatMapInfo = $this->parseSeatMap($fullSeatMap);

        if (! isset($seatMapInfo['seats'][$seatNumber])) {
            return 1; // Default to zone 1 if seat not found
        }

        $seatDistance = $seatMapInfo['seats'][$seatNumber]['door_distance'] ?? 0;

        // Find max distance to door across all seats
        $maxDistance = 0;
        foreach ($seatMapInfo['seats'] as $info) {
            $dist = $info['door_distance'] ?? 0;
            if ($dist > $maxDistance) {
                $maxDistance = $dist;
            }
        }

        if ($maxDistance == 0) {
            return 1;
        }

        // Zone 3: closest to door (<= 33% of max distance)
        // Zone 2: middle
        // Zone 1: furthest from door (> 66% of max distance)
        $threshold1 = $maxDistance / 3.0;
        $threshold2 = ($maxDistance * 2.0) / 3.0;

        if ($seatDistance <= $threshold1) {
            return 3;
        } elseif ($seatDistance <= $threshold2) {
            return 2;
        } else {
            return 1;
        }
    }

    /**
     * Obtient des statistiques sur l'occupation d'un voyage
     *
     * @param  string  $tripId  ID du voyage
     * @return array Statistiques d'occupation
     */
    public function getTripOccupancyStats(string $tripId): array
    {
        $trip = Trip::with('vehicle.vehicleType')->findOrFail($tripId);

        $totalSeats = $trip->vehicle->vehicleType->seat_count;
        $occupiedSeats = Ticket::where('trip_id', $tripId)
            ->where('status', '!=', 'cancelled')
            ->count();

        $occupancyRate = $totalSeats > 0 ? ($occupiedSeats / $totalSeats) * 100 : 0;

        return [
            'total_seats' => $totalSeats,
            'occupied_seats' => $occupiedSeats,
            'available_seats' => $totalSeats - $occupiedSeats,
            'occupancy_rate' => round($occupancyRate, 2),
            'booking_type' => $trip->booking_type,
            'vehicle_type' => $trip->vehicle->vehicleType->name,
        ];
    }
}
