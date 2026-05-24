const fs = require('fs');
const path = require('path');

const filepath = path.join(__dirname, 'pages', 'customer-dashboard.html');

// Read the file as buffer first
let buffer = fs.readFileSync(filepath);

// Replace byte sequences - these are the UTF-8 byte sequences for the corrupted characters
// Format: [byte sequence, replacement string]
const replacements = [
  // â€" (UTF-8 encoded wrong em-dash)
  [Buffer.from([0xc3, 0xa2, 0xc2, 0x80, 0xc2, 0x93]), '—'],
  [Buffer.from([0xc3, 0xa2, 0xc2, 0x80, 0xc2, 0x94]), '—'],
  // â€¢ (bullet)
  [Buffer.from([0xc3, 0xa2, 0xc2, 0x80, 0xc2, 0xa2]), '•'],
  // â‚± (peso)
  [Buffer.from([0xc3, 0xa2, 0xc2, 0x82, 0xc2, 0xb1]), '₱'],
];

// Convert to string for replacement
let content = buffer.toString('utf8');

// Apply string replacements for the actual malformed text
const stringReplacements = [
  ['â€"', '—'],
  ['â€¢', '•'],
  ['â‚±', '₱'],
  ['ðŸ ', '🏠'],
  ['ðŸ"¦', '📦'],
  ['ðŸ›\'', '🛒'],
  ['ðŸ¥¦', '🥦'],
  ['ðŸ¥›', '🥛'],
  ['ðŸ¥©', '🥩'],
  ['ðŸž', '🥖'],
  ['ðŸ¥¤', '🥤'],
  ['ðŸª', '🍪'],
  ['ðŸ§¹', '🧹'],
  ['ðŸ§´', '🧴'],
  ['ðŸ¼', '🍼'],
  ['ðŸ¶', '🐶'],
  ['ðŸšª', '🚪'],
  ['ðŸ\'‹', '👋'],
  ['ðŸ"', '📍'],
  ['ðŸšš', '🚚'],
  ['âœ…', '✅'],
  ['ðŸ›ï¸', '🛒'],
  ['ðŸŽ‰', '🎉'],
  ['ðŸŽ', '🎁'],
  ['ðŸ"Œ', '📌'],
  ['âŒ', '❌'],
  ['ðŸ\'¤', '👤'],
];

for (const [search, replace] of stringReplacements) {
  while (content.includes(search)) {
    content = content.replace(search, replace);
  }
}

// Write back the file
fs.writeFileSync(filepath, content, 'utf8');
console.log('✅ File fixed successfully!');

