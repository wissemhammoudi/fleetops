export const IDENTITY_NAMESPACE = 'int/v1';

export const LEGAL_FORMS = ['individual_business', 'suarl', 'sarl', 'sa', 'other'];

export const IDENTITY_DOCUMENTS = {
    individual: ['cin_front', 'cin_back'],
    company: ['tax_card', 'rne_extract'],
};

export async function openIdentityDocument(fetch, uuid) {
    const result = await fetch.get(`customer-identities/documents/${uuid}`, {}, { namespace: IDENTITY_NAMESPACE });
    const bytes = Uint8Array.from(atob(result.content), (char) => char.charCodeAt(0));
    const url = URL.createObjectURL(new Blob([bytes], { type: result.mime }));
    window.open(url, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(url), 60000);
}
