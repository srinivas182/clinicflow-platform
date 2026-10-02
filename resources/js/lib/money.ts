/**
 * South African Rand, space as thousands separator: R2 990.
 */
export function rand(amount: number, decimals = 0): string {
    const fixed = amount.toFixed(decimals);
    const [whole, cents] = fixed.split('.');
    const grouped = (whole ?? '0').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return `R${grouped}${cents ? `.${cents}` : ''}`;
}
