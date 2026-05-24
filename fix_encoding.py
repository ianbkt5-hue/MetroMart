#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os

# Read the file
filepath = r'c:\xampp\htdocs\MetroMart\pages\customer-dashboard.html'

with open(filepath, 'rb') as f:
    content = f.read()

# Decode as UTF-8
text = content.decode('utf-8', errors='ignore')

# Replace all the malformed characters
replacements = {
    'â€"': '—',  # em-dash
    'â€¢': '•',  # bullet
    'â„¢': '™',  # trademark
    'â‚±': '₱',  # Philippine peso
    'ðŸ ': '🏠',   # home
    'ðŸ"¦': '📦',  # package
    'ðŸ›\'': '🛒',  # shopping cart
    'ðŸ¥¦': '🥦',  # broccoli
    'ðŸ¥›': '🥛',  # milk glass
    'ðŸ¥©': '🥩',  # meat
    'ðŸž': '🥖',  # baguette
    'ðŸ¥¤': '🥤',  # beverage
    'ðŸª': '🍪',  # cookie/snacks
    'ðŸ§¹': '🧹',  # broom
    'ðŸ§´': '🧴',  # lotion bottle
    'ðŸ¼': '🍼',   # baby bottle
    'ðŸ¶': '🐶',   # dog
    'ðŸšª': '🚪',  # door
    'ðŸ\'‹': '👋',  # waving hand
    'ðŸ"': '📍',  # pin
    'ðŸšš': '🚚',  # truck
    'âœ…': '✅',  # checkmark
    'ðŸ›ï¸': '🛒',  # shopping bag
    'ðŸŽ‰': '🎉',  # celebration
    'ðŸŽ': '🎁',  # gift
    'ðŸ"Œ': '📌',  # pushpin
    'ðŸ›\'': '🛍',  # shopping bags
    'âŒ': '❌',  # cross mark
    'ðŸ¤': '🤔',  # thinking face
    'ðŸ'¤': '👤',  # person
    'ðŸ'„': '💄',  # lipstick
    'ðŸ'¡': '💡',  # light bulb
}

for old, new in replacements.items():
    text = text.replace(old, new)

# Write back with proper UTF-8 encoding
with open(filepath, 'w', encoding='utf-8') as f:
    f.write(text)

print('File fixed successfully')
