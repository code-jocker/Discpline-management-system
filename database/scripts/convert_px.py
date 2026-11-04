import os
import re

css_files = [
    'assets/css/auth.css',
    'assets/css/student-management.css',
    'assets/css/settings.css',
    'assets/css/discipline-engine.css'
]

def px_to_rem(match):
    px_val = float(match.group(1))
    if px_val <= 2:
        return match.group(0)  # Keep 1px, 2px as is
    
    rem_val = px_val / 16.0
    rem_str = f"{rem_val:g}"
    return f"{rem_str}rem"

for file_path in css_files:
    full_path = os.path.join('c:/xampp/htdocs/sbms', file_path)
    if os.path.exists(full_path):
        with open(full_path, 'r', encoding='utf-8') as f:
            content = f.read()
        
        new_content = re.sub(r'(\d+(?:\.\d+)?)px', px_to_rem, content)
        
        with open(full_path, 'w', encoding='utf-8') as f:
            f.write(new_content)
        print(f"Processed {file_path}")
    else:
        print(f"File not found: {file_path}")
