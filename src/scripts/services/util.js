/** @constant {string} UUID_TEMPLATE Template string for generating UUIDs. */
const UUID_TEMPLATE = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';

/** @constant {string} RANDOM_PLACEHOLDER Placeholder character used in UUID template for random generation. */
const RANDOM_PLACEHOLDER = 'x';

/** @constant {string} UUID_REPLACE_PATTERN Regular expression pattern for replacing characters in UUID template. */
const UUID_REPLACE_PATTERN = /[xy]/g;

/** @constant {number} HEX_RADIX Radix value for hexadecimal conversions. */
const HEX_RADIX = 16;

/** @constant {number} VARIANT_MASK Bitmask for extracting variant information. */
const VARIANT_MASK = 0x3;

/** @constant {number} VARIANT_FLAG Bit flag for indicating variant presence. */
const VARIANT_FLAG = 0x8;

/**
 * Create UUID string using Web Crypto API if available.
 * @returns {string} UUID string.
 */
export const createUUID = () => {
  if (typeof window.crypto?.randomUUID === 'function') {
    return window.crypto.randomUUID();
  }

  return UUID_TEMPLATE.replace(UUID_REPLACE_PATTERN, (char) => {
    const random = (Math.random() * HEX_RADIX) | 0;
    const newChar = char === RANDOM_PLACEHOLDER ? random : (random & VARIANT_MASK) | VARIANT_FLAG;
    return newChar.toString(HEX_RADIX);
  });
};
