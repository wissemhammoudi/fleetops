export const DRIVER_IDENTITY_NAMESPACE = 'int/v1';

export const DRIVER_KINDS = ['team', 'freelance'];

export const DRIVER_TAX_REGIMES = ['forfaitaire', 'reel'];

export const DRIVER_IDENTITY_DOCUMENTS = {
    team: ['cin_front', 'cin_back', 'contract'],
    freelance: ['cin_front', 'cin_back', 'contract', 'patente', 'rne_extract'],
};

export const DRIVER_PDF_ONLY_DOCUMENTS = ['contract'];

export function driverDocumentAccept(kind) {
    return DRIVER_PDF_ONLY_DOCUMENTS.includes(kind) ? 'application/pdf' : 'application/pdf,image/jpeg,image/png';
}

export async function openDriverIdentityDocument(fetch, uuid) {
    const result = await fetch.get(`driver-identities/documents/${uuid}`, {}, { namespace: DRIVER_IDENTITY_NAMESPACE });
    const bytes = Uint8Array.from(atob(result.content), (char) => char.charCodeAt(0));
    const url = URL.createObjectURL(new Blob([bytes], { type: result.mime }));
    window.open(url, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(url), 60000);
}
