export default function pickupCallLink(phone) {
    const number = typeof phone === 'string' ? phone.replace(/[\s()-]/g, '') : '';
    return /^\+?\d{6,15}$/.test(number) ? `tel:${number}` : null;
}
