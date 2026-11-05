export const VEHICLE_PAPERS_NAMESPACE = 'int/v1';

/** Tunisian delivery vehicle types with default capacity (parcels, kg, m³) pre-filled on selection. */
export const VEHICLE_TYPES = [
    { value: 'motorcycle', parcels: 25, weight: 40, volume: 0.15 },
    { value: 'car', parcels: 40, weight: 250, volume: 1 },
    { value: 'small_van', parcels: 120, weight: 600, volume: 3.5 },
    { value: 'large_van', parcels: 300, weight: 1200, volume: 10 },
    { value: 'small_truck', parcels: 600, weight: 3000, volume: 18 },
];

export const VEHICLE_FUELS = ['petrol', 'diesel', 'lpg', 'hybrid', 'electric'];

export const VEHICLE_OWNERSHIPS = ['company_owned', 'driver_owned', 'leased'];

export const VEHICLE_PAPER_DATES = ['insurance_expires_at', 'visite_technique_expires_at', 'vignette_expires_at'];

export const VEHICLE_PAPER_ALERT_DATES = ['insurance_expires_at', 'visite_technique_expires_at'];

export const VEHICLE_PAPER_DOCUMENTS = ['carte_grise', 'insurance'];

export const VEHICLE_PAPER_SOON_DAYS = 30;

export function vehiclePapers(vehicle) {
    const papers = vehicle?.meta?.papers;
    return papers && typeof papers === 'object' ? papers : {};
}

function todayString() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function addDays(date, days) {
    const [year, month, day] = date.split('-').map(Number);
    const result = new Date(year, month - 1, day + days);
    const pad = (value) => String(value).padStart(2, '0');
    return `${result.getFullYear()}-${pad(result.getMonth() + 1)}-${pad(result.getDate())}`;
}

/** Mirrors Fleetbase\FleetOps\Support\VehiclePapers::status (expired, expiring, missing, valid). */
export function paperDateStatus(value, today = todayString()) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return 'missing';
    }
    if (value < today) {
        return 'expired';
    }
    return value <= addDays(today, VEHICLE_PAPER_SOON_DAYS) ? 'expiring' : 'valid';
}

export function vehiclePaperStatus(vehicle, today = todayString()) {
    const papers = vehiclePapers(vehicle);
    const statuses = VEHICLE_PAPER_ALERT_DATES.map((kind) => paperDateStatus(papers[kind], today));
    for (const status of ['expired', 'expiring', 'missing']) {
        if (statuses.includes(status)) {
            return status;
        }
    }
    return 'valid';
}

export async function openVehiclePaper(fetch, uuid) {
    const result = await fetch.get(`vehicle-papers/documents/${uuid}`, {}, { namespace: VEHICLE_PAPERS_NAMESPACE });
    const bytes = Uint8Array.from(atob(result.content), (char) => char.charCodeAt(0));
    const url = URL.createObjectURL(new Blob([bytes], { type: result.mime }));
    window.open(url, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(url), 60000);
}
