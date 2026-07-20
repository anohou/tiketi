const routeStations = (route) => {
  if (!route) return [];

  const stops = route.route_stop_orders || route.routeStopOrders || [];
  const candidates = [
    route.origin_station || route.originStation,
    ...[...stops]
      .sort((a, b) => (a.stop_index ?? 0) - (b.stop_index ?? 0))
      .map((stop) => ({
        ...(stop.station || {}),
        id: stop.station_id || stop.station?.id,
        name: stop.station?.name || 'Gare',
      })),
    route.destination_station || route.destinationStation,
  ];
  const stationsById = new Map();

  candidates.forEach((station, index) => {
    const id = station?.id
      || (index === 0 ? route.origin_station_id : null)
      || (index === candidates.length - 1 ? route.destination_station_id : null);
    if (id && !stationsById.has(id)) {
      stationsById.set(id, station?.id ? station : { id, name: 'Gare' });
    }
  });

  return [...stationsById.values()];
};

export const buildTripCreationRouteOptions = (routes, originId) => {
  if (!originId) return [];

  return (routes || [])
    .filter((route) => routeStations(route).some((station) => station.id === originId))
    .map((route) => ({
      value: route.id,
      label: route.name || route.display_name || 'Ligne',
    }))
    .sort((a, b) => a.label.localeCompare(b.label));
};

export const buildTripCreationDestinationOptions = (routes, originId, routeId, stations = []) => {
  if (!originId || !routeId) return [];

  const route = (routes || []).find((candidate) => candidate.id === routeId);
  if (!route) return [];

  const stationNames = new Map(stations.map((station) => [station.id, station.name]));

  return routeStations(route)
    .filter((station) => station.id !== originId)
    .map((station) => ({
      value: station.id,
      destId: station.id,
      label: station.name || stationNames.get(station.id) || 'Gare',
    }));
};
