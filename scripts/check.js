import fs from "node:fs/promises";
import path from "node:path";
import { spawnSync } from "node:child_process";

async function files(directory) {
  const result = [];
  for (const entry of await fs.readdir(directory, { withFileTypes: true })) {
    const full = path.join(directory, entry.name);
    if (entry.isDirectory()) result.push(...(await files(full)));
    else result.push(full);
  }
  return result;
}
const sources = (
  await Promise.all(["server", "scripts", "public", "tests"].map(files))
).flat();
if (await fs.access(".git").then(() => true, () => false)) {
  const listed = spawnSync("git", ["ls-files", "--cached", "--", "public/data"], {
    encoding: "utf8",
  });
  if (listed.status !== 0) throw new Error("Could not check deployed data files.");
  const tracked = new Set(listed.stdout.trim().split(/\r?\n/));
  for (const filename of sources.filter((name) => name.startsWith(`public${path.sep}data${path.sep}`))) {
    if (!tracked.has(filename.replaceAll(path.sep, "/")))
      throw new Error(`Content file is missing from Git: ${filename}`);
  }
}
let checked = 0;
for (const filename of sources) {
  if (filename.endsWith(".js")) {
    const result = spawnSync(process.execPath, ["--check", filename], {
      encoding: "utf8",
    });
    if (result.status !== 0) throw new Error(`${filename}\n${result.stderr}`);
    checked++;
  }
  if (filename.startsWith("public/") && /\.(?:html|css|js|json|txt|svg)$/.test(filename)) {
    const contents = await fs.readFile(filename, "utf8");
    if (
      /\b(?:localStorage|sessionStorage|indexedDB)\b|navigator\.serviceWorker|caches\.open/.test(
        contents,
      )
    )
      throw new Error(`Browser persistence found in ${filename}`);
    if (/\.(?:html|css|js)$/.test(filename)) {
      for (const match of contents.matchAll(
        /(?:src|href)=["'](\/(?!\/)[^"'#?]*)/g,
      )) {
        const destination = match[1];
        if (!path.extname(destination) || destination.startsWith("/api/"))
          continue;
        try {
          await fs.access(path.join("public", destination));
        } catch {
          throw new Error(
            `Missing local reference: ${filename} -> ${destination}`,
          );
        }
      }
    }
  }
}
console.log(
  `Checked ${checked} JavaScript files; no browser data stores or missing local HTML references.`,
);
