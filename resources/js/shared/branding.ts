/**
 * Files served straight from public/, not bundled.
 *
 * Written as constants and bound with :src rather than put in a src attribute:
 * Vite resolves a literal src in a template as a module and fails the build,
 * because these are not imported assets - they are files on disk that an
 * administrator can replace without a rebuild.
 */
export const LOGO = '/images/logo.png';
