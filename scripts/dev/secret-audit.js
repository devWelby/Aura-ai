#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const cp = require('child_process');

const root = process.cwd();

const ignoreFiles = new Set([
  '.env.example',
  'README.md',
]);

const ignoreExt = new Set([
  '.png', '.jpg', '.jpeg', '.gif', '.webp', '.ico', '.pdf', '.zip', '.gz', '.7z', '.mp4', '.mp3', '.woff', '.woff2', '.ttf',
]);

const secretPatterns = [
  { name: 'Google API Key', regex: /AIza[0-9A-Za-z\-_]{35}/g },
  { name: 'Google OAuth Secret', regex: /GOCSPX-[0-9A-Za-z\-_]{20,}/g },
  { name: 'Stripe Secret Key', regex: /sk_(live|test)_[0-9A-Za-z]{16,}/g },
  { name: 'Stripe Webhook Secret', regex: /whsec_[0-9A-Za-z]{16,}/g },
  { name: 'Resend API Key', regex: /\bre_[0-9A-Za-z\-_]{16,}\b/g },
  { name: 'Private Key Block', regex: /-----BEGIN (?:RSA |EC )?PRIVATE KEY-----/g },
  { name: 'Generic High Entropy Secret', regex: /(?:api[_-]?key|secret|token|password)\s*[:=]\s*['\"]?[A-Za-z0-9\/_+=\-]{24,}/gi },
];

const placeholderHints = [
  'SUA_CHAVE',
  'SEU_',
  'AQUI',
  'example',
  'EXAMPLE',
  'gere_um_valor',
  'localhost',
];

function isPlaceholder(line) {
  return placeholderHints.some((hint) => line.includes(hint));
}

function listTrackedFiles() {
  const output = cp.execSync('git ls-files -z', { encoding: 'utf8' });
  return output.split('\u0000').filter(Boolean);
}

function shouldSkipFile(filePath) {
  const base = path.basename(filePath);
  if (ignoreFiles.has(base)) {
    return true;
  }

  const ext = path.extname(filePath).toLowerCase();
  if (ignoreExt.has(ext)) {
    return true;
  }

  if (filePath.startsWith('vendor/') || filePath.startsWith('node_modules/')) {
    return true;
  }

  return false;
}

function findSecrets(filePath, content) {
  const findings = [];
  const lines = content.split(/\r?\n/);

  lines.forEach((line, idx) => {
    if (isPlaceholder(line)) {
      return;
    }

    for (const pattern of secretPatterns) {
      if (pattern.regex.test(line)) {
        findings.push({
          filePath,
          line: idx + 1,
          rule: pattern.name,
          sample: line.trim().slice(0, 180),
        });
      }
      pattern.regex.lastIndex = 0;
    }
  });

  return findings;
}

function main() {
  const files = listTrackedFiles();
  const findings = [];

  for (const file of files) {
    if (shouldSkipFile(file)) {
      continue;
    }

    const absolute = path.join(root, file);
    if (!fs.existsSync(absolute)) {
      continue;
    }

    const stat = fs.statSync(absolute);
    if (!stat.isFile() || stat.size > 2 * 1024 * 1024) {
      continue;
    }

    const content = fs.readFileSync(absolute, 'utf8');
    findings.push(...findSecrets(file, content));
  }

  if (findings.length > 0) {
    console.error('Secret audit failed. Potential secrets found in tracked files:');
    for (const finding of findings) {
      console.error(`- ${finding.filePath}:${finding.line} [${finding.rule}] ${finding.sample}`);
    }
    process.exit(1);
  }

  console.log('Secret audit passed: no obvious secrets found in tracked files.');
}

main();
