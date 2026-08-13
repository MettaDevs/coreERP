import fs from 'fs';
const content = fs.readFileSync('c:/PKL/coreERP/apps/control-plane/resources/js/pages/dashboard.tsx', 'utf8');

const lines = content.split('\n');
lines.forEach((line, idx) => {
    if (line.includes('dark:')) {
        console.log(`L${idx + 1}: ${line.trim()}`);
    }
});
