export const API = { namespace: 'int/v1' };

export const TYPE_OPTIONS = [
    { label: 'Contact enquiry', value: 'contact' },
    { label: 'Driver application', value: 'driver' },
];

const HIDDEN_FIELDS = ['privacy_consent', 'website'];

export function typeLabel(type) {
    return type === 'driver' ? 'Driver application' : 'Contact enquiry';
}

export function permissionFor(type, action) {
    return `fleet-ops ${action} ${type === 'driver' ? 'driver-application' : 'contact-enquiry'}`;
}

export function formatDate(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
}

export function statusOptions(statuses = {}) {
    const values = [...new Set([...(statuses.contact ?? []), ...(statuses.driver ?? [])])];
    return values.map((value) => ({ label: value.replaceAll('_', ' '), value }));
}

export function assigneeOptions(assignees = []) {
    return [{ label: 'Unassigned', value: 'unassigned' }, ...assignees.map((staff) => ({ label: staff.name, value: staff.uuid }))];
}

export function decorate(row, permissions = {}) {
    return {
        ...row,
        typeLabel: typeLabel(row.type),
        receivedAt: formatDate(row.created_at),
        assigneeLabel: row.assigned_to_name ?? (row.assigned_to_uuid ? 'Former staff member' : 'Unassigned'),
        canView: Boolean(permissions?.[row.type]?.view),
    };
}

export function submissionFields(payload = {}) {
    return Object.entries(payload ?? {})
        .filter(([key]) => !HIDDEN_FIELDS.includes(key))
        .map(([key, value]) => ({ label: key.replaceAll('_', ' '), value: Array.isArray(value) ? value.join(', ') : String(value ?? '') }));
}

export function cleanParams(params = {}) {
    return Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''));
}
