import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = path.join(root, 'public');
const php = path.join(root, 'infinityfree', 'php');
const output = await fs.mkdtemp(path.join(root, 'infinityfree', 'package-'));
const htdocs = path.join(output, 'htdocs');

await fs.cp(source, htdocs, {
  recursive: true,
  filter: (file) => path.basename(file) !== 'upload-test.html',
});
for (const file of ['.htaccess', 'index.php', 'router.php'])
  await fs.copyFile(path.join(php, file), path.join(htdocs, file));
await fs.mkdir(path.join(htdocs, '_private'), { recursive: true });
for (const file of ['.htaccess', 'common.php', 'pages.php', 'auth.php', 'api.php'])
  await fs.copyFile(path.join(php, '_private', file), path.join(htdocs, '_private', file));
await fs.copyFile(path.join(php, 'config.example.php'), path.join(htdocs, '_private', 'config.php'));
await fs.copyFile(path.join(php, 'schema.sql'), path.join(output, 'schema.sql'));
await fs.copyFile(path.join(root, 'infinityfree', 'README.md'), path.join(output, 'README.md'));
console.log(output);
